# 江戸川（EDG）従来ML v1 ゼロベース監査 引き継ぎ

## 結論

江戸川は共通v1監査で、14用途をML本番候補、4用途を現行統計サイン維持と判定した。本番実装はまだ行っていない。

### 本番候補

|コース|ML採用候補|
|---|---|
|1C|なし|
|2C|first / top2 / top3|
|3C|first / top2 / top3|
|4C|top2 / top3|
|5C|first / top2 / top3|
|6C|first / top2 / top3|

### 現行維持

- 1C first / top2 / top3
- 4C first

## 実装時の前提

- テンプレートはHWJ/BWKの共通v1モデル生成・ライブ推論・`mergePurposeMlMatches()`。
- EDGは監査再現性のため、Python 3.12.3 / scikit-learn 1.4.1.post1 / NumPy 1.26.4で学習・推論する。
- 現行サインがない2C/3C/5C/6CはVALID coverage 15%の候補を使用する。1CはML非採用、4C firstは従来維持。
- 展示依存は候補のfeature groupsに`exhibition`を含む用途だけ。展示前・展示欠損時は、その用途のみ従来側へfallbackする。
- 本番実装では候補検証JSONを正とし、モデル種別・feature groups・feature names・threshold・coverage・TEST成績をartifact生成後に再現照合する。

## 監査成果物

- `analysis/output/edg_course_signal_zero_base_ml_point_in_time_20260927.json`
- `analysis/output/edg_course_signal_zero_base_ml_point_in_time_20260927.md`
- `analysis/output/edg_course_ml_candidate_20260927.json`
- `analysis/output/edg_course_ml_candidate_20260927.md`
- `analysis/output/edg_course_signal_v1_audit_summary_20260927.md`

## 安全性

- 対象期間は2023-09-28～2026-09-27、使用5,228R、splitは3,307 / 1,121 / 800R、race_code重複は0。
- 展示平均は対象日を除外した同一場183日履歴。結果、払戻、odds、race_number、未来情報は特徴量に含めていない。
- 本番側、既存10場、AI1着率forward、race_number関連には未変更。
