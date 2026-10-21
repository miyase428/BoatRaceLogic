# 尼崎公式展示の日中統合フロー案

この文書と実装は隔離clone専用です。既存の夜間、Web、エラー再試行、Strict Forwardの本番入口には接続していません。

## 実行の境界

`analysis/run_official_amagasaki_daytime_integration.php` は現在、`--dry-run --plan-json=FILE` だけを受け付けます。dry-runは計画判定だけを返し、公式サイト、DB、試行状態、ファイルへ書き込みません。通常モードは自動取得承認・実行ホスト・DBマイグレーションが確定するまで実装上も利用不能です。

通常モードの将来フローは次の順番です。

1. 公式締切と出走表を読み、AMGかつ2026-10-21以降だけを候補にする。
2. 既存6艇と監査の状態を `none`、`already_official`、`partial`、`source_unknown` に分類する。
3. `OfficialAmagasakiDaytimeFlow` が10〜8分前の初回または、未公開後5〜2分前の再試行だけを選ぶ。
4. PostgreSQLのversion付きCASで試行権を取得する。失敗時は外部取得しない。
5. 承認済みの場合だけNodeの尼崎アダプタを呼ぶ。
6. 取得完了時刻が締切2分前かつprediction cutoff以前であることを確認する。
7. 6艇、監査、試行状態の完了を同一PostgreSQLトランザクションで確定する。

403、429、認証、CAPTCHA、解析・品質不良、出所不明、stale claimは自動再試行しません。競艇日和へのフォールバックもしません。

## SQLと同時実行

`analysis/sql/create_official_exhibition_attempt_state_v1.sql` は提案だけです。初回claimは`INSERT ... ON CONFLICT DO NOTHING`、既存状態のclaimと完了はversionとclaim tokenを条件にしたCASです。claimの所有者以外は完了状態へ更新できません。

`PdoOfficialAmagasakiClaimedPersistence` は、既存の展示保存層を同一PDOトランザクション内で呼び、6艇・監査・試行状態の完了をまとめます。トランザクション失敗時は成功扱いを返しません。

## 起動周期

120秒周期は、遅延なしなら10〜8分前の120秒窓へ1回だけ入ります。121秒以上の開始遅延、前回ジョブの長時間化、またはlock待機だけで初回窓を失います。1分周期なら短い遅延に対する予備機会ができますが、公式サイトの利用条件、実行ホスト、重複防止、監査テーブルの承認を満たすまで本番登録しません。

## 4入口とStrict Forward

`OfficialExhibitionSourceRouter` の`nightly`、`web`、`retry`、`strict_forward`は同じAMG判定を持ちます。ただし、本番の4入口は今回も未接続です。監査済み公式行を夜間が上書きしない既存ポリシーは維持します。

Strict Forwardへの利用は、保存された行の存在ではなく、公式の最終取得完了時刻がprediction cutoff以前であることが必要です。締切5〜2分前の再試行は、それだけで前向き証跡の条件を満たしません。
