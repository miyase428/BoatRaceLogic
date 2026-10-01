# 江戸川 全コースサイン ゼロベースML監査（point-in-time版）

- 実行環境: Python 3.12.3 / scikit-learn 1.4.1.post1 / numpy 1.26.4
- 対象期間: 2023-09-28～2026-09-27
- 学習: ～2025-09-27 / 検証: ～2026-03-27 / 最終テスト: それ以降
- 使用レース: 5,228R
- 現行サインは比較対象のみ。モデル特徴量には不使用。
- 展示タイム場平均は対象日を除く同場直近183日だけで算出。
- ML選択数は、原則として現行サインと同程度のカバー率へ検証期間で固定。現行サインなしは20%。
- 月block比較は現行サインありなら現行、なしなら全体基礎率を比較対象とする。

## 1コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|現行維持寄り|44.38%|163 / 58.28%|165 / 57.58%|148 / 56.08%|87 / 68.97%|
|top2|次点|65.75%|163 / 73.62%|162 / 79.63%|149 / 79.19%|86 / 79.07%|
|top3|強い候補|77.38%|163 / 83.44%|153 / 94.12%|132 / 90.91%|88 / 88.64%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 -0.71%（改善確率 42.44%） / 展示後 -2.20%（改善確率 29.32%）
- top2: 展示前 6.01%（改善確率 95.44%） / 展示後 5.57%（改善確率 93.80%）
- top3: 展示前 10.68%（改善確率 99.98%） / 展示後 7.47%（改善確率 98.84%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 15.48% / 不安定 （改善月 1/6）
- top2: 月block改善確率 91.70% / やや不安定 （改善月 4/6）
- top3: 月block改善確率 100.00% / 安定 （改善月 6/6）

展示後MLの主な特徴:

- first: c1_national_win_rate、c5_national_exacta_rate、c2_national_exacta_rate、c5_national_win_rate、c2_national_win_rate
- top2: c1_national_win_rate、c6_national_win_rate、c2_national_exacta_rate、c4_makuri、c2_second_rank
- top3: c1_national_win_rate、c5_national_win_rate、c2_national_exacta_rate、c1_national_exacta_rate、c6_national_win_rate

## 2コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|強い候補|17.50%|0 / -|150 / 35.33%|162 / 36.42%|0 / -|
|top2|強い候補|39.38%|0 / -|156 / 64.10%|184 / 61.41%|0 / -|
|top3|強い候補|58.63%|0 / -|159 / 72.96%|157 / 77.07%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 17.83%（改善確率 100.00%） / 展示後 18.92%（改善確率 100.00%）
- top2: 展示前 24.73%（改善確率 100.00%） / 展示後 22.04%（改善確率 100.00%）
- top3: 展示前 14.33%（改善確率 100.00%） / 展示後 18.45%（改善確率 100.00%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 100.00% / 安定 （改善月 6/6）
- top2: 月block改善確率 100.00% / 安定 （改善月 6/6）
- top3: 月block改善確率 100.00% / 安定 （改善月 6/6）

展示後MLの主な特徴:

- first: c2_national_win_rate、c1_nige、c1_history_n、c2_national_exacta_rate、c1_gap_to_top
- top2: c2_national_win_rate、c5_national_win_rate、c5_national_exacta_rate、c6_national_win_rate、c3_national_win_rate
- top3: c2_national_win_rate、c3_national_win_rate、c6_national_win_rate、c6_national_exacta_rate、c2_motor_exacta_rate

## 3コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|強い候補|15.62%|0 / -|168 / 23.81%|164 / 21.95%|0 / -|
|top2|強い候補|36.12%|0 / -|146 / 61.64%|146 / 58.90%|0 / -|
|top3|強い候補|52.75%|0 / -|154 / 72.73%|152 / 75.66%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 8.18%（改善確率 99.82%） / 展示後 6.33%（改善確率 99.06%）
- top2: 展示前 25.52%（改善確率 100.00%） / 展示後 22.78%（改善確率 100.00%）
- top3: 展示前 19.98%（改善確率 100.00%） / 展示後 22.91%（改善確率 100.00%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 98.92% / 安定 （改善月 5/6）
- top2: 月block改善確率 100.00% / 安定 （改善月 6/6）
- top3: 月block改善確率 100.00% / 安定 （改善月 6/6）

展示後MLの主な特徴:

- first: c3_national_win_rate、c4_national_exacta_rate、c6_local_exacta_rate、c2_national_win_rate、c6_motor_exacta_rate
- top2: c3_national_win_rate、c3_local_exacta_rate、c6_lap_score、c2_national_win_rate、c6_local_exacta_rate
- top3: c3_national_win_rate、c4_national_win_rate、c3_local_exacta_rate、c3_exhibition_time_relative、c1_local_exacta_rate

## 4コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|強い候補|12.75%|43 / 18.60%|64 / 32.81%|61 / 32.79%|25 / 24.00%|
|top2|強い候補|27.50%|43 / 34.88%|64 / 45.31%|67 / 55.22%|27 / 40.74%|
|top3|次点|46.62%|43 / 65.12%|57 / 70.18%|44 / 79.55%|22 / 63.64%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 14.21%（改善確率 97.00%） / 展示後 14.18%（改善確率 97.78%）
- top2: 展示前 10.43%（改善確率 89.82%） / 展示後 20.34%（改善確率 99.18%）
- top3: 展示前 5.06%（改善確率 71.46%） / 展示後 14.43%（改善確率 94.46%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 100.00% / 安定 （改善月 5/5）
- top2: 月block改善確率 100.00% / 安定 （改善月 4/4）
- top3: 月block改善確率 100.00% / やや不安定 （改善月 3/3）

展示後MLの主な特徴:

- first: c2_national_win_rate、c6_ex_score、c6_second_rank、c6_national_exacta_rate、c2_national_exacta_rate
- top2: c4_national_win_rate、c2_national_win_rate、c1_ex_score、c4_national_exacta_rate、c6_ex_score
- top3: c4_national_win_rate、c4_national_exacta_rate、c3_national_win_rate、c6_ex_score、c4_second_rank

## 5コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|強い候補|6.88%|0 / -|209 / 12.44%|184 / 10.87%|0 / -|
|top2|強い候補|22.25%|0 / -|161 / 31.06%|154 / 32.47%|0 / -|
|top3|強い候補|40.50%|0 / -|160 / 56.88%|171 / 57.31%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 5.57%（改善確率 99.96%） / 展示後 3.99%（改善確率 98.74%）
- top2: 展示前 8.81%（改善確率 99.90%） / 展示後 10.22%（改善確率 99.86%）
- top3: 展示前 16.37%（改善確率 100.00%） / 展示後 16.81%（改善確率 100.00%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 100.00% / 安定 （改善月 6/6）
- top2: 月block改善確率 100.00% / 安定 （改善月 6/6）
- top3: 月block改善確率 100.00% / 安定 （改善月 6/6）

展示後MLの主な特徴:

- first: c3_local_exacta_rate、c3_history_n、c2_around_time_relative、c5_exhibition_time_relative、c2_lap_time_relative
- top2: c5_national_win_rate、c5_national_exacta_rate、c5_average_start、c5_makuri、c4_makuri
- top3: c5_national_win_rate、c4_makuri、c1_national_win_rate、c5_average_start、c5_second_rank

## 6コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|強い候補|2.88%|0 / -|144 / 7.64%|128 / 9.38%|0 / -|
|top2|強い候補|9.00%|0 / -|161 / 20.50%|133 / 24.06%|0 / -|
|top3|強い候補|24.12%|0 / -|167 / 49.10%|150 / 50.67%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 4.76%（改善確率 99.78%） / 展示後 6.50%（改善確率 99.96%）
- top2: 展示前 11.50%（改善確率 100.00%） / 展示後 15.06%（改善確率 100.00%）
- top3: 展示前 24.98%（改善確率 100.00%） / 展示後 26.54%（改善確率 100.00%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 100.00% / 安定 （改善月 6/6）
- top2: 月block改善確率 100.00% / 安定 （改善月 6/6）
- top3: 月block改善確率 100.00% / 安定 （改善月 6/6）

展示後MLの主な特徴:

- first: c6_national_win_rate、c2_national_win_rate、c4_history_n、c3_motor_exacta_rate、c6_history_n
- top2: c6_national_win_rate、c2_national_win_rate、c2_national_exacta_rate、c6_national_exacta_rate、c6_ex_score
- top3: c6_national_win_rate、c6_local_win_rate、c6_national_exacta_rate、c6_exhibition_time_relative、c2_boat_exacta_rate

## 判定上の注意

- これは条件見直し前の探索監査であり、本番サインは変更しない。
- 最終テストで良くても、月別安定性・ブートストラップ・回収率を通すまでは採用しない。
- 次段階で、MLが示した特徴を人が読める少数条件へ戻し、現行条件と再比較する。
