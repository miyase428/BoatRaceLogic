# 芦屋 全コースサイン ゼロベースML監査（point-in-time版）

- 対象期間: 2023-09-28～2026-09-27
- 学習: ～2025-09-27 / 検証: ～2026-03-27 / 最終テスト: それ以降
- 使用レース: 5,916R
- 現行サインは比較対象のみ。モデル特徴量には不使用。
- 展示タイム場平均は対象日を除く同場直近183日だけで算出。
- ML選択数は、原則として現行サインと同程度のカバー率へ検証期間で固定。6Cは20%。
- 月block比較は現行サインありなら現行、なしなら全体基礎率を比較対象とする。

## 1コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|59.61%|0 / -|249 / 69.88%|213 / 69.95%|0 / -|
|top2|74.98%|0 / -|283 / 85.87%|320 / 84.38%|0 / -|
|top3|83.06%|0 / -|240 / 91.67%|303 / 87.79%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）
- top2: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）
- top3: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 100.00% / 安定 （改善月 6/6）
- top2: 月block改善確率 100.00% / 安定 （改善月 7/7）
- top3: 月block改善確率 100.00% / 安定 （改善月 5/7）

展示後MLの主な特徴:

- first: c1_nige、c2_national_win_rate、c4_national_exacta_rate、c6_national_win_rate、c4_straight_time_relative
- top2: c1_nige、c1_exhibition_time_relative、c1_national_win_rate、c1_history_n、c2_history_n
- top3: c1_nige、c1_history_n、c1_national_win_rate、c3_national_exacta_rate、c3_start_timing_relative

## 2コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|10.01%|123 / 22.76%|135 / 29.63%|129 / 31.01%|59 / 33.90%|
|top2|35.12%|123 / 50.41%|152 / 54.61%|140 / 56.43%|65 / 66.15%|
|top3|55.49%|123 / 74.80%|145 / 77.93%|142 / 76.06%|61 / 85.25%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 6.87%（改善確率 95.24%） / 展示後 8.24%（改善確率 97.56%）
- top2: 展示前 4.20%（改善確率 80.24%） / 展示後 6.02%（改善確率 88.12%）
- top3: 展示前 3.13%（改善確率 74.82%） / 展示後 1.26%（改善確率 60.38%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 95.68% / 安定 （改善月 5/6）
- top2: 月block改善確率 78.00% / 安定 （改善月 5/6）
- top3: 月block改善確率 58.72% / やや不安定 （改善月 3/6）

展示後MLの主な特徴:

- first: c1_nige、c2_exhibition_time_relative、c2_makuri、c2_national_win_rate、c6_around_time_relative
- top2: c2_national_win_rate、c2_straight_time_relative、c2_exhibition_time_relative、c2_local_win_rate、c4_local_exacta_rate
- top3: c2_national_win_rate、c4_national_win_rate、c4_local_exacta_rate、c2_straight_time_relative、c3_local_exacta_rate

## 3コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|10.89%|390 / 15.64%|363 / 17.36%|374 / 18.45%|192 / 21.88%|
|top2|31.96%|390 / 41.28%|373 / 45.31%|384 / 43.75%|192 / 48.44%|
|top3|49.87%|390 / 58.46%|389 / 66.58%|407 / 64.62%|202 / 67.33%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 1.71%（改善確率 84.90%） / 展示後 2.81%（改善確率 94.64%）
- top2: 展示前 4.03%（改善確率 95.98%） / 展示後 2.47%（改善確率 85.36%）
- top3: 展示前 8.12%（改善確率 99.96%） / 展示後 6.16%（改善確率 99.52%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 93.26% / 安定 （改善月 5/7）
- top2: 月block改善確率 85.38% / やや不安定 （改善月 4/7）
- top3: 月block改善確率 95.30% / 安定 （改善月 6/7）

展示後MLの主な特徴:

- first: c1_nige、c3_national_win_rate、c3_exhibition_time_relative、c3_straight_time_relative、c3_final_2nd_score
- top2: c3_national_win_rate、c1_national_win_rate、c6_national_exacta_rate、c6_start_timing_relative、c3_exhibition_time_relative
- top3: c3_national_win_rate、c3_second_rank、c3_motor_exacta_rate、c1_national_win_rate、c2_around_time_relative

## 4コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|10.71%|77 / 20.78%|66 / 21.21%|65 / 21.54%|23 / 26.09%|
|top2|28.62%|77 / 38.96%|70 / 50.00%|67 / 56.72%|37 / 48.65%|
|top3|47.59%|77 / 61.04%|77 / 77.92%|85 / 80.00%|29 / 65.52%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 0.43%（改善確率 52.68%） / 展示後 0.76%（改善確率 54.26%）
- top2: 展示前 11.04%（改善確率 93.94%） / 展示後 17.76%（改善確率 99.34%）
- top3: 展示前 16.88%（改善確率 99.54%） / 展示後 18.96%（改善確率 99.84%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 60.36% / やや不安定 （改善月 3/6）
- top2: 月block改善確率 100.00% / やや不安定 （改善月 4/6）
- top3: 月block改善確率 100.00% / 安定 （改善月 5/6）

展示後MLの主な特徴:

- first: c4_lap_time_relative、c3_national_exacta_rate、c2_around_time_relative、c5_national_win_rate、c6_start_timing_relative
- top2: c4_national_win_rate、c4_national_exacta_rate、c4_makuri、c2_sashi、c3_local_exacta_rate
- top3: c4_national_win_rate、c4_exhibition_time_relative、c3_national_win_rate、c4_local_exacta_rate、c5_national_win_rate

## 5コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|6.67%|0 / -|274 / 9.85%|280 / 10.00%|0 / -|
|top2|20.63%|0 / -|217 / 28.57%|237 / 30.80%|0 / -|
|top3|40.91%|0 / -|229 / 58.08%|211 / 60.19%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）
- top2: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）
- top3: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 98.00% / 安定 （改善月 5/7）
- top2: 月block改善確率 98.24% / 安定 （改善月 5/7）
- top3: 月block改善確率 100.00% / 安定 （改善月 7/7）

展示後MLの主な特徴:

- first: c1_lap_time_relative、c5_national_win_rate、c5_st_rank、c3_around_time_relative、c4_sashi
- top2: c5_national_win_rate、c5_exhibition_time_relative、c5_attack、c3_st_rank、c4_motor_exacta_rate
- top3: c2_national_win_rate、c5_national_win_rate、c6_national_win_rate、c4_national_win_rate、c6_start_timing_relative

## 6コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|2.11%|0 / -|256 / 2.73%|205 / 4.88%|0 / -|
|top2|8.69%|0 / -|233 / 15.45%|226 / 15.04%|0 / -|
|top3|22.91%|0 / -|242 / 36.78%|224 / 40.18%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）
- top2: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）
- top3: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 99.42% / 安定 （改善月 5/7）
- top2: 月block改善確率 98.74% / やや不安定 （改善月 4/7）
- top3: 月block改善確率 100.00% / 安定 （改善月 7/7）

展示後MLの主な特徴:

- first: c6_lap_time_relative、c4_lap_time_relative、c3_national_exacta_rate、c6_history_n、c6_local_exacta_rate
- top2: c6_national_win_rate、c4_straight_time_relative、c6_history_n、c2_national_win_rate、c6_ex_score
- top3: c6_national_win_rate、c6_exhibition_time_relative、c6_history_n、c4_makuri、c4_st_rank

## 判定上の注意

- これは条件見直し前の探索監査であり、本番サインは変更しない。
- 最終テストで良くても、月別安定性・ブートストラップ・回収率を通すまでは採用しない。
- 次段階で、MLが示した特徴を人が読める少数条件へ戻し、現行条件と再比較する。
