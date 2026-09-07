#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
イン崩壊の穴頭について、
A=非インAI3連対率1位 / B=非インAI3連対率2位を内部定義として固定したまま、
「条件成立時だけBを穴本命に昇格し、Aと表示順を入れ替える」候補ルールを比較する。

入力は analyze_payout_signal_hole_head_ab_profile.php が生成したCSV。
同じディレクトリに同期間の
  payout_signal_s3_t3_heads_YYYYMMDD_YYYYMMDD.txt
があれば、頭A/BそれぞれのS3_T3（最大9点）の実戦成績も結合して表示する。

重要:
- 既観察期間の比較用。ここで本番ルールを自動変更しない。
- A/Bの内部定義は変更しない。表示上の「穴本命/穴対抗」の入れ替え候補だけを比較する。
- PayoutSignalClassifier / PredictionLogic / 本番買い目は変更しない。

Usage:
  python3 analysis/analyze_payout_signal_hole_head_b_swap_rules.py \
    analysis/output/payout_signal_hole_head_ab_profile_20260801_20260831.csv \
    analysis/output/payout_signal_hole_head_ab_profile_20260901_20260906.csv
"""

from __future__ import annotations

import csv
import re
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Callable, Optional

Row = dict[str, object]


def f(v: object) -> Optional[float]:
    if v is None:
        return None
    s = str(v).strip()
    if s == "":
        return None
    try:
        return float(s)
    except ValueError:
        return None


def i(v: object) -> Optional[int]:
    x = f(v)
    return int(x) if x is not None else None


def load_profile(path: Path) -> list[Row]:
    rows: list[Row] = []
    with path.open("r", encoding="utf-8-sig", newline="") as fp:
        reader = csv.DictReader(fp)
        for raw in reader:
            r: Row = dict(raw)
            for key in [
                "race_no", "in_boat", "actual_first", "a_boat", "b_boat",
                "a_course", "b_course",
            ]:
                r[key] = i(raw.get(key))
            for key in [
                "ai_a", "ai_b", "ai_gap",
                "first_a", "first_b", "first_diff_ba",
                "second_a", "second_b", "second_diff_ba",
                "final_a", "final_b", "final_diff_ba",
                "ex_a", "ex_b", "ex_diff_ba",
                "st_a", "st_b", "st_diff_ba",
            ]:
                r[key] = f(raw.get(key))
            rows.append(r)
    return rows


@dataclass
class BetInfo:
    payout: Optional[int] = None
    a_points: int = 0
    a_hit: bool = False
    b_points: int = 0
    b_hit: bool = False


def companion_s3_path(profile: Path) -> Optional[Path]:
    m = re.search(r"payout_signal_hole_head_ab_profile_(\d{8})_(\d{8})\.csv$", profile.name)
    if not m:
        return None
    p = profile.with_name(f"payout_signal_s3_t3_heads_{m.group(1)}_{m.group(2)}.txt")
    return p if p.is_file() else None


def load_s3_detail(path: Path) -> dict[str, BetInfo]:
    out: dict[str, BetInfo] = {}
    current: Optional[str] = None
    with path.open("r", encoding="utf-8", errors="replace") as fp:
        for line in fp:
            line = line.rstrip("\n")
            m = re.match(r"^\d{4}-\d{2}-\d{2}\s+.+?\s+\d+R\s+(\d{8}[A-Z]{3}\d{2})\s+", line)
            if m:
                current = m.group(1)
                out.setdefault(current, BetInfo())
                continue
            if current is None:
                continue
            m = re.match(r"^実3連単=.*?/(\d[\d,]*)円$", line)
            if m:
                out[current].payout = int(m.group(1).replace(",", ""))
                continue
            m = re.match(r"^頭A=(\d+)点/(的中|不的中)\s+/\s+買い目=", line)
            if m:
                out[current].a_points = int(m.group(1))
                out[current].a_hit = m.group(2) == "的中"
                continue
            m = re.match(r"^頭B=(\d+)点/(的中|不的中)\s+/\s+買い目=", line)
            if m:
                out[current].b_points = int(m.group(1))
                out[current].b_hit = m.group(2) == "的中"
                continue
    return out


@dataclass(frozen=True)
class Rule:
    name: str
    desc: str
    pred: Callable[[Row], bool]


def lt(key: str, x: float) -> Callable[[Row], bool]:
    return lambda r: r.get(key) is not None and float(r[key]) < x


def rules() -> list[Rule]:
    return [
        Rule("GAP_LT2", "AI3差 < 2", lt("ai_gap", 2.0)),
        Rule("GAP_LT5", "AI3差 < 5", lt("ai_gap", 5.0)),
        Rule("GAP_LT10", "AI3差 < 10", lt("ai_gap", 10.0)),
        Rule("B_INNER", "BがAより内", lambda r: i(r.get("b_course")) is not None and i(r.get("a_course")) is not None and int(r["b_course"]) < int(r["a_course"])),
        Rule("B_C2", "Bが2コース", lambda r: i(r.get("b_course")) == 2),
        Rule("GAP5_INNER", "AI3差<5 × BがAより内", lambda r: r.get("ai_gap") is not None and float(r["ai_gap"]) < 5.0 and i(r.get("b_course")) is not None and i(r.get("a_course")) is not None and int(r["b_course"]) < int(r["a_course"])),
        Rule("GAP5_C2", "AI3差<5 × Bが2コース", lambda r: r.get("ai_gap") is not None and float(r["ai_gap"]) < 5.0 and i(r.get("b_course")) == 2),
        Rule("GAP10_INNER", "AI3差<10 × BがAより内", lambda r: r.get("ai_gap") is not None and float(r["ai_gap"]) < 10.0 and i(r.get("b_course")) is not None and i(r.get("a_course")) is not None and int(r["b_course"]) < int(r["a_course"])),
        Rule("GAP10_C2", "AI3差<10 × Bが2コース", lambda r: r.get("ai_gap") is not None and float(r["ai_gap"]) < 10.0 and i(r.get("b_course")) == 2),
    ]


def pct(n: int | float, d: int | float) -> float:
    return 100.0 * n / d if d else 0.0


@dataclass
class Result:
    signal_n: int = 0
    signal_ab_n: int = 0
    signal_b_win: int = 0
    nonsignal_ab_n: int = 0
    nonsignal_a_win: int = 0
    head_correct: int = 0
    ab_n: int = 0
    exact_hit: int = 0
    bet_n: int = 0
    points: int = 0
    invest: float = 0.0
    ret: float = 0.0


def evaluate(rows: list[Row], bet_map: dict[str, BetInfo], pred: Callable[[Row], bool]) -> Result:
    z = Result()
    for r in rows:
        sig = bool(pred(r))
        if sig:
            z.signal_n += 1

        wg = str(r.get("winner_group") or "")
        if wg in {"A", "B"}:
            z.ab_n += 1
            if sig:
                z.signal_ab_n += 1
                if wg == "B":
                    z.signal_b_win += 1
            else:
                z.nonsignal_ab_n += 1
                if wg == "A":
                    z.nonsignal_a_win += 1
            selected = "B" if sig else "A"
            if wg == selected:
                z.head_correct += 1

        race_code = str(r.get("race_code") or "")
        bi = bet_map.get(race_code)
        if bi is not None:
            z.bet_n += 1
            if sig:
                pts, hit = bi.b_points, bi.b_hit
            else:
                pts, hit = bi.a_points, bi.a_hit
            z.points += pts
            z.invest += pts * 100.0
            if hit:
                z.exact_hit += 1
                if bi.payout is not None:
                    z.ret += bi.payout
    return z


def print_dataset(path: Path) -> None:
    rows = load_profile(path)
    s3_path = companion_s3_path(path)
    bet_map = load_s3_detail(s3_path) if s3_path else {}

    ab_rows = [r for r in rows if str(r.get("winner_group") or "") in {"A", "B"}]
    a_wins = sum(1 for r in ab_rows if r.get("winner_group") == "A")
    b_wins = sum(1 for r in ab_rows if r.get("winner_group") == "B")

    print("=" * 154)
    print(f"対象: {path}")
    print(f"有効候補={len(rows)}R / A・B頭捕捉={len(ab_rows)}R / A勝={a_wins} / B勝={b_wins}")
    print(f"常にAを穴本命にした頭正解率: {pct(a_wins, len(ab_rows)):.2f}%")
    if s3_path:
        print(f"9点成績結合: {s3_path}")
    else:
        print("9点成績結合: 対応するS3_T3詳細txtがないため、頭選択だけ評価")
    print("-" * 154)
    print("条件                 B昇格R   条件内B率  条件外A率  頭選択正解率  A固定比   9点的中率  平均点  100円ROI")
    print("-" * 154)

    baseline = evaluate(rows, bet_map, lambda r: False)
    baseline_head = pct(baseline.head_correct, baseline.ab_n)
    baseline_hit = pct(baseline.exact_hit, baseline.bet_n)
    baseline_avg = baseline.points / baseline.bet_n if baseline.bet_n else 0.0
    baseline_roi = pct(baseline.ret, baseline.invest)
    print(
        f"{'ALWAYS_A':<20} {0:6d}   {'-':>8}   {pct(a_wins,len(ab_rows)):8.2f}%   "
        f"{baseline_head:10.2f}% {0.0:+7.2f}pt   {baseline_hit:8.2f}% {baseline_avg:7.2f} {baseline_roi:9.2f}%"
    )

    for rule in rules():
        z = evaluate(rows, bet_map, rule.pred)
        head_rate = pct(z.head_correct, z.ab_n)
        b_rate = pct(z.signal_b_win, z.signal_ab_n)
        a_out = pct(z.nonsignal_a_win, z.nonsignal_ab_n)
        hit_rate = pct(z.exact_hit, z.bet_n)
        avg = z.points / z.bet_n if z.bet_n else 0.0
        roi = pct(z.ret, z.invest)
        print(
            f"{rule.name:<20} {z.signal_n:6d}   {b_rate:8.2f}%   {a_out:8.2f}%   "
            f"{head_rate:10.2f}% {head_rate-baseline_head:+7.2f}pt   {hit_rate:8.2f}% {avg:7.2f} {roi:9.2f}%"
        )
        print(f"  └ {rule.desc}")

    print("=" * 154)


def main() -> None:
    if len(sys.argv) < 2:
        print("Usage: python3 analysis/analyze_payout_signal_hole_head_b_swap_rules.py PROFILE.csv [PROFILE2.csv ...]")
        raise SystemExit(1)

    for arg in sys.argv[1:]:
        p = Path(arg)
        if not p.is_file():
            print(f"ファイルが見つかりません: {p}")
            raise SystemExit(1)
        print_dataset(p)

    print("※A/B内部定義は固定。条件成立時だけ表示上Bを『穴本命』へ入れ替えた場合の比較です。")
    print("※既観察期間なので、結果だけを見て本番閾値を追加調整せず、8月と9月頭の再現性を優先して判断します。")


if __name__ == "__main__":
    main()
