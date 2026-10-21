# 尼崎公式展示情報: 日中取得・保存フロー案

このフローは準備実装です。既存のPHP入口、cron、Raspberry Pi、DB、Strict Forward
のコードには接続していません。公式サイトの自動取得条件が未確認であるため、ルーター
は既定で `OFFICIAL_AUTOMATION_NOT_APPROVED` を返します。

## 現行処理との接点

| 経路 | 現在の挙動 | 将来の接続点 | このPhaseでの変更 |
| --- | --- | --- | --- |
| 日中前向き保存 | `capture_all_race_forward.php` が公式締切を読み、締切6〜2分前に既存APIを1回呼出 | API呼出直前の場別取得ルーター | なし |
| Strict Forward展示collector | 締切15〜4分前に既存APIを呼出 | 同じ場別取得ルーターと監査済み取得時刻 | なし |
| Web個別更新 | `update_exhibition.php` が直接競艇日和用Playwrightを実行 | 同じ場別取得ルーター | なし |
| エラーURL再試行 | `error_urls.txt` の競艇日和URLを再試行 | 既存URLはレガシー専用として維持 | なし |
| 夜間23時処理 | `run_nightly_exhibition_and_replay.sh` が一括取得後に再現 | 監査済み公式保存済みAMGを非上書きにするポリシー | なし |

## 日中の候補判定

1. 既存の `OfficialDeadlineLogic` が返す公式締切を入力にする。
2. `AMG` かつ `20261021` 以降だけ公式対象とする。他23場と旧AMGは既存経路のまま。
3. 公式自動取得の承認がない場合は停止する。競艇日和へのフォールバックはしない。
4. 締切の120秒以内、締切不明、既存値が部分保存・出所不明・不一致、または公式試行済みなら取得しない。
5. 公式ページが未公開なら保存成功にせず、次回試行はアクセス頻度の利用条件と試行監査方式が決まるまで自動化しない。
6. 公開済みpayloadは出走表と6艇を照合し、3ページ完了後の `fetched_at` がcutoff以下の場合だけ保存候補にする。

## 保存と前向き利用

`OfficialAmagasakiExhibitionTransactionStore` が新規6艇と `exhibition_source_audit_v1`
監査レコードを同一トランザクションで保存します。既存の6艇があっても監査出所なしは
`source_unknown` で、人手監査まで更新しません。監査済み公式値があるAMGは、将来の
夜間処理で競艇日和値に上書きしないポリシーです。

Strict Forwardの可否は保存済み行ではなく、監査済みの `fetched_at <= prediction_cutoff`
を根拠にします。締切後・23時取得・出所不明の行は前向き根拠にしません。

## 実行元を特定するための読み取り専用確認

Ubuntu と Raspberry Pi のそれぞれで、対象の実行ユーザーとして実行します。設定変更や
ジョブ実行は含みません。

```bash
printf '%s\n' '== user cron =='; crontab -l 2>&1 || true
printf '%s\n' '== root cron, if an administrator explicitly authorizes it =='
sudo crontab -l 2>&1 || true
printf '%s\n' '== system cron =='
grep -RInE 'boatrace|exhibition|late_replay|forward_capture' /etc/crontab /etc/cron.d 2>/dev/null || true
printf '%s\n' '== systemd =='
systemctl list-timers --all 2>&1 | grep -Ei 'boatrace|exhibition|replay|capture' || true
systemctl list-unit-files 2>&1 | grep -Ei 'boatrace|exhibition|replay|capture' || true
printf '%s\n' '== known wrapper references =='
grep -RInE 'run_nightly_exhibition_and_replay|run_all_race_forward_capture|collect_strict_forward_exhibition' /var/www/html/boatrace/analysis /var/www/html/boatrace/logic 2>/dev/null || true
```

## 本番適用前の必須条件

- 尼崎公式サイトの自動取得許可・アクセス頻度の確認
- 実際の日中・23時ジョブのホスト、ユーザー、スクリプト、ログ、重複実行状態の特定
- 監査テーブルSQLのレビューと明示承認、隔離PostgreSQLでのPDO実装テスト
- AMG既存行の出所・値差異の監査と、ロールバック手順の承認
- 4入口を同一ルーターへ接続する変更のレビュー。現時点では未接続
