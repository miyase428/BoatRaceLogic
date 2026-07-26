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
$selected_place  = $_GET['place'] ?? 'OMR';
$selected_race   = $_GET['race'] ?? '07';
$in_course       = $_GET['in_course'] ?? '123456'; // デフォルト：枠なり進入

// YYYYMMDD 形式
$formatted_date  = date('Ymd', strtotime($selected_date));
// レースコード生成
$race_code       = $formatted_date . $selected_place . sprintf('%02d', $selected_race);

// 1. 出走表データの取得（calc_scores.php から取得）
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
        $api_error = '出走表データが見つかりませんでした (status != ok)';
    }
} else {
    $api_error = '出走表APIの呼び出しに失敗しました。';
}

// 2. 決まり手データの取得（kimarite_api.php から POST 取得）
$kimarite_data = [];
$kimarite_error = '';

if (!empty($race_code) && strlen($in_course) === 6) {
    $post_data = http_build_query([
        'race_code' => $race_code,
        'in_course' => $in_course
    ]);
    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $post_data,
            'timeout' => 3
        ]
    ];
    $context = stream_context_create($opts);
    $k_json = @file_get_contents("http://localhost/kimarite_api.php", false, $context);

    if ($k_json !== false) {
        $kimarite_data = json_decode($k_json, true) ?? [];
    } else {
        $kimarite_error = '決まり手APIの取得に失敗しました。';
    }
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
            max-width: 950px;
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
            margin-top: 35px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
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
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
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
            margin-top: 10px;
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
            padding: 8px;
            border-bottom: 1px solid #334155;
            white-space: nowrap;
        }
        .lane-badge {
            width: 24px;
            height: 24px;
            line-height: 24px;
            border-radius: 50%;
            font-weight: bold;
            display: inline-block;
        }
        .player-name { font-weight: bold; font-size: 14px; }
        .no-data {
            padding: 20px;
            text-align: center;
            color: #94a3b8;
            background-color: #0f172a;
            border-radius: 8px;
        }

        /* 決まり手テーブル固有スタイル */
        .kimarite-table td { font-family: monospace; }
        .border-top-course { border-top: 2px solid #475569; }
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

                    <div class="form-group">
                        <label>進入コース (6桁)</label>
                        <input type="text" name="in_course" maxlength="6" value="<?= htmlspecialchars($in_course) ?>">
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

        <!-- ■ 決まり手情報 -->
        <h2>🎯 決まり手情報</h2>

        <?php if (!empty($kimarite_data)): ?>
            <div class="table-container">
                <table class="kimarite-table">
                    <thead>
                        <tr>
                            <th>枠</th>
                            <th>期間</th>
                            <th>逃げ</th>
                            <th>差し</th>
                            <th>まくり</th>
                            <th>まくり差し</th>
                            <th>逃がし</th>
                            <th>差され</th>
                            <th>まくられ</th>
                            <th>まくられ差</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($course = 1; $course <= 6; $course++): ?>
                            <?php
                                $c_str = (string)$course;
                                $data_1y = $kimarite_data[$c_str]['1year'] ?? [];
                                $data_6m = $kimarite_data[$c_str]['6month'] ?? [];
                                $c = $lane_colors[$course] ?? $lane_colors[1];
                            ?>
                            <!-- 1年データ -->
                            <tr class="border-top-course">
                                <td rowspan="2" style="vertical-align: middle;">
                                    <span class="lane-badge" style="background-color: <?= $c['bg'] ?>; color: <?= $c['text'] ?>; border: 1px solid <?= $c['border'] ?>;">
                                        <?= $course ?>
                                    </span>
                                </td>
                                <td>1年</td>
                                <td><?= number_format(($data_1y['nige'] ?? 0), 1) ?>%</td>
                                <td><?= number_format(($data_1y['sashi'] ?? 0), 1) ?>%</td>
                                <td><?= number_format(($data_1y['makuri'] ?? 0), 1) ?>%</td>
                                <td><?= number_format(($data_1y['makurizashi'] ?? 0), 1) ?>%</td>
                                <td><?= number_format(($data_1y['nogashi'] ?? 0), 1) ?>%</td>
                                <td><?= number_format(($data_1y['sasare'] ?? 0), 1) ?>%</td>
                                <td><?= number_format(($data_1y['makurare'] ?? 0), 1) ?>%</td>
                                <td><?= number_format(($data_1y['makurarezashi'] ?? 0), 1) ?>%</td>
                            </tr>
                            <!-- 6ヶ月データ -->
                            <tr>
                                <td style="color: #94a3b8;">6ヶ月</td>
                                <td><?= number_format(($data_6m['nige'] ?? 0), 1) ?>%</td>
                                <td><?= number_format(($data_6m['sashi'] ?? 0), 1) ?>%</td>
                                <td><?= number_format(($data_6m['makuri'] ?? 0), 1) ?>%</td>
                                <td><?= number_format(($data_6m['makurizashi'] ?? 0), 1) ?>%</td>
                                <td><?= number_format(($data_6m['nogashi'] ?? 0), 1) ?>%</td>
                                <td><?= number_format(($data_6m['sasare'] ?? 0), 1) ?>%</td>
                                <td><?= number_format(($data_6m['makurare'] ?? 0), 1) ?>%</td>
                                <td><?= number_format(($data_6m['makurarezashi'] ?? 0), 1) ?>%</td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-data">
                <?= htmlspecialchars($kimarite_error ?: '決まり手データが存在しません。') ?>
            </div>
        <?php endif; ?>

    </div>
</body>
</html>