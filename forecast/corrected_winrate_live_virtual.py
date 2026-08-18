#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""仮想進入用 補正後1着率ラッパー。

通常の exact 系スクリプトは変更しない。
仮想進入モード時だけ、今回レースの展示進入コースを指定値へ差し替え、
基本率リマップ / SUM / スリット補正を同じ仮想進入で計算する。

引数2は「艇番 -> コース」の6桁文字列。
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO_ROOT = HERE.parent
THEORY_DIR = REPO_ROOT / "theories" / "course_correction"
sys.path.insert(0, str(HERE))


def parse_lane_to_course(value: str) -> dict[int, int]:
    value = str(value or "").strip()
    if len(value) != 6 or sorted(value) != list("123456"):
        raise RuntimeError("仮想進入は1～6を1回ずつ使う6桁で指定してください")
    return {lane: int(value[lane - 1]) for lane in range(1, 7)}


def main() -> int:
    if len(sys.argv) != 3:
        print(json.dumps({"status": "error", "error": "Usage: corrected_winrate_live_virtual.py RACE_CODE LANE_TO_COURSE"}, ensure_ascii=False))
        return 1

    race_code = sys.argv[1].strip().upper()
    lane_to_course_text = sys.argv[2].strip()

    try:
        lane_to_course = parse_lane_to_course(lane_to_course_text)
        place_code = race_code[8:11]

        if place_code in {"AMG", "TKY"}:
            import corrected_winrate_live_exact_amg_tky as selected  # noqa: E402
        else:
            import corrected_winrate_live_exact as selected  # noqa: E402

        live = selected.live if hasattr(selected, "live") else selected

        original_load_current_exhibition = live.load_current_exhibition

        def load_virtual_exhibition(conn, target_race_code):
            exhibition = original_load_current_exhibition(conn, target_race_code)
            for lane in range(1, 7):
                if lane not in exhibition:
                    raise RuntimeError(f"{lane}号艇の展示情報がありません")
                exhibition[lane]["course"] = lane_to_course[lane]

            courses = {int(exhibition[lane]["course"]) for lane in range(1, 7)}
            if courses != set(range(1, 7)):
                raise RuntimeError("仮想進入1～6コースが揃っていません")
            return exhibition

        def virtual_slit_prediction_and_buff(target_race_code, target_date):
            predict = live.run_json_script(
                [
                    sys.executable,
                    str(THEORY_DIR / "predict_pattern_virtual.py"),
                    target_race_code,
                    lane_to_course_text,
                ],
                timeout=30,
            )
            pid = int(predict.get("pattern_id", 0))
            if pid not in range(1, 13):
                raise RuntimeError("仮想進入のスリットPIDを取得できません")

            buff_data = live.run_json_script(
                [
                    sys.executable,
                    str(THEORY_DIR / "live_win_buff.py"),
                    target_date.isoformat(),
                ],
                timeout=180,
            )
            buff = buff_data.get("buff", {}).get(str(pid), {})
            if not buff:
                raise RuntimeError(f"PID={pid} のrolling slit buffがありません")
            return predict, buff_data, buff

        live.load_current_exhibition = load_virtual_exhibition
        live.slit_prediction_and_buff = virtual_slit_prediction_and_buff

        # 元main()の引数仕様を維持して呼ぶ。
        sys.argv = [sys.argv[0], race_code]
        return int(live.main())

    except Exception as exc:
        print(json.dumps({"status": "error", "boats": {}, "error": str(exc)}, ensure_ascii=False))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
