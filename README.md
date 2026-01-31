# BoatRaceLogic
Windows API server for boat race logic.

## API Server Startup
## 1. API サーバ起動コマンド
php -S 0.0.0.0:8080 -t public

## 2. フォルダ構成の説明
BoatRaceLogic/
  public/      ← API エンドポイント
  logic/       ← ロジック計算（PHP/Python）
  models/      ← ML モデル
  config/      ← 設定ファイル

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


## 以下メモ
$dsn = "pgsql:host=192.168.0.205;port=5432;dbname=devdb;";
$user = "miyase428";
$pass = "herunia0113";

cd C:\Apache24\bin
 ./httpd.exe
httpd.exe -k start

httpd.exe -k stop

httpd.exe -k restart