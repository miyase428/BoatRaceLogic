# 三国 全コースサイン ゼロベースML監査（point-in-time版）

- 実行環境: Python 3.12.3 / scikit-learn 1.4.1.post1 / numpy 1.26.4
- 対象期間: 2023-09-28～2026-09-27
- 学習: ～2025-09-27 / 検証: ～2026-03-27 / 最終テスト: それ以降
- 使用レース: 5,901R
- 現行サインは比較対象のみ。モデル特徴量には不使用。
- 展示タイム場平均は対象日を除く同場直近183日だけで算出。
- ML選択数は、原則として現行サインと同程度のカバー率へ検証期間で固定。現行サインなしは20%。
- 月block比較は現行サインありなら現行、なしなら全体基礎率を比較対象とする。

## 1コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|次点|52.68%|275 / 67.64%|280 / 71.07%|280 / 71.07%|147 / 75.51%|
|top2|次点|69.94%|275 / 81.82%|246 / 84.55%|270 / 85.19%|143 / 86.01%|
|top3|強い候補|78.75%|275 / 87.64%|239 / 89.12%|281 / 90.75%|145 / 89.66%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 3.44%（改善確率 90.62%） / 展示後 3.44%（改善確率 90.68%）
- top2: 展示前 2.73%（改善確率 89.68%） / 展示後 3.37%（改善確率 92.92%）
- top3: 展示前 1.48%（改善確率 78.46%） / 展示後 3.11%（改善確率 95.02%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 98.40% / 安定 （改善月 5/7）
- top2: 月block改善確率 88.54% / やや不安定 （改善月 4/7）
- top3: 月block改善確率 99.36% / 安定 （改善月 5/7）

展示後MLの主な特徴:

- first: c1_nige、c1_national_win_rate、c1_national_exacta_rate、c1_around_time_relative、c3_average_start
- top2: c6_local_exacta_rate、c6_local_win_rate、c6_lap_time_relative、c6_straight_time_relative、c2_mawari_score
- top3: c1_national_win_rate、c6_lap_time_relative、c6_local_win_rate、c6_local_exacta_rate、c4_attack

## 2コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|強い候補|14.53%|0 / -|257 / 25.29%|223 / 26.46%|0 / -|
|top2|強い候補|40.42%|0 / -|221 / 61.54%|208 / 58.17%|0 / -|
|top3|強い候補|58.22%|0 / -|228 / 75.88%|210 / 74.76%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 10.76%（改善確率 100.00%） / 展示後 11.93%（改善確率 100.00%）
- top2: 展示前 21.12%（改善確率 100.00%） / 展示後 17.76%（改善確率 100.00%）
- top3: 展示前 17.66%（改善確率 100.00%） / 展示後 16.54%（改善確率 100.00%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 99.94% / 安定 （改善月 5/6）
- top2: 月block改善確率 100.00% / 安定 （改善月 6/6）
- top3: 月block改善確率 100.00% / 安定 （改善月 6/6）

展示後MLの主な特徴:

- first: c3_attack、c6_lap_time_relative、c2_national_win_rate、c2_exhibition_time_relative、c6_start_timing_relative
- top2: c2_national_win_rate、c2_national_exacta_rate、c4_national_exacta_rate、c2_local_win_rate、c3_national_exacta_rate
- top3: c2_national_win_rate、c6_lap_time_relative、c3_national_exacta_rate、c3_ex_score、c1_ex_score

## 3コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|次点|14.71%|89 / 22.47%|139 / 28.06%|115 / 27.83%|48 / 27.08%|
|top2|次点|36.24%|89 / 48.31%|119 / 57.14%|131 / 56.49%|64 / 53.12%|
|top3|次点|54.50%|89 / 69.66%|120 / 75.00%|128 / 74.22%|62 / 77.42%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 5.59%（改善確率 91.14%） / 展示後 5.35%（改善確率 85.56%）
- top2: 展示前 8.83%（改善確率 95.88%） / 展示後 8.17%（改善確率 93.58%）
- top3: 展示前 5.34%（改善確率 84.90%） / 展示後 4.56%（改善確率 81.46%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 85.30% / 不安定 （改善月 2/5）
- top2: 月block改善確率 99.94% / 安定 （改善月 4/5）
- top3: 月block改善確率 73.42% / やや不安定 （改善月 3/5）

展示後MLの主な特徴:

- first: c1_around_time_relative、c2_national_exacta_rate、c3_motor_exacta_rate、c6_exhibition_time_relative、c3_national_exacta_rate
- top2: c2_national_exacta_rate、c3_national_win_rate、c3_national_exacta_rate、c6_lap_time_relative、c5_national_exacta_rate
- top3: c3_national_win_rate、c2_national_exacta_rate、c2_national_win_rate、c5_lap_time_relative、c3_lap_time_relative

## 4コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|現行維持寄り|9.72%|68 / 20.59%|105 / 14.29%|107 / 17.76%|38 / 26.32%|
|top2|現行維持寄り|28.70%|68 / 42.65%|92 / 48.91%|117 / 47.01%|31 / 51.61%|
|top3|次点|50.59%|68 / 63.24%|106 / 72.64%|112 / 74.11%|31 / 61.29%|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 -6.30%（改善確率 10.12%） / 展示後 -2.83%（改善確率 28.52%）
- top2: 展示前 6.27%（改善確率 82.26%） / 展示後 4.36%（改善確率 74.22%）
- top3: 展示前 9.41%（改善確率 92.34%） / 展示後 10.87%（改善確率 94.70%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 21.02% / 不安定 （改善月 1/5）
- top2: 月block改善確率 76.24% / やや不安定 （改善月 3/5）
- top3: 月block改善確率 97.80% / 安定 （改善月 5/5）

展示後MLの主な特徴:

- first: c4_exhibition_time_relative、c4_straight_time_relative、c6_lap_time_relative、c3_national_exacta_rate、c6_local_win_rate
- top2: c4_national_exacta_rate、c3_local_win_rate、c2_national_exacta_rate、c6_st_rank、c4_straight_time_relative
- top3: c4_national_win_rate、c3_local_win_rate、c4_national_exacta_rate、c2_national_win_rate、c3_local_exacta_rate

## 5コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|次点|6.36%|0 / -|243 / 11.93%|190 / 7.89%|0 / -|
|top2|強い候補|17.62%|0 / -|244 / 26.64%|233 / 24.46%|0 / -|
|top3|強い候補|36.51%|0 / -|243 / 53.09%|234 / 53.42%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 5.58%（改善確率 99.98%） / 展示後 1.54%（改善確率 81.52%）
- top2: 展示前 9.02%（改善確率 100.00%） / 展示後 6.84%（改善確率 99.70%）
- top3: 展示前 16.57%（改善確率 100.00%） / 展示後 16.91%（改善確率 100.00%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 81.74% / やや不安定 （改善月 4/6）
- top2: 月block改善確率 100.00% / 安定 （改善月 6/6）
- top3: 月block改善確率 100.00% / 安定 （改善月 7/7）

展示後MLの主な特徴:

- first: c5_national_win_rate、c5_boat_exacta_rate、c4_start_timing_relative、c5_motor_exacta_rate、c5_national_exacta_rate
- top2: c2_national_exacta_rate、c5_mawari_score、c6_start_timing_relative、c2_national_win_rate、c1_lap_score
- top3: c3_national_win_rate、c5_national_win_rate、c3_national_exacta_rate、c6_national_win_rate、c5_exhibition_time_relative

## 6コース

|対象|分類|基礎率|現行 N/率|展示前ML N/率|展示後ML N/率|現行＋ML N/率|
|---|---|---:|---:|---:|---:|---:|
|first|強い候補|2.00%|0 / -|206 / 3.88%|219 / 4.11%|0 / -|
|top2|強い候補|7.08%|0 / -|227 / 14.98%|199 / 17.09%|0 / -|
|top3|強い候補|21.44%|0 / -|229 / 37.55%|229 / 37.55%|0 / -|

MLと現行サインの差（最終6か月ブートストラップ）:

- first: 展示前 1.89%（改善確率 95.60%） / 展示後 2.11%（改善確率 98.00%）
- top2: 展示前 7.89%（改善確率 100.00%） / 展示後 10.00%（改善確率 100.00%）
- top3: 展示前 16.12%（改善確率 100.00%） / 展示後 16.12%（改善確率 100.00%）

展示後MLの月ブロック評価・月別安定性:

- first: 月block改善確率 96.06% / やや不安定 （改善月 4/6）
- top2: 月block改善確率 100.00% / 安定 （改善月 7/7）
- top3: 月block改善確率 100.00% / 安定 （改善月 7/7）

展示後MLの主な特徴:

- first: c6_start_timing_relative、c6_exhibition_time_relative、c6_history_n、c6_ex_score、c3_local_win_rate
- top2: c6_national_win_rate、c6_national_exacta_rate、c6_local_win_rate、c3_local_win_rate、c1_motor_exacta_rate
- top3: c6_national_win_rate、c6_national_exacta_rate、c6_history_n、c1_st_score、c4_start_timing_relative

## 判定上の注意

- これは条件見直し前の探索監査であり、本番サインは変更しない。
- 最終テストで良くても、月別安定性・ブートストラップ・回収率を通すまでは採用しない。
- 次段階で、MLが示した特徴を人が読める少数条件へ戻し、現行条件と再比較する。
