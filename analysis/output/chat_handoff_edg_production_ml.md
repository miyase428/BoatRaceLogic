# 江戸川（EDG）コースサイン ML v1 本番実装 引き継ぎ

## 実装結果

- version: `edogawa_course_signal_v1`
- artifact: `forecast/models/edogawa_course_signal_v1.joblib`
- manifest: `forecast/models/edogawa_course_signal_v1.json`
- SHA256: `ca0f1523b97605f127b94c7a3e51c4be05ed0d57087845ddc6b62f2b4ebfda93`
- runtime: Python 3.12.3 / scikit-learn 1.4.1.post1 / NumPy 1.26.4
- period: 2023-09-28～2026-09-27（TRAIN 3,307R / VALID 1,121R / TEST 800R）
- production_enabled_from: 2026-10-01

## 採用用途

|コース|用途|モデル|特徴群|展示依存|VALID coverage|TEST選択 / 対象率|
|---|---|---|---|---|---:|---:|
|2C|first / ★★★|HistGradientBoosting|technique + exhibition|あり|15%|139 / 1着 34.53%|
|2C|top2 / ★★|Logistic Regression|player_strength + motor_boat|なし|15%|105 / 2連 69.52%|
|2C|top3 / ★|Logistic Regression|player_strength + motor_boat|なし|15%|110 / 3連 76.36%|
|3C|first / ★★★|Logistic Regression|player_strength + motor_boat|なし|15%|129 / 1着 24.81%|
|3C|top2 / ★★|Logistic Regression|player_strength + motor_boat|なし|15%|119 / 2連 62.18%|
|3C|top3 / ★|Logistic Regression|player_strength|なし|15%|117 / 3連 78.63%|
|4C|top2 / ★★|Logistic Regression|player_strength + motor_boat|なし|5.53%|58 / 2連 53.45%|
|4C|top3 / ★|Logistic Regression|player_strength + motor_boat + exhibition|あり|5.53%|43 / 3連 81.40%|
|5C|first / ★★★|HistGradientBoosting|player_strength + exhibition|あり|15%|138 / 1着 12.32%|
|5C|top2 / ★★|HistGradientBoosting|player_strength + motor_boat + technique|なし|15%|123 / 2連 37.40%|
|5C|top3 / ★|Logistic Regression|player_strength|なし|15%|119 / 3連 61.34%|
|6C|first / ★★★|HistGradientBoosting|player_strength|なし|15%|135 / 1着 5.93%|
|6C|top2 / ★★|Logistic Regression|player_strength + exhibition|あり|15%|111 / 2連 23.42%|
|6C|top3 / ★|HistGradientBoosting|player_strength + motor_boat + exhibition|あり|15%|126 / 3連 47.62%|

現行維持は1Cの全3用途と4C first（★★★）。

## 展示前fallback

展示依存は2C first、4C top3、5C first、6C top2、6C top3の5用途だけ。展示前・展示欠損では当該段階をML置換せず従来サインを残す。残る9用途は展示前からML判定する。Python/joblib/manifest異常はAPIの既存try/catchによりEDG全体を従来サインへ戻す。

## 再現・ライブ確認

- artifactの14用途について、algorithm / groups / feature_names / uses_exhibition / threshold / VALID coverage / TEST選択件数 / 対象率が候補検証JSONと全件一致。
- manifest SHA256とjoblib SHA256が一致。
- 2026-09-30（展示なし）: 2C top2、3C、5C top2/top3、6C firstなど展示非依存用途が展示前で推論。展示依存用途は抑止。
- 2026-09-18（展示あり）: 2C first、5C first、6C top2を展示反映で確認。PHP APIの`center_ml.applied=true`、version一致を確認。
- APIは1Cを従来サインのまま返す。4Cは★★をML、★★★は部分置換の採用対象外。
- 6C firstは通常の`selected_target=first`として保存payloadへ残るため、course=6との組合せで後から個別集計可能。

## snapshot / 表示 / 回帰

- `PredictionForwardSnapshotStore::courseSignalLogicVersion()`にEDG joblibを追加。
- 同一versionのTOP snapshotは有効、不一致versionは既存の場別API直取得へfallbackすることを確認。
- API短期キャッシュ名をv13からv14へ更新。
- PC、アプリ、TOPの各JavaScriptに`edogawa_course_signal_v1`を既存v1表示として追加。
- 既存10場（TMG/TDA/OMR/SMS/SME/KRY/BWK/ASY/AMG/HWJ）は各ライブ経路で`status=ok`と既存versionを確認。
- AI1着率forward、race_number、買い目、最終予想には変更なし。

## 注意

6C firstは改善幅が小さい候補であるため、2026-10-01以降のstrict前方保存でcourse=6・selected_target=firstを個別評価する。
