# ChatGPT分析用CSV抽出

`export_chat_analysis_dataset.sql` は、結果確定済みのレースを
**1 race_code × 1艇（通常は1レース6行）** でCSVに出力するためのpsqlスクリプトです。

このファイルは抽出SQLだけです。DB更新、CSV生成、集計、ML学習は行いません。

## 実行コマンド

リポジトリのルートで、接続先を環境変数または普段使っているpsql接続指定へ置き換えて実行します。

```bash
psql "$DATABASE_URL" -X -v ON_ERROR_STOP=1 \
  -v start_date='2026-09-21' \
  -v end_date='2026-09-27' \
  -v output_csv='/tmp/chat_analysis_20260921_20260927.csv' \
  -f analysis/sql/export_chat_analysis_dataset.sql
```

出力先例は `/tmp/chat_analysis_20260921_20260927.csv` です。`\copy` を使うため、CSVは
**psqlを実行した端末側** に作成されます。

## 使用テーブルと結合

|テーブル|結合|用途|
|---|---|---|
|`race_entry`|基準: `race_code + lane_number`|レース日、場、枠番、選手、モーター、機器ボート番号|
|`race_result_detail`|`race_code + lane_number`|着順、実進入コース。`rank='1'` の別CTEから決まり手・勝者コース|
|`race_payouts`|`race_code`|2連単・3連単の出目と払戻|
|`player_stats`|`race_code + player_id`|全国／当地勝率・2連率|
|`engine_specs`|`race_code + motor_number`|モーター・ボート2連率|
|`racer_results`|`player_id + term_info`|平均ST。期別は既存ライブ推論と同じレース日からの算出式|
|`exhibition_live`|`race_code + player_id`|展示進入・展示タイム・展示ST・周回／まわり足／直線。最新`created_date`を優先して1艇1行へ固定|

`boat_number` は分析で扱いやすい枠番（`race_entry.lane_number`、1～6）です。
DBに保存されている機器としてのボート番号は `boat_serial_number` として別列です。

## 出力列

- レース: `race_code`, `race_date`, `stadium`, `race_number`
- 艇: `boat_number`, `boat_serial_number`, `course`, `player_id`
- 選手・機力: `national_win_rate`, `local_win_rate`, `national_2ren_rate`, `local_2ren_rate`, `average_st`, `motor_number`, `motor_2ren_rate`, `boat_2ren_rate`
- 展示: `exhibition_course`, `exhibition_time`, `exhibition_st`, `lap_time`, `around_time`, `straight_time`
- 結果: `finish_order`, `kimarite`, `winner_course`, `exacta_combination`, `exacta_payout`, `trifecta_combination`, `trifecta_payout`

## 今回は含めない項目

一次評価、二次評価、最終予想、AI 1着率、AI 3連対率、`kiru` は未接続です。
現行コードでは主にリクエスト時に算出されています。DBにある
`prediction_forward_snapshots` はレース単位・JSON payload・stage/component/version単位の複数行であり、
1レース×1艇への安全で一意なJOIN先ではないため、この汎用CSVには混ぜていません。
`air_predictions` もユーザー／プロフィール別の買い目であり、艇単位の予想値ではないため未接続です。
