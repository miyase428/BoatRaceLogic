# forecast.py
# ---------------------------------------------
# baseline のみで最終スコアを返す最小構成
# ---------------------------------------------

from utils.fetch_input import fetch_input_data
from baseline import calc_baseline
import json
import sys


def forecast_baseline_only(race_code: str):
    """
    baseline のみで順位を返す最小構成
    """

    # Raspberry Pi から entries[] を取得
    entries = fetch_input_data(race_code)

    results = []

    for entry in entries:
        lane = entry["lane_number"]
        player = entry["player_name"]

        # baseline 計算
        baseline_score = calc_baseline(entry)

        results.append({
            "lane": lane,
            "player": player,
            "baseline": baseline_score
        })

    # baseline の降順で並べる
    results_sorted = sorted(results, key=lambda x: x["baseline"], reverse=True)

    return results_sorted


if __name__ == "__main__":
    # コマンドライン引数から race_code を受け取る
    if len(sys.argv) < 2:
        print(json.dumps({"error": "race_code is required"}))
        sys.exit(1)

    race_code = sys.argv[1]

    results = forecast_baseline_only(race_code)

    print(json.dumps({
        "race_code": race_code,
        "results": results
    }, ensure_ascii=False, indent=2))
