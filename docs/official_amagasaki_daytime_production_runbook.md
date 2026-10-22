# 尼崎公式展示・Web手動取得と23時補完の導入手順

対象は`20261021`以降の`AMG`だけです。日中はWeb/PWAの「展示情報を更新」を押したレースだけ尼崎公式HPから取得し、23時JST台は保存できなかった同日レースだけを競艇日和で補完します。公式パーサー未対応の23場は手動画面で未対応を明示し、日中の競艇日和へフォールバックしません。

この文書のSQL、cron、公式HTTPアクセスは**明示承認後だけ**実行します。コードを配置しただけでは共有制御行が無効なので、公式アクセスも夜間補完も始まりません。

## 保存条件

- 日中公式: Web/PWAのCSRF確認済みPOSTを受けた1レースだけ取得します。定期ジョブと自動再試行は使いません。締切前後は監査マーカーで区別します。
- 23時補完: 専用ラッパー、JST 23:00〜23:59、当日のAMG、`nightly_backfill_enabled=true`、公式監査なし、`exhibition_live`の物理行数ゼロをすべて満たす場合だけ保存します。
- 部分行、品質不完全行、出所不明の6艇、公式監査済み6艇は補完しません。`20261021AMG01`も自動更新しません。
- 23時の競艇日和データは`kyoteibiyori_nightly`として監査し、`official_amagasaki`やStrict Forwardの締切前証跡として扱いません。

## 締切キャッシュと23時優先処理

- 日中ジョブは`/tmp/boatrace_official_deadlines/deadlines_YYYYMMDD.json`にAMG全12Rの`HH:MM`がそろった場合だけ候補を作成します。欠損・破損・読込不能なら`OFFICIAL_DEADLINE_CACHE_UNAVAILABLE`で停止し、公式展示へ接続しません。
- `analysis/prepare_official_amagasaki_deadline_cache.php --execute`は、共有制御行で日中公式取得が承認済みの場合だけ既存の`OfficialDeadlineLogic`を呼びます。同日AMGの全12Rキャッシュが完全なら外部アクセスをせず`CACHE_COMPLETE`を返します。日中ジョブと同じホスト・実行ユーザー（または同じ読取権限）で実行してください。
- 専用23時ラッパーは、過去日の`last_date`処理より当日のAMGを先に処理し、当日内でもAMGを最優先にします。通常の過去日巡回、他23場、23時外のAMGにはこの優先化を適用しません。

## 導入前の必須条件

1. 尼崎公式HPの手動取得条件・回数・頻度を確認し、承認済みであること。
2. Web手動処理と23時ジョブの実行ホスト、実行ユーザー、ログ出力先が確定していること。
3. `20261021AMG01`を含む既存AMG行を読み取り監査し、出所不明行の人手判断が済んでいること。
4. DBバックアップと復旧担当者を確定していること。
5. Strict Forwardの`DECLARED_IMPLEMENTATION_IDENTITY_MISMATCH`を別問題として維持し、認可を変更しないこと。

## 第1段階の安全な配置

この段階では公式HTTP、23時補完、cron登録を有効化しません。本番の作業ツリーをcheckout・reset・cleanせず、GitHubのレビュー済み参照から別ディレクトリで差分を作り、検査後にだけ明示的に適用します。

```bash
# 本番ホスト上。保護領域は運用担当者が事前に作成します。
export BOATRACE_ROOT=/var/www/html/boatrace
export BOATRACE_SAFE=/secure/backups/amagasaki-phase1-$(date +%Y%m%dT%H%M%S)
export AMG_REF=codex/official-exhibition-amagasaki-final-integration
mkdir -p "$BOATRACE_SAFE"
git -C "$BOATRACE_ROOT" status --porcelain=v1 > "$BOATRACE_SAFE/status-before.txt"
git -C "$BOATRACE_ROOT" diff --binary > "$BOATRACE_SAFE/tracked-before.patch"
git -C "$BOATRACE_ROOT" ls-files --others --exclude-standard > "$BOATRACE_SAFE/untracked-before.txt"
```

`status-before.txt`、`tracked-before.patch`、`untracked-before.txt`を確認してから、別の一時cloneでレビュー済み参照のパッチを作ります。アクティブな本番ツリーで`pull`、`checkout`、`reset`、`clean`、`stash`は実行しません。

```bash
git clone --branch "$AMG_REF" --single-branch https://github.com/miyase428/BoatRaceLogic.git "$BOATRACE_SAFE/release"
git -C "$BOATRACE_SAFE/release" diff --binary b4416ed09a6cca2351e33391b1d3306b97a2fa37..HEAD > "$BOATRACE_SAFE/amagasaki-phase1.patch"
git -C "$BOATRACE_ROOT" apply --check "$BOATRACE_SAFE/amagasaki-phase1.patch"
# 上のcheckが成功し、対象一覧を人手確認して承認された場合だけ適用する。
git -C "$BOATRACE_ROOT" apply "$BOATRACE_SAFE/amagasaki-phase1.patch"
```

適用直後も共有制御行は全てfalseのままにし、`git status --porcelain=v1`を`status-before.txt`と比較します。既存の変更と未追跡ファイルが一つでも欠けた場合は、後続のSQL・cron作業を中止し、バックアップパッチを使う前に原因を確認します。

## 承認後の適用順序

1. 対象DBを確認するだけの事前照会を行います。DBバックアップはスキーマ適用より先に行います。

```bash
psql "$BOATRACE_DB_DSN" -v ON_ERROR_STOP=1 \
  -f analysis/sql/official_amagasaki_daytime_preflight_v1.sql
```

2. DB運用担当者が、別保護領域へバックアップを作成します。

```bash
pg_dump "$BOATRACE_DB_DSN" --schema=boat_race --format=custom \
  --file="/secure/backups/boat_race_before_amagasaki_$(date +%Y%m%dT%H%M%S).dump"
```

3. 既存`exhibition_live`を変更しない4本のSQLを順に適用します。

```bash
psql "$BOATRACE_DB_DSN" -v ON_ERROR_STOP=1 -f analysis/sql/create_official_exhibition_audit_v1.sql
psql "$BOATRACE_DB_DSN" -v ON_ERROR_STOP=1 -f analysis/sql/create_official_exhibition_attempt_state_v1.sql
psql "$BOATRACE_DB_DSN" -v ON_ERROR_STOP=1 -f analysis/sql/create_official_exhibition_control_v1.sql
psql "$BOATRACE_DB_DSN" -v ON_ERROR_STOP=1 -f analysis/sql/create_official_exhibition_nightly_backfill_v1.sql
```

4. **第1段階（有効化しない安全な配置）**として、共有制御行がすべて無効であることを確認し、公式HTTPを呼ばないdry-runを行います。cron登録はまだ行いません。

```bash
psql "$BOATRACE_DB_DSN" -c "SELECT route_enabled, automation_approved, nightly_backfill_enabled, retry_interval_seconds FROM boat_race.official_exhibition_control_v1 WHERE control_key='amagasaki';"
php analysis/run_official_amagasaki_daytime_job.php --date=YYYYMMDD --dry-run
php analysis/prepare_official_amagasaki_deadline_cache.php --date=YYYYMMDD --dry-run
```

5. **第2段階（明示承認後の稼働）**では、公式HPの利用条件・頻度、実行ホスト・実行ユーザー、バックアップ復旧担当、AMG既存行監査を確認してから、手動公式取得と23時補完を個別に有効化します。定期自動取得は無効のままにします。

```bash
psql "$BOATRACE_DB_DSN" -v ON_ERROR_STOP=1 -c "UPDATE boat_race.official_exhibition_control_v1 SET route_enabled=true, automation_approved=false, nightly_backfill_enabled=true, retry_interval_seconds=NULL, version=version+1, updated_at=NOW(), updated_by='APPROVED_OPERATOR' WHERE control_key='amagasaki';"
```

6. 締切キャッシュをWeb処理から読める権限で一日一度だけ準備します。出力が`prepared`または`ready`で、AMG全12Rがあることを確認します。Web手動処理は診断HTML保存を無効にし、`www-data`へ0700ディレクトリの権限を与えません。

```bash
php analysis/prepare_official_amagasaki_deadline_cache.php --date=YYYYMMDD --execute
php -r '$p="/tmp/boatrace_official_deadlines/deadlines_YYYYMMDD.json"; $j=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR); $n=0; foreach ($j["deadlines"] as $k=>$v) if (preg_match("/^YYYYMMDDAMG(0[1-9]|1[0-2])$/",$k)&&preg_match("/^\\d{2}:\\d{2}$/",$v)) $n++; if ($n!==12) exit(1); echo "AMG deadlines: $n\\n";'
```

7. CLI単発診断はWeb手動取得とは分離します。診断HTMLを保存する場合は、実行ユーザーが所有するWeb公開外の`/var/lib/boatrace/official-exhibition-diagnostics`を`0700`のまま使用し、承認済みCLI単発実行でだけ`--capture-diagnostic-html`を付けます。

```bash
php analysis/run_official_amagasaki_daytime_job.php --date=YYYYMMDD --only-race=NN --dry-run
# 出力がYYYYMMDDAMGNNだけであることを確認し、明示承認後に一度だけ実行する。
php analysis/run_official_amagasaki_daytime_job.php --date=YYYYMMDD --only-race=NN --execute --capture-diagnostic-html
analysis/print_official_amagasaki_daytime_cron_template.sh --print
```

8. 現在のユーザーcrontabは`23:10 run_late_replay_cron.sh`だけで、尼崎補完ラッパーへ接続されていません。承認後にこの1行を`23:10 run_nightly_exhibition_and_replay.sh`へ置き換えます。新旧を並行登録すると夜間再現が二重になるため禁止します。ラッパーは`run_official_amagasaki_nightly_backfill.sh`を一度実行してから夜間再現へ進みます。

## 監視SQL

```sql
SELECT race_code, source, fetched_at, db_saved_at, result_status, error_reason
FROM boat_race.exhibition_source_audit_v1
WHERE race_code LIKE 'YYYYMMDDAMG%'
ORDER BY db_saved_at, race_code;

SELECT race_code, outcome, attempt_count, retry_not_before, terminal
FROM boat_race.official_exhibition_attempt_state_v1
WHERE race_code LIKE 'YYYYMMDDAMG%'
ORDER BY race_code;
```

日中公式保存は`official_amagasaki`、23時補完は`kyoteibiyori_nightly`です。後者は正式前方証跡へ利用しません。

## 緊急停止・復旧

最初に共有制御行を無効化し、次に日中cronを外します。夜間補完も同じ行で停止します。監査や既存展示行を削除しません。

```bash
psql "$BOATRACE_DB_DSN" -v ON_ERROR_STOP=1 -c "UPDATE boat_race.official_exhibition_control_v1 SET route_enabled=false, automation_approved=false, nightly_backfill_enabled=false, retry_interval_seconds=NULL, version=version+1, updated_at=NOW(), updated_by='EMERGENCY_STOP' WHERE control_key='amagasaki';"
```

DB復旧が必要な場合は、日中・夜間のジョブを先に止め、導入前バックアップをDB運用担当者の承認済み手順で復元します。本番作業ツリーに既存の未コミット変更があるため、`reset`、`clean`、強制checkoutでの配置は禁止です。別のリリースディレクトリまたはパッチ内容を確認した明示配置を用います。
