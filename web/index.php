<?php
require_once __DIR__ . '/../common/db_connect.php';

// config/place_map.php から場コードマスタを取得 (ID => 英字3桁)
$place_map = require __DIR__ . '/../config/place_map.php';

// 画面表示用の日本語場名マップ
$place_names = [
    'KRY' => '桐生',   'TDA' => '戸田',   'EDG' => '江戸川', 'HWJ' => '平和島',
    'TMG' => '多摩川', 'HMN' => '浜名湖', 'GMG' => '蒲郡',   'TKN' => '常滑',
    'TSU' => '津',     'MKN' => '三国',   'BWK' => 'びわこ', 'SME' => '住之江',
    'AMG' => '尼崎',   'NRT' => '鳴門',   'MRG' => '丸亀',   'KJM' => '児島',
    'MYJ' => '宮島',   'TKY' => '徳山',   'SMS' => '下関',   'WKM' => '若松',
    'ASY' => '芦屋',   'FKO' => '福岡',   'KRT' => '唐津',   'OMR' => '大村',
];

// フォーム入力値の取得
$selected_date   = $_GET['date'] ?? date('Y-m-d');
$selected_place  = $_GET['place'] ?? 'OMR'; // デフォルト：大村
$selected_race   = $_GET['race'] ?? '07';   // デフォルト：07R

// YYYYMMDD 形式
$formatted_date  = date('Ymd', strtotime($selected_date));
// レースコード生成 (例: 20260724 + OMR + 07)
$race_code       = $formatted_date . $selected_place . sprintf('%02d', $selected_race);

// 出走表データの取得（calc_scores.php から取得）
$entries = [];
$results = [];
$api_error = '';

$api_url = "http://localhost/calc_scores.php?race_code=" . urlencode($race_code);
$json_data = @file_get_contents($api_url);

if ($json_data !== false) {
    $response = json_decode($json_data, true);
    if (isset($response['status']) && $response['status'] === 'ok') {
        $entries = $response['entries'] ?? [];
        $results = $response['results'] ?? [];
    } else {
        $api_error = 'データが見つかりませんでした (status != ok)';
    }
} else {
    $api_error = '出走表APIの呼び出しに失敗しました。';
}

// 枠番カラー設定
$lane_colors = [
    1 => ['bg' => '#f8fafc', 'text' => '#0f172a', 'border' => '#e2e8f0'],
    2 => ['bg' => '#1e293b', 'text' => '#f8fafc', 'border' => '#475569'],
    3 => ['bg' => '#ef4444', 'text' => '#ffffff', 'border' => '#dc2626'],
    4 => ['bg' => '#3b82f6', 'text' => '#ffffff', 'border' => '#2563eb'],
    5 => ['bg' => '#eab308', 'text' => '#0f172a', 'border' => '#ca8a04'],
    6 => ['bg' => '#22c55e', 'text' => '#ffffff', 'border' => '#16a34a'],
];
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
            max-width: 900px;
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
        h2 {
            color: #f8fafc;
            font-size: 18px;
            margin-top: 30px;
            margin-bottom: 15px;
        }
        .form-section {
            background-color: #0f172a;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #334155;
            margin-bottom: 20px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        label { font-size: 12px; color: #94a3b8; }
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
            padding: 12px 20px;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        .code-label { font-size: 13px; color: #a5b4fc; }
        .code-value {
            font-size: 20px;
            font-weight: bold;
            color: #38bdf8;
            letter-spacing: 2px;
            font-family: monospace;
        }

        .table-container {
            overflow-x: auto;
            margin-top: 15px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: center;
        }
        th {
            background-color: #0f172a;
            color: #94a3b8;
            padding: 10px;
            font-weight: 600;
            border-bottom: 2px solid #334155;
            white-space: nowrap;
        }
        td {
            padding: 10px 8px;
            border-bottom: 1px solid #334155;
            white-space: nowrap;
        }
        .lane-badge {
            width: 26px;
            height: 26px;
            line-height: 26px;
            border-radius: 50%;
            font-weight: bold;
            display: inline-block;
        }
        .player-name { font-weight: bold; font-size: 14px; }
        .no-data {
            padding: 30px;
            text-align: center;
            color: #94a3b8;
            background-color: #0f172a;
            border-radius: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🛥️ BoatRace Analytics</h1>

        <!-- ■ 入力情報 -->
        <div class="form-section">
            <form method="GET" action="">
                <div class="form-grid">
                    <div class="form-group">
                        <label>日付</label>
                        <input type="date" name="date" value="<?= htmlspecialchars($selected_date) ?>">
                    </div>

                    <div class="form-group">
                        <label>開催場所</label>
                        <select name="place">
                            <?php foreach ($place_map as $id => $code): ?>
                                <?php $name = $place_names[$code] ?? $code; ?>
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

                <button type="submit">レース情報取得</button>
            </form>
        </div>

        <div class="code-box">
            <div class="code-label">生成されたレースコード</div>
            <div class="code-value"><?= htmlspecialchars($race_code) ?></div>
        </div>

        <!-- ■ 出走表情報 -->
        <h2>📋 出走表情報</h2>

        <?php if (!empty($entries)): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>枠</th>
                            <th>選手名</th>
                            <th>級別/支部</th>
                            <th>全国勝率</th>
                            <th>当地勝率</th>
                            <th>モータ</th>
                            <th>ボート</th>
                            <th>平均ST</th>
                            <th>地力</th>
                            <th>一次総合</th>
                            <th>評価</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $index => $e): ?>
                            <?php
                                $lane = (int)$e['lane_number'];
                                $c = $lane_colors[$lane] ?? $lane_colors[1];
                                $r = $results[$index] ?? [];
                            ?>
                            <tr>
                                <td>
                                    <span class="lane-badge" style="background-color: <?= $c['bg'] ?>; color: <?= $c['text'] ?>; border: 1px solid <?= $c['border'] ?>;">
                                        <?= $lane ?>
                                    </span>
                                </td>
                                <td class="player-name"><?= htmlspecialchars($e['player_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($e['class'] ?? '') ?> / <?= htmlspecialchars($e['branch'] ?? '') ?></td>
                                <td><?= htmlspecialchars($e['national_win_rate'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($e['local_win_rate'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($e['motor_exacta_rate'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($e['boat_exacta_rate'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($e['average_start'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['jiryoku_score'] ?? '-') ?></td>
                                <td style="font-weight: bold; color: #38bdf8;"><?= htmlspecialchars($r['total_score'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['ichiji_eval'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-data">
                <?= htmlspecialchars($api_error ?: '該当レースのデータが存在しません。') ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>