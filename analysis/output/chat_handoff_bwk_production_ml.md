# びわこ（BWK）MLコースサイン v1 本番実装

## 1. Git状態

- 開始HEAD: `d36faf8ecdb6c64aa03fa494c2aabbae5aaf0500`
- ブランチ: `main`
- 本資料作成時点ではBWK関連変更は未コミット。
- `config/last_date.php` と `theories/new_sam/stats_OMR.json` は既存の未関連変更として保持し、変更・stage・commitしない。

## 2. モデルと実行環境

- version: `biwako_course_signal_v1`
- joblib: `forecast/models/biwako_course_signal_v1.joblib`
- SHA256: `756a574039409c3357ac2dd0b73f1d24de3cf3cf96065b615739725d7ad4a0d3`
- Python: `3.12.3`
- scikit-learn: `1.4.1.post1`
- numpy: `1.26.4`
- production_enabled_from: `2026-09-28`
- 学習・検証期間: 2023-09-27 ～ 2026-09-27
  - TRAIN: ～ 2025-08-31
  - VALID: 2025-09-01 ～ 2026-02-28
  - TEST: 2026-03-01 ～ 2026-09-27
- びわこの2020-10-26レイアウト変更以前のデータは含めていない。

## 3. 前回の無言終了の原因調査

- OOM Killer / `Killed process` / `oom_reaper` / Segfault / signal 9 / signal 11 のOS記録はなし。
- 診断時のavailable RAMは6.7GiB、swap空きは3.9GiB、ディスク空きは34GiB。
- dataset build単独は42.20秒・最大RSS 898,864KB・exit 0・signal 0で正常終了。
- 2C top2 Logistic単独は42.26秒・exit 0、2C first HistGradientBoosting単独は44.67秒・exit 0。
- Codex実行セルは約30秒で応答待機が切れるが、Pythonプロセス自体はその後も継続して正常終了した。前回の学習もこの挙動でjoblibとmanifestを生成済みだった。
- 今後の長時間処理では、プロセス状態、終了コード、ログ、artifact SHAを別コマンドで必ず確認する。

## 4. 候補検証との再現一致

`analysis/output/bwk_ml_candidate_validation_20260927.json` を正とし、11用途すべてでalgorithm、feature groups、VALID coverage、threshold、TEST N、TEST率を照合済み。

|コース|用途|model|feature groups|VALID coverage|threshold|TEST|
|---|---|---|---|---:|---:|---:|
|2C|first|hist_gradient|player_strength + motor_boat + st + technique + exhibition|10%|0.2391205231174867|180 / 31.11%|
|2C|top2|logistic|player_strength + motor_boat|10%|0.5706040104123207|161 / 60.25%|
|2C|top3|logistic|player_strength + exhibition|15%|0.7406443003869121|252 / 78.17%|
|3C|first|logistic|player_strength|10%|0.25940744625825196|136 / 34.56%|
|3C|top2|hist_gradient|player_strength + motor_boat + st + technique|10%|0.5912401667507343|156 / 55.13%|
|3C|top3|logistic|player_strength + motor_boat + st + technique|10%|0.7605011901126628|140 / 72.86%|
|4C|first|logistic|player_strength + technique|current-like 5.197%|0.26983118401301454|59 / 28.81%|
|4C|top3|logistic|player_strength + motor_boat|current-like 5.197%|0.7470978045724139|84 / 78.57%|
|5C|top3|logistic|player_strength|10%|0.5721881957647441|135 / 64.44%|
|6C|top2|logistic|player_strength|10%|0.16973543112551714|127 / 23.62%|
|6C|top3|logistic|player_strength + motor_boat + st + technique|10%|0.4453227466676778|122 / 52.46%|

6C top3だけ候補JSONとの差が約`7e-13`だが、浮動小数点丸め誤差の範囲。

## 5. 用途別部分置換

|コース|★ top3|★★ top2|★★★ first|
|---|---|---|---|
|1C|legacy|legacy|legacy|
|2C|展示後ML|ML|展示後ML|
|3C|ML|ML|ML|
|4C|ML|legacy|ML|
|5C|ML|legacy|legacy|
|6C|ML|ML|legacy|

既存の`mergePurposeMlMatches()`で採用用途だけを置換する。ML非採用用途のlegacyサインは削除しない。

## 6. 展示依存

- 展示依存は2C firstと2C top3のみ。
- 展示前・展示欠損では2Cの★ / ★★★はlegacyへfallbackし、★★ top2はMLを使用する。
- 3C全用途、4Cの★ / ★★★、5Cの★、6Cの★ / ★★は展示前からMLを使用する。
- `post_ml`という候補名ではなく、実際に`exhibition`特徴群を使うかで判定している。

## 7. ライブ推論・PHP/APIスモーク

- 2026-09-27 / BWK / 展示前: Python共通ライブ推論はexit 0。3Cと6Cの展示不要MLを返した。
- 2026-09-27 / BWK / 展示後: Python共通ライブ推論はexit 0。2C top3の展示依存MLを返した。
- PHP/APIの展示前・展示後レスポンスとも`center_ml.applied=true`、`version=biwako_course_signal_v1`を確認。
- API条件表示は2C展示前fallback、3C全用途ML、4Cの★★legacy、5Cの★★/★★★legacy、6Cの★★★legacyを示す仕様になっている。

## 8. フロント・キャッシュ・前方追跡

- PC、アプリ、TOP強調の既存ML v1認識リストへ`biwako_course_signal_v1`を追加。
- コースサインキャッシュ名をv11からv12へ更新し、旧BWKレスポンスを使わない。
- `PredictionForwardSnapshotStore::courseSignalLogicVersion()`のハッシュ対象へBWK joblibを追加。既存`captureDisplayedCourseSignals()`経路で、2026-09-28以降の表示内容を前方保存する。

## 9. 既存8場の回帰

- ASY / AMGはsystem Python（scikit-learn 1.4.1.post1）でjoblib load可能。
- TMG / TDA / OMR / SMS / SME / KRYは既存`.venv-models`（scikit-learn 1.9.1）でjoblib load可能。
- 既存8場のモデル再学習・再生成はしていない。
- 共通対応場一覧にBWKを追加したのみで、既存8場の分岐を変更していない。

## 10. 未確認事項とChatGPT確認事項

- 締切前オッズを用いたROI評価は今回の対象外。
- 実レースの前方追跡結果は2026-09-28以降に蓄積する。
- 本番反映後、アプリ画面でBWKのv1表示を1レース確認すると運用確認として十分。
