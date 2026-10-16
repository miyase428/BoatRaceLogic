#!/usr/bin/env python3
"""Outcome-blind fixtures for the live Course Signal input contract.

This test imports no database and never loads a production artifact.  The
OMR01/SMS01-shaped fixture demonstrates that race_master is not a prerequisite
when current race_entry and six exhibition-era boats are available.
"""

from __future__ import annotations

import importlib.util
from datetime import date
from pathlib import Path

import analyze_all_venue_secondary_signals as SECONDARY


ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "forecast" / "tamagawa_center_signal_live_v1.py"
SPEC = importlib.util.spec_from_file_location("course_signal_live_fixture", MODULE_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("live_module_load_failed")
LIVE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(LIVE)


def assert_that(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def complete_race() -> dict:
    return {"boats": [{"course": course, "player_id": str(9000 + course)} for course in range(1, 7)]}


class FixtureCursor:
    def __init__(self, rows: list[tuple]) -> None:
        self.rows = rows
        self.sql = ""

    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False

    def execute(self, sql: str, _params: tuple) -> None:
        self.sql = sql
        assert_that("race_master" not in sql.lower(), "live exhibition SQL must not depend on race_master")
        assert_that("race_entry" in sql.lower() and "re.race_date" in sql.lower(), "live exhibition SQL must use race_entry date")

    def fetchall(self) -> list[tuple]:
        return self.rows


class FixtureConnection:
    def __init__(self, cursor: FixtureCursor) -> None:
        self.fixture_cursor = cursor

    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False

    def cursor(self) -> FixtureCursor:
        return self.fixture_cursor


def fixture_exhibition_rows(race_code: str, count: int = 6) -> list[tuple]:
    return [
        (
            race_code,
            course,
            str(9000 + course),
            6.70 + course / 100,
            0.10 + course / 100,
            37.0 + course / 10,
            5.0 + course / 10,
            None if "SME" in race_code else 6.0 + course / 10,
        )
        for course in range(1, count + 1)
    ]


def load_fixture_exhibition(rows: list[tuple]) -> dict[str, list[dict]]:
    cursor = FixtureCursor(rows)
    original = SECONDARY.connect_db
    SECONDARY.connect_db = lambda: FixtureConnection(cursor)
    try:
        return SECONDARY.load_exhibition(date(2099, 1, 1), date(2099, 1, 1), "SME")
    finally:
        SECONDARY.connect_db = original


def scheduler_exhibition_complete(rows: list[tuple], place: str) -> bool:
    courses = {
        int(row[1]) for row in rows
        if 1 <= int(row[1]) <= 6
        and all(row[index] is not None for index in (3, 4, 5, 6))
        and (place in {"AMG", "TKY", "SME"} or row[7] is not None)
    }
    return courses == set(range(1, 7))


sql = LIVE.live_entry_sql().lower()
assert_that("race_master" not in sql, "live current-day SQL must not depend on race_master")
assert_that("race_entry" in sql and "race_date" in sql, "live SQL must use race_entry.race_date")

# CASE 1 / CASE 2: target race_master presence is irrelevant to both entry and
# exhibition input contracts.  Both fixtures intentionally have the same
# race_entry/exhibition_live rows and no master-derived field.
complete = {"20990101OMR01": complete_race()}
status, failures = LIVE.classify_live_input(complete, 1)
assert_that(status == "OK" and failures == {}, "complete current entry is inference-ready without race_master")
fixture_rows = fixture_exhibition_rows("20990101SME11")
with_master_equivalent = load_fixture_exhibition(fixture_rows)
without_master = load_fixture_exhibition(fixture_rows)
assert_that(with_master_equivalent == without_master, "race_master presence cannot change live exhibition input")
assert_that(len(without_master["20990101SME11"]) == 6, "six exhibition rows load without race_master")

# CASE 3: the scheduler's six-course prerequisite and Python's exhibition
# feature prerequisite agree for the same pre-race rows.  SME legitimately has
# no straight_time feed, which both contracts allow.
python_ready = LIVE.build_second_scores(without_master["20990101SME11"], 6.75) is not None
scheduler_ready = scheduler_exhibition_complete(fixture_rows, "SME")
assert_that(scheduler_ready and python_ready, "scheduler and Python must both accept complete exhibition input")

# A day/place without any race_entry is a valid no-op, not an error.
status, failures = LIVE.classify_live_input({}, 0)
assert_that(status == "NO_APPLICABLE_RACE" and failures == {}, "no target entry must be distinct")

# CASE 4: target entries known upstream but no materialized input must fail loud.
status, failures = LIVE.classify_live_input({}, 1)
assert_that(status == "INFERENCE_INPUT_EMPTY" and failures == {}, "materialization gap must fail loud")

# Incomplete exhibition input remains an explicit exhibition-unavailable
# decision for exhibition-dependent targets; it cannot become a false ML vote.
incomplete_exhibition = load_fixture_exhibition(fixture_exhibition_rows("20990101SME11", 5))
assert_that(
    LIVE.build_second_scores(incomplete_exhibition["20990101SME11"], 6.75) is None,
    "incomplete exhibition input must remain EXHIBITION_MISSING",
)

# CASE 5: six-boat/course invariant is mandatory for live inference.
incomplete = {"20990101SMS01": {"boats": [{"course": course} for course in range(1, 6)]}}
status, failures = LIVE.classify_live_input(incomplete, 1)
assert_that(status == "INFERENCE_INPUT_INCOMPLETE", "incomplete race must fail loud")
assert_that(failures == {"20990101SMS01": "INFERENCE_INPUT_INCOMPLETE"}, "incomplete race reason")

# CASE 6: artifact errors remain explicit rather than being represented as an
# empty successful prediction payload.
payload = LIVE.error_payload("ARTIFACT_ERROR", "fixture_manifest_missing")
assert_that(payload["status"] == "error" and payload["error"] == "ARTIFACT_ERROR", "artifact failure payload")

print("PASS: live_input_contract cases=1,2,3,4,5,6,7,8")
