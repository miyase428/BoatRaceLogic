# 尼崎 全コースサイン ゼロベースML監査（point-in-time版）

- 対象期間: 2023-09-28～2026-09-27
- 学習: ～2025-09-27 / 検証: ～2026-03-27 / 最終テスト: それ以降
- 使用レース: 5,917R
- 現行サインは比較対象のみ。モデル特徴量には不使用。
- 展示タイム場平均は対象日を除く同場直近183日だけで算出。
- ML選択数は、原則として現行サインと同程度のカバー率へ検証期間で固定。6Cは20%。
- 月block比較は現行サインありなら現行、なしなら全体基礎率を比較対象とする。

## 1コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|59.77%|500 / 74.60%|540 / 74.81%|492 / 76.63%|252 / 78.97%|
|top2|76.96%|500 / 86.40%|551 / 87.30%|571 / 86.34%|314 / 87.26%|
|top3|84.97%|500 / 92.20%|563 / 92.01%|560 / 91.07%|286 / 94.41%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 0.21%（改善確率 55.62%） / 展示後 2.03%（改善確率 91.34%）
- top2: 展示前 0.90%（改善確率 79.76%） / 展示後 -0.06%（改善確率 48.82%）
- top3: 展示前 -0.19%（改善確率 43.42%） / 展示後 -1.13%（改善確率 16.96%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 94.54% / やや不安定 （改善月 4/7）
- top2: 月block改善確率 48.94% / 不安定 （改善月 3/7）
- top3: 月block改善確率 14.00% / 不安定 （改善月 3/7）

展示後MLの主な特徴:

- first: c1_nige、c2_national_exacta_rate、c3_ex_score、c3_national_win_rate、c3_national_exacta_rate
- top2: c1_nige、c4_attack、c5_national_exacta_rate、c4_st_rank、c1_history_n
- top3: c2_national_exacta_rate、c5_lap_time_relative、c1_national_exacta_rate、c3_national_win_rate、c3_lap_time_relative

## 2コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|13.44%|282 / 21.63%|321 / 28.97%|351 / 26.50%|174 / 28.16%|
|top2|37.15%|282 / 51.77%|371 / 57.95%|332 / 59.64%|166 / 61.45%|
|top3|55.93%|282 / 71.28%|362 / 76.80%|357 / 75.35%|172 / 79.65%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 7.34%（改善確率 99.94%） / 展示後 4.86%（改善確率 97.94%）
- top2: 展示前 6.18%（改善確率 98.38%） / 展示後 7.87%（改善確率 99.46%）
- top3: 展示前 5.52%（改善確率 97.68%） / 展示後 4.07%（改善確率 92.32%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 100.00% / 安定 （改善月 7/7）
- top2: 月block改善確率 97.74% / 安定 （改善月 5/7）
- top3: 月block改善確率 95.78% / 安定 （改善月 5/7）

展示後MLの主な特徴:

- first: c2_national_win_rate、c1_nige、c2_motor_exacta_rate、c2_exhibition_time_relative、c1_national_win_rate
- top2: c2_national_win_rate、c2_national_exacta_rate、c2_local_win_rate、c2_lap_time_relative、c2_exhibition_time_relative
- top3: c2_national_win_rate、c6_national_win_rate、c2_local_win_rate、c2_lap_time_relative、c2_ex_score

## 3コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|11.52%|182 / 21.98%|256 / 28.12%|253 / 30.04%|106 / 32.08%|
|top2|32.05%|182 / 45.05%|271 / 58.30%|231 / 59.31%|113 / 53.98%|
|top3|52.59%|182 / 62.64%|280 / 77.86%|219 / 79.00%|97 / 73.20%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 6.15%（改善確率 97.66%） / 展示後 8.06%（改善確率 99.62%）
- top2: 展示前 13.25%（改善確率 100.00%） / 展示後 14.25%（改善確率 99.98%）
- top3: 展示前 15.22%（改善確率 100.00%） / 展示後 16.36%（改善確率 100.00%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 100.00% / 安定 （改善月 7/7）
- top2: 月block改善確率 99.98% / 安定 （改善月 6/7）
- top3: 月block改善確率 99.96% / 安定 （改善月 6/7）

展示後MLの主な特徴:

- first: c3_national_win_rate、c3_motor_exacta_rate、c3_exhibition_time_relative、c1_national_win_rate、c3_national_exacta_rate
- top2: c3_national_win_rate、c3_exhibition_time_relative、c2_national_win_rate、c3_local_win_rate、c3_national_exacta_rate
- top3: c3_national_win_rate、c3_exhibition_time_relative、c3_motor_exacta_rate、c6_national_win_rate、c3_local_win_rate

## 4コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|9.43%|94 / 17.02%|124 / 23.39%|130 / 29.23%|50 / 22.00%|
|top2|28.05%|94 / 41.49%|128 / 55.47%|168 / 52.98%|53 / 54.72%|
|top3|50.08%|94 / 62.77%|123 / 86.18%|139 / 86.33%|54 / 74.07%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 6.37%（改善確率 93.56%） / 展示後 12.21%（改善確率 99.66%）
- top2: 展示前 13.98%（改善確率 99.10%） / 展示後 11.49%（改善確率 97.98%）
- top3: 展示前 23.41%（改善確率 100.00%） / 展示後 23.56%（改善確率 100.00%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 99.98% / 安定 （改善月 5/5）
- top2: 月block改善確率 100.00% / 安定 （改善月 5/5）
- top3: 月block改善確率 100.00% / 安定 （改善月 5/5）

展示後MLの主な特徴:

- first: c4_exhibition_time_relative、c1_national_win_rate、c5_start_timing_relative、c3_motor_exacta_rate、c4_national_win_rate
- top2: c4_national_win_rate、c4_exhibition_time_relative、c6_national_exacta_rate、c4_national_exacta_rate、c3_national_exacta_rate
- top3: c4_national_win_rate、c2_national_win_rate、c6_lap_time_relative、c4_national_exacta_rate、c5_lap_time_relative

## 5コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|4.84%|340 / 8.24%|373 / 9.92%|304 / 9.87%|167 / 13.17%|
|top2|18.11%|340 / 26.18%|357 / 29.97%|331 / 31.42%|193 / 34.72%|
|top3|35.89%|340 / 47.06%|367 / 52.86%|360 / 52.50%|188 / 57.45%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 1.68%（改善確率 96.10%） / 展示後 1.63%（改善確率 91.58%）
- top2: 展示前 3.80%（改善確率 96.30%） / 展示後 5.24%（改善確率 98.98%）
- top3: 展示前 5.80%（改善確率 98.78%） / 展示後 5.44%（改善確率 97.82%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 96.40% / やや不安定 （改善月 4/7）
- top2: 月block改善確率 100.00% / 安定 （改善月 7/7）
- top3: 月block改善確率 97.86% / 安定 （改善月 6/7）

展示後MLの主な特徴:

- first: c5_national_exacta_rate、c5_national_win_rate、c1_nige、c2_exhibition_time_relative、c2_start_timing_relative
- top2: c6_lap_time_relative、c2_ex_score、c5_national_win_rate、c3_national_win_rate、c1_national_win_rate
- top3: c5_national_win_rate、c4_national_win_rate、c3_national_win_rate、c6_local_exacta_rate、c1_national_win_rate

## 6コース

|対象|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---:|---:|---:|---:|---:|
|first|1.00%|0 / -|198 / 1.52%|184 / 2.17%|0 / -|
|top2|7.68%|0 / -|225 / 19.11%|187 / 19.79%|0 / -|
|top3|20.37%|0 / -|242 / 38.84%|244 / 36.89%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）
- top2: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）
- top3: 展示前 -（改善確率 -） / 展示後 -（改善確率 -）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 80.80% / 不安定 （改善月 2/7）
- top2: 月block改善確率 100.00% / 安定 （改善月 7/7）
- top3: 月block改善確率 100.00% / 安定 （改善月 7/7）

展示後MLの主な特徴:

- first: c6_exhibition_time_relative、c1_local_win_rate、c6_attack、c6_national_exacta_rate、c6_makuri
- top2: c6_national_exacta_rate、c6_national_win_rate、c4_lap_time_relative、c1_local_exacta_rate、c3_makuri
- top3: c6_national_win_rate、c6_national_exacta_rate、c6_exhibition_time_relative、c2_national_exacta_rate、c2_second_rank

## 判定上の注意

- これは条件見直し前の探索監査であり、本番サインは変更しない。
- 最終テストで良くても、月別安定性・ブートストラップ・回収率を通すまでは採用しない。
- 次段階で、MLが示した特徴を人が読める少数条件へ戻し、現行条件と再比較する。
