# classify_slit_pattern.py

def extract_features(st_list, venue_settings):
    """
    STから特徴量を抽出する
    """

    avg_st = sum(st_list) / len(st_list)
    st_diff = [st - avg_st for st in st_list]
    order = sorted(range(len(st_list)), key=lambda i: st_list[i])
    spread = max(st_list) - min(st_list)

    features = {

        # 基本情報
        "avg_st": avg_st,
        "spread": spread,
        "st_diff": st_diff,
        "order": order,

        # レーン
        "fastest_lane": order[0] + 1,
        "slowest_lane": order[-1] + 1,

        # 新しい特徴量
        "inside_fast": False,
        "outside_fast": False,
        "wall_none": False,
        "middle_attack": False,
        "dash_fast": False,
        "inside_late": False,
        "line_abreast": False,
        "two_three_late": False,
        "middle_hollow": False,
        "middle_bulge": False,
        "one_two_fast": False,
        "outside_attack": False
    }

    delay_th = venue_settings["big_delay_threshold"]

    # 1号艇が遅れる
    if st_diff[0] >= delay_th:
        features["inside_late"] = True

    # 2・3号艇が遅れる
    if st_diff[1] >= delay_th and st_diff[2] >= delay_th:
        features["two_three_late"] = True

    # 3・4号艇が遅れる（中凹み）
    if st_diff[2] >= delay_th and st_diff[3] >= delay_th:
        features["middle_hollow"] = True

    # 3・4号艇が最も早い
    if set(order[:2]) == {2, 3}:
        features["middle_bulge"] = True

    # 1・2号艇が先行
    if order[0] == 0 and order[1] == 1:
        features["one_two_fast"] = True

    # 外側2艇が先行
    if order[0] in {3,4,5} and order[1] in {3,4,5}:
        features["outside_attack"] = True

    # ==========================================
    # 特徴量判定
    # ==========================================
    # 横一線
    if spread <= 0.05:
        features["line_abreast"] = True

    # スロー勢（1～3号艇）が上位3艇
    if set(order[:3]).issubset({0, 1, 2}):
        features["inside_fast"] = True

    # ダッシュ勢（4～6号艇）が上位3艇
    if set(order[:3]).issubset({3, 4, 5}):
        features["dash_fast"] = True
        features["outside_fast"] = True

    # 2号艇が遅れて壁にならない
    if st_diff[1] >= delay_th:
        features["wall_none"] = True

    # 3号艇が最も早い
    if order[0] == 2:
        features["middle_attack"] = True

    return features


def decide_pattern(features):

    # ---------------------------------------------------------
    # 12. ダッシュ先行
    # （4～6号艇が上位3艇）
    # ---------------------------------------------------------
    if features["dash_fast"]:
        return 12

    # ---------------------------------------------------------
    # 4. スロー先行
    # （1～3号艇が上位3艇）
    # ---------------------------------------------------------
    if features["inside_fast"]:
        return 4

    # ---------------------------------------------------------
    # 11. 外側先行
    # ---------------------------------------------------------
    if features["outside_attack"]:
        return 11

    # ---------------------------------------------------------
    # 6. 2・3遅れ
    # ---------------------------------------------------------
    if features["two_three_late"]:
        return 6

    # ---------------------------------------------------------
    # 7. 中凹み
    # ---------------------------------------------------------
    if features["middle_hollow"]:
        return 7

    # ---------------------------------------------------------
    # 5. カベなし
    # ---------------------------------------------------------
    if features["wall_none"]:
        return 5

    # ---------------------------------------------------------
    # 9. 中ぶくれ
    # ---------------------------------------------------------
    if features["middle_bulge"]:
        return 9

    # ---------------------------------------------------------
    # 8. 3の先攻め
    # ---------------------------------------------------------
    if features["middle_attack"]:
        return 8

    # ---------------------------------------------------------
    # 3. 1・2先行
    # ---------------------------------------------------------
    if features["one_two_fast"]:
        return 3

    # ---------------------------------------------------------
    # 10. 1が遅れる
    # ---------------------------------------------------------
    if features["inside_late"]:
        return 10

    # ---------------------------------------------------------
    # 2. 横一線
    # ---------------------------------------------------------
    if features["line_abreast"]:
        return 2

    # ---------------------------------------------------------
    # 1. 内側先行
    # ---------------------------------------------------------
    return 1

def classify_slit_pattern(st_list, venue_settings):
    """
    PatternIDと特徴量を返す
    """

    features = extract_features(st_list, venue_settings)
    pattern_id = decide_pattern(features)

    return pattern_id, features