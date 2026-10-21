# 尼崎公式展示情報: 本番反映前の確認手順

この文書は準備中の公式取得・保存層の運用前確認用です。ここにある SQL は
適用せず、公式サイトの自動取得許可、監査テーブルの承認、隔離DBテスト、実行元の
特定がそろうまで公式取得を有効化しません。

## 23時ジョブの読み取り専用確認

Ubuntu と Raspberry Pi で、それぞれ対象ユーザーとして次を実行してください。
`sudo` が必要な行は管理者が内容を確認してから個別に実行します。設定を変更する
コマンド、サービスを再起動するコマンド、ジョブを起動するコマンドは含みません。

```bash
printf '%s\n' '== user cron =='
crontab -l 2>&1 || true
printf '%s\n' '== system cron definitions =='
grep -RInE 'boatrace|scrape_exhibition|nightly_exhibition|late_replay' /etc/crontab /etc/cron.d 2>/dev/null || true
printf '%s\n' '== systemd units/timers =='
systemctl list-timers --all 2>&1 | grep -Ei 'boatrace|exhibition|replay' || true
systemctl list-unit-files 2>&1 | grep -Ei 'boatrace|exhibition|replay' || true
printf '%s\n' '== repository wrappers =='
grep -RInE 'scrape_exhibition|run_nightly_exhibition_and_replay|late_replay' /var/www/html/boatrace/analysis /var/www/html/boatrace/logic 2>/dev/null || true
```

記録するのは、ホスト名、実行ユーザー、実行時刻、実行コマンド、ログ出力先、重複起動
の有無だけです。root の crontab は、承認済みの管理者セッションで `sudo crontab -l`
を別途読み取り専用で確認します。

## 前向き予測の境界

監査上の `fetched_at` は3ページの取得と相互検証を終えた UTC 時刻です。23時の夜間
取得値は当日締切前の観測事実にはならず、Strict Forward 入力には
`fetched_at <= prediction_cutoff` を満たす場合だけ利用可能です。
