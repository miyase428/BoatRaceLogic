# びわこ（BWK）MLコースサイン候補検証

## 1. Git状態

- 開始HEAD: `9d2745c5afd73226e6033a11faab8104f27f3293`
- 本番コードは変更していない。

## 2. 実行環境

- Python 3.12.3 / scikit-learn 1.4.1.post1 / numpy 1.26.4

## 3. データ・split・point-in-time

- レイアウト変更日: 2020-10-26 / 元データ最古日: 2023-09-27 / 使用最古日: 2024-01-04
- 使用 5,852R、除外 750R（展示または183日平均不足のみ）。
- TRAIN 2025-08-31まで / VALID 2025-09-01〜2026-02-28 / TEST 2026-03-01〜2026-09-27。split重複は0。
- 展示平均は同場・対象日前183日・対象日除外。決まり手／被攻め履歴も対象日前だけ。

## 4. 11用途の最終結果

|用途|採用pre/post|VALID coverage|VALID閾値|TEST N/率|比較率|差|通常|月block|安定性|判定|
|---|---|---:|---:|---:|---:|---:|---:|---:|---|---|
|2C first|post_ml (hist_gradient)|10.0%|0.239121|180 / 31.1%|13.9%|17.2%|100.0%|100.0%|安定|本番候補|
|2C top2|post_ml (logistic)|10.0%|0.570604|161 / 60.2%|40.7%|19.5%|100.0%|100.0%|安定|本番候補|
|2C top3|post_ml (logistic)|15.0%|0.740644|252 / 78.2%|60.0%|18.2%|100.0%|100.0%|安定|本番候補|
|3C first|post_ml (logistic)|10.0%|0.259407|136 / 34.6%|14.5%|20.0%|100.0%|100.0%|安定|本番候補|
|3C top2|pre_ml (hist_gradient)|10.0%|0.591240|156 / 55.1%|36.6%|18.5%|100.0%|100.0%|安定|本番候補|
|3C top3|pre_ml (logistic)|10.0%|0.760501|140 / 72.9%|55.7%|17.1%|100.0%|100.0%|安定|本番候補|
|4C first|post_ml (logistic)|5.2%|0.269831|59 / 28.8%|17.6%|11.2%|96.7%|96.7%|安定|本番候補|
|4C top3|post_ml (logistic)|5.2%|0.747098|84 / 78.6%|60.8%|17.8%|99.4%|99.9%|安定|本番候補|
|5C top3|post_ml (logistic)|10.0%|0.572188|135 / 64.4%|36.5%|27.9%|100.0%|100.0%|安定|本番候補|
|6C top2|post_ml (logistic)|10.0%|0.169735|127 / 23.6%|8.0%|15.6%|100.0%|100.0%|安定|本番候補|
|6C top3|pre_ml (logistic)|10.0%|0.445323|122 / 52.5%|20.7%|31.7%|100.0%|100.0%|安定|本番候補|

## 5. pre vs post・モデル選択

- 2C first: pre=logistic VALID Brier 0.1038 / post=hist_gradient(player_strength+motor_boat+st+technique+exhibition) VALID Brier 0.1000 → post_ml。
- 2C top2: pre=hist_gradient VALID Brier 0.2114 / post=logistic(player_strength+motor_boat) VALID Brier 0.2098 → post_ml。
- 2C top3: pre=logistic VALID Brier 0.2179 / post=logistic(player_strength+exhibition) VALID Brier 0.2175 → post_ml。
- 3C first: pre=hist_gradient VALID Brier 0.1155 / post=logistic(player_strength) VALID Brier 0.1129 → post_ml。
- 3C top2: pre=hist_gradient VALID Brier 0.2153 / post=hist_gradient(player_strength+motor_boat+st+technique+exhibition) VALID Brier 0.2165 → pre_ml。
- 3C top3: pre=logistic VALID Brier 0.2335 / post=hist_gradient(player_strength+motor_boat+st+technique+exhibition) VALID Brier 0.2329 → pre_ml。
- 4C first: pre=logistic VALID Brier 0.0957 / post=logistic(player_strength+technique) VALID Brier 0.0950 → post_ml。
- 4C top3: pre=logistic VALID Brier 0.2215 / post=logistic(player_strength+motor_boat) VALID Brier 0.2212 → post_ml。
- 5C top3: pre=logistic VALID Brier 0.2156 / post=logistic(player_strength) VALID Brier 0.2148 → post_ml。
- 6C top2: pre=logistic VALID Brier 0.0758 / post=logistic(player_strength) VALID Brier 0.0730 → post_ml。
- 6C top3: pre=logistic VALID Brier 0.1744 / post=logistic(player_strength) VALID Brier 0.1726 → pre_ml。

## 6. 選手偏り・感度

- 2C first: 最多 4677 2.2%。除外後差 17.9%。
- 2C top2: 最多 3473 3.7%。除外後差 20.6%。
- 2C top3: 最多 3473 2.0%。除外後差 18.6%。
- 3C first: 最多 4262 2.2%。除外後差 18.8%。
- 3C top2: 最多 5075 2.6%。除外後差 19.2%。
- 3C top3: 最多 3721 2.9%。除外後差 18.5%。
- 4C first: 最多 4264 3.4%。除外後差 12.3%。
- 4C top3: 最多 4262 6.0%。除外後差 16.4%。
- 5C top3: 最多 5280 5.2%。除外後差 27.2%。
- 6C top2: 最多 4262 3.1%。除外後差 16.4%。
- 6C top3: 最多 4448 2.5%。除外後差 30.7%。

## 7. 級別・全国勝率帯偏り

- 2C first: A1/A2内は候補 146件/32.9%、比較 624件/18.8%、差 14.1%。勝率帯候補: 4.0未満: 0件/- / 4.0-5.0: 15件/20.0% / 5.0-6.0: 59件/28.8% / 6.0以上: 106件/34.0%。
- 2C top2: A1/A2内は候補 145件/61.4%、比較 624件/50.2%、差 11.2%。勝率帯候補: 4.0未満: 0件/- / 4.0-5.0: 6件/50.0% / 5.0-6.0: 25件/64.0% / 6.0以上: 130件/60.0%。
- 2C top3: A1/A2内は候補 201件/79.6%、比較 624件/68.6%、差 11.0%。勝率帯候補: 4.0未満: 2件/100.0% / 4.0-5.0: 21件/76.2% / 5.0-6.0: 70件/74.3% / 6.0以上: 159件/79.9%。
- 3C first: A1/A2内は候補 124件/34.7%、比較 619件/19.2%、差 15.5%。勝率帯候補: 4.0未満: 0件/- / 4.0-5.0: 1件/0.0% / 5.0-6.0: 35件/31.4% / 6.0以上: 100件/36.0%。
- 3C top2: A1/A2内は候補 146件/56.2%、比較 619件/44.3%、差 11.9%。勝率帯候補: 4.0未満: 0件/- / 4.0-5.0: 4件/75.0% / 5.0-6.0: 33件/42.4% / 6.0以上: 119件/58.0%。
- 3C top3: A1/A2内は候補 133件/72.2%、比較 619件/64.1%、差 8.0%。勝率帯候補: 4.0未満: 0件/- / 4.0-5.0: 4件/75.0% / 5.0-6.0: 21件/71.4% / 6.0以上: 115件/73.0%。
- 4C first: A1/A2内は候補 55件/29.1%、比較 63件/17.5%、差 11.6%。勝率帯候補: 4.0未満: 0件/- / 4.0-5.0: 0件/- / 5.0-6.0: 5件/0.0% / 6.0以上: 54件/31.5%。
- 4C top3: A1/A2内は候補 77件/77.9%、比較 63件/63.5%、差 14.4%。勝率帯候補: 4.0未満: 0件/- / 4.0-5.0: 1件/0.0% / 5.0-6.0: 8件/62.5% / 6.0以上: 75件/81.3%。
- 5C top3: A1/A2内は候補 129件/65.1%、比較 568件/47.0%、差 18.1%。勝率帯候補: 4.0未満: 0件/- / 4.0-5.0: 0件/- / 5.0-6.0: 14件/64.3% / 6.0以上: 121件/64.5%。
- 6C top2: A1/A2内は候補 117件/25.6%、比較 536件/11.0%、差 14.6%。勝率帯候補: 4.0未満: 1件/0.0% / 4.0-5.0: 4件/0.0% / 5.0-6.0: 16件/25.0% / 6.0以上: 106件/24.5%。
- 6C top3: A1/A2内は候補 115件/54.8%、比較 536件/29.9%、差 24.9%。勝率帯候補: 4.0未満: 1件/0.0% / 4.0-5.0: 4件/0.0% / 5.0-6.0: 16件/50.0% / 6.0以上: 101件/55.4%。

## 8. target overlap

- 2C: 共通 67件 / 和集合 344件。first__top2: 80件, J=0.31 / first__top3: 117件, J=0.37 / top2__top3: 119件, J=0.40
- 3C: 共通 51件 / 和集合 253件。first__top2: 71件, J=0.32 / first__top3: 77件, J=0.39 / top2__top3: 82件, J=0.38
- 4C: 共通 34件 / 和集合 109件。first__top3: 34件, J=0.31
- 6C: 共通 83件 / 和集合 166件。top2__top3: 83件, J=0.50

## 9. readable rule・ROI

- 簡易ルールはVALID重要度とTRAIN分位点だけで探索し、説明用としてJSONへ保存。採用比較はML probability + VALID固定thresholdを主とする。
- ROIは締切前オッズを保証できないため未評価。

## 10. 前回監査との再現

- 前回強かった4C first/top3は候補splitでも本番候補。2C/3Cも全用途で安定・本番候補となり、方向性は反転していない。

## 11. 本番未変更確認

- forecast/models、学習本番、ライブ推論、PHP、Web、JS、API、course_signal_rules、DB、買い目、本命、前方保存は未変更。

## 12. ChatGPTが判断すべきこと

1. 本番候補のうち、どの用途から前方監視・実装候補へ進めるか。
2. 4C top3の選手偏りと、2C/3Cのtarget別モデルを統合せず残すか。
3. 条件調整候補を追加検証するか、現行維持とするか。

## ChatGPT用要約

11用途をTRAIN/VALIDのみでモデル・特徴群・coverage・pre/postを固定し、TESTで最終評価した。詳細な採否、偏り、選手除外感度、月別、overlapは上表とJSONを参照。本番変更は一切していない。
