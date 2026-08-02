#!/usr/bin/env python3
"""Retry recoverable asset failures and update the archive manifest."""

from __future__ import annotations

import json
import mimetypes
import ssl
import urllib.parse
import urllib.request
from pathlib import Path

from crawl_townofglover import USER_AGENT, normalize_url, safe_relative_name, short_hash


PROJECT_FILES = Path(__file__).resolve().parents[1]
ARCHIVE = PROJECT_FILES / "townofglover-content-archive"


def retry(record: dict) -> dict:
    raw_url = record["url"]
    encoded_url = normalize_url(raw_url, keep_query=True) or raw_url
    request = urllib.request.Request(encoded_url, headers={"User-Agent": USER_AGENT})
    context = ssl.create_default_context()
    if urllib.parse.urlsplit(encoded_url).hostname == "gloverparkvt.org":
        context = ssl._create_unverified_context()
    try:
        with urllib.request.urlopen(request, timeout=45, context=context) as response:
            body = response.read()
            content_type = response.headers.get_content_type()
            final_url = response.geturl()
            name = safe_relative_name(final_url, f"{record['kind']}-{short_hash(raw_url)}")
            if not name.suffix:
                name = name.with_suffix(mimetypes.guess_extension(content_type) or "")
            target = ARCHIVE / record["kind"] / name
            if target.exists() and target.read_bytes() != body:
                target = target.with_name(
                    f"{target.stem}-{short_hash(raw_url)}{target.suffix}"
                )
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            record.update({
                "local_path": str(target.relative_to(ARCHIVE)),
                "content_type": content_type,
                "bytes": len(body),
                "status": response.status,
                "final_url": final_url,
                "error": "",
            })
    except Exception as exc:
        record["error"] = str(exc)
    return record


manifest_path = ARCHIVE / "asset-manifest.json"
manifest = json.loads(manifest_path.read_text())
for index, item in enumerate(manifest):
    if item.get("error"):
        manifest[index] = retry(item)

manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2))

remaining = [item for item in manifest if item.get("error")]
(ARCHIVE / "broken-or-unavailable-assets.json").write_text(
    json.dumps(remaining, ensure_ascii=False, indent=2)
)

summary_path = ARCHIVE / "archive-summary.json"
summary = json.loads(summary_path.read_text())
summary.update({
    "downloadable_files_downloaded": sum(
        1 for item in manifest
        if item["kind"] == "attachments" and item.get("local_path")
    ),
    "images_downloaded": sum(
        1 for item in manifest
        if item["kind"] == "images" and item.get("local_path")
    ),
    "asset_download_errors": len(remaining),
})
summary_path.write_text(json.dumps(summary, indent=2))
print(json.dumps(summary, indent=2))
print(json.dumps(remaining, ensure_ascii=False, indent=2))
