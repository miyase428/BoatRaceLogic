# 三国（MKN）従来ML v1 ゼロベース監査 引き継ぎ

## 結論

三国は共通 v1 監査で、8用途をML本番候補、8用途を現行統計サイン維持、2用途を見送りと判定した。本番実装はまだ行っていない。

### 本番候補

|コース|ML採用候補|
|---|---|
|1C|なし|
|2C|first / top2 / top3|
|3C|top2|
|4C|なし|
|5C|top2 / top3|
|6C|top2 / top3|

### 現行維持

- 1C first / top2 / top3
- 3C first / top3
- 4C first / top2 / top3

### 見送り

- 5C first
- 6C first

## 実装時の前提

- テンプレートはHWJ / EDG / BWKの共通v1モデル生成・ライブ推論・`mergePurposeMlMatches()`。
- 三国は監査再現性のため、Python 3.12.3 / scikit-learn 1.4.1.post1 / NumPy 1.26.4で学習・推論する。
- 主サイン停止の2C / 5C / 6CはVALID coverage 15%の候補を使用する。1C、3C first/top3、4Cは従来統計サインを維持する。
- 展示依存は候補のfeature groupsに`exhibition`を含む用途だけ。展示前・展示欠損時は、その用途のみ従来側へfallbackする。
  - 展示依存候補: 3C top2、5C top2、6C top3
  - 展示不要候補: 2C first / top2 / top3、5C top3、6C top2
- 本番実装では候補検証JSONを正とし、モデル種別・feature groups・feature names・threshold・coverage・TEST成績をartifact生成後に再現照合する。

## 監査成果物

- `analysis/output/mkn_course_signal_zero_base_ml_point_in_time_20260927.json`
- `analysis/output/mkn_course_signal_zero_base_ml_point_in_time_20260927.md`
- `analysis/output/mkn_course_ml_candidate_20260927.json`
- `analysis/output/mkn_course_ml_candidate_20260927.md`
- `analysis/output/mkn_course_signal_v1_audit_summary_20260927.md`

## 安全性

- 対象期間は2023-09-28～2026-09-27、使用5,901R、splitは3,743 / 1,057 / 1,101R、race_code重複は0。
- 展示平均は対象日を除外した同一場183日履歴。結果、払戻、odds、race_number、未来情報は特徴量に含めていない。
- 本番側、既存11場、AI1着率forward、race_number関連には未変更。
