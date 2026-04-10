# BoatRaceLogic
Windows API server for boat race logic.

## API Server Startup
## 1. API サーバ起動コマンド
php -S 0.0.0.0:8080 -t public

## 2. フォルダ構成の説明
D:\BoatRaceLogic
├─common               # 共通関数・ユーティリティ
├─config               # 設定ファイル（DB接続・APIキーなど）
├─log                  # ログ出力
├─logic                # 既存のPHPロジック・API処理
├─playwright           # スクレイピング関連（Node.js / Playwright）
│   └─node_modules
├─public               # 公開API（Excelや外部から叩くPHP）
├─sample               # サンプルデータ・テスト用
└─theories             # 予測理論・分析ロジック（Python中心）
    ├─new_sam          # 新サム理論
    │   ├─new_sam.py
    │   ├─weights.json
    │   ├─README.md
    │   └─tests
    ├─course_correction # コース別補正ロジック
    │   ├─course_correction.py
    │   └─tests
    └─relative_lap      # 1周タイム相対評価ロジック ※まだない
        ├─relative_lap.py
        └─tests



## 3. 必要な環境（PHP 8.5 など）

- PHP 8.5  
- Windows 11  
- Git  
- VSCode（任意）

## 4. API サーバの起動方法

1. PowerShell を開く  
2. `D:\BoatRaceLogic` に移動  
3. コマンド実行  
4. ブラウザで `http://localhost:8080/test.php` を確認

## 5. 注意点  
- コンソールを閉じると API サーバも終了する  
- 最小化しておけば常時稼働できる  
- 将来はサービス化も可能


##　スクレイピングについて
1/31
対応表作成まで。
実際にスクレイピングのプログラムはこれから。
2/1
Playwrightを使っていく。


## 以下メモ
## $dsn = "pgsql:host=192.168.0.205;port=5432;dbname=devdb;";
$dsn = "pgsql:host=192.168.0.208;port=5432;dbname=devdb;";
$user = "miyase428";
$pass = "herunia0113";

cd C:\Apache24\bin
./httpd.exe
