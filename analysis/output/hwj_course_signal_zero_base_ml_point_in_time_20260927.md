# 平和島 全コースサイン ゼロベースML監査（point-in-time版）

- 実行環境: Python 3.12.3 / scikit-learn 1.4.1.post1 / numpy 1.26.4
- 対象期間: 2023-09-28～2026-09-27
- 学習: ～2025-09-27 / 検証: ～2026-03-27 / 最終テスト: それ以降
- 使用レース: 5,561R
- 現行サインは比較対象のみ。モデル特徴量には不使用。
- 展示タイム場平均は対象日を除く同場直近183日だけで算出。
- ML選択数は、原則として現行サインと同程度のカバー率へ検証期間で固定。現行サインなしは20%。
- 月block比較は現行サインありなら現行、なしなら全体基礎率を比較対象とする。

## 1コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|現行維持寄り|43.27%|216 / 64.81%|223 / 67.71%|229 / 65.50%|106 / 74.53%|
|top2|強い候補|61.76%|216 / 78.70%|218 / 81.19%|223 / 86.10%|106 / 89.62%|
|top3|強い候補|74.93%|216 / 87.04%|231 / 92.21%|248 / 91.13%|111 / 93.69%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 2.90%（改善確率 86.24%） / 展示後 0.69%（改善確率 56.90%）
- top2: 展示前 2.49%（改善確率 81.92%） / 展示後 7.39%（改善確率 99.60%）
- top3: 展示前 5.17%（改善確率 99.28%） / 展示後 4.09%（改善確率 95.74%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 58.60% / やや不安定 （改善月 3/6）
- top2: 月block改善確率 100.00% / 安定 （改善月 5/6）
- top3: 月block改善確率 93.66% / やや不安定 （改善月 4/6）

展示後MLの主な特徴:

- first: c1_national_win_rate、c1_history_n、c1_nige、c1_national_exacta_rate、c1_motor_exacta_rate
- top2: c1_national_win_rate、c1_second_rank、c4_ex_score、c6_lap_time_relative、c6_local_win_rate
- top3: c1_national_win_rate、c1_national_exacta_rate、c6_second_rank、c1_second_rank、c4_lap_time_relative

## 2コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|強い候補|18.20%|137 / 24.09%|183 / 32.24%|193 / 38.86%|66 / 34.85%|
|top2|強い候補|41.14%|137 / 54.01%|195 / 62.05%|138 / 63.04%|48 / 60.42%|
|top3|強い候補|60.12%|137 / 71.53%|189 / 78.84%|188 / 81.38%|61 / 83.61%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 8.15%（改善確率 98.40%） / 展示後 14.77%（改善確率 100.00%）
- top2: 展示前 8.04%（改善確率 96.68%） / 展示後 9.03%（改善確率 96.46%）
- top3: 展示前 7.30%（改善確率 96.16%） / 展示後 9.85%（改善確率 99.04%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 99.92% / 安定 （改善月 5/6）
- top2: 月block改善確率 89.52% / やや不安定 （改善月 3/6）
- top3: 月block改善確率 96.98% / 安定 （改善月 5/7）

展示後MLの主な特徴:

- first: c2_national_win_rate、c6_national_win_rate、c3_ex_score、c1_nige、c5_national_win_rate
- top2: c2_national_win_rate、c2_exhibition_time_relative、c2_lap_time_relative、c2_national_exacta_rate、c2_motor_exacta_rate
- top3: c2_national_win_rate、c5_national_win_rate、c4_ex_score、c2_national_exacta_rate、c3_lap_time_relative

## 3コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|強い候補|17.91%|222 / 23.42%|201 / 30.35%|208 / 32.69%|135 / 32.59%|
|top2|強い候補|37.85%|222 / 48.65%|218 / 52.29%|208 / 56.25%|116 / 56.90%|
|top3|強い候補|55.76%|222 / 64.86%|197 / 70.56%|191 / 72.25%|117 / 76.07%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 6.92%（改善確率 99.04%） / 展示後 9.27%（改善確率 99.94%）
- top2: 展示前 3.64%（改善確率 87.68%） / 展示後 7.60%（改善確率 98.70%）
- top3: 展示前 5.69%（改善確率 95.50%） / 展示後 7.39%（改善確率 98.60%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 100.00% / 安定 （改善月 7/7）
- top2: 月block改善確率 99.38% / 安定 （改善月 6/7）
- top3: 月block改善確率 97.32% / やや不安定 （改善月 4/7）

展示後MLの主な特徴:

- first: c3_national_win_rate、c1_national_win_rate、c1_nige、c1_average_start、c3_exhibition_time_relative
- top2: c3_national_win_rate、c3_local_exacta_rate、c1_motor_exacta_rate、c4_straight_time_relative、c3_exhibition_time_relative
- top3: c3_national_win_rate、c3_exhibition_time_relative、c2_national_win_rate、c3_lap_time_relative、c3_final_2nd_score

## 4コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|次点|10.55%|80 / 18.75%|54 / 35.19%|65 / 24.62%|50 / 26.00%|
|top2|強い候補|26.43%|80 / 42.50%|70 / 60.00%|75 / 54.67%|43 / 55.81%|
|top3|強い候補|44.63%|80 / 58.75%|70 / 77.14%|69 / 79.71%|41 / 68.29%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 16.44%（改善確率 99.66%） / 展示後 5.87%（改善確率 84.74%）
- top2: 展示前 17.50%（改善確率 99.50%） / 展示後 12.17%（改善確率 96.74%）
- top3: 展示前 18.39%（改善確率 99.80%） / 展示後 20.96%（改善確率 99.94%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 95.72% / 安定 （改善月 4/4）
- top2: 月block改善確率 89.04% / やや不安定 （改善月 2/4）
- top3: 月block改善確率 98.04% / 安定 （改善月 4/4）

展示後MLの主な特徴:

- first: c4_national_win_rate、c4_national_exacta_rate、c2_exhibition_time_relative、c2_motor_exacta_rate、c4_lap_time_relative
- top2: c6_start_timing_relative、c4_national_win_rate、c4_national_exacta_rate、c1_national_win_rate、c2_second_rank
- top3: c4_national_win_rate、c6_start_timing_relative、c6_national_win_rate、c2_national_exacta_rate、c3_lap_score

## 5コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|強い候補|7.55%|0 / -|221 / 17.65%|183 / 15.30%|0 / -|
|top2|強い候補|22.46%|0 / -|211 / 43.13%|170 / 44.71%|0 / -|
|top3|強い候補|38.53%|0 / -|184 / 64.13%|182 / 63.74%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 10.10%（改善確率 100.00%） / 展示後 7.75%（改善確率 99.98%）
- top2: 展示前 20.67%（改善確率 100.00%） / 展示後 22.25%（改善確率 100.00%）
- top3: 展示前 25.60%（改善確率 100.00%） / 展示後 25.21%（改善確率 100.00%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 98.48% / 安定 （改善月 6/7）
- top2: 月block改善確率 100.00% / 安定 （改善月 7/7）
- top3: 月block改善確率 100.00% / 安定 （改善月 7/7）

展示後MLの主な特徴:

- first: c5_national_win_rate、c3_around_time_relative、c1_boat_exacta_rate、c4_lap_time_relative、c5_national_exacta_rate
- top2: c5_national_win_rate、c6_second_rank、c6_exhibition_time_relative、c5_lap_time_relative、c6_lap_time_relative
- top3: c5_national_win_rate、c6_second_rank、c1_national_win_rate、c3_national_win_rate、c4_attack

## 6コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|強い候補|2.52%|0 / -|300 / 5.33%|273 / 5.49%|0 / -|
|top2|強い候補|10.36%|0 / -|270 / 22.59%|225 / 25.33%|0 / -|
|top3|強い候補|25.56%|0 / -|243 / 44.44%|224 / 45.98%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 2.82%（改善確率 99.96%） / 展示後 2.98%（改善確率 99.78%）
- top2: 展示前 12.23%（改善確率 100.00%） / 展示後 14.98%（改善確率 100.00%）
- top3: 展示前 18.89%（改善確率 100.00%） / 展示後 20.43%（改善確率 100.00%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 100.00% / 安定 （改善月 6/7）
- top2: 月block改善確率 100.00% / 安定 （改善月 7/7）
- top3: 月block改善確率 100.00% / 安定 （改善月 7/7）

展示後MLの主な特徴:

- first: c5_history_n、c6_exhibition_time_relative、c1_start_timing_relative、c2_lap_time_relative、c3_around_time_relative
- top2: c6_national_win_rate、c6_exhibition_time_relative、c3_history_n、c6_attack、c2_national_win_rate
- top3: c6_national_win_rate、c6_national_exacta_rate、c1_national_exacta_rate、c1_national_win_rate、c3_ex_score

## 判定上の注意

- これは条件見直し前の探索監査であり、本番サインは変更しない。
- 最終テストで良くても、月別安定性・ブートストラップ・回収率を通すまでは採用しない。
- 次段階で、MLが示した特徴を人が読める少数条件へ戻し、現行条件と再比較する。
