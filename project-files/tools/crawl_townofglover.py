#!/usr/bin/env python3
"""Create a redesign-oriented content archive of townofglover.com."""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import mimetypes
import re
import time
import urllib.error
import urllib.parse
import urllib.request
from collections import deque
from datetime import datetime, timezone
from pathlib import Path

from lxml import etree, html


BASE = "https://townofglover.com/"
ALLOWED_HOSTS = {"townofglover.com", "www.townofglover.com"}
USER_AGENT = "TownOfGloverContentArchive/1.0 (website redesign content inventory)"
ATTACHMENT_EXTS = {
    ".pdf", ".doc", ".docx", ".xls", ".xlsx", ".ppt", ".pptx", ".csv",
    ".txt", ".rtf", ".zip", ".ics", ".odt", ".ods", ".xml", ".json",
    ".mp3", ".mp4", ".m4a", ".wav", ".mov", ".avi",
}
IMAGE_EXTS = {
    ".jpg", ".jpeg", ".png", ".gif", ".webp", ".svg", ".avif", ".bmp",
    ".tif", ".tiff", ".ico",
}
PROJECT_FILES = Path(__file__).resolve().parents[1]
DEFAULT_ARCHIVE = PROJECT_FILES / "townofglover-content-archive"
SKIP_PATH_PREFIXES = (
    "/wp-admin", "/wp-login", "/wp-json", "/xmlrpc.php",
)
BLOCK_TAGS = {
    "h1", "h2", "h3", "h4", "h5", "h6", "p", "li", "blockquote",
    "address", "figcaption", "dt", "dd", "tr",
}


def clean_space(value: str) -> str:
    return re.sub(r"\s+", " ", value or "").strip()


def short_hash(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()[:10]


def normalize_url(url: str, base: str = BASE, keep_query: bool = False) -> str | None:
    url = clean_space(url)
    if not url or url.startswith(("#", "mailto:", "tel:", "javascript:", "data:")):
        return None
    absolute = urllib.parse.urljoin(base, url)
    parts = urllib.parse.urlsplit(absolute)
    host = (parts.hostname or "").lower()
    if host not in ALLOWED_HOSTS:
        return absolute
    path = re.sub(r"/{2,}", "/", parts.path or "/")
    # Browsers tolerate literal spaces and other unescaped characters in old
    # WordPress links; urllib does not. Preserve existing escapes while safely
    # encoding those legacy paths.
    path = urllib.parse.quote(urllib.parse.unquote(path), safe="/:@-._~!$&'()*+,;=")
    if path != "/" and path.endswith("/"):
        path = path.rstrip("/") + "/"
    query = parts.query if keep_query else ""
    return urllib.parse.urlunsplit(("https", "townofglover.com", path, query, ""))


def classified_extension(url: str) -> str:
    path = urllib.parse.urlsplit(url).path
    return Path(urllib.parse.unquote(path)).suffix.lower()


def is_image(url: str, content_type: str = "") -> bool:
    return classified_extension(url) in IMAGE_EXTS or content_type.lower().startswith("image/")


def is_attachment(url: str, content_type: str = "") -> bool:
    ext = classified_extension(url)
    content_type = content_type.lower()
    return (
        ext in ATTACHMENT_EXTS
        or content_type.startswith(("application/pdf", "application/msword",
                                    "application/vnd.", "audio/", "video/"))
    )


def safe_relative_name(url: str, default_name: str) -> Path:
    parts = urllib.parse.urlsplit(url)
    path = urllib.parse.unquote(parts.path).strip("/")
    if not path:
        path = default_name
    segments = []
    for segment in path.split("/"):
        segment = re.sub(r"[^A-Za-z0-9._-]+", "-", segment).strip("-")
        segments.append(segment[:140] or "item")
    rel = Path(*segments)
    if len(str(rel)) > 220:
        rel = Path(rel.parent.name, f"{Path(rel.name).stem[:80]}-{short_hash(url)}{Path(rel.name).suffix}")
    return rel


class Fetcher:
    def __init__(self, delay: float = 0.08):
        self.delay = delay
        self.last_request = 0.0

    def fetch(self, url: str, attempts: int = 3) -> tuple[bytes, str, str, int]:
        error = None
        for attempt in range(attempts):
            elapsed = time.monotonic() - self.last_request
            if elapsed < self.delay:
                time.sleep(self.delay - elapsed)
            request = urllib.request.Request(
                url,
                headers={
                    "User-Agent": USER_AGENT,
                    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
                },
            )
            try:
                with urllib.request.urlopen(request, timeout=45) as response:
                    self.last_request = time.monotonic()
                    return (
                        response.read(),
                        response.headers.get_content_type(),
                        response.geturl(),
                        response.status,
                    )
            except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError) as exc:
                self.last_request = time.monotonic()
                error = exc
                if attempt + 1 < attempts:
                    time.sleep(1.5 * (attempt + 1))
        raise RuntimeError(f"Failed to fetch {url}: {error}")


def parse_sitemap(fetcher: Fetcher, start_urls: list[str]) -> tuple[set[str], list[dict]]:
    discovered: set[str] = set()
    records: list[dict] = []
    pending = deque(start_urls)
    seen = set()
    while pending:
        sitemap_url = pending.popleft()
        if sitemap_url in seen:
            continue
        seen.add(sitemap_url)
        try:
            body, content_type, final_url, status = fetcher.fetch(sitemap_url)
            root = etree.fromstring(body)
            locs = [clean_space(x) for x in root.xpath("//*[local-name()='loc']/text()")]
            sitemap_children = [u for u in locs if u.lower().endswith((".xml", ".xml.gz"))]
            for child in sitemap_children:
                child_norm = normalize_url(child, sitemap_url, keep_query=True)
                if child_norm and child_norm not in seen:
                    pending.append(child_norm)
            for loc in locs:
                normalized = normalize_url(loc, sitemap_url)
                if normalized and urllib.parse.urlsplit(normalized).hostname in ALLOWED_HOSTS:
                    if not normalized.lower().endswith((".xml", ".xml.gz")):
                        discovered.add(normalized)
            records.append({
                "url": sitemap_url,
                "final_url": final_url,
                "status": status,
                "content_type": content_type,
                "loc_count": len(locs),
                "error": "",
            })
        except Exception as exc:
            records.append({
                "url": sitemap_url,
                "final_url": "",
                "status": "",
                "content_type": "",
                "loc_count": 0,
                "error": str(exc),
            })
    return discovered, records


def page_filename(url: str) -> Path:
    parts = urllib.parse.urlsplit(url)
    path = urllib.parse.unquote(parts.path).strip("/")
    if not path:
        return Path("home.md")
    clean = re.sub(r"[^A-Za-z0-9/_-]+", "-", path).strip("-")
    clean = "/".join(x[:100] for x in clean.split("/") if x)
    return Path(clean) / "index.md"


def extract_main(root):
    candidates = root.xpath(
        "//main | //article | //*[@id='content'] | //*[@id='main'] | "
        "//*[contains(concat(' ', normalize-space(@class), ' '), ' entry-content ')] | "
        "//*[contains(concat(' ', normalize-space(@class), ' '), ' site-content ')]"
    )
    if candidates:
        return max(candidates, key=lambda n: len(clean_space(n.text_content())))
    bodies = root.xpath("//body")
    return bodies[0] if bodies else root


def extract_blocks(container) -> list[dict]:
    blocks: list[dict] = []
    seen_text: set[tuple[str, str]] = set()
    for node in container.iter():
        tag = node.tag.lower() if isinstance(node.tag, str) else ""
        if tag not in BLOCK_TAGS:
            continue
        if any(
            isinstance(ancestor.tag, str)
            and ancestor.tag.lower() in {"nav", "header", "footer", "script", "style", "noscript"}
            for ancestor in node.iterancestors()
        ):
            continue
        text = clean_space(node.text_content())
        if not text:
            continue
        if tag == "tr":
            cells = [clean_space(c.text_content()) for c in node.xpath("./th|./td")]
            cells = [c for c in cells if c]
            if not cells:
                continue
            text = " | ".join(cells)
        key = (tag, text)
        if key in seen_text:
            continue
        seen_text.add(key)
        blocks.append({"type": tag, "text": text})
    return blocks


def asset_urls(root, base_url: str) -> tuple[set[str], set[str], set[str]]:
    images: set[str] = set()
    attachments: set[str] = set()
    stylesheets: set[str] = set()

    for node in root.xpath("//*[@src or @data-src or @data-lazy-src or @srcset or @data-srcset]"):
        for attr in ("src", "data-src", "data-lazy-src"):
            value = node.get(attr)
            if value:
                normalized = normalize_url(value, base_url, keep_query=True)
                if normalized and is_image(normalized):
                    images.add(normalized)
        for attr in ("srcset", "data-srcset"):
            value = node.get(attr, "")
            for candidate in value.split(","):
                candidate_url = clean_space(candidate).split(" ")[0]
                normalized = normalize_url(candidate_url, base_url, keep_query=True)
                if normalized and is_image(normalized):
                    images.add(normalized)

    for node in root.xpath("//a[@href]"):
        normalized = normalize_url(node.get("href"), base_url, keep_query=True)
        if not normalized:
            continue
        if is_image(normalized):
            images.add(normalized)
        elif classified_extension(normalized) in ATTACHMENT_EXTS:
            attachments.add(normalized)

    for node in root.xpath("//link[@href]"):
        normalized = normalize_url(node.get("href"), base_url, keep_query=True)
        if not normalized:
            continue
        rel = " ".join(node.get("rel", "").lower().split())
        if "stylesheet" in rel:
            stylesheets.add(normalized)
        elif is_image(normalized):
            images.add(normalized)

    for node in root.xpath("//*[@style]"):
        for raw in re.findall(r"url\([\"']?([^\"')]+)", node.get("style", ""), flags=re.I):
            normalized = normalize_url(raw, base_url, keep_query=True)
            if normalized and is_image(normalized):
                images.add(normalized)

    return images, attachments, stylesheets


def markdown_page(record: dict) -> str:
    lines = [
        f"# {record['title'] or '(Untitled page)'}",
        "",
        f"- Source URL: {record['url']}",
        f"- Final URL: {record['final_url']}",
        f"- Crawled: {record['crawled_at']}",
        f"- Page type: {record['page_type']}",
        f"- Published/updated: {record['date'] or 'Not stated'}",
        "",
        "## Page content",
        "",
    ]
    for block in record["blocks"]:
        tag = block["type"]
        text = block["text"]
        if tag.startswith("h") and len(tag) == 2 and tag[1].isdigit():
            level = min(int(tag[1]) + 1, 6)
            lines.extend([f"{'#' * level} {text}", ""])
        elif tag == "li":
            lines.append(f"- {text}")
        elif tag == "blockquote":
            lines.extend([f"> {text}", ""])
        elif tag == "tr":
            lines.append(f"- Table row: {text}")
        else:
            lines.extend([text, ""])

    if record["attachments"]:
        lines.extend(["## Downloadable files referenced", ""])
        for item in record["attachments"]:
            lines.append(f"- {item}")
        lines.append("")
    if record["images"]:
        lines.extend(["## Images referenced", ""])
        for item in record["images"]:
            lines.append(f"- {item}")
        lines.append("")
    if record["internal_links"]:
        lines.extend(["## Internal links", ""])
        for item in record["internal_links"]:
            lines.append(f"- {item}")
        lines.append("")
    if record["external_links"]:
        lines.extend(["## External links", ""])
        for item in record["external_links"]:
            lines.append(f"- {item}")
        lines.append("")
    return "\n".join(lines).rstrip() + "\n"


def download_asset(fetcher: Fetcher, url: str, root_dir: Path, kind: str) -> dict:
    record = {
        "url": url, "kind": kind, "local_path": "", "content_type": "",
        "bytes": 0, "status": "", "final_url": "", "error": "",
    }
    try:
        body, content_type, final_url, status = fetcher.fetch(url)
        name = safe_relative_name(final_url, f"{kind}-{short_hash(url)}")
        if not name.suffix:
            guessed = mimetypes.guess_extension(content_type) or ""
            name = name.with_suffix(guessed)
        target = root_dir / kind / name
        if target.exists() and target.read_bytes() != body:
            target = target.with_name(f"{target.stem}-{short_hash(url)}{target.suffix}")
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(body)
        record.update({
            "local_path": str(target.relative_to(root_dir)),
            "content_type": content_type,
            "bytes": len(body),
            "status": status,
            "final_url": final_url,
        })
    except Exception as exc:
        record["error"] = str(exc)
    return record


def crawl(output: Path, page_limit: int = 10000) -> None:
    output.mkdir(parents=True, exist_ok=True)
    (output / "pages").mkdir(exist_ok=True)
    (output / "attachments").mkdir(exist_ok=True)
    (output / "images").mkdir(exist_ok=True)
    (output / "raw-html").mkdir(exist_ok=True)
    fetcher = Fetcher()

    sitemap_seeds = [
        urllib.parse.urljoin(BASE, "wp-sitemap.xml"),
        urllib.parse.urljoin(BASE, "sitemap_index.xml"),
        urllib.parse.urljoin(BASE, "sitemap.xml"),
    ]
    sitemap_urls, sitemap_records = parse_sitemap(fetcher, sitemap_seeds)
    pending = deque([BASE] + sorted(sitemap_urls))
    queued = set(pending)
    visited: set[str] = set()
    pages: list[dict] = []
    all_images: set[str] = set()
    all_attachments: set[str] = set()
    all_stylesheets: set[str] = set()
    fetch_errors: list[dict] = []

    while pending and len(visited) < page_limit:
        url = pending.popleft()
        if url in visited:
            continue
        visited.add(url)
        path = urllib.parse.urlsplit(url).path
        if path.startswith(SKIP_PATH_PREFIXES) or path.endswith(("/feed/", "/comments/feed/")):
            continue
        ext = classified_extension(url)
        if ext in IMAGE_EXTS:
            all_images.add(url)
            continue
        if ext in ATTACHMENT_EXTS:
            all_attachments.add(url)
            continue
        try:
            body, content_type, final_url_raw, status = fetcher.fetch(url)
        except Exception as exc:
            fetch_errors.append({"url": url, "error": str(exc)})
            continue

        final_url = normalize_url(final_url_raw) or final_url_raw
        if content_type not in {"text/html", "application/xhtml+xml"}:
            if is_image(final_url, content_type):
                all_images.add(final_url)
            elif is_attachment(final_url, content_type):
                all_attachments.add(final_url)
            continue

        try:
            root = html.fromstring(body, base_url=final_url)
        except Exception as exc:
            fetch_errors.append({"url": url, "error": f"HTML parse error: {exc}"})
            continue

        for bad in root.xpath("//script|//style|//noscript|//template"):
            bad.drop_tree()

        title = clean_space(" ".join(root.xpath("//title/text()")))
        h1 = clean_space(" ".join(root.xpath("//h1[1]//text()")))
        if h1:
            title = h1
        description = clean_space(" ".join(root.xpath("//meta[@name='description']/@content")))
        canonical = clean_space(" ".join(root.xpath("//link[contains(concat(' ', translate(@rel,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'), ' '), ' canonical ')]/@href")))
        date = clean_space(" ".join(root.xpath(
            "//time/@datetime | //meta[@property='article:published_time']/@content | "
            "//meta[@property='article:modified_time']/@content"
        )))

        internal_links: set[str] = set()
        external_links: set[str] = set()
        for href in root.xpath("//a[@href]/@href"):
            normalized_with_query = normalize_url(href, final_url, keep_query=True)
            if not normalized_with_query:
                continue
            host = (urllib.parse.urlsplit(normalized_with_query).hostname or "").lower()
            if host in ALLOWED_HOSTS:
                normalized = normalize_url(normalized_with_query, final_url)
                if normalized:
                    ext = classified_extension(normalized)
                    if ext in IMAGE_EXTS:
                        all_images.add(normalized_with_query)
                    elif ext in ATTACHMENT_EXTS:
                        all_attachments.add(normalized_with_query)
                    else:
                        internal_links.add(normalized)
                        if (
                            normalized not in queued
                            and not urllib.parse.urlsplit(normalized).path.startswith(SKIP_PATH_PREFIXES)
                        ):
                            queued.add(normalized)
                            pending.append(normalized)
            else:
                external_links.add(normalized_with_query)

        images, attachments, stylesheets = asset_urls(root, final_url)
        all_images.update(images)
        all_attachments.update(attachments)
        all_stylesheets.update(stylesheets)

        main = extract_main(root)
        blocks = extract_blocks(main)
        page_type = "page"
        classes = " ".join(root.xpath("//body/@class")).lower()
        if "single-post" in classes or " type-post " in f" {classes} ":
            page_type = "post"
        elif any(x in classes for x in ("archive", "category", "tag", "author")):
            page_type = "archive"
        elif final_url.rstrip("/") == BASE.rstrip("/"):
            page_type = "home"

        record = {
            "url": url,
            "final_url": final_url,
            "canonical_url": normalize_url(canonical, final_url) if canonical else "",
            "status": status,
            "content_type": content_type,
            "title": title,
            "description": description,
            "date": date,
            "page_type": page_type,
            "crawled_at": datetime.now(timezone.utc).isoformat(),
            "blocks": blocks,
            "content_text": "\n".join(block["text"] for block in blocks),
            "internal_links": sorted(internal_links),
            "external_links": sorted(external_links),
            "images": sorted(images),
            "attachments": sorted(attachments),
        }
        pages.append(record)

        md_path = output / "pages" / page_filename(final_url)
        md_path.parent.mkdir(parents=True, exist_ok=True)
        md_path.write_text(markdown_page(record), encoding="utf-8")
        raw_path = output / "raw-html" / page_filename(final_url).with_suffix(".html")
        raw_path.parent.mkdir(parents=True, exist_ok=True)
        raw_path.write_bytes(body)

        if len(pages) % 25 == 0:
            print(f"Crawled {len(pages)} HTML pages; queue={len(pending)}", flush=True)

    # Scan stylesheets for CSS-referenced images.
    for css_url in sorted(all_stylesheets):
        try:
            body, content_type, final_url, status = fetcher.fetch(css_url)
            text = body.decode("utf-8", errors="replace")
            for raw in re.findall(r"url\([\"']?([^\"')]+)", text, flags=re.I):
                normalized = normalize_url(raw, final_url, keep_query=True)
                if normalized and is_image(normalized):
                    all_images.add(normalized)
        except Exception as exc:
            fetch_errors.append({"url": css_url, "error": f"Stylesheet scan: {exc}"})

    asset_records = []
    for index, url in enumerate(sorted(all_attachments), 1):
        asset_records.append(download_asset(fetcher, url, output, "attachments"))
        if index % 25 == 0:
            print(f"Downloaded {index}/{len(all_attachments)} attachments", flush=True)
    for index, url in enumerate(sorted(all_images), 1):
        asset_records.append(download_asset(fetcher, url, output, "images"))
        if index % 50 == 0:
            print(f"Downloaded {index}/{len(all_images)} images", flush=True)

    page_by_url = {page["url"]: page for page in pages}
    successful_assets = {item["url"]: item for item in asset_records if item["local_path"]}
    for page in pages:
        page["attachment_files"] = [
            successful_assets[url]["local_path"]
            for url in page["attachments"]
            if url in successful_assets
        ]
        page["image_files"] = [
            successful_assets[url]["local_path"]
            for url in page["images"]
            if url in successful_assets
        ]

    (output / "content.json").write_text(
        json.dumps(pages, ensure_ascii=False, indent=2), encoding="utf-8"
    )
    (output / "asset-manifest.json").write_text(
        json.dumps(asset_records, ensure_ascii=False, indent=2), encoding="utf-8"
    )
    (output / "crawl-errors.json").write_text(
        json.dumps(fetch_errors, ensure_ascii=False, indent=2), encoding="utf-8"
    )
    (output / "sitemap-audit.json").write_text(
        json.dumps(sitemap_records, ensure_ascii=False, indent=2), encoding="utf-8"
    )

    with (output / "page-inventory.csv").open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=[
            "title", "url", "final_url", "canonical_url", "page_type", "date",
            "status", "word_count", "internal_link_count", "external_link_count",
            "image_count", "attachment_count", "local_markdown",
        ])
        writer.writeheader()
        for page in sorted(pages, key=lambda x: x["final_url"]):
            writer.writerow({
                "title": page["title"],
                "url": page["url"],
                "final_url": page["final_url"],
                "canonical_url": page["canonical_url"],
                "page_type": page["page_type"],
                "date": page["date"],
                "status": page["status"],
                "word_count": len(page["content_text"].split()),
                "internal_link_count": len(page["internal_links"]),
                "external_link_count": len(page["external_links"]),
                "image_count": len(page["images"]),
                "attachment_count": len(page["attachments"]),
                "local_markdown": str(Path("pages") / page_filename(page["final_url"])),
            })

    combined = [
        "# Town of Glover Website Content Archive",
        "",
        f"Source: {BASE}",
        f"Archive generated: {datetime.now(timezone.utc).isoformat()}",
        f"HTML pages captured: {len(pages)}",
        f"Downloadable files discovered: {len(all_attachments)}",
        f"Images discovered: {len(all_images)}",
        "",
        "This document preserves page content for website redesign. Each section includes the original source URL.",
        "",
    ]
    for page in sorted(pages, key=lambda x: (x["page_type"], x["title"].lower(), x["final_url"])):
        combined.extend([
            "---",
            "",
            markdown_page(page),
            "",
        ])
    (output / "MASTER-CONTENT.md").write_text("\n".join(combined), encoding="utf-8")

    summary = {
        "source": BASE,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "sitemap_urls_discovered": len(sitemap_urls),
        "html_pages_captured": len(pages),
        "unique_html_urls_visited": len(visited),
        "downloadable_files_discovered": len(all_attachments),
        "downloadable_files_downloaded": sum(
            1 for item in asset_records if item["kind"] == "attachments" and item["local_path"]
        ),
        "images_discovered": len(all_images),
        "images_downloaded": sum(
            1 for item in asset_records if item["kind"] == "images" and item["local_path"]
        ),
        "asset_download_errors": sum(1 for item in asset_records if item["error"]),
        "page_fetch_errors": len(fetch_errors),
        "queue_remaining_at_limit": len(pending),
        "page_limit": page_limit,
    }
    (output / "archive-summary.json").write_text(
        json.dumps(summary, indent=2), encoding="utf-8"
    )
    print(json.dumps(summary, indent=2), flush=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", default=str(DEFAULT_ARCHIVE))
    parser.add_argument("--page-limit", type=int, default=10000)
    args = parser.parse_args()
    crawl(Path(args.output), page_limit=args.page_limit)
