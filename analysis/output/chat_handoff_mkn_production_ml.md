# 三国（MKN）コースサイン ML v1 本番実装 引き継ぎ

## coverage問題と修正

MKN 3C top2（★★）の学習specへ、閾値決定用のVALID coverageではなく、TESTで結果的に実現したcoverage `0.123524069028...` が誤転記されていた。

`forecast/train_tamagawa_center_signal_v1.py` の固定coverage指定を削除し、現行サインあり用途の共通処理でVALID期間の現行3C★★表示率 `100 / 1057 = 0.0946073793755913` を取得するよう修正した。固定値の手入力は行っていない。

再発防止として候補検証JSONへ、既存の `coverage` を残したまま次を追加した。

- `validation_coverage`: 閾値決定に使ったVALID側の目標coverage
- `test_realized_coverage`: 固定閾値をTESTへ適用した実現coverage

候補検証Markdownにも「VALID threshold target coverage」と「TEST realized coverage」を別列で表示するよう変更した。

## 実装結果

- version: `mikuni_course_signal_v1`
- artifact: `forecast/models/mikuni_course_signal_v1.joblib`
- manifest: `forecast/models/mikuni_course_signal_v1.json`
- SHA256: `3a1383de4b9727902767c80ee9e1a80ae3c1e0ed035629b7183a3229a73c12c3`
- runtime: Python 3.12.3 / scikit-learn 1.4.1.post1 / NumPy 1.26.4
- period: 2023-09-28～2026-09-27
- production_enabled_from: `2026-10-01`

## 正式採用8用途

|コース|用途|モデル|特徴群|展示依存|VALID coverage|閾値|TEST選択 / 実現coverage / 対象率|
|---|---|---|---|---|---:|---:|---:|
|2C|first / ★★★|Logistic Regression|player_strength|なし|15.00%|0.274431763316|156 / 14.17% / 1着30.77%|
|2C|top2 / ★★|HistGradientBoosting|player_strength + motor_boat + st + technique|なし|15.00%|0.599489691849|169 / 15.35% / 2連59.76%|
|2C|top3 / ★|Logistic Regression|player_strength + motor_boat|なし|15.00%|0.759707333935|177 / 16.08% / 3連74.01%|
|3C|top2 / ★★|Logistic Regression|player_strength + exhibition|あり|9.4607379376%|0.560848710500|136 / 12.3524069028% / 2連61.03%|
|5C|top2 / ★★|Logistic Regression|player_strength + st + technique + exhibition|あり|15.00%|0.269029541321|163 / 14.80% / 2連25.15%|
|5C|top3 / ★|Logistic Regression|player_strength + technique|なし|15.00%|0.492428756616|185 / 16.80% / 3連52.97%|
|6C|top2 / ★★|HistGradientBoosting|player_strength|なし|15.00%|0.110600220687|153 / 13.90% / 2連15.69%|
|6C|top3 / ★|HistGradientBoosting|player_strength + motor_boat + exhibition|あり|15.00%|0.364196429732|193 / 17.53% / 3連36.79%|

artifactと正式候補JSONを、algorithm / groups / feature_names / uses_exhibition / threshold / VALID coverage / TEST選択件数 / TEST実現coverage / first・top2・top3率で照合し、8/8一致を確認した。

特に3C top2の正式値は次のとおり。

- requested VALID coverage: `0.0946073793755913`
- threshold: `0.5608487105000407`
- TEST selection: `136`
- TEST realized coverage: `0.12352406902815623`
- TEST top2 rate: `0.6102941176470589`

## 部分置換とfallback

画面対応は top3=★、top2=★★、first=★★★。

- 1C: ★ / ★★ / ★★★を従来維持
- 2C: ★ / ★★ / ★★★をML
- 3C: ★と★★★を従来維持、★★だけML
- 4C: ★ / ★★ / ★★★を従来維持
- 5C: ★と★★をML、★★★は非採用
- 6C: ★と★★をML、★★★は非採用

展示依存は3C top2、5C top2、6C top3の3用途だけ。展示前・展示欠損では該当用途だけ従来サインを維持する。2Cの3用途、5C top3、6C top2は展示前からML判定する。Python/joblib/manifest異常時はAPI既存の例外処理によりMKN全体を従来サインへ戻す。

## ライブ・API・表示確認

- 2026-09-25の展示前推論で、2Cの3用途、5C top3、6C top2だけが利用可能で、展示依存3用途は抑止された。
- 同日の展示後推論で、3C top2、5C top2、6C top3が追加された。
- 5C/6C firstモデルはartifactにもライブ結果にも存在しない。
- PHP APIで `center_ml.applied=true`、`model_version=mikuni_course_signal_v1` を確認した。
- APIの部分置換条件は上記マッピングと一致し、非採用・現行維持用途を上書きしない。
- PC、アプリ、TOPの各JavaScriptへ `mikuni_course_signal_v1` を既存ML v1表示として追加した。
- API短期キャッシュをv14からv15へ更新した。

## snapshot・前方保存

- `PredictionForwardSnapshotStore::courseSignalLogicVersion()` のハッシュ対象へMKN joblibを追加した。
- 追加後のlogic versionは `5bd9eaba73c4de1cae436c5a0bebb9935c959f09f1f56ae7b7467fe426920a33`。
- 更新前のTOP snapshotは保存版と現在版が不一致となり `valid_for_current_logic=false`、既存API直取得へfallbackすることを確認した。
- 2026-10-01のTOP事前生成を再実行後、保存版と現在版が一致し `valid_for_current_logic=true` を確認した。
- 前方保存は既存の `PredictionForwardSnapshotStore::captureDisplayedCourseSignals()` を利用する。開始日は2026-10-01で、過去backfillは行っていない。

## 回帰・変更範囲

- 既存11場 TMG / TDA / OMR / SMS / SME / KRY / BWK / ASY / AMG / HWJ / EDG のjoblibを、それぞれの既存runtimeでloadできることを確認した。
- 既存11場の分岐条件・model version認識は変更していない。
- Python構文、PHP構文、JavaScript構文、Git whitespace検査は合格。
- AI1着率forward、race_number、買い目、最終予想は変更していない。
- `config/last_date.php`、`theories/new_sam/stats_OMR.json`、既知のrace_number関連未追跡ファイルは本作業のcommit対象外。

## 残る確認事項

- 2026-10-01はMKN開催がないため、当日実レースでの画面目視は未実施。過去レースのライブ・PHP APIと、PC/アプリ/TOPの認識経路で確認済み。
- 2026-10-01以降の実開催については既存strict前方保存で成績を蓄積し、3C top2を含む8用途を個別評価する。
