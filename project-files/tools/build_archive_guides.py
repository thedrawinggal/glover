#!/usr/bin/env python3
"""Build human-readable guides and cross-reference inventories."""

from __future__ import annotations

import csv
import json
from collections import defaultdict
from datetime import datetime
from pathlib import Path
from urllib.parse import urljoin

from lxml import html


PROJECT_FILES = Path(__file__).resolve().parents[1]
ROOT = PROJECT_FILES / "townofglover-content-archive"
pages = json.loads((ROOT / "content.json").read_text())
assets = json.loads((ROOT / "asset-manifest.json").read_text())
page_errors = json.loads((ROOT / "crawl-errors.json").read_text())
summary = json.loads((ROOT / "archive-summary.json").read_text())

asset_by_url = {item["url"]: item for item in assets}
references: dict[str, list[str]] = defaultdict(list)
for page in pages:
    for url in page["attachments"] + page["images"]:
        references[url].append(page["final_url"])

for page in pages:
    page["attachment_files"] = [
        asset_by_url[url]["local_path"]
        for url in page["attachments"]
        if url in asset_by_url and asset_by_url[url].get("local_path")
    ]
    page["image_files"] = [
        asset_by_url[url]["local_path"]
        for url in page["images"]
        if url in asset_by_url and asset_by_url[url].get("local_path")
    ]
(ROOT / "content.json").write_text(json.dumps(pages, ensure_ascii=False, indent=2))

with (ROOT / "asset-inventory.csv").open("w", newline="", encoding="utf-8") as handle:
    writer = csv.DictWriter(handle, fieldnames=[
        "kind", "source_url", "local_path", "content_type", "bytes", "status",
        "reference_count", "referencing_pages", "error",
    ])
    writer.writeheader()
    for item in sorted(assets, key=lambda x: (x["kind"], x["url"])):
        refs = sorted(set(references[item["url"]]))
        writer.writerow({
            "kind": item["kind"],
            "source_url": item["url"],
            "local_path": item["local_path"],
            "content_type": item["content_type"],
            "bytes": item["bytes"],
            "status": item["status"],
            "reference_count": len(refs),
            "referencing_pages": " | ".join(refs),
            "error": item["error"],
        })

# Extract global navigation, contact schemes, form fields, and image alt text
# directly from the preserved HTML, supplementing the main-content documents.
home = html.fromstring((ROOT / "raw-html" / "home.html").read_bytes())

nav_links = []
seen_nav = set()
for anchor in home.xpath("//nav[1]//a[@href]"):
    label = " ".join(anchor.text_content().split()).replace("Expand", "").strip()
    href = urljoin(summary["source"], anchor.get("href"))
    key = (label, href)
    if label and href != "#" and key not in seen_nav:
        seen_nav.add(key)
        nav_links.append(key)

footer_links = []
seen_footer = set()
for anchor in home.xpath("//footer//a[@href]"):
    label = " ".join(anchor.text_content().split()).replace("Continue", "").strip()
    href = urljoin(summary["source"], anchor.get("href"))
    key = (label, href)
    if label and key not in seen_footer:
        seen_footer.add(key)
        footer_links.append(key)

scheme_links: dict[str, set[tuple[str, str, str]]] = defaultdict(set)
forms: set[tuple[str, str, str, str, str]] = set()
alt_text: dict[tuple[str, str], set[str]] = defaultdict(set)
for page in pages:
    raw_rel = Path("raw-html") / Path(
        "home.html" if page["final_url"].rstrip("/") == summary["source"].rstrip("/")
        else page["final_url"].replace(summary["source"], "").strip("/") + "/index.html"
    )
    raw_path = ROOT / raw_rel
    if not raw_path.exists():
        continue
    try:
        tree = html.fromstring(raw_path.read_bytes(), base_url=page["final_url"])
    except Exception:
        continue
    for anchor in tree.xpath("//a[@href]"):
        href = anchor.get("href", "").strip()
        scheme = href.split(":", 1)[0].lower()
        if scheme in {"mailto", "tel"}:
            label = " ".join(anchor.text_content().split())
            scheme_links[scheme].add((label, href, page["final_url"]))
    for form in tree.xpath("//form"):
        action = urljoin(page["final_url"], form.get("action", ""))
        method = form.get("method", "get").upper()
        for field in form.xpath(".//input|.//textarea|.//select|.//button"):
            forms.add((
                action,
                method,
                field.tag,
                field.get("name", ""),
                field.get("placeholder", "") or field.get("value", ""),
            ))
    for image in tree.xpath("//img"):
        src = image.get("src") or image.get("data-src") or ""
        src = urljoin(page["final_url"], src)
        alt = image.get("alt", "")
        if src or alt:
            alt_text[(src, alt)].add(page["final_url"])

sitewide = [
    "# Sitewide and Supplemental Content",
    "",
    "This file preserves globally repeated interface content and structured details that sit outside the main article/page body.",
    "",
    "## Primary navigation",
    "",
]
for label, href in nav_links:
    sitewide.append(f"- {label}: {href}")

sitewide.extend(["", "## Footer and utility links", ""])
for label, href in footer_links:
    sitewide.append(f"- {label}: {href}")

sitewide.extend([
    "",
    "## Footer identity and address",
    "",
    "- © 2026 Town of Glover, Vermont",
    "- 51 Bean Hill Rd, Glover, VT 05839",
    "",
    "## Email links",
    "",
])
for label, href, page_url in sorted(scheme_links["mailto"]):
    sitewide.append(f"- {label or '(no visible label)'} — {href} — referenced on {page_url}")

sitewide.extend(["", "## Telephone links", ""])
for label, href, page_url in sorted(scheme_links["tel"]):
    sitewide.append(f"- {label or '(no visible label)'} — {href} — referenced on {page_url}")

sitewide.extend(["", "## Forms and interactive fields", ""])
for action, method, tag, name, hint in sorted(forms):
    sitewide.append(
        f"- {tag}; name={name or '(none)'}; label/value/placeholder={hint or '(none)'}; "
        f"method={method}; action={action}"
    )

sitewide.extend(["", "## Image alternative text and page references", ""])
for (src, alt), refs in sorted(alt_text.items()):
    sitewide.append(
        f"- Source: {src or '(missing source)'}; alt text: {alt or '(blank)'}; "
        f"pages: {' | '.join(sorted(refs))}"
    )
(ROOT / "SITEWIDE-AND-SUPPLEMENTAL-CONTENT.md").write_text(
    "\n".join(sitewide).rstrip() + "\n", encoding="utf-8"
)

broken_assets = [item for item in assets if item.get("error")]
broken = [
    "# Broken or Unavailable Source Links",
    "",
    "These URLs were referenced by the live website but were unavailable after repeated retrieval attempts. They are retained here because broken links are part of the current-site content audit.",
    "",
    "## Unavailable pages",
    "",
]
if page_errors:
    for item in page_errors:
        broken.append(f"- {item['url']} — {item['error']}")
else:
    broken.append("- None")
broken.extend(["", "## Unavailable files and images", ""])
if broken_assets:
    for item in broken_assets:
        refs = sorted(set(references[item["url"]]))
        broken.append(
            f"- {item['kind']}: {item['url']} — {item['error']} — "
            f"referenced by: {' | '.join(refs) if refs else '(stylesheet or global reference)'}"
        )
else:
    broken.append("- None")
(ROOT / "BROKEN-OR-UNAVAILABLE-LINKS.md").write_text(
    "\n".join(broken).rstrip() + "\n", encoding="utf-8"
)

readme = f"""# Town of Glover Website Content Archive

Source: {summary['source']}

Captured: {datetime.fromisoformat(summary['generated_at']).astimezone().strftime('%B %d, %Y at %I:%M %p %Z')}

## Archive totals

- {summary['html_pages_captured']:,} public HTML pages captured
- {sum(len(p['content_text'].split()) for p in pages):,} extracted words
- {summary['downloadable_files_downloaded']:,} of {summary['downloadable_files_discovered']:,} referenced downloadable files saved
- {summary['images_downloaded']:,} of {summary['images_discovered']:,} referenced images saved
- {summary['asset_download_errors']:,} referenced assets currently unavailable at their source URLs
- {summary['page_fetch_errors']:,} linked page currently unavailable at its source URL

## Start here

- `MASTER-CONTENT.md` — all extracted page/post content in one document
- `pages/` — one readable Markdown document per captured page
- `SITEWIDE-AND-SUPPLEMENTAL-CONTENT.md` — navigation, footer, contact links, forms, and image alt text
- `page-inventory.csv` — page-level migration inventory
- `asset-inventory.csv` — every file/image with its source, local path, and referring pages
- `BROKEN-OR-UNAVAILABLE-LINKS.md` — live-site references that returned errors

## Source-preservation folders

- `attachments/` — downloaded municipal files, preserving original source path structure
- `images/` — downloaded imagery, logos, icons, and responsive variants
- `raw-html/` — exact HTML response saved for every captured page

## Machine-readable data

- `content.json` — structured content, metadata, links, and local asset mappings
- `asset-manifest.json` — download metadata for all referenced assets
- `archive-summary.json` — capture totals
- `sitemap-audit.json` — sitemap discovery results
- `crawl-errors.json` — unavailable linked pages and stylesheet-scan errors

## Scope note

This is a point-in-time capture of publicly accessible content on July 30, 2026. The crawl queue completed with no unvisited URLs remaining and covered all 556 URLs exposed by the WordPress sitemap plus additional internally linked archive and utility pages. Password-protected, administrative, database-only, or content not linked or published by the public website is outside this archive.
"""
(ROOT / "README.md").write_text(readme, encoding="utf-8")

print(f"Built guides for {len(pages)} pages and {len(assets)} assets")
