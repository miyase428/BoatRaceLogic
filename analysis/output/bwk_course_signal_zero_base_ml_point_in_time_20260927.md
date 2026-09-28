# びわこ 全コースサイン ゼロベースML監査（point-in-time版）

- 実行環境: Python 3.12.3 / scikit-learn 1.4.1.post1 / numpy 1.26.4
- 対象期間: 2023-09-28～2026-09-27
- 学習: ～2025-09-27 / 検証: ～2026-03-27 / 最終テスト: それ以降
- 使用レース: 5,852R
- 現行サインは比較対象のみ。モデル特徴量には不使用。
- 展示タイム場平均は対象日を除く同場直近183日だけで算出。
- ML選択数は、原則として現行サインと同程度のカバー率へ検証期間で固定。現行サインなしは20%。
- 月block比較は現行サインありなら現行、なしなら全体基礎率を比較対象とする。

- レイアウト変更日: 2020-10-26
- 元データ最古日: 2023-10-01
- 使用データは全件レイアウト変更後: はい

## 1コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|次点|55.24%|350 / 72.00%|309 / 73.14%|294 / 73.81%|163 / 77.30%|
|top2|次点|73.51%|350 / 85.71%|288 / 87.85%|261 / 88.12%|159 / 89.31%|
|top3|次点|81.74%|350 / 90.86%|266 / 92.48%|257 / 93.39%|147 / 94.56%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 1.14%（改善確率 71.34%） / 展示後 1.81%（改善確率 80.00%）
- top2: 展示前 2.13%（改善確率 87.90%） / 展示後 2.41%（改善確率 87.20%）
- top3: 展示前 1.62%（改善確率 85.14%） / 展示後 2.53%（改善確率 92.36%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 79.46% / やや不安定 （改善月 3/6）
- top2: 月block改善確率 87.08% / やや不安定 （改善月 3/6）
- top3: 月block改善確率 86.28% / やや不安定 （改善月 3/6）

展示後MLの主な特徴:

- first: c1_nige、c1_national_win_rate、c3_national_win_rate、c5_local_exacta_rate、c6_national_win_rate
- top2: c1_history_n、c1_national_win_rate、c1_nige、c2_history_n、c2_national_win_rate
- top3: c1_national_win_rate、c6_motor_exacta_rate、c1_lap_time_relative、c1_history_n、c2_history_n

## 2コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|強い候補|13.02%|0 / -|236 / 24.58%|205 / 24.39%|0 / -|
|top2|強い候補|39.78%|0 / -|241 / 54.77%|244 / 55.33%|0 / -|
|top3|強い候補|59.49%|0 / -|277 / 77.26%|258 / 79.46%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 11.56%（改善確率 100.00%） / 展示後 11.37%（改善確率 100.00%）
- top2: 展示前 14.99%（改善確率 100.00%） / 展示後 15.54%（改善確率 100.00%）
- top3: 展示前 17.76%（改善確率 100.00%） / 展示後 19.96%（改善確率 100.00%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 100.00% / 安定 （改善月 5/6）
- top2: 月block改善確率 100.00% / 安定 （改善月 6/6）
- top3: 月block改善確率 100.00% / 安定 （改善月 6/6）

展示後MLの主な特徴:

- first: c2_sashi、c2_national_exacta_rate、c2_national_win_rate、c1_national_win_rate、c1_local_exacta_rate
- top2: c2_national_win_rate、c2_national_exacta_rate、c4_local_exacta_rate、c2_boat_exacta_rate、c2_lap_time_relative
- top3: c2_national_win_rate、c4_national_win_rate、c4_second_rank、c1_ex_score、c2_ex_score

## 3コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|強い候補|14.74%|0 / -|216 / 25.93%|201 / 26.87%|0 / -|
|top2|強い候補|36.80%|0 / -|233 / 48.93%|213 / 50.70%|0 / -|
|top3|強い候補|55.24%|0 / -|210 / 70.48%|219 / 69.86%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 11.19%（改善確率 100.00%） / 展示後 12.13%（改善確率 100.00%）
- top2: 展示前 12.13%（改善確率 99.98%） / 展示後 13.90%（改善確率 100.00%）
- top3: 展示前 15.23%（改善確率 100.00%） / 展示後 14.62%（改善確率 100.00%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 100.00% / 安定 （改善月 6/6）
- top2: 月block改善確率 99.96% / 安定 （改善月 5/6）
- top3: 月block改善確率 100.00% / 安定 （改善月 5/6）

展示後MLの主な特徴:

- first: c3_national_win_rate、c6_national_win_rate、c1_nige、c3_ex_score、c4_national_win_rate
- top2: c3_national_exacta_rate、c3_national_win_rate、c1_history_n、c2_national_exacta_rate、lane1_vulnerability_rate
- top3: c3_national_win_rate、c3_national_exacta_rate、c5_attack、c6_st_rank、c4_makuri

## 4コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|強い候補|9.22%|71 / 18.31%|65 / 30.77%|66 / 33.33%|43 / 18.60%|
|top2|次点|23.87%|71 / 38.03%|46 / 54.35%|46 / 47.83%|35 / 45.71%|
|top3|強い候補|45.57%|71 / 59.15%|48 / 83.33%|46 / 84.78%|32 / 65.62%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 12.46%（改善確率 97.98%） / 展示後 15.02%（改善確率 98.50%）
- top2: 展示前 16.32%（改善確率 96.86%） / 展示後 9.80%（改善確率 87.84%）
- top3: 展示前 24.18%（改善確率 99.82%） / 展示後 25.63%（改善確率 99.96%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 98.48% / やや不安定 （改善月 3/5）
- top2: 月block改善確率 92.76% / やや不安定 （改善月 4/6）
- top3: 月block改善確率 100.00% / 安定 （改善月 6/6）

展示後MLの主な特徴:

- first: c4_national_win_rate、c6_lap_time_relative、c2_second_rank、c2_national_exacta_rate、c6_local_exacta_rate
- top2: c4_national_win_rate、c1_national_win_rate、c6_lap_time_relative、c1_nige、c2_around_time_relative
- top3: c4_national_win_rate、c4_national_exacta_rate、c2_national_win_rate、c5_national_win_rate、c6_lap_time_relative

## 5コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|強い候補|6.24%|0 / -|199 / 17.09%|223 / 17.04%|0 / -|
|top2|強い候補|18.17%|0 / -|211 / 37.44%|240 / 32.50%|0 / -|
|top3|強い候補|36.98%|0 / -|223 / 61.43%|236 / 63.56%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 10.85%（改善確率 100.00%） / 展示後 10.80%（改善確率 100.00%）
- top2: 展示前 19.27%（改善確率 100.00%） / 展示後 14.33%（改善確率 100.00%）
- top3: 展示前 24.45%（改善確率 100.00%） / 展示後 26.58%（改善確率 100.00%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 100.00% / 安定 （改善月 6/6）
- top2: 月block改善確率 100.00% / 安定 （改善月 6/6）
- top3: 月block改善確率 100.00% / 安定 （改善月 6/6）

展示後MLの主な特徴:

- first: c6_lap_time_relative、c6_national_exacta_rate、c2_national_exacta_rate、c6_national_win_rate、c5_mawari_score
- top2: c5_exhibition_time_relative、c5_local_exacta_rate、c1_final_2nd_score、c6_national_win_rate、c5_makuri
- top3: c5_national_win_rate、c4_national_win_rate、c6_second_rank、c2_second_rank、c4_second_rank

## 6コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|強い候補|1.54%|0 / -|207 / 4.35%|210 / 3.33%|0 / -|
|top2|強い候補|7.87%|0 / -|196 / 18.88%|192 / 16.67%|0 / -|
|top3|強い候補|20.89%|0 / -|193 / 44.04%|186 / 44.09%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 2.81%（改善確率 99.66%） / 展示後 1.80%（改善確率 96.86%）
- top2: 展示前 11.01%（改善確率 100.00%） / 展示後 8.80%（改善確率 100.00%）
- top3: 展示前 23.16%（改善確率 100.00%） / 展示後 23.20%（改善確率 100.00%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 96.04% / やや不安定 （改善月 4/6）
- top2: 月block改善確率 100.00% / 安定 （改善月 6/6）
- top3: 月block改善確率 100.00% / 安定 （改善月 6/6）

展示後MLの主な特徴:

- first: lane1_vulnerability_rate、c2_around_time_relative、c2_exhibition_time_relative、c1_around_time_relative、c4_boat_exacta_rate
- top2: c6_national_win_rate、c4_attack、c6_national_exacta_rate、c4_boat_exacta_rate、c4_start_timing_relative
- top3: c6_national_win_rate、c6_national_exacta_rate、c1_lap_time_relative、c4_second_rank、c3_lap_time_relative

## 判定上の注意

- これは条件見直し前の探索監査であり、本番サインは変更しない。
- 最終テストで良くても、月別安定性・ブートストラップ・回収率を通すまでは採用しない。
- 次段階で、MLが示した特徴を人が読める少数条件へ戻し、現行条件と再比較する。
