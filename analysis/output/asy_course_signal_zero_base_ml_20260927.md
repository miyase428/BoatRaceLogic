# 芦屋 全コースサイン ゼロベースML監査

- 対象期間: 2023-09-28～2026-09-27
- 学習: ～2025-09-27 / 検証: ～2026-03-27 / 最終テスト: それ以降
- 使用レース: 5,928R
- 現行サインは比較対象のみ。モデル特徴量には不使用。
- ML選択数は、原則として現行サインと同程度のカバー率へ検証期間で固定。6Cは20%。

## 1コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|59.61%|0 / -|253 / 69.57%|217 / 70.97%|0 / -|
|top2|74.98%|0 / -|312 / 83.33%|317 / 84.54%|0 / -|
|top3|83.06%|0 / -|243 / 91.36%|258 / 89.15%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）
- top2: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）
- top3: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）

展示後MLの主な特徴:

- first: c1_nige、c2_national_win_rate、c4_national_exacta_rate、c5_straight_time_relative、c4_straight_time_relative
- top2: c1_nige、c1_history_n、c1_national_win_rate、c5_around_time_relative、c1_exhibition_time_relative
- top3: c1_national_win_rate、c5_national_exacta_rate、c4_second_rank、c5_straight_time_relative、c3_second_rank

## 2コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|10.01%|123 / 22.76%|132 / 30.30%|131 / 25.95%|51 / 41.18%|
|top2|35.12%|123 / 50.41%|152 / 55.26%|148 / 56.76%|75 / 62.67%|
|top3|55.49%|123 / 74.80%|146 / 78.08%|134 / 77.61%|57 / 89.47%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 7.54%（改善確率 96.08%） / 展示後 3.19%（改善確率 77.38%）
- top2: 展示前 4.86%（改善確率 83.56%） / 展示後 6.35%（改善確率 89.86%）
- top3: 展示前 3.29%（改善確率 75.94%） / 展示後 2.82%（改善確率 72.06%）

展示後MLの主な特徴:

- first: c1_nige、c2_exhibition_time_relative、c2_final_2nd_score、c2_national_win_rate、c6_around_time_relative
- top2: c2_national_win_rate、c1_national_win_rate、c4_national_exacta_rate、c5_average_start、c2_straight_time_relative
- top3: c2_national_win_rate、c3_makuri、c2_boat_exacta_rate、c2_history_n、c2_exhibition_time_relative

## 3コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|10.89%|390 / 15.64%|372 / 16.67%|357 / 18.21%|181 / 20.99%|
|top2|31.96%|390 / 41.28%|378 / 44.71%|388 / 44.33%|195 / 50.26%|
|top3|49.87%|390 / 58.46%|385 / 66.49%|409 / 65.04%|199 / 68.84%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 1.03%（改善確率 73.22%） / 展示後 2.57%（改善確率 91.50%）
- top2: 展示前 3.43%（改善確率 93.08%） / 展示後 3.05%（改善確率 90.14%）
- top3: 展示前 8.03%（改善確率 99.96%） / 展示後 6.58%（改善確率 99.72%）

展示後MLの主な特徴:

- first: c1_nige、c3_national_win_rate、c1_exhibition_time_relative、c3_exhibition_time_relative、c1_around_time_relative
- top2: c3_national_win_rate、c1_national_win_rate、c6_national_exacta_rate、c6_start_timing_relative、c3_second_rank
- top3: c3_national_win_rate、c1_national_win_rate、c3_second_rank、c3_motor_exacta_rate、c2_around_time_relative

## 4コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|10.71%|77 / 20.78%|66 / 24.24%|71 / 19.72%|25 / 28.00%|
|top2|28.62%|77 / 38.96%|86 / 53.49%|69 / 57.97%|42 / 50.00%|
|top3|47.59%|77 / 61.04%|79 / 77.22%|75 / 85.33%|35 / 62.86%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 3.46%（改善確率 74.06%） / 展示後 -1.06%（改善確率 41.70%）
- top2: 展示前 14.53%（改善確率 98.38%） / 展示後 19.01%（改善確率 99.56%）
- top3: 展示前 16.18%（改善確率 99.34%） / 展示後 24.29%（改善確率 99.98%）

展示後MLの主な特徴:

- first: c3_national_exacta_rate、c4_lap_time_relative、c2_around_time_relative、c5_national_win_rate、c6_start_timing_relative
- top2: c4_national_win_rate、c4_national_exacta_rate、c4_makuri、c2_sashi、c2_st_rank
- top3: c4_national_win_rate、c3_national_win_rate、c4_exhibition_time_relative、c5_national_win_rate、c1_local_win_rate

## 5コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|6.67%|0 / -|276 / 9.78%|268 / 9.70%|0 / -|
|top2|20.63%|0 / -|222 / 28.83%|237 / 32.91%|0 / -|
|top3|40.91%|0 / -|230 / 57.83%|223 / 60.09%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）
- top2: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）
- top3: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）

展示後MLの主な特徴:

- first: c1_lap_time_relative、c5_st_rank、c4_sashi、c5_makuri、c1_history_n
- top2: c5_national_win_rate、c5_exhibition_time_relative、c5_start_timing_relative、c5_attack、c6_national_exacta_rate
- top3: c2_national_win_rate、c5_national_win_rate、c6_national_win_rate、c4_national_win_rate、c6_start_timing_relative

## 6コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|2.11%|0 / -|254 / 2.76%|211 / 4.74%|0 / -|
|top2|8.69%|0 / -|234 / 15.81%|234 / 15.81%|0 / -|
|top3|22.91%|0 / -|243 / 37.04%|207 / 36.71%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）
- top2: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）
- top3: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）

展示後MLの主な特徴:

- first: c2_ex_score、c4_lap_time_relative、c6_lap_time_relative、c6_history_n、c6_local_exacta_rate
- top2: c6_national_win_rate、c4_straight_time_relative、c6_history_n、c2_national_win_rate、c3_around_time_relative
- top3: c6_national_win_rate、c6_national_exacta_rate、c6_history_n、c4_straight_time_relative、c3_around_time_relative

## 判定上の注意

- これは条件見直し前の探索監査であり、本番サインは変更しない。
- 最終テストで良くても、月別安定性・ブートストラップ・回収率を通すまでは採用しない。
- 次段階で、MLが示した特徴を人が読める少数条件へ戻し、現行条件と再比較する。
