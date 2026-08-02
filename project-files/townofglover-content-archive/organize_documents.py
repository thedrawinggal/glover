#!/usr/bin/env python3
"""Inventory, read, classify, and consistently rename the Glover uploads archive."""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import re
import shutil
import subprocess
import tempfile
import unicodedata
import zipfile
from collections import Counter
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime
from pathlib import Path

from pypdf import PdfReader


ROOT = Path(__file__).resolve().parent
UPLOADS = ROOT / "attachments" / "wp-content" / "uploads"
CACHE = ROOT / ".document-organization-cache.json"
PLAN = ROOT / "DOCUMENT_RENAME_PLAN.csv"
LOG = ROOT / "DOCUMENT_RENAME_LOG.csv"
REPORT = ROOT / "DOCUMENT_ORGANIZATION_REPORT.txt"

MONTHS = {
    "jan": 1, "january": 1, "feb": 2, "february": 2, "mar": 3, "march": 3,
    "apr": 4, "april": 4, "may": 5, "jun": 6, "june": 6, "jul": 7,
    "july": 7, "aug": 8, "august": 8, "sep": 9, "sept": 9, "september": 9,
    "oct": 10, "october": 10, "nov": 11, "november": 11, "dec": 12,
    "december": 12,
}

# Dates recovered from otherwise image-only closing/signature pages or from
# unambiguous event dates (including weekday/date combinations) during review.
KNOWN_DATES = {
    "Adopted-and-Signed-Flood-Hazard-Area-Bylaws-1.pdf": "2025-12-11",
    "Adopted-and-Signed-Flood-Hazard-Area-Bylaws.pdf": "2025-12-11",
    "Adopted-and-Signed-River-Corridor-Bylaws-1.pdf": "2025-12-11",
    "Adopted-and-Signed-River-Corridor-Bylaws.pdf": "2025-12-11",
    "glovertownreport.pdf": "2018",
    "mudseason24.pdf": "2024-04-06",
    "idea-jam-poster-1.pdf": "2025-02-21",
    "idea-jam-poster.pdf": "2025-02-21",
    "SOS-VT-Glover-Support-Group-1.pdf": "2023-11-01",
    "Volunteer-Planting-Day-Poster.pdf": "2025-08-29",
    "closed-poster-pdf.pdf": "2026-01-24",
    "May-14-Meeting-Notice-Final.pdf": "2025-05-14",
}


def normalize_space(value: str) -> str:
    return re.sub(r"\s+", " ", value.replace("\x00", " ")).strip()


def pdf_text(path: Path) -> tuple[str, str]:
    try:
        reader = PdfReader(str(path), strict=False)
        chunks = []
        for page in reader.pages:
            try:
                chunks.append(page.extract_text() or "")
            except Exception:
                continue
        text = normalize_space("\n".join(chunks))
        if len(re.sub(r"\W", "", text)) >= 80:
            return text, "embedded-text"
    except Exception:
        text = ""

    # OCR up to the first three pages, where municipal document identity/date live.
    try:
        with tempfile.TemporaryDirectory(prefix="glover-ocr-") as td:
            prefix = str(Path(td) / "page")
            subprocess.run(
                ["pdftoppm", "-f", "1", "-l", "2", "-r", "140", "-png", str(path), prefix],
                check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=120,
            )
            chunks = []
            for image in sorted(Path(td).glob("page-*.png")):
                proc = subprocess.run(
                    ["tesseract", str(image), "stdout", "-l", "eng", "--psm", "6"],
                    check=False, capture_output=True, text=True, timeout=120,
                )
                chunks.append(proc.stdout)
            text = normalize_space("\n".join(chunks))
            return text, "ocr" if text else "unreadable"
    except Exception:
        return text, "unreadable"


def docx_text(path: Path) -> tuple[str, str]:
    try:
        with zipfile.ZipFile(path) as archive:
            xml = archive.read("word/document.xml").decode("utf-8", "ignore")
        text = normalize_space(re.sub(r"<[^>]+>", " ", xml))
        return text, "embedded-text" if text else "unreadable"
    except Exception:
        return "", "unreadable"


def doc_text(path: Path) -> tuple[str, str]:
    try:
        proc = subprocess.run(["strings", str(path)], capture_output=True, text=True, timeout=30)
        text = normalize_space(proc.stdout)
        return text, "strings" if text else "unreadable"
    except Exception:
        return "", "unreadable"


def digest(path: Path) -> str:
    h = hashlib.sha1()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def inventory() -> list[dict[str, str]]:
    cached = {}
    if CACHE.exists():
        try:
            cached = json.loads(CACHE.read_text())
        except Exception:
            cached = {}
    files = sorted(p for p in UPLOADS.iterdir() if p.is_file())
    records_by_name = {}
    pending = []
    for path in files:
        stat_key = f"{path.name}|{path.stat().st_size}|{path.stat().st_mtime_ns}"
        prior = cached.get(stat_key)
        if prior:
            records_by_name[path.name] = prior
        else:
            pending.append((path, stat_key))

    def read_one(item: tuple[Path, str]) -> tuple[str, str, dict[str, str]]:
        path, stat_key = item
        ext = path.suffix.lower()
        if ext == ".pdf":
            text, method = pdf_text(path)
        elif ext == ".docx":
            text, method = docx_text(path)
        elif ext == ".doc":
            text, method = doc_text(path)
        else:
            text, method = "", "unsupported"
        return path.name, stat_key, {
            "original_name": path.name, "size": str(path.stat().st_size),
            "sha1": digest(path), "method": method, "text": text,
        }

    done_count = len(files) - len(pending)
    if done_count:
        print(f"Loaded {done_count}/{len(files)} files from extraction cache", flush=True)
    with ThreadPoolExecutor(max_workers=4) as pool:
        futures = [pool.submit(read_one, item) for item in pending]
        for future in as_completed(futures):
            name, stat_key, record = future.result()
            records_by_name[name] = record
            cached[stat_key] = record
            done_count += 1
            if done_count % 25 == 0 or done_count == len(files):
                print(f"Read {done_count}/{len(files)} files", flush=True)
                CACHE.write_text(json.dumps(cached, ensure_ascii=False))
    return [records_by_name[p.name] for p in files]


def parse_date(candidate: str, filename: str) -> str | None:
    text = candidate[:1200]
    filename = filename.replace("_", " ")
    # A full date deliberately placed in the filename is normally the meeting or
    # publication date; body text often mentions many later/earlier dates.
    sources = [filename, text]
    patterns = [
        re.compile(r"(?<!\d)(20\d{2}|19\d{2})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])(?!\d)"),
        re.compile(r"(?<!\d)(20\d{2}|19\d{2})[-_/\.](0?[1-9]|1[0-2])[-_/\.](0?[1-9]|[12]\d|3[01])(?!\d)"),
        re.compile(r"(?<!\d)(0?[1-9]|1[0-2])[-_/\. ](0?[1-9]|[12]\d|3[01])[-_/\. ](20\d{2}|19\d{2}|\d{2})(?!\d)"),
        re.compile(r"\b(" + "|".join(MONTHS) + r")\.?[- ]+(0?[1-9]|[12]\d|3[01])(?:st|nd|rd|th)?[-, ]+(20\d{2}|19\d{2})\b", re.I),
        re.compile(r"\b(0?[1-9]|[12]\d|3[01])\s+(" + "|".join(MONTHS) + r")[,]?\s+(20\d{2}|19\d{2})\b", re.I),
    ]
    for source_index, source in enumerate(sources):
        for idx, pattern in enumerate(patterns):
            match = pattern.search(source)
            if not match:
                continue
            try:
                if idx in {0, 1}:
                    y, m, d = map(int, match.groups())
                elif idx == 2:
                    m, d, y = map(int, match.groups())
                    if y < 100:
                        y += 2000 if y <= 40 else 1900
                elif idx == 3:
                    mon, d, y = match.groups(); m = MONTHS[mon.lower()]; d = int(d); y = int(y)
                else:
                    d, mon, y = match.groups(); m = MONTHS[mon.lower()]; d = int(d); y = int(y)
                return datetime(y, m, d).strftime("%Y-%m-%d")
            except ValueError:
                continue
        # If the filename intentionally supplies a year but not a full date,
        # prefer it over historical/incidental dates inside reports and plans.
        if source_index == 0:
            filename_year = re.search(r"\b(19\d{2}|20\d{2})\b", filename)
            year_led = re.search(r"^(?:19|20)\d{2}-(?!\d)", filename)
            year_document = re.search(
                r"(?i)(report|townrpt|gloverar|town[- ]plan|calendar|survey|map|policy|ordinance|budget)",
                filename,
            )
            if filename_year and (year_led or year_document):
                return filename_year.group(1)
    # Year-only documents (town reports, calendars, plans) are still usefully dated.
    year = re.search(r"\b(19\d{2}|20\d{2})\b", text[:1500] + " " + filename)
    return year.group(1) if year else None


def parse_legal_date(text: str, filename: str) -> str | None:
    """Use dates explicitly tied to adoption/approval; ignore statute numbers."""
    # Filename dates remain intentional and reliable.
    filename_date = parse_date("", filename)
    if filename_date:
        return filename_date
    for match in re.finditer(r"(?i)\b(adopted|approved|effective|revised|amended)\b", text):
        snippet = text[match.start():match.start() + 260]
        found = parse_date(snippet, "")
        if found and len(found) == 10:
            return found
    return None


def contains(haystack: str, *needles: str) -> bool:
    return any(n in haystack for n in needles)


def classify(filename: str, text: str) -> tuple[str, str, str]:
    blob = (filename + " " + text[:220]).lower().replace("_", " ")
    compact = re.sub(r"\W+", "", blob)
    # Organization: most specific committees first.
    org_rules = [
        ("Glover Energy Committee", ("energy committee", "energy meeting", "ecm")),
        ("Glover Equity Committee", ("equity committee", "equity meeting")),
        ("Glover Planning Commission", ("planning commission", "planning board", "gpc ", "gpc-")),
        ("Glover Cemetery Commission", ("cemetery commission", "cemetery committee", "cemetery meeting", "cemetary")),
        ("Glover Development Review Board", ("development review board", " drb ", "zoning board of adjustment")),
        ("Glover Board of Civil Authority", ("board of civil authority", " bca ", "bca-")),
        ("Glover ARPA Advisory Committee", ("arpa advisory", "arpa committee")),
        ("Glover Budget and Finance Committee", ("budget / finance committee", "budget and finance committee", "budget finance committee")),
        ("Glover Hazard Mitigation Committee", ("hazard mitigation committee", "lhmp committee")),
        ("Glover Trails Committee", ("trails committee", "trail committee")),
        ("Glover Conservation Commission", ("conservation commission", "conservation committee")),
        ("Glover Town Meeting", ("town meeting", "annual meeting")),
        ("Glover Selectboard", ("selectboard", "select board", "board of selectmen")),
        ("Town of Glover", ("town of glover", "glover, vermont", "glover vt", "glover town")),
    ]
    organization = "Town of Glover"
    for label, needles in org_rules:
        if contains(blob, *needles) or any(
            len(re.sub(r"\W+", "", n)) > 5 and re.sub(r"\W+", "", n) in compact
            for n in needles
        ):
            organization = label
            break
    # Explicit committee names in filenames are stronger than a committee merely
    # being mentioned inside another board's minutes.
    fn = filename.lower().replace("_", " ")
    filename_org_rules = [
        ("Glover Selectboard", ("selectboard", "select-board")),
        ("Glover Energy Committee", ("energy",)),
        ("Glover Equity Committee", ("equity",)),
        ("Glover Planning Commission", ("planning", "gpc")),
        ("Glover Cemetery Commission", ("cemetery", "cemetary")),
        ("Glover Board of Civil Authority", ("bca",)),
        ("Glover ARPA Advisory Committee", ("arpa",)),
        ("Glover Trails Committee", ("trail",)),
    ]
    for label, needles in filename_org_rules:
        if contains(fn, *needles):
            organization = label
            break

    # Document type: explicit headings and filenames outrank incidental prose.
    # Classification uses the filename and title area only. Meeting bodies often
    # discuss unrelated policies/plans later on page one.
    lead = (filename + " " + text[:220]).lower().replace("_", " ")
    lead_compact = re.sub(r"\W+", "", lead)
    filename_blob = filename.lower().replace("_", " ")
    type_rules = [
        ("Town Report", ("town report", "townreport", "annual report")),
        ("Town Plan", ("town plan", "townplan")),
        ("Meeting Agenda", ("meeting agenda", "agenda for", "agenda-", " agenda ")),
        ("Meeting Minutes", ("meeting minutes", "minutes of", "approved minutes", "draft minutes", "minutes-", "minutes ", "meeting-minutes")),
        ("Meeting Warning", ("meeting warning", "warning for", "special meeting warning")),
        ("Public Hearing Notice", ("public hearing notice", "notice of public hearing", "hearing notice")),
        ("Town Meeting Warning", ("town meeting warning",)),
        ("Proposal", ("proposal",)),
        ("Press Release", ("press release",)),
        ("Flyer", ("flyer", "flier", "poster")),
        ("Policy", (" policy", "policy-")),
        ("Ordinance", ("ordinance", "regulation of ", "regulating all-terrain")),
        ("Bylaw", ("bylaw", "by-law")),
        ("Notice", (" notice", "notice-", "notice to")),
        ("Event Calendar", ("events calendar", "event calendar")),
        ("Survey", ("survey", "questionnaire")),
        ("Report", (" report", "report-", "assessment", "analysis", "study")),
        ("Application", ("application",)),
        ("Form", (" form", "form-")),
        ("Letter", ("letter", "correspondence", "memo")),
        ("Presentation", ("presentation", "slides")),
        ("Newsletter", ("newsletter",)),
        ("Article", ("article", "chronicle", "newspaper")),
        ("Bid Documents", ("invitation to bid", "request for proposal", " rfp ", "bid documents")),
        ("Tax Document", ("tax bill", "tax rate", "grand list", "taxpayer", "tax payer", "homestead")),
        ("Election Document", ("election", "ballot", "polling")),
        ("Budget", ("budget",)),
        ("Audit", ("audit",)),
        ("Map", (" map", "map-")),
        ("Permit", ("permit",)),
        ("Guidance", ("guidance", "what can be recycled", "questions concerns answers")),
    ]
    document_type = "Document"
    for label, needles in type_rules:
        if contains(lead, *needles) or any(re.sub(r"\W+", "", n) in lead_compact for n in needles):
            document_type = label
            break

    # Common municipal minutes omit the word "minutes" from their heading but
    # clearly record attendance and proceedings. Apply only to meeting bodies.
    meeting_orgs = {
        "Glover Selectboard", "Glover Energy Committee", "Glover Equity Committee",
        "Glover Planning Commission", "Glover Cemetery Commission",
        "Glover Development Review Board", "Glover Board of Civil Authority",
        "Glover ARPA Advisory Committee", "Glover Hazard Mitigation Committee",
        "Glover Trails Committee", "Glover Budget and Finance Committee",
    }
    proceedings = contains(
        lead, "attending", "attendees", "present:", "present-", "present were",
        "called to order", "meeting was called", "meeting came to order",
        "meeting started", "meeting began",
    )
    meeting_heading = "meeting" in lead or "meeting" in filename_blob or "site visit" in lead
    if "agenda" in filename_blob:
        document_type = "Meeting Agenda"
    elif "meeting minutes" in text[:140].lower():
        document_type = "Meeting Minutes"
    elif organization in meeting_orgs and proceedings:
        document_type = "Meeting Minutes"
    elif document_type == "Document" and organization in meeting_orgs and meeting_heading and contains(lead, "represented by", "discussion", "motion"):
        document_type = "Meeting Minutes"

    # A few recurring public-information documents are visually sparse posters.
    if document_type == "Document":
        if contains(lead, "calendar of events", "collection schedule"):
            document_type = "Event Calendar"
        elif contains(lead, "community forum", "workbee", "work bee", "clinic", "summer hours", "closed", "earth day", "glover day", "auction", "dance"):
            document_type = "Flyer"
        elif contains(lead, "design", "plans", "plan for reference", "architect"):
            document_type = "Plans"
        elif contains(lead, "fact sheet", "safety tips"):
            document_type = "Fact Sheet"
        elif contains(lead, "mitigation plan", "participation plan", "resilience plan"):
            document_type = "Plan"
        elif contains(lead, "rules & regulations", "rules and regulations"):
            document_type = "Rules and Regulations"
        elif contains(lead, "news release"):
            document_type = "Press Release"
        elif contains(lead, "petition"):
            document_type = "Petition"
        elif contains(lead, "agreement for", "contract"):
            document_type = "Contract"
        elif contains(lead, "operating guidelines"):
            document_type = "Guidelines"
        elif contains(lead, "meeting summary", "summary of our meeting", "phone conversation"):
            document_type = "Meeting Summary"
        elif contains(lead, "road update", "recovery center open", "dates to remember"):
            document_type = "Notice"
        elif contains(lead, "chronicle", "daily express", "community news source"):
            document_type = "Article"
        elif contains(lead, "all hazards mitigation plan", "flood resilience plan"):
            document_type = "Plan"

    if document_type == "Document" and re.search(r"(?i)(townreport|town-report|gloverar)", filename):
        document_type = "Town Report"
    elif document_type == "Document" and re.search(r"(?i)(press-release|\bPR\b)", filename):
        document_type = "Press Release"

    # Filename/title-specific fallbacks for one-off municipal records.
    if document_type == "Document":
        fallback_rules = [
            ("Notice", ("dates-to-remember", "holiday-cleanup")),
            ("Meeting Summary", ("summary-of-our-meeting", "phone-converstion", "phone-conversation")),
            ("Engineering Analysis", ("additional-alternative", "response-to-vtdec", "spillway-modification")),
            ("Letter", ("extension-granted", "sld-extension")),
            ("Timeline", ("timeline",)),
            ("Article", ("chronice", "chronicle", "daily")),
            ("Grant Summary", ("grant-simplified",)),
            ("Resource List", ("fuding-sources", "funding-sources")),
            ("Fact Sheet", ("shadow-lake-dam-noel",)),
            ("Flyer", ("support-group", "earthday", "save-the-dates")),
            ("Emergency Action Plan", ("emergency-action-plan",)),
            ("Technical Review", ("dsp-review",)),
            ("Regulation", ("flood-hazard-area-regulation",)),
            ("Reference Guide", ("roles-responsibilities",)),
        ]
        for label, needles in fallback_rules:
            if contains(filename.lower(), *needles):
                document_type = label
                break

    if "may-14-meeting-notice" in filename.lower():
        document_type = "Public Hearing Notice"
        organization = "Glover Planning Commission"
    elif "notice-of-change-or-adoption" in filename.lower():
        document_type = "Ordinance Amendment Notice"

    if organization == "Glover Town Meeting" and document_type == "Meeting Minutes":
        document_type = "Town Meeting Minutes"
    elif organization == "Glover Town Meeting" and document_type in {"Meeting Warning", "Town Meeting Warning"}:
        document_type = "Town Meeting Warning"

    status = ""
    status_filename = filename.lower()
    status_title = text[:140].lower()
    if contains(status_filename, "approved", "adopted", "signed", "final") or "approved minutes" in status_title:
        status = "Approved"
    elif "draft" in status_filename or re.search(r"\bdraft\b", status_title):
        status = "Draft"
    elif contains(status_filename, "revised", "updated"):
        status = "Revised"
    return organization, document_type, status


def clean_subject(filename: str, organization: str, document_type: str, date: str | None, status: str) -> str:
    stem = Path(filename).stem
    stem = re.sub(r"[_\.]+", " ", stem)
    stem = re.sub(r"(?i)\b(19\d{2}|20\d{2})[- /](0?[1-9]|1[0-2])[- /](0?[1-9]|[12]\d|3[01])\b", " ", stem)
    stem = re.sub(r"(?i)\b(0?[1-9]|1[0-2])[- /](0?[1-9]|[12]\d|3[01])[- /](?:19|20)?\d{2}\b", " ", stem)
    stem = re.sub(r"(?i)\b(" + "|".join(MONTHS) + r")\.?[- ]+(0?[1-9]|[12]\d|3[01])(?:st|nd|rd|th)?[-, ]+(19\d{2}|20\d{2})\b", " ", stem)
    noise = [organization, document_type, status, "Town of Glover", "Glover", "Meeting", "Minutes", "Agenda", "Approved", "Final", "Draft", "Revised"]
    for phrase in sorted((n for n in noise if n), key=len, reverse=True):
        stem = re.sub(r"(?i)\b" + re.escape(phrase).replace(r"\ ", r"[- ]+") + r"\b", " ", stem)
    stem = re.sub(r"(?i)\b(AP|PC|ECM|TEC|CC)\b", " ", stem)
    stem = re.sub(r"(?i)\b(aprl|augus|janaury|jaunary|septemder)\b", " ", stem)
    if document_type in {"Meeting Minutes", "Meeting Agenda", "Town Meeting Minutes", "Town Meeting Warning"}:
        stem = re.sub(
            r"(?i)\b(energy|equity|planning|commission|cemetery|cemetary|selectboard|select|board|gpc|trails?|committee|mtg|mee?eting)\b",
            " ", stem,
        )
    stem = re.sub(r"\b\d{1,2}[- ]\d{1,2}(?:[- ]\d{2,4})?\b", " ", stem)
    stem = re.sub(r"\b(?:19|20)\d{2}\b", " ", stem)
    stem = re.sub(r"(?i)\b(copy|scan\d+|document|pdf|jpg|signed)\b", " ", stem)
    stem = re.sub(r"[- ]+", " ", stem).strip(" -")
    if re.fullmatch(r"\d+", stem or ""):
        stem = ""
    words = stem.split()
    subject = " ".join(w.upper() if w.isupper() and len(w) <= 5 else w.capitalize() for w in words)
    return subject[:90].strip()


def safe_component(value: str) -> str:
    value = unicodedata.normalize("NFKC", value)
    value = value.replace("/", "-").replace(":", "-")
    value = re.sub(r"[\x00-\x1f]", "", value)
    value = re.sub(r"\s+", " ", value).strip(" .-")
    return value


def make_plan(records: list[dict[str, str]]) -> list[dict[str, str]]:
    planned = []
    used: set[str] = set()
    hash_counts = Counter(r["sha1"] for r in records)
    hash_seen = Counter()
    for record in records:
        old = record["original_name"]
        org, doc_type, status = classify(old, record["text"])
        if old in KNOWN_DATES:
            date = KNOWN_DATES[old]
        elif doc_type in {"Policy", "Ordinance", "Bylaw", "Rules and Regulations", "Guidelines"}:
            date = parse_legal_date(record["text"], old)
        else:
            date = parse_date(record["text"], old)
        subject = clean_subject(old, org, doc_type, date, status)
        pieces = [date or "Undated", org, doc_type]
        if subject and subject.lower() not in " ".join(pieces).lower():
            pieces.append(subject)
        if status:
            pieces.append(status)
        base = " - ".join(safe_component(p) for p in pieces if p)
        ext = Path(old).suffix.lower()
        candidate = f"{base}{ext}"
        duplicate_of = ""
        if hash_counts[record["sha1"]] > 1:
            hash_seen[record["sha1"]] += 1
            if hash_seen[record["sha1"]] > 1:
                duplicate_of = next(p["new_name"] for p in planned if p["sha1"] == record["sha1"])
                candidate = f"{base} - Duplicate {hash_seen[record['sha1']]}{ext}"
        suffix = 2
        while candidate.casefold() in used or (UPLOADS / candidate).exists() and candidate != old:
            candidate = f"{base} - Version {suffix}{ext}"
            suffix += 1
        used.add(candidate.casefold())
        confidence = "high"
        if not date or doc_type == "Document" or record["method"] == "unreadable":
            confidence = "review"
        planned.append({
            "original_name": old, "new_name": candidate, "date": date or "Undated",
            "organization": org, "document_type": doc_type, "subject": subject,
            "status": status, "read_method": record["method"], "confidence": confidence,
            "duplicate_of": duplicate_of, "sha1": record["sha1"],
            "text_preview": record["text"][:500],
        })
    return planned


def write_csv(path: Path, rows: list[dict[str, str]]) -> None:
    fields = list(rows[0]) if rows else []
    with path.open("w", newline="", encoding="utf-8") as stream:
        writer = csv.DictWriter(stream, fieldnames=fields)
        writer.writeheader(); writer.writerows(rows)


def apply_plan(rows: list[dict[str, str]]) -> None:
    # Two-stage rename avoids collisions between old names and planned names.
    staged = []
    for i, row in enumerate(rows):
        source = UPLOADS / row["original_name"]
        temporary = UPLOADS / f".glover-rename-{i:04d}{source.suffix.lower()}"
        if source != UPLOADS / row["new_name"]:
            source.rename(temporary)
            staged.append((temporary, UPLOADS / row["new_name"], row))
    for temporary, destination, _ in staged:
        temporary.rename(destination)
    log_rows = [{k: v for k, v in r.items() if k != "text_preview"} for r in rows]
    write_csv(LOG, log_rows)


def rollback() -> None:
    if not LOG.exists():
        raise FileNotFoundError(f"Rename log not found: {LOG}")
    rows = list(csv.DictReader(LOG.open(encoding="utf-8")))
    staged = []
    for i, row in enumerate(rows):
        source = UPLOADS / row["new_name"]
        destination = UPLOADS / row["original_name"]
        if not source.exists():
            raise FileNotFoundError(f"Cannot roll back; renamed file is missing: {source}")
        if destination.exists():
            raise FileExistsError(f"Cannot roll back; original name is occupied: {destination}")
        temporary = UPLOADS / f".glover-rollback-{i:04d}{source.suffix.lower()}"
        source.rename(temporary)
        staged.append((temporary, destination))
    for temporary, destination in staged:
        temporary.rename(destination)
    print(f"Rolled back {len(rows)} filenames using {LOG}")


def write_report(rows: list[dict[str, str]], applied: bool) -> None:
    methods = Counter(r["read_method"] for r in rows)
    types = Counter(r["document_type"] for r in rows)
    orgs = Counter(r["organization"] for r in rows)
    lines = [
        "GLOVER DOCUMENT ORGANIZATION REPORT", "",
        f"Files inventoried: {len(rows)}", f"Renames applied: {'yes' if applied else 'no (dry run)'}",
        f"Files needing manual review: {sum(r['confidence'] == 'review' for r in rows)}",
        f"Exact duplicate copies retained and labeled: {sum(bool(r['duplicate_of']) for r in rows)}", "",
        "READ METHODS", *[f"- {k}: {v}" for k, v in methods.most_common()], "",
        "DOCUMENT TYPES", *[f"- {k}: {v}" for k, v in types.most_common()], "",
        "ORGANIZATIONS", *[f"- {k}: {v}" for k, v in orgs.most_common()], "",
        "NAMING CONVENTION", "- YYYY-MM-DD - Organization - Document Type - Subject - Status.ext",
        "- Undated is used only when no reliable date was found in the document or old filename.",
        "- Exact duplicate files are retained and explicitly labeled Duplicate 2, etc.",
    ]
    REPORT.write_text("\n".join(lines), encoding="utf-8")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true", help="Apply planned renames")
    parser.add_argument("--rollback", action="store_true", help="Restore original names from the rename log")
    args = parser.parse_args()
    if args.apply and args.rollback:
        parser.error("Choose either --apply or --rollback")
    if args.rollback:
        rollback()
        return
    records = inventory()
    rows = make_plan(records)
    write_csv(PLAN, rows)
    if args.apply:
        apply_plan(rows)
    write_report(rows, args.apply)
    print(f"Wrote {PLAN}")
    print(f"Wrote {REPORT}")
    if args.apply:
        print(f"Applied {len(rows)} rename decisions; log: {LOG}")


if __name__ == "__main__":
    main()
