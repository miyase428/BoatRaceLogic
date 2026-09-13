#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""場SUM × 選手SUM サインの実着順影響を前方検証する。

画面の4サイン:
  ◎ 場↑ 選↑
  ⚠ 場↓ 選↑
  ⚠ 場↑ 選↓
  ▼ 場↓ 選↓

を、対象レースより前の履歴だけで再構成して、実際の
1着率 / 2着率 / 3着率 / 3連対率を集計する。

重要:
- 場SUM側は「場×コース×SUM区間」の3連対率 - 「場×コース全体」の3連対率
- 選手SUM側は「選手×コース×現在4区分」の3連対率 - 「選手×コース全体」の3連対率
- 選手SUMは現行Webと同じく、現在帯 N<5 は参考外として4サインから除外
- 場SUM / 選手SUMとも対象レース自身と未来レースを履歴に入れない
- raw率だけだとコース構成差の影響を受けるため、同期間の場×コース基準で期待率とliftも出す

Usage:
  python3 analysis/analyze_sam_cross_sign_effect.py 2026-03-13 2026-09-12
  python3 analysis/analyze_sam_cross_sign_effect.py 2025-09-13 2026-09-12
  python3 analysis/analyze_sam_cross_sign_effect.py 2026-03-13 2026-09-12 TMG

第3引数を省略すると全場。場指定でも、選手SUM履歴は他場を含めた全履歴を使う。
"""

from __future__ import annotations

from collections import defaultdict
from dataclasses import dataclass, field
from datetime import date, datetime
import sys

from slit_validate_v2 import connect_db


SIGN_ORDER = ["UP_UP", "DOWN_UP", "UP_DOWN", "DOWN_DOWN"]
SIGN_LABELS = {
    "UP_UP": "◎ 場↑ 選↑",
    "DOWN_UP": "⚠ 場↓ 選↑",
    "UP_DOWN": "⚠ 場↑ 選↓",
    "DOWN_DOWN": "▼ 場↓ 選↓",
}

INTERVAL_TO_BAND = {
    "0.4-0.6": "plus_04",
    "0.6以上": "plus_04",
    "0.0-0.2": "zero_plus_04",
    "0.2-0.4": "zero_plus_04",
    "-0.4--0.2": "minus_04_zero",
    "-0.2-0.0": "minus_04_zero",
    "-0.6未満": "under_minus_04",
    "-0.6--0.4": "under_minus_04",
}


@dataclass
class Counts:
    n: int = 0
    win: int = 0
    place2: int = 0
    place3: int = 0
    trio: int = 0

    def add_rank(self, rank: float) -> None:
        self.n += 1
        if rank == 1.0:
            self.win += 1
        if rank == 2.0:
            self.place2 += 1
        if rank == 3.0:
            self.place3 += 1
        if rank <= 3.0:
            self.trio += 1

    def rate(self, metric: str) -> float | None:
        if self.n <= 0:
            return None
        return getattr(self, metric) / self.n


@dataclass
class SignBucket:
    counts: Counts = field(default_factory=Counts)
    mix: dict[tuple[str, int], int] = field(default_factory=lambda: defaultdict(int))
    by_course: dict[int, Counts] = field(default_factory=lambda: defaultdict(Counts))

    def add(self, place: str, course: int, rank: float) -> None:
        self.counts.add_rank(rank)
        self.mix[(place, course)] += 1
        self.by_course[course].add_rank(rank)


def parse_date(raw: str) -> date:
    try:
        return datetime.strptime(raw, "%Y-%m-%d").date()
    except ValueError as exc:
        raise SystemExit(f"日付は YYYY-MM-DD 形式で指定してください: {raw}") from exc


def normalize_rank(raw) -> tuple[float | None, bool]:
    """(評価用rank, PlayerSam履歴で母数に入れるか)"""
    if raw is None or raw == "":
        return 5.5, False
    text = str(raw).strip()
    if text.isdigit():
        value = int(text)
        if 1 <= value <= 6:
            return float(value), True
    return None, False


def classify_sign(venue_diff: float, player_diff: float) -> str | None:
    if venue_diff > 0 and player_diff > 0:
        return "UP_UP"
    if venue_diff < 0 and player_diff > 0:
        return "DOWN_UP"
    if venue_diff > 0 and player_diff < 0:
        return "UP_DOWN"
    if venue_diff < 0 and player_diff < 0:
        return "DOWN_DOWN"
    return None


def rate(counts: Counts, metric: str) -> float:
    value = counts.rate(metric)
    return 0.0 if value is None else value


def expected_rate(bucket: SignBucket, baselines: dict[tuple[str, int], Counts], metric: str) -> float:
    if bucket.counts.n <= 0:
        return 0.0
    weighted = 0.0
    used = 0
    for key, n in bucket.mix.items():
        base = baselines.get(key)
        if base is None or base.n <= 0:
            continue
        weighted += n * rate(base, metric)
        used += n
    return weighted / used if used > 0 else 0.0


def fmt_pct(v: float) -> str:
    return f"{v * 100:6.2f}%"


def fmt_pt(v: float) -> str:
    return f"{v * 100:+6.2f}pt"


def fetch_rows(conn, to_date: date):
    sql = """
        SELECT
            f.race_code,
            f.race_date,
            f.place_code,
            f.course,
            f.interval_label,
            ex.player_id,
            rd.rank
        FROM boat_race.sum_history_fact f
        LEFT JOIN LATERAL (
            SELECT el.player_id::text AS player_id
            FROM boat_race.exhibition_live el
            WHERE el.race_code = f.race_code
              AND el.entry_course = f.course
            ORDER BY el.created_date DESC NULLS LAST
            LIMIT 1
        ) ex ON TRUE
        LEFT JOIN LATERAL (
            SELECT rrd.rank
            FROM boat_race.race_result_detail rrd
            WHERE rrd.race_code = f.race_code
              AND ex.player_id IS NOT NULL
              AND rrd.player_id::text = ex.player_id
            LIMIT 1
        ) rd ON TRUE
        WHERE f.race_date <= %s
        ORDER BY f.race_date, f.race_code, f.course
    """
    cur = conn.cursor(name="sam_cross_sign_effect")
    cur.itersize = 10000
    cur.execute(sql, (to_date,))
    return cur


def main() -> int:
    if len(sys.argv) < 3:
        print(__doc__.strip())
        return 1

    from_date = parse_date(sys.argv[1])
    to_date = parse_date(sys.argv[2])
    if from_date > to_date:
        raise SystemExit("開始日は終了日以前にしてください。")

    place_filter = (sys.argv[3].strip().upper() if len(sys.argv) >= 4 else "")
    if place_filter and (len(place_filter) != 3 or not place_filter.isalnum()):
        raise SystemExit("場コードは TMG のような3文字で指定してください。")

    # point-in-time 履歴
    venue_base: dict[tuple[str, int], Counts] = defaultdict(Counts)
    venue_interval: dict[tuple[str, int, str], Counts] = defaultdict(Counts)
    player_base: dict[tuple[str, int], Counts] = defaultdict(Counts)
    player_band: dict[tuple[str, int, str], Counts] = defaultdict(Counts)

    # 対象期間の集計
    sign_buckets = {key: SignBucket() for key in SIGN_ORDER}
    target_baseline: dict[tuple[str, int], Counts] = defaultdict(Counts)
    counters = defaultdict(int)

    print("=" * 112)
    print("場SUM × 選手SUM サイン影響 前方検証")
    print("=" * 112)
    print(f"対象期間   : {from_date} ～ {to_date}")
    print(f"場条件     : {place_filter if place_filter else '全場'}")
    print("場SUM判定  : 対象レースより前の 場×コース×SUM区間 3連対率 - 場×コース基準")
    print("選手SUM判定: 対象レースより前の 選手×コース×4区分 3連対率 - 選手×コース基準")
    print("参考外     : 選手現在帯 N<5（現行Webと同じ）")
    print("影響量     : 同期間の場×コース構成で補正した期待率との差(lift)")
    print("-" * 112)

    with connect_db() as conn:
        cur = fetch_rows(conn, to_date)
        current_code = None
        race_rows = []

        def process_race(rows: list[tuple]) -> None:
            if not rows:
                return

            race_date = rows[0][1]
            place = str(rows[0][2])
            is_target_race = from_date <= race_date <= to_date and (not place_filter or place == place_filter)

            # まず対象判定。履歴追加はこのレース全艇の判定後に行う。
            if is_target_race:
                counters["target_races"] += 1
                for race_code, _, place_code, course_raw, interval_raw, player_id_raw, rank_raw in rows:
                    course = int(course_raw)
                    interval = str(interval_raw or "")
                    player_id = str(player_id_raw or "").strip()
                    actual_rank, _ = normalize_rank(rank_raw)

                    if actual_rank is None or not interval or course < 1 or course > 6:
                        counters["target_invalid"] += 1
                        continue

                    counters["target_boats"] += 1
                    target_baseline[(place_code, course)].add_rank(actual_rank)

                    if not player_id:
                        counters["player_missing"] += 1
                        continue

                    band = INTERVAL_TO_BAND.get(interval)
                    if band is None:
                        counters["band_unknown"] += 1
                        continue

                    vb = venue_base[(place_code, course)]
                    vi = venue_interval[(place_code, course, interval)]
                    if vb.n <= 0 or vi.n <= 0:
                        counters["venue_no_history"] += 1
                        continue
                    venue_diff = rate(vi, "trio") - rate(vb, "trio")

                    pb = player_base[(player_id, course)]
                    pi = player_band[(player_id, course, band)]
                    if pi.n < 5 or pb.n <= 0:
                        counters["player_ref_out"] += 1
                        continue
                    player_diff = rate(pi, "trio") - rate(pb, "trio")

                    sign = classify_sign(venue_diff, player_diff)
                    if sign is None:
                        counters["neutral"] += 1
                        continue

                    sign_buckets[sign].add(place_code, course, actual_rank)
                    counters[sign] += 1

            # その後で履歴へ追加。対象レース自身を自分の判定には使わない。
            for _, _, place_code, course_raw, interval_raw, player_id_raw, rank_raw in rows:
                course = int(course_raw)
                interval = str(interval_raw or "")
                player_id = str(player_id_raw or "").strip()
                actual_rank, player_history_valid = normalize_rank(rank_raw)
                if actual_rank is None or not interval or course < 1 or course > 6:
                    continue

                venue_base[(place_code, course)].add_rank(actual_rank)
                venue_interval[(place_code, course, interval)].add_rank(actual_rank)

                if player_id and player_history_valid:
                    band = INTERVAL_TO_BAND.get(interval)
                    if band is not None:
                        player_base[(player_id, course)].add_rank(actual_rank)
                        player_band[(player_id, course, band)].add_rank(actual_rank)

        try:
            for row in cur:
                code = str(row[0])
                if current_code is None:
                    current_code = code
                if code != current_code:
                    process_race(race_rows)
                    race_rows = []
                    current_code = code
                race_rows.append(row)
            process_race(race_rows)
        finally:
            cur.close()

    print("\n【対象・除外】")
    print(f"対象レース         : {counters['target_races']}")
    print(f"対象艇             : {counters['target_boats']}")
    print(f"選手SUM参考外 N<5 : {counters['player_ref_out']}")
    print(f"場SUM履歴不足      : {counters['venue_no_history']}")
    print(f"中立(0差含む)      : {counters['neutral']}")
    if counters["target_invalid"] or counters["player_missing"] or counters["band_unknown"]:
        print(
            "その他除外         : "
            f"target_invalid={counters['target_invalid']} / "
            f"player_missing={counters['player_missing']} / "
            f"band_unknown={counters['band_unknown']}"
        )

    print("\n" + "=" * 112)
    print("【4サイン 実着順率 + 場×コース補正lift】")
    print("=" * 112)
    print(
        f"{'サイン':<18} {'N':>7}  "
        f"{'1着率':>9} {'1着lift':>10}  "
        f"{'2着率':>9} {'2着lift':>10}  "
        f"{'3着率':>9} {'3着lift':>10}  "
        f"{'3連対率':>9} {'3連lift':>10}"
    )
    print("-" * 112)

    for key in SIGN_ORDER:
        bucket = sign_buckets[key]
        n = bucket.counts.n
        values = {}
        for metric in ("win", "place2", "place3", "trio"):
            actual = rate(bucket.counts, metric)
            expected = expected_rate(bucket, target_baseline, metric)
            values[metric] = (actual, actual - expected)

        print(
            f"{SIGN_LABELS[key]:<18} {n:7d}  "
            f"{fmt_pct(values['win'][0]):>9} {fmt_pt(values['win'][1]):>10}  "
            f"{fmt_pct(values['place2'][0]):>9} {fmt_pt(values['place2'][1]):>10}  "
            f"{fmt_pct(values['place3'][0]):>9} {fmt_pt(values['place3'][1]):>10}  "
            f"{fmt_pct(values['trio'][0]):>9} {fmt_pt(values['trio'][1]):>10}"
        )

    print("\n※ lift は『このサインに1Cが多い/少ない』などの構成差をできるだけ除いた値。")
    print("※ まず見るなら 1着lift / 2着lift / 3連lift。raw率だけで強弱を決めない。")

    print("\n" + "=" * 112)
    print("【コース別】")
    print("=" * 112)
    for key in SIGN_ORDER:
        bucket = sign_buckets[key]
        print(f"\n{SIGN_LABELS[key]}")
        print(f"{'C':>2} {'N':>7} {'1着率':>9} {'2着率':>9} {'3着率':>9} {'3連対率':>9}")
        print("-" * 52)
        for course in range(1, 7):
            c = bucket.by_course.get(course, Counts())
            if c.n <= 0:
                continue
            print(
                f"{course:>2} {c.n:7d} "
                f"{fmt_pct(rate(c, 'win')):>9} "
                f"{fmt_pct(rate(c, 'place2')):>9} "
                f"{fmt_pct(rate(c, 'place3')):>9} "
                f"{fmt_pct(rate(c, 'trio')):>9}"
            )

    print("\n" + "=" * 112)
    print("見方")
    print("=" * 112)
    print("◎ 場↑ 選↑ で3連liftが明確にプラス → 場傾向と選手特性の同方向一致が実戦材料になりやすい")
    print("⚠ 場↓ 選↑ で選手側が持ち上げる → 場SUMだけで軽視すると取りこぼし候補")
    print("⚠ 場↑ 選↓ で落ちる → 場SUM好条件でもその選手では過信注意")
    print("▼ 場↓ 選↓ で明確にマイナス → 両方逆風の弱化サイン候補")
    print("ただし、採用判断はNと別期間再現を確認してから。")
    print("=" * 112)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
