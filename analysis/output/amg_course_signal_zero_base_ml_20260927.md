# 尼崎 全コースサイン ゼロベースML監査

- 対象期間: 2023-09-28～2026-09-27
- 学習: ～2025-09-27 / 検証: ～2026-03-27 / 最終テスト: それ以降
- 使用レース: 5,929R
- 現行サインは比較対象のみ。モデル特徴量には不使用。
- ML選択数は、原則として現行サインと同程度のカバー率へ検証期間で固定。6Cは20%。

## 1コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|59.77%|500 / 74.60%|533 / 75.05%|511 / 76.32%|270 / 77.04%|
|top2|76.96%|500 / 86.40%|566 / 87.28%|585 / 85.98%|293 / 88.74%|
|top3|84.97%|500 / 92.20%|557 / 91.92%|586 / 91.30%|278 / 93.88%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 0.45%（改善確率 62.78%） / 展示後 1.72%（改善確率 87.66%）
- top2: 展示前 0.88%（改善確率 78.80%） / 展示後 -0.42%（改善確率 36.52%）
- top3: 展示前 -0.28%（改善確率 40.44%） / 展示後 -0.90%（改善確率 21.64%）

展示後MLの主な特徴:

- first: c1_nige、c2_national_exacta_rate、c3_national_win_rate、c3_national_exacta_rate、c2_around_time_relative
- top2: c1_nige、c4_attack、c1_history_n、c5_national_exacta_rate、c5_start_timing_relative
- top3: c1_national_exacta_rate、c2_national_exacta_rate、c3_lap_time_relative、c3_national_win_rate、c5_lap_time_relative

## 2コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|13.44%|282 / 21.63%|324 / 29.01%|319 / 26.96%|153 / 30.07%|
|top2|37.15%|282 / 51.77%|371 / 57.95%|305 / 58.03%|170 / 61.18%|
|top3|55.93%|282 / 71.28%|365 / 76.99%|359 / 75.77%|173 / 79.77%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 7.38%（改善確率 99.94%） / 展示後 5.33%（改善確率 98.84%）
- top2: 展示前 6.18%（改善確率 98.40%） / 展示後 6.26%（改善確率 97.84%）
- top3: 展示前 5.71%（改善確率 98.20%） / 展示後 4.49%（改善確率 94.00%）

展示後MLの主な特徴:

- first: c2_national_win_rate、c1_nige、c2_motor_exacta_rate、c2_exhibition_time_relative、c2_local_win_rate
- top2: c2_national_win_rate、c2_national_exacta_rate、c2_lap_time_relative、c2_local_win_rate、c2_exhibition_time_relative
- top3: c2_national_win_rate、c2_exhibition_time_relative、c2_local_win_rate、c2_lap_time_relative、c6_national_win_rate

## 3コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|11.52%|182 / 21.98%|258 / 27.91%|241 / 28.63%|114 / 30.70%|
|top2|32.05%|182 / 45.05%|270 / 57.78%|225 / 58.67%|106 / 51.89%|
|top3|52.59%|182 / 62.64%|281 / 77.22%|273 / 76.56%|106 / 70.75%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 5.93%（改善確率 97.34%） / 展示後 6.65%（改善確率 98.44%）
- top2: 展示前 12.72%（改善確率 99.98%） / 展示後 13.61%（改善確率 99.98%）
- top3: 展示前 14.59%（改善確率 100.00%） / 展示後 13.92%（改善確率 100.00%）

展示後MLの主な特徴:

- first: c3_national_win_rate、c3_motor_exacta_rate、c3_exhibition_time_relative、c3_national_exacta_rate、c1_national_win_rate
- top2: c3_national_win_rate、c3_exhibition_time_relative、c3_local_win_rate、c3_national_exacta_rate、c3_motor_exacta_rate
- top3: c3_national_win_rate、c6_lap_time_relative、c6_national_win_rate、c4_national_win_rate、c5_national_win_rate

## 4コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|9.43%|94 / 17.02%|128 / 22.66%|129 / 27.13%|47 / 27.66%|
|top2|28.05%|94 / 41.49%|132 / 55.30%|162 / 53.09%|50 / 54.00%|
|top3|50.08%|94 / 62.77%|123 / 86.18%|140 / 85.00%|54 / 72.22%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 5.63%（改善確率 91.26%） / 展示後 10.11%（改善確率 98.84%）
- top2: 展示前 13.81%（改善確率 98.96%） / 展示後 11.60%（改善確率 97.84%）
- top3: 展示前 23.41%（改善確率 100.00%） / 展示後 22.23%（改善確率 100.00%）

展示後MLの主な特徴:

- first: c1_ex_score、c6_lap_time_relative、c2_national_exacta_rate、c4_ex_score、c6_local_exacta_rate
- top2: c4_national_win_rate、c4_exhibition_time_relative、c3_national_win_rate、c4_national_exacta_rate、c4_lap_time_relative
- top3: c4_national_win_rate、c2_national_win_rate、c6_lap_time_relative、c4_national_exacta_rate、c5_lap_time_relative

## 5コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|4.84%|340 / 8.24%|350 / 10.00%|324 / 8.95%|185 / 12.43%|
|top2|18.11%|340 / 26.18%|357 / 29.97%|331 / 31.42%|192 / 34.90%|
|top3|35.89%|340 / 47.06%|365 / 52.60%|358 / 53.07%|184 / 57.07%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 1.76%（改善確率 93.60%） / 展示後 0.72%（改善確率 72.14%）
- top2: 展示前 3.80%（改善確率 96.32%） / 展示後 5.24%（改善確率 99.16%）
- top3: 展示前 5.54%（改善確率 98.32%） / 展示後 6.01%（改善確率 98.68%）

展示後MLの主な特徴:

- first: c5_national_exacta_rate、c5_attack、c5_national_win_rate、c3_national_exacta_rate、c1_motor_exacta_rate
- top2: c6_lap_time_relative、c3_national_win_rate、c5_national_win_rate、c5_exhibition_time_relative、c2_local_win_rate
- top3: c5_national_win_rate、c4_national_win_rate、c3_national_win_rate、c6_local_exacta_rate、c1_national_win_rate

## 6コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|1.00%|0 / -|177 / 1.13%|167 / 2.40%|0 / -|
|top2|7.68%|0 / -|223 / 18.83%|194 / 20.62%|0 / -|
|top3|20.37%|0 / -|240 / 39.17%|236 / 38.56%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）
- top2: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）
- top3: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）

展示後MLの主な特徴:

- first: c1_local_win_rate、c6_makuri、c1_final_2nd_score、c6_national_exacta_rate、c3_gap_to_top
- top2: c6_national_exacta_rate、c6_national_win_rate、c6_exhibition_time_relative、c2_around_time_relative、c4_lap_time_relative
- top3: c6_national_win_rate、c6_national_exacta_rate、c2_national_exacta_rate、c4_national_win_rate、c2_lap_time_relative

## 判定上の注意

- これは条件見直し前の探索監査であり、本番サインは変更しない。
- 最終テストで良くても、月別安定性・ブートストラップ・回収率を通すまでは採用しない。
- 次段階で、MLが示した特徴を人が読める少数条件へ戻し、現行条件と再比較する。
