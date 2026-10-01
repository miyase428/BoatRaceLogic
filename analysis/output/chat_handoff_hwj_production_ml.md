# 平和島（HWJ）コースサイン ML v1 本番実装

## 実装範囲

- 実装日: 2026-10-01 JST
- モデル版: `heiwajima_course_signal_v1`
- 学習・ライブruntime: Python 3.12.3 / scikit-learn 1.4.1.post1 / NumPy 1.26.4
- production enabled from: `2026-10-01`
- 学習期間: 2023-09-28～2026-09-27
  - TRAIN: 2023-09-28～2025-09-27
  - VALID: 2025-09-28～2026-03-27
  - TEST: 2026-03-28～2026-09-27
- 学習対象: 5,561R（元データ6,352R、出走または展示欠損を791R除外）
- joblib SHA256: `58a449832fcd580078c72ed6de219be5258d4e4989e0c541e73fc2820a361224`

## 採用した12用途

|コース|用途|画面段階|モデル|特徴群|展示依存|
|---|---|---|---|---|---|
|1C|top2|★★|Logistic|選手力・決まり手・展示|はい|
|2C|top2|★★|HGB|選手力・機力・展示|はい|
|2C|top3|★|Logistic|選手力・機力|いいえ|
|3C|first|★★★|HGB|ST・決まり手・展示|はい|
|3C|top3|★|HGB|選手力・決まり手・展示|はい|
|4C|top2|★★|Logistic|選手力|いいえ|
|4C|top3|★|Logistic|選手力・機力|いいえ|
|5C|first|★★★|HGB|ST・決まり手・展示|はい|
|5C|top2|★★|Logistic|選手力|いいえ|
|5C|top3|★|Logistic|選手力・決まり手・展示|はい|
|6C|top2|★★|HGB|機力・ST・決まり手|いいえ|
|6C|top3|★|Logistic|選手力・決まり手|いいえ|

現行統計サインを維持するのは、1Cの★・★★★、2Cの★★★、3Cの★★、4Cの★★★である。6Cの★★★はML・従来主サインとも非採用である。

## 再現性確認

`analysis/output/hwj_course_ml_candidate_20260927.json` とjoblib manifestを12用途で照合した。

- algorithm: 12/12一致
- feature groups / feature names: 12/12一致
- exhibition dependency: 12/12一致
- threshold: 12/12一致（許容誤差 `1e-12`）
- TEST選択件数・対象率: 12/12一致

最初の生成では、候補JSON中のTEST実測coverageを閾値作成用coverageとして誤って固定し、一部閾値が一致しなかった。候補検証を再実行して元JSONの再現を確認後、既存v1仕様どおり、1～4CはVALID期間の現行サイン表示率から閾値を作る形へ修正した。現行主サインがない5C・6Cだけは、監査で固定したVALID coverage 15%を使用している。最終artifactは上記の12/12一致を満たす。

## 展示前fallback / 部分置換

展示依存6用途（1C top2、2C top2、3C first/top3、5C first/top3）は、展示前または展示欠損時にその用途だけ従来統計サインを維持する。

展示非依存6用途（2C top3、4C top2/top3、5C top2、6C top2/top3）は展示前からMLを使用する。

2026-09-28の平和島でPHP APIを確認した結果:

- 展示前: 2C★、4C★★、5C★★、6C★★のML出力を確認。展示依存の1C・3C・5Cの対象レベルはMLへ置換されない。
- 展示後: 1C★★、2C★/★★、3C★/★★★、4C★★、5C★/★★/★★★、6C★★のML出力を確認。
- API応答は `center_ml.applied=true`、`version=heiwajima_course_signal_v1`、`fallback=false`。
- Python・joblib・JSON異常時は、既存のtry/catchによりHWJ全体を従来統計サインへ安全に戻す。

## 表示 / snapshot

- PC、アプリ、TOPの3つの既存フロント判定に `heiwajima_course_signal_v1` を追加した。API出力の `機械学習 v1`、確率、選択閾値、展示前/展示反映のphaseを既存v1と同じ形式で表示する。
- `PredictionForwardSnapshotStore::courseSignalLogicVersion()` のハッシュ対象にHWJ joblibを追加した。
- logic version一致のスナップショット判定は `true`、意図的に異なるhashでは `false` を確認した。
- 実在した2026-10-01の朝スナップショットは旧logic versionであり、`valid_for_current_logic=false` を返した。TOPは既存の `loadVenueSignals()` により場別APIへfallbackするため、旧サインは使われない。
- `analysis/prewarm_home_course_signals.php` はすでにHWJを対象24場に含んでおり、追加修正は不要。以後の当日prewarmは新logic versionで保存される。

## 既存9場回帰

再学習・再生成は行わず、各既存joblibを従来runtimeでライブ推論ロードした。

|場|結果|
|---|---|
|AMG|`amagasaki_course_signal_v1` を正常ロード|
|ASY|`ashiya_course_signal_v1` を正常ロード|
|BWK|`biwako_course_signal_v1` を正常ロード|
|KRY|`kiryuu_course_signal_v1` を正常ロード|
|TMG|`tamagawa_course_signal_v1` を正常ロード|
|TDA|`toda_course_signal_v1` を正常ロード|
|OMR|`omura_course_signal_v1` を正常ロード|
|SMS|`shimonoseki_course_signal_v1` を正常ロード|
|SME|`suminoe_course_signal_v1` を正常ロード|

ASY / AMG / BWK / HWJ はscikit-learn 1.4.1.post1の `/usr/bin/python3` を使う。その他の既存v1場は従来どおり `.venv-models` を使う。

## 前方保存と対象外確認

- HWJコースサインは既存 `PredictionForwardSnapshotStore::captureDisplayedCourseSignals()` 経路で、`production_enabled_from`以降の当日表示分だけ自然に保存される。過去日のbackfillはしていない。
- AI1着率forward（v6 / AutoGluon / EBM）、race_number、買い目、最終予想には変更なし。
- 無関係なローカル変更（`config/last_date.php`、`theories/new_sam/stats_OMR.json`、race_number分析ファイル群）は今回の変更・commit対象外。
