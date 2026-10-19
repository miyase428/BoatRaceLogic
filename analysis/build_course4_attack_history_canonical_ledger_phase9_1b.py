#!/usr/bin/env python3
"""Create the immutable Phase 7 Attack-Type historical event ledger.

The baseline is *only* the event-level Phase 4 monthly history cache.  Current
raw DB history is never used to rebuild or amend that baseline.  A manual run
may append finalized post-2026-10-01 events as explicitly-labelled
late_bootstrap rows; it never modifies an existing event.
"""
from __future__ import annotations

import argparse
import csv
import hashlib
import io
import json
import subprocess
import sys
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parent
OUT = ROOT / "output"
HISTORY_DIR = OUT / "course4_attack_phase4_history"
LEDGER = OUT / "course4_attack_history_canonical_ledger_v1.csv"
MANIFEST = OUT / "course4_attack_history_canonical_ledger_v1_manifest.json"
LEDGER_VERSION = "v1"
BASELINE_END = date(2026, 9, 30)
FIELDS = (
    "event_id", "race_code", "race_date", "player_id", "actual_course", "final_rank",
    "raw_technique", "normalized_technique", "is_history_eligible", "is_makuri", "is_makurizashi",
    "source_kind", "source_path", "source_sha256", "ledger_version", "baseline_frozen",
    "availability_kind", "race_finalized_at", "first_observed_at", "ingested_at",
)


class GenerationBuilderError(RuntimeError):
    """Generation input is incomplete or would weaken the ledger contract."""


def _canonical_json_bytes(value: dict) -> bytes:
    return (json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"), allow_nan=False) + "\n").encode("utf-8")


def _utc_timestamp(value: str, label: str) -> datetime:
    try:
        parsed = datetime.fromisoformat(value)
    except (TypeError, ValueError) as error:
        raise GenerationBuilderError(f"{label}_INVALID") from error
    if parsed.tzinfo is None or parsed.utcoffset() is None:
        raise GenerationBuilderError(f"{label}_TIMEZONE_REQUIRED")
    return parsed.astimezone(timezone.utc)


def resolve_generation_through_date(date_value: str | None, through_value: str | None, *, today_jst: date) -> date:
    """Resolve the explicit inclusive JST upper bound without DB access.

    ``--date`` remains only an exact alias for older callers.  A generation
    writer must never silently select its own end date.
    """
    if not date_value and not through_value:
        raise GenerationBuilderError("THROUGH_DATE_REQUIRED")
    parsed: list[date] = []
    for value in (date_value, through_value):
        if value:
            try:
                parsed.append(date.fromisoformat(value))
            except ValueError as error:
                raise GenerationBuilderError("THROUGH_DATE_INVALID") from error
    if len(parsed) == 2 and parsed[0] != parsed[1]:
        raise GenerationBuilderError("DATE_ARGUMENT_MISMATCH")
    through = parsed[0]
    if through <= BASELINE_END:
        raise GenerationBuilderError("THROUGH_DATE_BEFORE_PROSPECTIVE_RANGE")
    if through > today_jst:
        raise GenerationBuilderError("THROUGH_DATE_IN_FUTURE")
    return through


def _csv_bytes(rows: list[dict]) -> bytes:
    out = io.StringIO(newline="")
    writer = csv.DictWriter(out, fieldnames=FIELDS, extrasaction="raise")
    writer.writeheader()
    for row in rows:
        writer.writerow({field: row.get(field, "") for field in FIELDS})
    return out.getvalue().encode("utf-8")


def _immutable_row_view(row: dict) -> dict:
    """Ignore only ingestion bookkeeping when comparing frozen baseline rows."""
    return {field: str(row.get(field, "")) for field in FIELDS if field != "ingested_at"}


def _validate_complete_finalized_races(raw_rows: list[dict], *, through_date: date, observed_at: datetime) -> list[dict]:
    """Convert complete, injected race results into append-only C4 rows.

    This intentionally accepts no DB cursor.  The production database schema
    has not yet been proven to express a six-entrant final-result condition,
    so a DB adapter must be added separately and must supply this complete
    normalized shape.  Partial results are rejected rather than inferred.
    """
    by_race: dict[str, list[dict]] = {}
    for raw in raw_rows:
        try:
            race_code = str(raw["race_code"])
            race_day = date.fromisoformat(str(raw["race_date"]))
            player_id = str(raw["player_id"]).strip()
            actual_course = int(raw["actual_course"])
            final_rank = int(raw["final_rank"])
        except (KeyError, TypeError, ValueError) as error:
            raise GenerationBuilderError("FINALIZED_RACE_ROW_INVALID") from error
        if not race_code or not player_id or actual_course not in range(1, 7) or final_rank not in range(1, 7):
            raise GenerationBuilderError("FINALIZED_RACE_ROW_INVALID")
        if not (BASELINE_END < race_day <= through_date):
            raise GenerationBuilderError("FINALIZED_RACE_OUTSIDE_THROUGH_DATE")
        item = dict(raw)
        item.update({"race_code": race_code, "race_date": race_day.isoformat(), "player_id": player_id,
                     "actual_course": actual_course, "final_rank": final_rank})
        by_race.setdefault(race_code, []).append(item)

    rows: list[dict] = []
    stamp = observed_at.isoformat()
    for race_code, entrants in sorted(by_race.items()):
        if len(entrants) != 6:
            raise GenerationBuilderError("RACE_FINALIZATION_NOT_COMPLETE")
        if len({item["player_id"] for item in entrants}) != 6 or {item["final_rank"] for item in entrants} != set(range(1, 7)):
            raise GenerationBuilderError("RACE_FINALIZATION_NOT_COMPLETE")
        c4 = [item for item in entrants if item["actual_course"] == 4]
        if len(c4) != 1:
            raise GenerationBuilderError("RACE_COURSE_MAPPING_NOT_COMPLETE")
        item = c4[0]
        technique = str(item.get("raw_technique", item.get("technique", "")) or "").strip()
        norm = normalize_technique(technique)
        source_record = {"race_code": race_code, "race_date": item["race_date"], "player_id": item["player_id"],
                         "actual_course": 4, "final_rank": item["final_rank"], "raw_technique": technique}
        rows.append({
            "event_id": event_id(race_code, item["player_id"]), **source_record,
            "normalized_technique": norm, "is_history_eligible": 1,
            "is_makuri": int(item["final_rank"] == 1 and norm == "MAKURI"),
            "is_makurizashi": int(item["final_rank"] == 1 and norm == "MAKURIZASHI"),
            "source_kind": "prospective_complete_result", "source_path": "injected_finalized_race_provider",
            "source_sha256": sha256_bytes(_canonical_json_bytes(source_record)), "ledger_version": LEDGER_VERSION,
            "baseline_frozen": 0, "availability_kind": "OBSERVED_AT_INGESTION",
            "race_finalized_at": str(item.get("race_finalized_at", "")), "first_observed_at": stamp, "ingested_at": stamp,
        })
    return rows


def build_generation_ledger(*, baseline_rows: list[dict], baseline_source_manifest: list[dict],
                            baseline_source_sha256: str, existing_rows: list[dict], finalized_race_rows: list[dict],
                            through_date: date, observed_at: str, builder_sha256: str) -> tuple[bytes, bytes, dict]:
    """Build an immutable generation payload only from explicit inputs.

    No fixed legacy path, DB connection, or clock is consulted here.  This is
    the seam that allows the writer and its fixture to prove their contracts
    without touching live artifacts.
    """
    observed = _utc_timestamp(observed_at, "DB_OBSERVED_AT")
    if not isinstance(baseline_source_sha256, str) or len(baseline_source_sha256) != 64:
        raise GenerationBuilderError("BASELINE_SOURCE_SHA256_INVALID")
    baseline_by_id = {str(row.get("event_id", "")): row for row in baseline_rows}
    if not baseline_by_id or len(baseline_by_id) != len(baseline_rows):
        raise GenerationBuilderError("BASELINE_IDENTITY_INVALID")
    for row in baseline_rows:
        if str(row.get("baseline_frozen")) not in ("1", "True", "true", "1"):
            raise GenerationBuilderError("BASELINE_NOT_FROZEN")
    existing_by_id = {str(row.get("event_id", "")): row for row in existing_rows}
    if existing_rows and len(existing_by_id) != len(existing_rows):
        raise GenerationBuilderError("EXISTING_EVENT_ID_DUPLICATE")
    if existing_rows:
        prior_baseline = {event: row for event, row in existing_by_id.items() if str(row.get("baseline_frozen")) in ("1", "True", "true", "1")}
        if set(prior_baseline) != set(baseline_by_id):
            raise GenerationBuilderError("BASELINE_IDENTITY_CHANGED")
        for event, row in baseline_by_id.items():
            if _immutable_row_view(prior_baseline[event]) != _immutable_row_view(row):
                raise GenerationBuilderError("BASELINE_ROW_CHANGED")
    else:
        existing_by_id = dict(baseline_by_id)

    incoming = _validate_complete_finalized_races(finalized_race_rows, through_date=through_date, observed_at=observed)
    rows, append = append_only(list(existing_by_id.values()), incoming)
    ledger_bytes = _csv_bytes(rows)
    manifest = {
        "ledger_id": "course4_attack_history_canonical_ledger", "ledger_version": LEDGER_VERSION,
        "schema_version": "course4-attack-ledger-generation-manifest/v1",
        "baseline_source_sha256": baseline_source_sha256, "baseline_source_files": baseline_source_manifest,
        "baseline_row_count": len(baseline_rows), "row_count": len(rows),
        "prospective_row_count": len(rows) - len(baseline_rows), "generation_through_date": through_date.isoformat(),
        "db_observed_at": observed.isoformat(), "builder_sha256": builder_sha256,
        "append_policy": "append-only; duplicate event_id is a no-op; prior rows are immutable",
        "finalization_policy": "exactly six distinct entrants and ranks 1..6 required before C4 event append",
        "history_date_policy": "post-baseline race_date must satisfy baseline_end < race_date <= through_date",
        "append_run": append, "ledger_sha256": sha256_bytes(ledger_bytes),
    }
    return ledger_bytes, _canonical_json_bytes(manifest), append


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def composite_hash(paths: list[Path]) -> str:
    h = hashlib.sha256()
    for path in paths:
        h.update(path.name.encode("utf-8")); h.update(b"\0")
        h.update(path.read_bytes()); h.update(b"\0")
    return h.hexdigest()


def git_head() -> str:
    return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()


def generator_commit(path: Path) -> str | None:
    result = subprocess.run(["git", "log", "--all", "-1", "--format=%H", "--", str(path.relative_to(REPO))], cwd=REPO, text=True, capture_output=True, check=True)
    return result.stdout.strip() or None


def normalize_technique(value: str) -> str:
    raw = str(value or "").strip()
    return {"まくり": "MAKURI", "まくり差し": "MAKURIZASHI", "逃げ": "NIGE", "差し": "SASHI", "抜き": "NUKI", "恵まれ": "MEGUMARE"}.get(raw, "UNKNOWN")


def event_id(race_code: str, player_id: str) -> str:
    # race_code is a race identity but a player suffix makes the ledger robust
    # even if a malformed historical source ever contains duplicate C4 mapping.
    return f"{race_code}:{player_id}"


def baseline_sources() -> list[Path]:
    paths = sorted(HISTORY_DIR.glob("*.csv"))
    if len(paths) != 36:
        raise RuntimeError(f"expected 36 fixed Phase4 history files, found {len(paths)}")
    return paths


def build_baseline(paths: list[Path], now: str) -> tuple[list[dict], list[dict]]:
    rows, source_manifest = [], []
    for path in paths:
        digest = sha256_file(path)
        source_manifest.append({"path": str(path.relative_to(REPO)), "sha256": digest, "format": "CSV UTF-8 header: race_date,race_code,player_id,actual_course,rank,technique"})
        with path.open(encoding="utf-8") as f:
            reader = csv.DictReader(f)
            expected = {"race_date", "race_code", "player_id", "actual_course", "rank", "technique"}
            if set(reader.fieldnames or []) != expected:
                raise RuntimeError(f"unexpected source schema: {path}")
            for raw in reader:
                course, rank = int(raw["actual_course"]), int(raw["rank"])
                if course != 4 or rank not in range(1, 7):
                    raise RuntimeError(f"baseline event violates Phase7 eligibility: {raw}")
                technique = normalize_technique(raw["technique"])
                rows.append({
                    "event_id": event_id(raw["race_code"], raw["player_id"]),
                    "race_code": raw["race_code"], "race_date": raw["race_date"], "player_id": raw["player_id"],
                    "actual_course": course, "final_rank": rank, "raw_technique": raw["technique"],
                    "normalized_technique": technique, "is_history_eligible": 1,
                    "is_makuri": int(rank == 1 and technique == "MAKURI"),
                    "is_makurizashi": int(rank == 1 and technique == "MAKURIZASHI"),
                    "source_kind": "frozen_research_cache", "source_path": str(path.relative_to(REPO)),
                    "source_sha256": digest, "ledger_version": LEDGER_VERSION, "baseline_frozen": 1,
                    # This is a logical research-baseline availability marker, not an invented timestamp.
                    "availability_kind": "RESEARCH_BASELINE_LOGICAL", "race_finalized_at": "",
                    "first_observed_at": "", "ingested_at": now,
                })
    rows.sort(key=lambda r: (r["race_date"], r["race_code"], r["player_id"]))
    if len(rows) != len({r["event_id"] for r in rows}):
        raise RuntimeError("duplicate baseline event_id")
    return rows, source_manifest


def fetch_late_bootstrap(now: str) -> list[dict]:
    """Read only finalized post-baseline C4 events; never query pre-baseline history."""
    sys.path.insert(0, str(ROOT))
    from slit_validate_v2 import connect_db  # noqa: WPS433
    today = date.today()
    sql = """
      WITH ex AS (
        SELECT DISTINCT ON (race_code,player_id) race_code,player_id,entry_course
        FROM boat_race.exhibition_live
        WHERE substring(race_code,1,8) >= '20261001'
        ORDER BY race_code,player_id,created_date DESC NULLS LAST,entry_course
      ), winners AS (
        SELECT DISTINCT race_code FROM boat_race.race_result_detail
        WHERE trim(rank::text)='1'
      )
      SELECT rm.race_code,rm.race_date,re.player_id::text,
             coalesce(rd.entry_course,ex.entry_course,re.lane_number) actual_course,
             rd.rank,trim(coalesce(rd.technique,'')) technique
      FROM boat_race.race_master rm
      JOIN boat_race.race_entry re ON re.race_code=rm.race_code
      JOIN boat_race.race_result_detail rd ON rd.race_code=re.race_code AND rd.player_id=re.player_id
      JOIN winners w ON w.race_code=rm.race_code
      LEFT JOIN ex ON ex.race_code=re.race_code AND ex.player_id=re.player_id
      WHERE rm.race_date >= %s AND rm.race_date < %s
        AND coalesce(rd.entry_course,ex.entry_course,re.lane_number)=4
        AND rd.rank::text ~ '^[1-6]$'
      ORDER BY rm.race_date,rm.race_code,re.player_id
    """
    raw_rows = []
    with connect_db() as conn, conn.cursor() as cur:
        cur.execute(sql, (date(2026, 10, 1), today))
        for code, race_date, pid, course, rank, technique in cur.fetchall():
            raw = {"race_code": str(code), "race_date": race_date.isoformat(), "player_id": str(pid).strip(),
                   "actual_course": int(course), "final_rank": int(rank), "raw_technique": str(technique or "").strip()}
            norm = normalize_technique(raw["raw_technique"])
            raw_rows.append({
                "event_id": event_id(raw["race_code"], raw["player_id"]), **raw, "normalized_technique": norm,
                "is_history_eligible": 1, "is_makuri": int(raw["final_rank"] == 1 and norm == "MAKURI"),
                "is_makurizashi": int(raw["final_rank"] == 1 and norm == "MAKURIZASHI"),
                "source_kind": "late_bootstrap", "source_path": "boat_race.race_master+race_entry+race_result_detail+exhibition_live",
                "source_sha256": sha256_bytes(json.dumps(raw, ensure_ascii=False, sort_keys=True).encode()),
                "ledger_version": LEDGER_VERSION, "baseline_frozen": 0, "availability_kind": "OBSERVED_AT_INGESTION",
                "race_finalized_at": "", "first_observed_at": now, "ingested_at": now,
            })
    return raw_rows


def append_only(existing: list[dict], incoming: list[dict]) -> tuple[list[dict], dict]:
    by_id = {r["event_id"]: r for r in existing}
    added = 0
    for row in incoming:
        prior = by_id.get(row["event_id"])
        if prior is not None:
            # Never reconcile or replace an existing source row, even if the
            # current DB reports a different value.
            continue
        by_id[row["event_id"]] = row
        added += 1
    rows = sorted(by_id.values(), key=lambda r: (r["race_date"], r["race_code"], r["player_id"]))
    return rows, {"incoming_n": len(incoming), "added_n": added, "no_op_duplicate_n": len(incoming) - added}


def build_ledger_index(rows: list[dict]) -> dict[str, list[dict]]:
    """Index canonical rows only; this function has no DB dependency."""
    index: dict[str, list[dict]] = {}
    for row in rows:
        if str(row.get("is_history_eligible")) not in ("1", "True", "true", "1.0", 1):
            continue
        index.setdefault(str(row["player_id"]), []).append(row)
    for values in index.values():
        values.sort(key=lambda r: (r["race_date"], r["event_id"]))
    return index


def history_features_from_canonical_ledger(index: dict[str, list[dict]], player_id: str, target_race_date: date,
                                           prediction_cutoff: datetime | None, *, research_reproduction: bool,
                                           ledger_activation_at: datetime | None, include_event_ids: bool = False) -> dict:
    """Build the three fixed Phase7 features from canonical rows alone.

    A frozen baseline is logically available in research-reproduction mode.
    In live mode it becomes available only after the factual ledger activation
    timestamp; prospective rows additionally require first_observed_at not
    later than the supplied cutoff.  No timestamp is fabricated for baseline
    events.
    """
    # Preserve calendar-month semantics for month ends.
    import calendar
    lo = date(target_race_date.year - 1, target_race_date.month, min(target_race_date.day, calendar.monthrange(target_race_date.year - 1, target_race_date.month)[1]))
    included = []
    for row in index.get(str(player_id), []):
        event_date = date.fromisoformat(row["race_date"])
        if not (lo <= event_date < target_race_date):
            continue
        is_baseline = str(row["baseline_frozen"]) in ("1", "True", "true")
        if is_baseline:
            available = research_reproduction or (prediction_cutoff is not None and ledger_activation_at is not None and prediction_cutoff >= ledger_activation_at)
        else:
            observed = datetime.fromisoformat(row["first_observed_at"])
            available = prediction_cutoff is not None and observed <= prediction_cutoff
        if available:
            included.append(row)
    n = len(included)
    makuri = sum(int(r["is_makuri"]) for r in included)
    makurizashi = sum(int(r["is_makurizashi"]) for r in included)
    return {
        "course4_history_n": n,
        "course4_history_makuri_rate_pct": None if n == 0 else 100.0 * makuri / n,
        "course4_history_makurizashi_rate_pct": None if n == 0 else 100.0 * makurizashi / n,
        "included_event_ids": [r["event_id"] for r in included] if include_event_ids else None,
    }


def write_ledger(rows: list[dict]):
    tmp = LEDGER.with_suffix(".csv.tmp")
    with tmp.open("w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=FIELDS)
        writer.writeheader(); writer.writerows(rows)
    tmp.replace(LEDGER)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--baseline-only", action="store_true", help="do not query post-baseline finalized rows")
    args = parser.parse_args()
    now = datetime.now().astimezone().isoformat()
    sources = baseline_sources()
    baseline, source_manifest = build_baseline(sources, now)
    baseline_by_id = {r["event_id"]: r for r in baseline}
    existing = []
    if LEDGER.exists():
        with LEDGER.open(encoding="utf-8") as f:
            existing = list(csv.DictReader(f))
        current_baseline = {r["event_id"]: r for r in existing if r["baseline_frozen"] in ("1", 1)}
        # Compare immutable serialized field values exactly.
        if set(current_baseline) != set(baseline_by_id):
            raise RuntimeError("existing baseline identity differs; create a new ledger version, do not overwrite v1")
        for key, row in baseline_by_id.items():
            # ingested_at records the first actual ledger observation and is
            # intentionally preserved on reruns; it is not source content.
            immutable_fields = [k for k in FIELDS if k != "ingested_at"]
            if {k: str(current_baseline[key].get(k, "")) for k in immutable_fields} != {k: str(row.get(k, "")) for k in immutable_fields}:
                raise RuntimeError(f"existing baseline row altered: {key}")
    else:
        existing = baseline
    incoming = [] if args.baseline_only else fetch_late_bootstrap(now)
    rows, append = append_only(existing, incoming)
    write_ledger(rows)
    base_rows = [r for r in rows if str(r["baseline_frozen"]) == "1"]
    late_rows = [r for r in rows if str(r["baseline_frozen"]) != "1"]
    manifest = {
        "ledger_id": "course4_attack_history_canonical_ledger", "ledger_version": LEDGER_VERSION,
        "baseline_source_path": str(HISTORY_DIR.relative_to(REPO)), "baseline_source_sha256": composite_hash(sources),
        "baseline_source_files": source_manifest, "baseline_date_range": {"min": min(r["race_date"] for r in baseline), "max": max(r["race_date"] for r in baseline)},
        "baseline_row_count": len(baseline), "row_count": len(rows), "late_bootstrap_row_count": len(late_rows),
        "player_count": len({r["player_id"] for r in base_rows}), "race_count": len({r["race_code"] for r in base_rows}),
        "event_identity": "event_id = race_code + ':' + player_id; source data is actual_course=4, so this identifies one history event",
        "history_definition": {"actual_course": 4, "final_rank": "integer 1..6", "history_n_denominator": "one eligible actual-4C event", "makuri_numerator": "final_rank=1 AND normalized_technique=MAKURI", "makurizashi_numerator": "final_rank=1 AND normalized_technique=MAKURIZASHI", "unknown_technique": "normalized to UNKNOWN; denominator retained, both numerators zero", "missing_result_or_mapping_failure": "not eligible; no row appended"},
        "window_definition": "[target_race_date - 12 calendar months, target_race_date); target race and all same-day events excluded",
        "append_policy": "append finalized eligible C4 event only when event_id is absent; duplicate event_id is a no-op; existing rows are never updated",
        "immutability_policy": "baseline_frozen rows are byte-semantic immutable; changed source data requires a new ledger version, never v1 overwrite",
        "available_at_policy": {"baseline": "RESEARCH_BASELINE_LOGICAL: no fabricated historical timestamp; for research parity baseline eligibility is governed by the exact frozen cache plus race_date window", "late_bootstrap": "first_observed_at/ingested_at are real ingestion timestamps; rows may only serve cutoffs >= first_observed_at", "race_finalized_at": "blank when DB lacks a factual finalized timestamp"},
        "ledger_activation_at": min(r["ingested_at"] for r in base_rows if r["ingested_at"]),
        "late_bootstrap_policy": "post-2026-10-01 finalized DB rows are explicitly late_bootstrap, not historical claims about prior observation",
        "feature_provider_contract": {"function": "history_features_from_canonical_ledger", "inputs": ["canonical ledger index", "player_id", "target_race_date", "prediction_cutoff"], "required_filters": ["[target_race_date-12 calendar months,target_race_date)", "baseline logical availability only in research reproduction or after ledger_activation_at", "late_bootstrap first_observed_at <= prediction_cutoff"], "raw_db_history_access": "PROHIBITED"},
        "append_run": append, "builder_path": str(Path(__file__).relative_to(REPO)), "builder_sha256": sha256_file(Path(__file__)),
        "builder_source_git_commit": generator_commit(Path(__file__)), "source_generator_path": "analysis/build_course4_attack_history_phase4.py", "source_generator_git_commit": generator_commit(ROOT / "build_course4_attack_history_phase4.py"),
        "ledger_sha256": sha256_file(LEDGER), "generated_at": now, "source_git_commit": git_head(),
    }
    MANIFEST.write_text(json.dumps(manifest, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({"ledger": str(LEDGER), "manifest": str(MANIFEST), "baseline_rows": len(baseline), "late_bootstrap_rows": len(late_rows), "append": append, "ledger_sha256": manifest["ledger_sha256"]}, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
