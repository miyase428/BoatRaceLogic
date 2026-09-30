# AI1着率 v6 + 新ML 総合比較

## 結論（historical TEST）

- race-level Brier最良は **v6_pre_course**: 0.08934310。現行v6再現は 0.08938652。
- 新ML最良は **ebm_course_first**: 0.09123801 で、v6 finalを上回らなかった。
- Top1は最良Brierモデル 62.18% / v6 61.96%、勝者Log Lossは 1.093817 / 1.093127。
- これは既に観察済みhistorical TESTであり、本番置換判断ではない。次は候補を固定してforward validationする。

## 今回の6つの回答

1. v6の強い部品はrating-v6 rankerとv4 ranker。3者blendの `v6_pre_course` が総合Brier最良。
2. v2 HGB/XGBoost/blendは単体で弱い。最終course補正はwinner NLLを少し改善したが、BrierとTop1はわずかに悪化。
3. AutoGluon単体systemはv6を上回らなかった。
4. EBM / NGBoost / CatBoost等も、1〜6Cの各艇単位Brierでv6 finalを上回らなかった。
5. 新MLで精度と多様性のバランスが最も良いのはEBM。TabDPTはv6との相関が最も低いが、精度差が大きい。
6. 次のblend候補はまず `v6 + EBM`。4Cの差が小さいNGBoostは補助候補。重み探索は今回未実施。

## 監査で判明したデータ定義差

- 既存course-signalのfirstは結果の実進入courseラベル。今回の艇1着率では、展示進入で選んだsubject player本人の勝敗へ揃えた。特徴量・split・採用レース数は不変。
- v6学習SQLはstraight_time 6艇必須だがliveは欠損許容。尼崎はstraight_time未収録のため、分析専用再学習だけliveと同じNaN許容にした。本番コードは未変更。

## 固定条件

- TRAIN 2023-09-27 to 2025-08-31 / VALID 2025-09-01 to 2026-02-28 / TEST 2026-03-01 to 2026-09-27。
- 新MLは展示込み固定170特徴。v6系は現行実装どおりv2=13、v4=31、reset-rating=35特徴。情報設計が異なるsystem比較である。
- TESTは学習・温度・course factor・モデル選択・blend調整に未使用。新しい校正とblend探索は実施していない。
- 全確率は既存 `normalized_race_probabilities()` でレース内100%化。

## TEST総合

|順位|model|Brier(mean/boat)|Brier(sum/race)|winner NLL|Top1|勝者平均確率|
|---:|---|---:|---:|---:|---:|---:|
|1|v6_pre_course|0.08934310|0.53605858|1.093817|62.18%|45.81%|
|2|v6_final|0.08938652|0.53631912|1.093127|61.96%|46.08%|
|3|rating_v6_ranker|0.08947599|0.53685597|1.094670|62.34%|46.51%|
|4|v4_ranker|0.08983781|0.53902683|1.103151|62.22%|46.22%|
|5|ebm_course_first|0.09123801|0.54742808|1.122748|61.31%|45.29%|
|6|autogluon_course_first|0.09138180|0.54829081|1.129382|61.19%|45.07%|
|7|catboost_course_first|0.09158601|0.54951607|1.130270|61.42%|46.84%|
|8|ngboost_course_first|0.09172347|0.55034080|1.137908|61.23%|46.84%|
|9|flaml_course_first|0.09196321|0.55177926|1.137738|60.85%|44.47%|
|10|tabdpt_turbo_course_first|0.09269680|0.55618083|1.160533|60.47%|42.34%|
|11|v2_xgboost|0.09318009|0.55908055|1.143610|60.54%|42.21%|
|12|v2_blend|0.09319291|0.55915749|1.143525|60.70%|42.24%|
|13|v2_hgb|0.09326492|0.55958952|1.144694|60.66%|42.27%|
|14|hgb_course_first|0.09342475|0.56054853|1.189547|60.85%|49.24%|

## v6内部比較

|段階|Brier|winner NLL|Top1|
|---|---:|---:|---:|
|v2_hgb|0.09326492|1.144694|60.66%|
|v2_xgboost|0.09318009|1.143610|60.54%|
|v2_blend|0.09319291|1.143525|60.70%|
|v4_ranker|0.08983781|1.103151|62.22%|
|rating_v6_ranker|0.08947599|1.094670|62.34%|
|v6_pre_course|0.08934310|1.093817|62.18%|
|v6_final|0.08938652|1.093127|61.96%|

## 場別・コース別

各区分の最良Brier（system評価）:

- venue AMG: **v6_pre_course** 0.08749088
- venue ASY: **v6_pre_course** 0.09134243
- course 1: **v6_final** 0.20972390
- course 2: **rating_v6_ranker** 0.08971346
- course 3: **rating_v6_ranker** 0.08516406
- course 4: **v6_pre_course** 0.08083875
- course 5: **v6_pre_course** 0.05159965
- course 6: **v6_final** 0.01797415

## 月別

|月|最良model|Brier|
|---|---|---:|
|2026-03|rating_v6_ranker|0.08219993|
|2026-04|v6_pre_course|0.09066456|
|2026-05|v6_final|0.09031012|
|2026-06|rating_v6_ranker|0.08654565|
|2026-07|tabdpt_turbo_course_first|0.09602372|
|2026-08|rating_v6_ranker|0.09087060|
|2026-09|rating_v6_ranker|0.08727722|

## 多様性・次のblend候補

- v6との予測相関が最も低い候補: **tabdpt_turbo_course_first**（Pearson 0.9525）。
- Top1不一致レースは 406R。詳細CSVはルール作成前の観察用で、今回はroutingやblend weightを作っていない。
- 単体Brierでv6を上回る新MLはなかった。次フェーズでは、精度と多様性のバランスが最も良いEBMを `v6 + EBM` 候補として前方保存し、weight最適化前に検証設計を固定する。

## 注意

- production artifactはTEST期間を含むため直接評価していない。分析専用モデルは/tmpだけで、本番model/PHP/frontend/買い目は無変更。
- actual_finishはrace_result_detailに保存されている着順を補完し、未収録艇（欠場・失格等を含む）は空欄。actual_winは全レースで確定している。
- course別行は6艇レースを分割した艇単位Brierであり、Top1・確率和は算出していない。
- calibration bucket、runtime、version、course factor、各stage条件はexperiment JSONに保存。
