#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""本命・対抗の買い目を実結果と払戻で評価する。

入力は export_final_prediction_fast_cached.php が出力したレース単位CSVと、
export_trifecta_payout_cache.php が出力した3連単払戻CSV。各買い目を1点100円で
購入したものとして、本命、対抗、両者の重複除外合算を集計する。
"""

from __future__ import annotations

import csv
import json
import re
import sys
from collections import defaultdict
from dataclasses import dataclass, field
from datetime import date, datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
VALID_BET = re.compile(r"^[1-6]-[1-6]-[1-6]$")


def parse_date(value: str) -> date:
    return datetime.strptime(value, "%Y-%m-%d").date()


def pct(num: int | float, den: int | float) -> float:
    return 100.0 * num / den if den else 0.0


def expand_bets(expression: str) -> set[str]:
    """`1-234-2345` のような表記を、有効な3連単へ展開する。"""
    parts = str(expression or "").strip().replace("・", "").split("-")
    if len(parts) != 3 or any(not part for part in parts):
        return set()
    bets = set()
    for first in parts[0]:
        for second in parts[1]:
            for third in parts[2]:
                if {first, second, third} <= set("123456") and len({first, second, third}) == 3:
                    bets.add(f"{first}-{second}-{third}")
    return bets


@dataclass
class BetStat:
    races: int = 0
    races_with_bets: int = 0
    hits: int = 0
    points: int = 0
    roi_races: int = 0
    roi_hits: int = 0
    roi_points: int = 0
    payout_yen: int = 0
    hit_payouts: list[int] = field(default_factory=list)

    def add(self, bets: set[str], actual: str, payout: int | None) -> None:
        self.races += 1
        self.races_with_bets += int(bool(bets))
        self.points += len(bets)
        hit = actual in bets
        self.hits += int(hit)
        if payout is not None:
            self.roi_races += 1
            self.roi_points += len(bets)
            self.roi_hits += int(hit)
            if hit:
                self.payout_yen += payout
                self.hit_payouts.append(payout)

    def summary(self) -> dict:
        investment = self.roi_points * 100
        payouts = sorted(self.hit_payouts, reverse=True)
        return {
            "races": self.races,
            "races_with_bets": self.races_with_bets,
            "hits": self.hits,
            "hit_rate": pct(self.hits, self.races),
            "points": self.points,
            "avg_points_per_race": self.points / self.races if self.races else 0.0,
            "roi_races": self.roi_races,
            "roi_hits": self.roi_hits,
            "roi_points": self.roi_points,
            "investment_yen": investment,
            "payout_yen": self.payout_yen,
            "profit_yen": self.payout_yen - investment,
            "roi": pct(self.payout_yen, investment),
            "max_hit_payout_yen": payouts[0] if payouts else 0,
            "top5_return_share": pct(sum(payouts[:5]), self.payout_yen),
        }


@dataclass
class HeadStat:
    races: int = 0
    honmei: int = 0
    taikou: int = 0
    either: int = 0

    def add(self, actual_first: str, honmei_head: str, taikou_head: str) -> None:
        self.races += 1
        honmei_hit = actual_first == honmei_head
        taikou_hit = actual_first == taikou_head
        self.honmei += int(honmei_hit)
        self.taikou += int(taikou_hit)
        self.either += int(honmei_hit or taikou_hit)

    def summary(self) -> dict:
        return {
            "races": self.races,
            "honmei_first_hits": self.honmei,
            "honmei_first_rate": pct(self.honmei, self.races),
            "taikou_first_hits": self.taikou,
            "taikou_first_rate": pct(self.taikou, self.races),
            "either_first_hits": self.either,
            "either_first_rate": pct(self.either, self.races),
        }


def load_payouts(path: Path) -> dict[str, int]:
    with path.open(encoding="utf-8-sig", newline="") as fp:
        return {
            row["race_code"].strip(): int(row["trifecta_payout"])
            for row in csv.DictReader(fp)
            if row.get("race_code") and str(row.get("trifecta_payout", "")).isdigit()
        }


def load_signed_courses(path: Path | None) -> dict[str, set[str]]:
    if path is None:
        return {}
    data = json.loads(path.read_text(encoding="utf-8"))
    signed = defaultdict(set)
    for row in data.get("signals", []):
        if row.get("period") == "post_adoption":
            signed[str(row["race_code"])].add(str(row["course"]))
    return dict(signed)


def evaluate(races_path: Path, payouts_path: Path, adopted: date, signals_path: Path | None = None) -> dict:
    payouts = load_payouts(payouts_path)
    signed_courses = load_signed_courses(signals_path)
    rows = []
    skipped = []
    with races_path.open(encoding="utf-8-sig", newline="") as fp:
        for row in csv.DictReader(fp):
            actual = str(row.get("actual_trifecta") or "").strip()
            if not VALID_BET.fullmatch(actual) or len(set(actual.split("-"))) != 3:
                skipped.append(row.get("race_code", ""))
                continue
            row["parsed_date"] = parse_date(row["race_date"])
            row["honmei_bets"] = expand_bets(row.get("honmei_kai", ""))
            row["taikou_bets"] = expand_bets(row.get("taikou_kai", ""))
            rows.append(row)

    periods = {
        "september_all": lambda d: True,
        "before_adoption": lambda d: d < adopted,
        "post_adoption": lambda d: d >= adopted,
    }
    result = {
        "definition": {
            "source": "現在のPredictionLogicを各過去レースへ再適用",
            "bet_unit_yen": 100,
            "combined": "本命と対抗の買い目を重複除外して合算",
            "adopted_start": adopted.isoformat(),
        },
        "valid_result_races": len(rows),
        "payout_rows": len(payouts),
        "skipped_invalid_result_races": skipped,
        "periods": {},
    }

    for period, includes in periods.items():
        stats = {name: BetStat() for name in ("honmei", "taikou", "combined")}
        heads = HeadStat()
        crossover = defaultdict(BetStat)
        daily = defaultdict(lambda: {name: BetStat() for name in ("honmei", "taikou", "combined")})
        venues = defaultdict(lambda: {name: BetStat() for name in ("honmei", "taikou", "combined")})
        start = end = None
        for row in rows:
            race_date = row["parsed_date"]
            if not includes(race_date):
                continue
            start = race_date if start is None else min(start, race_date)
            end = race_date if end is None else max(end, race_date)
            actual = row["actual_trifecta"]
            payout = payouts.get(row["race_code"])
            bets = {
                "honmei": row["honmei_bets"],
                "taikou": row["taikou_bets"],
                "combined": row["honmei_bets"] | row["taikou_bets"],
            }
            for name, selections in bets.items():
                stats[name].add(selections, actual, payout)
                daily[race_date.isoformat()][name].add(selections, actual, payout)
                venues[row["stadium_name"]][name].add(selections, actual, payout)
            heads.add(str(row.get("actual_1st") or ""), str(row.get("honmei_head") or ""), str(row.get("taikou_head") or ""))

            signed = signed_courses.get(row["race_code"], set())
            honmei_signed = str(row.get("honmei_head") or "") in signed
            taikou_signed = str(row.get("taikou_head") or "") in signed
            if signed:
                crossover["any_signal__combined"].add(bets["combined"], actual, payout)
            else:
                crossover["no_signal__combined"].add(bets["combined"], actual, payout)
            if honmei_signed:
                crossover["honmei_head_signed__honmei"].add(bets["honmei"], actual, payout)
                crossover["honmei_head_signed__combined"].add(bets["combined"], actual, payout)
            if taikou_signed:
                crossover["taikou_head_signed__taikou"].add(bets["taikou"], actual, payout)
                crossover["taikou_head_signed__combined"].add(bets["combined"], actual, payout)
            if honmei_signed or taikou_signed:
                crossover["either_head_signed__combined"].add(bets["combined"], actual, payout)
            else:
                crossover["neither_head_signed__combined"].add(bets["combined"], actual, payout)

        result["periods"][period] = {
            "date_range": [start.isoformat(), end.isoformat()] if start and end else [],
            "bets": {name: stat.summary() for name, stat in stats.items()},
            "heads": heads.summary(),
            "course_signal_crossover": {name: stat.summary() for name, stat in sorted(crossover.items())},
            "daily_combined": {day: values["combined"].summary() for day, values in sorted(daily.items())},
            "by_venue_combined": {
                venue: values["combined"].summary()
                for venue, values in sorted(venues.items())
            },
        }
    return result


def main() -> None:
    if len(sys.argv) not in (3, 4, 5):
        raise SystemExit("Usage: python3 analysis/evaluate_september_honmei_taikou.py RACES_CSV PAYOUTS_CSV [ADOPTED_START] [COURSE_SIGNAL_JSON]")
    races_path = Path(sys.argv[1])
    payouts_path = Path(sys.argv[2])
    adopted = parse_date(sys.argv[3]) if len(sys.argv) == 4 else date(2026, 9, 10)
    if len(sys.argv) >= 4:
        adopted = parse_date(sys.argv[3])
    signals_path = Path(sys.argv[4]) if len(sys.argv) == 5 else None
    result = evaluate(races_path, payouts_path, adopted, signals_path)
    start, end = result["periods"]["september_all"]["date_range"]
    out = ROOT / "analysis/output" / f"september_honmei_taikou_evaluation_{start.replace('-', '')}_{end.replace('-', '')}.json"
    out.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({
        "valid_result_races": result["valid_result_races"],
        "payout_rows": result["payout_rows"],
        "periods": result["periods"],
        "json": str(out),
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
