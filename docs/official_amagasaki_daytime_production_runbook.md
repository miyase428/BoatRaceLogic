# 尼崎公式展示・本番導入パッケージ

このパッケージは、`20261021`以降の`AMG`だけを対象にします。通常状態では既存の取得経路を維持し、公式HPには接続しません。公式ルーティングと自動取得承認の二つを明示して初めて、尼崎対象レースを日中ジョブが所有します。ほかの23場と、2026-10-20以前の尼崎は既存経路のままです。

## 導入前に必ず満たす条件

1. 尼崎公式HPの自動取得条件、頻度、利用条件を運用者が確認・承認している。
2. 日中ジョブの実行ホストと実行ユーザーを確定している。
3. `exhibition_live`のAMG既存行を読み取り監査し、出所不明の完全6艇行を手動レビュー対象としている。`20261021AMG01`を含むこの種の行は自動上書きしない。
4. DBバックアップと復旧手順を実施可能な状態で確認している。
5. Strict Forwardの`DECLARED_IMPLEMENTATION_IDENTITY_MISMATCH`は別問題として残す。この日中ジョブはその認可を変更・迂回しない。

## 適用順序（承認後にだけ実行）

1. 対象DB・ユーザーを明示して、読み取り専用の事前確認を行う。

```bash
psql "$BOATRACE_DB_DSN" -v ON_ERROR_STOP=1 -f analysis/sql/official_amagasaki_daytime_preflight_v1.sql
```

2. DB運用担当者が、対象DBであることを再確認してバックアップを取得する。バックアップ先は本番ディスクとは別の保護された場所を使う。

```bash
pg_dump "$BOATRACE_DB_DSN" --schema=boat_race --format=custom --file="/secure/backups/boat_race_before_amagasaki_official_$(date +%Y%m%dT%H%M%S).dump"
```

3. 次の提案SQLを、DB運用担当者が順に適用する。いずれも既存`exhibition_live`を変更・初期化しない。

```bash
psql "$BOATRACE_DB_DSN" -v ON_ERROR_STOP=1 -f analysis/sql/create_official_exhibition_audit_v1.sql
psql "$BOATRACE_DB_DSN" -v ON_ERROR_STOP=1 -f analysis/sql/create_official_exhibition_attempt_state_v1.sql
```

4. 事前確認SQLを再実行し、二つの追加テーブルだけが存在することを確認する。次に、公式サイトへ接続しないdry-runを行う。

```bash
php analysis/run_official_amagasaki_daytime_job.php --date=YYYYMMDD --dry-run
```

5. 初回運用は、利用条件の承認後だけ、監視下で単発実行する。二つの環境変数を同時に与えない限り、実行モードは公式取得前に停止する。

```bash
OFFICIAL_AMAGASAKI_ROUTE_ENABLED=1 OFFICIAL_AMAGASAKI_AUTOMATION_APPROVED=1 \
  php analysis/run_official_amagasaki_daytime_job.php --date=YYYYMMDD --execute
```

6. 単発運用で監査・attempt状態・ログを確認してから、1分周期のテンプレートを出力する。テンプレート出力自体はcrontabを変更しない。

```bash
analysis/print_official_amagasaki_daytime_cron_template.sh --print
```

## 競合・上書き防止

`OFFICIAL_AMAGASAKI_ROUTE_ENABLED=1`かつ`OFFICIAL_AMAGASAKI_AUTOMATION_APPROVED=1`のとき、nightly、Web個別更新、error URL retry、Strict Forward collectorの既存取得経路は、AMG対象レースを公式日中ジョブ所有として保存前に停止します。監査済み公式行は、切替後にフラグを落としても既存経路から上書きしません。

完全な既存6艇で監査がない行は`EXISTING_SOURCE_UNKNOWN_REQUIRES_REVIEW`として停止します。これは既存値を公式値と偽装しないためで、別レースの取得を止めません。

公式ルートを有効にしたのに自動取得承認がない場合は、競艇日和への自動フォールバックをせず`OFFICIAL_AUTOMATION_NOT_APPROVED`で停止します。フラグを設定しない通常状態では現行の取得を変更しません。

## 監視と緊急停止

- ログ: `logs/official_amagasaki_daytime.log`
- DB監査: `boat_race.exhibition_source_audit_v1`
- 試行状態: `boat_race.official_exhibition_attempt_state_v1`
- 取得開始は締切10〜8分前、未公開だけは5〜2分前に一度だけ再試行する。2分前以降は開始しない。

緊急停止は、日中cron登録だけを外し、二つの環境変数をジョブ環境から外す。既存データや監査テーブルを削除しない。DB復旧が必要な場合は導入前バックアップをDB運用担当者の手順で復元し、先に日中cronを停止する。

## 意図的に今回の対象外とする事項

- Strict Forwardのidentity mismatchの修復
- 過去snapshot・ML学習データ・正式証跡の再生成
- 尼崎以外の場の切替
- 既存AMG行の自動再分類・上書き
