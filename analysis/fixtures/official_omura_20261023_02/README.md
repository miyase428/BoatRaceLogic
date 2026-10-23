# 大村 2026-10-23 2R 最小fixture

これは、2026-10-23に各1回だけ取得した公式レスポンスから、パーサーが使う日付・レース選択・登録番号・展示計測行だけを残した最小fixtureです。完全な公式HTMLや追加取得データは含めません。

- start: `https://omurakyotei.jp/yosou/start.php?day=20261023&race=02`
- direct info: `https://omurakyotei.jp/yosou/chokuzen.php?day=20261023&race=02`
- detail: `https://pic.omurakyotei.jp/yosou/sp/syussou/?day=20261023&race=02`

テストは URL パラメータだけを信頼せず、各ページ本文に含まれる選択済み日付・2R・6艇の登録番号・値の相互一致を確認する。
