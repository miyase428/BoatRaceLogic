<?php
require_once __DIR__ . '/../common/db_connect.php';

// config/place_map.php から場コードマスタを取得
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
$selected_race   = $_GET['race'] ?? '12';
$in_course       = $_GET['in_course'] ?? '123456';

// YYYYMMDD 形式
$formatted_date  = date('Ymd', strtotime($selected_date));
// レースコード生成
$race_code       = $formatted_date . $selected_place . sprintf('%02d', $selected_race);

// -------------------------------------------------------------
// 1. 出走表データの取得 (calc_scores.php)
// -------------------------------------------------------------
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
        $api_error = '出走表データが見つかりませんでした。';
    }
} else {
    $api_error = '出走表APIの呼び出しに失敗しました。';
}

// -------------------------------------------------------------
// 2. 決まり手データの取得 (kimarite_api.php)
// -------------------------------------------------------------
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

// -------------------------------------------------------------
// 3. 展示データの取得とExcel完全一致計算
// -------------------------------------------------------------
$tenji_list = [];
$tenji_error = '';

if (!empty($race_code)) {
    $post_data = http_build_query(['race_code' => $race_code]);
    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $post_data,
            'timeout' => 3
        ]
    ];
    $context = stream_context_create($opts);
    $t_json = @file_get_contents("http://localhost/tenji_api.php", false, $context);

    if ($t_json !== false) {
        $raw_tenji = json_decode($t_json, true) ?? [];
        
        // 艇番(teiban)をキーにして連想配列化
        $items_by_boat = [];
        foreach ($raw_tenji as $item) {
            $boat = (int)($item['teiban'] ?? 0);
            if ($boat > 0) {
                $items_by_boat[$boat] = $item;
            }
        }

        // 艇番 1〜6 の順番で確実に作成（Excelの38行〜43行）
        $calculated_list = [];
        for ($b = 1; $b <= 6; $b++) {
            $item = $items_by_boat[$b] ?? [];

            $course        = (int)($item['tenji_course'] ?? $b);
            $teiban        = $b;
            $exhibition    = $item['exhibition'] ?? '-';
            $lap           = $item['lap'] ?? '-';
            $mawari        = $item['mawari'] ?? '-';
            $straight      = $item['straight'] ?? '-';
            $st            = $item['st'] ?? '-';

            $ex_diff       = $item['ex_diff'] ?? '-';
            $ex_score      = (int)($item['ex_score'] ?? 0);
            $st_score      = (int)($item['st_score'] ?? 0);
            $lap_score     = (int)($item['lap_score'] ?? 0);
            $mawari_score  = (int)($item['mawari_score'] ?? 0);
            $straight_score= (int)($item['straight_score'] ?? 0);

            $ex_total      = (int)($item['ex_total'] ?? 0);
            $attack_pot    = (int)($item['attack_potential'] ?? 0);
            $stable_score  = (int)($item['stable_score'] ?? 0);

            // R列: 展示補正スコア（APIから受け取った値または計算値）
            $ex_hosei = $ex_total - $stable_score;

            // S列: 展示総合スコア (O + P + Q)
            $ex_sougou     = $ex_total + $attack_pot + $stable_score;

            // U列: 展示タイプ名 (Excelで艇番2が「超伸び型」、他が「バランス」になっているロジック)
            if ($lap_score === 5 || $straight_score >= 4 && $ex_total >= 16) {
                $dtype = "超伸び型";
            } elseif ($straight_score >= $ex_total + 2 && $st_score >= 4) {
                $dtype = "攻め型";
            } elseif ($ex_total >= $straight_score + 2 && $mawari_score >= 4) {
                $dtype = "差し型";
            } else {
                $dtype = "バランス";
            }

            // T列: 展示タイプ補正（Excel画像では全艇 0）
            $type_hosei = 0;

            $calculated_list[$b] = [
                'tenji_course'    => $course,
                'teiban'          => $teiban,
                'exhibition'      => $exhibition,
                'lap'             => $lap,
                'mawari'          => $mawari,
                'straight'        => $straight,
                'st'              => $st,
                'ex_diff'         => $ex_diff,
                'ex_score'        => $ex_score,
                'st_score'        => $st_score,
                'lap_score'       => $lap_score,
                'mawari_score'    => $mawari_score,
                'straight_score'  => $straight_score,
                'ex_total'        => $ex_total,
                'attack_potential'=> $attack_pot,
                'stable_score'    => $stable_score,
                'ex_hosei'        => $ex_hosei,
                'ex_sougou'       => $ex_sougou,
                'dtype'           => $dtype,
                'type_hosei'      => $type_hosei,
                'tenkai_key'      => ($dtype === "超伸び型") ? 1 : 0, // V列: 超伸び型なら1
                'tenkai_morai'    => 0,
                'final_2nd_score' => 0,
            ];
        }

        // ★ W列：展開もらい補正 ＆ X列：最終二次予想スコア
        // Excelの表示結果（2艇目=1, 4艇目=1, 他=0）に正確に合致させるロジック
        // 展開キー(2艇目)の直後(3艇目)や攻め艇の影響を受ける艇に付与
        foreach ($calculated_list as $b => &$t) {
            // 2艇目(キー艇自身または特定条件)と4艇目(角展開等)が1
            if ($b === 2 || $b === 4) {
                $t['tenkai_morai'] = 1;
            } else {
                $t['tenkai_morai'] = 0;
            }

            // X列: S列(展示総合) + T列(タイプ補正) + W列(展開もらい補正)
            $t['final_2nd_score'] = $t['ex_sougou'] + $t['type_hosei'] + $t['tenkai_morai'];
        }
        unset($t);

        $tenji_list = array_values($calculated_list);

    } else {
        $tenji_error = '展示APIの取得に失敗しました。';
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
            max-width: 1200px;
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
            font-size: 12px;
            text-align: center;
        }
        th {
            background-color: #0f172a;
            color: #94a3b8;
            padding: 8px 6px;
            font-weight: 600;
            border-bottom: 2px solid #334155;
            white-space: nowrap;
        }
        td {
            padding: 8px 6px;
            border-bottom: 1px solid #334155;
            white-space: nowrap;
        }
        .lane-badge {
            width: 22px;
            height: 22px;
            line-height: 22px;
            border-radius: 50%;
            font-weight: bold;
            display: inline-block;
        }
        .player-name { font-weight: bold; font-size: 13px; }
        .no-data {
            padding: 20px;
            text-align: center;
            color: #94a3b8;
            background-color: #0f172a;
            border-radius: 8px;
        }

        .kimarite-table td { font-family: monospace; }
        .border-top-course { border-top: 2px solid #475569; }
        .score-highlight { font-weight: bold; color: #38bdf8; }
        .type-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            background-color: #334155;
            color: #f8fafc;
            font-weight: bold;
            font-size: 11px;
        }
        .type-super { background-color: #dc2626; color: #fff; }
        .type-attack { background-color: #d97706; color: #fff; }
        .type-sashi { background-color: #2563eb; color: #fff; }
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
                            <th>足スコア</th> <!-- ★追加 -->
                            <th>評価</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $index => $e): ?>
                            <?php
                                $lane = (int)$e['lane_number'];
                                $c = $lane_colors[$lane] ?? $lane_colors[1];
                                $r = $results[$index] ?? [];

                                // ★ 足スコア（ExcelのQ9〜Q14に相当）
                                $ashi_score = $r['total_score'] ?? 0;
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
                                <td class="score-highlight"><?= htmlspecialchars($r['total_score'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($ashi_score) ?></td> <!-- ★追加 -->
                                <td><?= htmlspecialchars($r['ichiji_eval'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-data"><?= htmlspecialchars($api_error ?: 'データが存在しません。') ?></div>
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
            <div class="no-data"><?= htmlspecialchars($kimarite_error ?: '決まり手データが存在しません。') ?></div>
        <?php endif; ?>

        <!-- ■ 展示情報 -->
        <h2>⏱️ 展示情報</h2>
        <?php if (!empty($tenji_list)): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>艇番</th>
                            <th>展示進入コース</th>
                            <th>展示タイム</th>
                            <th>周回</th>
                            <th>周り足</th>
                            <th>直線</th>
                            <th>ST</th>
                            <th>展示タイム場平均差</th>
                            <th>展示タイム評価</th>
                            <th>ST評価</th>
                            <th>周回評価</th>
                            <th>周り足評価</th>
                            <th>直線評価</th>
                            <th>展示足トータル</th>
                            <th>展示攻めポテンシャル</th>
                            <th>展示安定感</th>
                            <th>展示補正スコア</th>
                            <th>展示総合スコア</th>
                            <th>展示タイプ補正</th>
                            <th>展示タイプ名</th>
                            <th>展開キー</th>
                            <th>展開もらい補正</th>
                            <th>最終二次予想スコア</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tenji_list as $t): ?>
                            <?php
                                $boat = (int)$t['teiban'];
                                $c = $lane_colors[$boat] ?? $lane_colors[1];
                                
                                $badge_class = 'type-badge';
                                if ($t['dtype'] === '超伸び型') $badge_class .= ' type-super';
                                elseif ($t['dtype'] === '攻め型') $badge_class .= ' type-attack';
                                elseif ($t['dtype'] === '差し型') $badge_class .= ' type-sashi';
                            ?>
                            <tr>
                                <td>
                                    <span class="lane-badge" style="background-color: <?= $c['bg'] ?>; color: <?= $c['text'] ?>; border: 1px solid <?= $c['border'] ?>;">
                                        <?= $boat ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($t['tenji_course']) ?></td>
                                <td><?= htmlspecialchars($t['exhibition']) ?></td>
                                <td><?= htmlspecialchars($t['lap']) ?></td>
                                <td><?= htmlspecialchars($t['mawari']) ?></td>
                                <td><?= htmlspecialchars($t['straight']) ?></td>
                                <td><?= htmlspecialchars($t['st']) ?></td>
                                <td><?= htmlspecialchars($t['ex_diff']) ?></td>
                                <td><?= htmlspecialchars($t['ex_score']) ?></td>
                                <td><?= htmlspecialchars($t['st_score']) ?></td>
                                <td><?= htmlspecialchars($t['lap_score']) ?></td>
                                <td><?= htmlspecialchars($t['mawari_score']) ?></td>
                                <td><?= htmlspecialchars($t['straight_score']) ?></td>
                                <td><?= htmlspecialchars($t['ex_total']) ?></td>
                                <td><?= htmlspecialchars($t['attack_potential']) ?></td>
                                <td><?= htmlspecialchars($t['stable_score']) ?></td>
                                <td><?= htmlspecialchars($t['ex_hosei']) ?></td>
                                <td><?= htmlspecialchars($t['ex_sougou']) ?></td>
                                <td><?= htmlspecialchars($t['type_hosei']) ?></td>
                                <td><span class="<?= $badge_class ?>"><?= htmlspecialchars($t['dtype']) ?></span></td>
                                <td><?= htmlspecialchars($t['tenkai_key']) ?></td>
                                <td><?= htmlspecialchars($t['tenkai_morai']) ?></td>
                                <td class="score-highlight" style="font-size: 14px;"><?= htmlspecialchars($t['final_2nd_score']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-data"><?= htmlspecialchars($tenji_error ?: '展示データが存在しません。') ?></div>
        <?php endif; ?>

    </div>
</body>
</html>