<?php
require_once __DIR__ . '/../common/db_connect.php';

// 画像通りの全24場・英字3桁コードマップ
$stadium_codes = [
    'OMR' => '大村',
    'KRY' => '桐生',
    'TDA' => '戸田',
    'EDG' => '江戸川',
    'HWJ' => '平和島',
    'TMG' => '多摩川',
    'HMN' => '浜名湖',
    'GMG' => '蒲郡',
    'TKN' => '常滑',
    'TSU' => '津',
    'MKN' => '三国',
    'BWK' => 'びわこ',
    'SME' => '住之江',
    'AMG' => '尼崎',
    'NRT' => '鳴門',
    'MRG' => '丸亀',
    'KJM' => '児島',
    'MYJ' => '宮島',
    'TKY' => '徳山',
    'SMS' => '下関',
    'WKM' => '若松',
    'ASY' => '芦屋',
    'FKO' => '福岡',
    'KRT' => '唐津',
];

// フォーム入力値の取得
$selected_date   = $_GET['date'] ?? date('Y-m-d');
$selected_place  = $_GET['place'] ?? 'OMR'; // デフォルト：大村
$selected_race   = $_GET['race'] ?? '07';   // デフォルト：07R

// YYYYMMDD 形式に変換
$formatted_date  = date('Ymd', strtotime($selected_date));

// レースコードの生成 (例: 20260724 + OMR + 07 = 20260724OMR07)
$race_code       = $formatted_date . $selected_place . sprintf('%02d', $selected_race);

// DB接続テスト
$db_status_msg = '';
$is_success = false;

try {
    $pdo = getPDO();
    $db_status_msg = "PostgreSQL: 接続成功！";
    $is_success = true;
} catch (Throwable $e) {
    $db_status_msg = "接続エラー: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BoatRace Analytics</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            background-color: #0f172a;
            color: #f8fafc;
            margin: 0;
            padding: 30px 20px;
            display: flex;
            justify-content: center;
        }
        .container {
            max-width: 700px;
            width: 100%;
            background-color: #1e293b;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
            border: 1px solid #334155;
        }
        h1 {
            color: #38bdf8;
            font-size: 22px;
            margin-top: 0;
            border-bottom: 2px solid #334155;
            padding-bottom: 12px;
        }
        .form-section {
            background-color: #0f172a;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #334155;
            margin-bottom: 20px;
        }
        .form-title {
            font-size: 16px;
            color: #f8fafc;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        label {
            font-size: 12px;
            color: #94a3b8;
        }
        input, select {
            background-color: #1e293b;
            border: 1px solid #475569;
            color: #fff;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 14px;
        }
        button {
            background-color: #0284c7;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
        }
        button:hover { background-color: #0369a1; }

        .code-box {
            background-color: #1e1b4b;
            border: 1px solid #6366f1;
            padding: 15px;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .code-label { font-size: 13px; color: #a5b4fc; }
        .code-value {
            font-size: 22px;
            font-weight: bold;
            color: #38bdf8;
            letter-spacing: 2px;
            font-family: monospace;
        }

        .status-card {
            background-color: #090d16;
            border-left: 4px solid #22c55e;
            padding: 12px 15px;
            border-radius: 4px;
            font-size: 13px;
        }
        .text-green { color: #22c55e; }
        .text-red { color: #ef4444; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🛥️ BoatRace Analytics</h1>

        <div class="form-section">
            <div class="form-title">■ 入力情報</div>
            <form method="GET" action="">
                <div class="form-grid">
                    <div class="form-group">
                        <label>日付</label>
                        <input type="date" name="date" value="<?= htmlspecialchars($selected_date) ?>">
                    </div>

                    <div class="form-group">
                        <label>開催場所</label>
                        <select name="place">
                            <?php foreach ($stadium_codes as $code => $name): ?>
                                <option value="<?= $code ?>" <?= $selected_place === $code ? 'selected' : '' ?>>
                                    <?= $name ?> (<?= $code ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>レース番号</label>
                        <select name="race">
                            <?php for($i=1; $i<=12; $i++): ?>
                                <?php $r = sprintf('%02d', $i); ?>
                                <option value="<?= $r ?>" <?= $selected_race === $r ? 'selected' : '' ?>><?= $i ?>R</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <button type="submit">レース選択 / 検索</button>
            </form>
        </div>

        <div class="code-box">
            <div class="code-label">生成されたレースコード</div>
            <div class="code-value"><?= htmlspecialchars($race_code) ?></div>
        </div>

        <div class="status-card">
            <span class="<?= $is_success ? 'text-green' : 'text-red' ?>">
                <?= $db_status_msg ?>
            </span>
        </div>
    </div>
</body>
</html>