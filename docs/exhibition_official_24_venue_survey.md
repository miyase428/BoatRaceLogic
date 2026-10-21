# 全国24場・公式展示情報取得への完全移行 — Phase 1 調査・移行設計

- 調査日時: 2026-10-21 JST
- 調査範囲: 公開されている公式ページの通常閲覧、および独立 clone 内の静的ソース確認
- 非実施: 本番取得・DB/API・Playwright・cron・Raspberry Pi・snapshot・scheduler の実行または変更
- 基点: GitHub `main` `b4416ed09a6cca2351e33391b1d3306b97a2fa37`

## 結論

競艇日和に結合した単一スクレーパーを、場別アダプタと共通正規化・品質判定に置換する設計は可能である。ただし、24場の独自サイトが同じ項目・同じHTML/APIを公開することはこの調査だけでは確認できない。まず **BOAT RACE公式の直前情報を補助的な基準面** として使い、各場の独自サイトで必要な4種の走行タイム（展示・周回・まわり足・直線）を取得できることを場単位で実証してから切り替える。

公式共通サイトの直前情報は `hd=YYYYMMDD`、`jcd=場コード`、`rno=レース番号` で対象を表せる。実例では展示タイム、進入とスタート展示、締切予定時刻を表示する。周回・まわり足・直線は共通ページの確認範囲では公開を確認できなかったため、既存の完全性要件を満たす主取得元とはまだ判断しない。

## 調査根拠

- [BOAT RACE公式・24場一覧](https://www.boatrace.jp/owpc/pc/site/place/stadium/index.html) は対象24場と各場公式サイトへのリンクを掲載している。
- [BOAT RACE公式・直前情報の参照URL（戸田）](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=20260813&jcd=02&rno=99) はURL形式と画面項目の確認に使用した。`rno=99` は実レース番号ではなく、実取得成功・データ保存の根拠ではない。実装時は開催日・1〜12Rの実在レースだけを対象にする。
- [BOAT RACE公式・直前情報の実例（多摩川）](https://www.boatrace.jp/owsp/sp/race/beforeinfo?hd=20260901&jcd=05&rno=1) は展示タイムとスタート展示を確認できる。
- [BOAT RACE公式スマートフォン利用案内](https://boatrace.jp/static_extra/pc_static/images/extra/tb/support/guide/boatrace_smartphone_web_manual_all.pdf) は、直前情報が時間経過で変化し、展示情報に進入・ST・展示タイムを表示することを説明している。

以降の「確認済み」は上記の公式共通サイト、または明示した各場公式サイトの通常閲覧で確認したものに限る。「未確認」は情報がないという意味ではなく、今回の低頻度・非自動化の調査では取得契約を確認していないという意味である。

## 24場比較表

共通公式URLの `jcd` は各場で確定している。`YYYYMMDD` と `R` を実開催日・1〜12に置換する。

|#|場|jcd|場別公式サイト（確認できた入口）|公式直前情報URL（補助）|独自直前情報の確認|展示進入/ST/展示タイム|周回/まわり足/直線|候補方式・難度|
|---:|---|---:|---|---|---|---|---|---|
|1|桐生|01|[桐生](https://www.kiryu-kyotei.com/)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=01&rno=R)|直前・展示情報の案内を確認|共通面で確認|未確認|独自ページ調査後、HTML/XHR。中|
|2|戸田|02|[戸田](https://www.boatrace-toda.jp/)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=02&rno=R)|独自「展示情報」「オリジナル展示データ」を確認|独自で進入/ST/展示タイムを確認|未確認|先行PoC候補。中|
|3|江戸川|03|[江戸川](https://www.boatrace-edogawa.com/)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=03&rno=R)|独自スマホ出走表に直前情報を確認|展示タイムを確認、ST等は要契約確認|未確認|HTML/JSON調査。中|
|4|平和島|04|[平和島](https://www.heiwajima.gr.jp/)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=04&rno=R)|競技情報・展示リプレイを確認|数値表の契約は未確認|未確認|独自画面/XHR確認。中〜高|
|5|多摩川|05|[多摩川](https://www.boatrace-tamagawa.com/)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=05&rno=R)|独自「直前情報」「スタート展示」「オリジナル展示データ」を確認|独自で進入/ST/展示タイムを確認|未確認|先行PoC候補。中|
|6|浜名湖|06|[浜名湖](https://www.boatrace-hamanako.jp/)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=06&rno=R)|独自数値ページ未確認|共通面で確認|未確認|公式独自面を確認後。中|
|7|蒲郡|07|[蒲郡](https://www.gamagori-kyotei.com/)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=07&rno=R)|公式にスタート展示時刻を確認|共通面で確認|未確認|開催中ページ/APIを確認。中|
|8|常滑|08|[常滑](https://www.boatrace-tokoname.jp/)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=08&rno=R)|独自数値ページ未確認|共通面で確認|未確認|公式独自面を確認後。中|
|9|津|09|[公式サイト一覧経由](https://www.boatrace.jp/owpc/pc/site/place/stadium/index.html)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=09&rno=R)|未確認|共通面で確認|未確認|公式リンク先の契約確認。中|
|10|三国|10|[三国](https://www.b-race.jp/)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=10&rno=R)|独自数値ページ未確認|共通面で確認|未確認|公式独自面を確認後。中|
|11|びわこ|11|[びわこ](https://www.boatrace-biwako.jp/)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=11&rno=R)|独自「直前情報」導線を確認|共通面で確認|未確認|公式独自面を確認後。中|
|12|住之江|12|[住之江](https://www.boatrace-suminoe.jp/)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=12&rno=R)|独自数値ページ未確認|共通面で確認|未確認|公式独自面を確認後。中|
|13|尼崎|13|[尼崎](https://boatrace-amagasaki.jp/)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=13&rno=R)|独自直前情報・オリジナル展示タイムを確認|共通面で確認|独自項目の契約未確認|先行PoC候補。中|
|14|鳴門|14|[公式サイト一覧経由](https://www.boatrace.jp/owpc/pc/site/place/stadium/index.html)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=14&rno=R)|未確認|共通面で確認|未確認|公式リンク先の契約確認。中|
|15|丸亀|15|[公式サイト一覧経由](https://www.boatrace.jp/owpc/pc/site/place/stadium/index.html)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=15&rno=R)|未確認|共通面で確認|未確認|公式リンク先の契約確認。中|
|16|児島|16|[公式サイト一覧経由](https://www.boatrace.jp/owpc/pc/site/place/stadium/index.html)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=16&rno=R)|未確認|共通面で確認|未確認|公式リンク先の契約確認。中|
|17|宮島|17|[宮島](https://www.boatrace-miyajima.com/)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=17&rno=R)|独自数値ページ未確認|共通面で確認|未確認|公式独自面を確認後。中|
|18|徳山|18|[徳山](https://www.boatrace-tokuyama.jp/)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=18&rno=R)|独自「直前情報」「展示情報」導線を確認|共通面で確認|未確認|公式独自面を確認後。中|
|19|下関|19|[下関](https://www.boatrace-shimonoseki.jp/)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=19&rno=R)|スタート展示予定時刻を確認|共通面で確認|未確認|公式独自面を確認後。中|
|20|若松|20|[公式サイト一覧経由](https://www.boatrace.jp/owpc/pc/site/place/stadium/index.html)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=20&rno=R)|未確認|共通面で確認|未確認|公式リンク先の契約確認。中|
|21|芦屋|21|[芦屋](https://www.boatrace-ashiya.com/)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=21&rno=R)|独自数値ページ未確認|共通面で確認|未確認|公式独自面を確認後。中|
|22|福岡|22|[公式サイト一覧経由](https://www.boatrace.jp/owpc/pc/site/place/stadium/index.html)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=22&rno=R)|未確認|共通面で確認|未確認|公式リンク先の契約確認。中|
|23|唐津|23|[唐津](https://www.boatrace-karatsu.jp/)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=23&rno=R)|スタート展示予定時刻・直前予想を確認|共通面で確認|未確認|公式独自面を確認後。中|
|24|大村|24|[公式サイト一覧経由](https://www.boatrace.jp/owpc/pc/site/place/stadium/index.html)|[共通](https://www.boatrace.jp/owpc/pc/race/beforeinfo?hd=YYYYMMDD&jcd=24&rno=R)|未確認|共通面で確認|未確認|公式リンク先の契約確認。中|

### 項目対応の現時点

|項目|公式共通直前情報|場別公式サイト|移行の扱い|
|---|---|---|---|
|選手・艇番|確認済み|場別に要確認|選手IDと出走表照合を必須化|
|展示進入|スマホ案内で確認|場別に要確認|1〜6の一意性を必須化|
|展示ST|スマホ案内で確認|場別に要確認|`F`/`L` を文字列保持後に正規化|
|展示タイム|確認済み|戸田・多摩川等で表示確認|数値、0以下を欠損扱い|
|周回タイム|今回未確認|今回未確認|未提供を 0 で補完しない|
|まわり足タイム|今回未確認|今回未確認|未提供を 0 で補完しない|
|直線タイム|今回未確認|今回未確認|場別の恒久未公表は capability で表現|
|締切予定時刻|確認済み|場別に要確認|公式deadline source と突合|

## 取得方式のグループ

1. **G1: 場別独自ページで展示表と必要なタイム全項目を実証済みの場** — Phase 1 時点では該当を確定しない。戸田・多摩川・尼崎は独自展示導線が確認でき、優先的にPoCする。
2. **G2: 場別独自ページで直前情報または展示導線を確認済みだが、データ契約未確定の場** — 桐生、江戸川、平和島、蒲郡、びわこ、徳山、下関、唐津を含む。開催日に1場ずつ通常閲覧でHTML/XHR・利用条件を確認する。
3. **G3: 公式サイトの入口は確認済みだが、今回の範囲では展示数値の独自公開契約を未確認の場** — 浜名湖、常滑、津、三国、住之江、鳴門、丸亀、児島、宮島、若松、芦屋、福岡、大村。公式サイト一覧から手動確認を行うまで実装対象にしない。
4. **G4: BOAT RACE公式共通直前情報** — 24場共通の補助面。展示タイム・進入・ST・締切の照合、場別アダプタの監査に使う。ユーザー要件上、場別公式HPを置換して主取得元にする判断はしない。

## 現行システムの確認結果

### 取得経路

- `logic/race_url.php` は `raceCodeToKyoteiBiyoriUrl()` で競艇日和の固定URLを組み立てる。
- `logic/scrape_exhibition.php` と `public/update_exhibition.php` はそのURLを `playwright/exhibition_live_scraper.js` に渡す。
- Playwrightは競艇日和固有のタブとテーブル行をXPath/CSSで読み、`entry_course`、`player_id`、`exhibition_time`、`lap_time`、`around_time`、`straight_time`、`start_timing` の6艇配列を返す。
- `logic/exhibition_data_quality.php` は、6艇・重複なし進入・選手ID・ST・展示/周回/まわり足と、原則直線の正値を要求する。尼崎・徳山・住之江だけは直線なしを許容する現在の例外である。
- `logic/exhibition_source_guard.php` は競艇日和用の失敗2回・30分cooldown状態をTMPDIRに記録する。
- `common/save_exhibition.php` と各呼出しは `boat_race.exhibition_live` にUPSERTする。現状のDBスキーマには取得元URL・取得時刻・項目別公開状態の列が見えない。
- `public/update_exhibition.php` は完全6艇なら外部再取得を省略する。

### 運用経路と影響

- `analysis/run_all_race_forward_capture_cron.sh` は日中、`capture_all_race_forward.php --db-only` を起動し、外部展示取得を行わない。
- `capture_all_race_forward.php` は締切6〜2分前を候補とし、DB上で展示が未完備なら `ApiClientProduction::updateExhibition()` を呼ぶ設計である。ただしcron wrapperは `--db-only` のため、日中取得の実運用はAPI手動経路・別collector・未確認のscheduler設定に依存する。
- `analysis/run_nightly_exhibition_and_replay.sh` は既存の23時処理として `logic/scrape_exhibition.php` と夜間再現を順に実行する。Raspberry Pi側の実設定はリポジトリ静的調査だけでは確認できない。
- Strict Forward用の `analysis/collect_strict_forward_exhibition.php` は正式Window候補に対して既存 `ApiClientProduction` の更新経路を呼ぶ。取得元変更はこの経路にも明示的に注入する必要があるが、今回変更していない。

### 現行の「6艇ならスキップ」の限界

完全性は現在、全タイムを要求する一括真偽値である。場別公開が段階的な場合、既に6艇の一部データを保存してしまう、または恒久的に未公表な直線を空値として扱えない、という問題がある。移行時は「6艇登録済み」ではなく、次を持つレース単位の品質状態に変更する必要がある。

- `source_id`, `source_url`, `fetched_at`, `published_at`（判明時のみ）
- 項目ごとの `present / not_published / not_yet_published / invalid`
- 選手ID・枠番・展示進入の照合結果
- 同じsource revisionかつ品質が**改善**する場合だけ更新を許す比較規則
- 現在より低い品質、選手不一致、締切後、時刻不明の入力による上書き拒否

## 共通データ形式案

```json
{
  "schema": "official-exhibition/v1",
  "race_code": "YYYYMMDDTMG01",
  "venue_code": "TMG",
  "source": {
    "kind": "venue_official",
    "adapter": "tamagawa/v1",
    "url": "https://…",
    "fetched_at": "2026-10-21T12:34:56+09:00",
    "published_at": null
  },
  "deadline": {"scheduled_at": "…", "verified": true},
  "boats": [
    {
      "lane": 1,
      "player_id": "1234",
      "exhibition_course": 1,
      "start_timing_raw": "F.01",
      "start_timing_seconds": -0.01,
      "exhibition_time": 6.78,
      "lap_time": null,
      "around_time": null,
      "straight_time": null,
      "availability": {
        "lap_time": "not_published",
        "around_time": "not_published",
        "straight_time": "not_published"
      }
    }
  ],
  "quality": {"boat_count": 6, "identity_verified": true, "complete_for_profile": false}
}
```

`null` は「値なし」であり0ではない。source別capability（必須・任意・恒久未公表）をアダプタ設定に置く。F/Lは数値化前の原文を保存し、Fは負、Lは正という既存変換規則を共通化する。

## 実装設計

```
各場公式HP
  -> venue adapter (URL生成 / 取得方式 / parser / capability)
  -> official-exhibition/v1 正規化
  -> identity + deadline + 6艇品質検証
  -> quality-improving write gate
  -> 既存 exhibition_live 保存アダプタ
  -> Web・展示評価・②ML・③展開AI
```

- **アダプタは場別**: HTML、XHR/JSON、必要時のみPlaywrightを明示する。公開JSON/HTMLが使える場にPlaywrightを強制しない。
- **日中運用**: 公式の展示公開後、各レースの締切前に一回だけ候補化する。開始は「展示未公開」の場合に短い再試行窓を設けても、締切安全余裕に達したら停止する。具体的な回数・間隔は各公式サイトの利用条件確認後に決める。
- **負荷保護**: venue単位のtoken bucket / circuit breakerを持ち、403・429・5xx・CAPTCHA・認証要求は即停止し、回避・連続リトライをしない。
- **前方性**: `fetched_at < deadline - safety_margin` を満たし、取得時刻・URL・内容SHAをsnapshot provenanceに記録できるまで、正式前方入力として利用しない。
- **切替**: まず保存候補を影響なしのshadow監査で比較し、完全性・選手照合・締切前取得の実証後にvenue単位で有効化する。競艇日和の停止は全場の受入条件を満たした後だけにする。

## 公開タイミング・利用条件・アクセス制限

- 直前情報は展示実施後に変化する情報であり、公式スマホ案内も更新操作を案内している。全場共通の公開確定時刻は今回確認できない。
- 蒲郡、浜名湖、唐津、下関などでは開催案内にスタート展示予定時刻が見られるが、全レース・全節での機械可読な保証ではない。
- robots.txt、利用規約、API利用許諾、ログイン・CAPTCHA有無は各場で個別に未確認。実装前に一場ずつ確認し、禁止・制限があればその場を対象外にする。
- CAPTCHA、認証、WAF、robots拒否、403/429/5xxを回避する実装は採用しない。

## 移行順序

1. **基盤**: source/capability/品質状態/provenanceを表すDTOと、既存DBを汚さないshadow記録先を作る。
2. **PoC 1場**: 戸田または多摩川で、利用条件と実際の全項目公開を開催日に手動確認。アダプタfixtureを作る。
3. **同形式の少数場**: 尼崎、江戸川、蒲郡など、HTML/XHR契約が明示できた場だけを追加する。
4. **全24場**: 場ごとの公開項目プロファイルと受入テストを満たしたものから追加する。未対応場は既存取得を維持する。
5. **shadow比較**: race_code単位で選手・進入・ST・時刻・完全性を比較し、既存正常値を絶対に低品質値で置換しない。
6. **venue単位切替**: 7日以上の安定取得など、別途定義する客観受入条件を満たしてから切替える。
7. **完全移行**: 24場の契約・運用監視・ロールバックが揃った後にのみ競艇日和取得を停止する。

## 次Phaseで実装すべき内容

1. 場別アダプタのインターフェースと `official-exhibition/v1` DTO（副作用なし）。
2. 公式ページの保存済みHTML fixture だけを使う正規化・品質テスト。
3. 戸田または多摩川の1場のみで、公式利用条件・robotsを確認した上でread-only adapterを実装する。
4. 取得元・時刻・項目公開状態を保存するためのDB移行案を作る。ただし本番適用は別Phaseで判断する。
5. Strict Forward、日中API、夜間replayの呼出し先を切り替え可能にする設計を作り、既存の正式snapshotを変更しない。

## 未確認事項・リスク

- 各場独自サイトのHTML/XHR/JSONの安定性、利用規約、robots、公開時刻、全走行タイムの有無。
- 公式共通サイトが周回・まわり足・直線を提供しない場合の、②ML・③展開AIのfeature profileと既存DB品質規則への影響。
- 本番のRaspberry Pi、root crontab、外部schedulerの実稼働設定。
- 既存DBのschema変更、履歴のsource provenance追加、古い展示データとの互換性。
- 日中取得の時間帯がレース間隔・ナイター・ミッドナイト・順延に対して十分か。

この文書は移行判断の設計資料であり、いずれのサイトにも自動アクセス、保存、cron登録、Playwright実行を行っていない。
