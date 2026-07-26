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
        
        $items_by_boat = [];
        foreach ($raw_tenji as $item) {
            $boat = (int)($item['teiban'] ?? 0);
            if ($boat > 0) {
                $items_by_boat[$boat] = $item;
            }
        }

        $calculated_list = [];
        for ($b = 1; $b <= 6; $b++) {

            $item = $items_by_boat[$b] ?? [];
            $ashi_score = $results[$b-1]['ashi_score'] ?? 0;

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

            $ex_hosei      = $ex_total - $ashi_score;
            $ex_sougou     = $ex_total + $attack_pot + $stable_score;

            if ($lap_score === 5 || $straight_score >= 4 && $ex_total >= 16) {
                $dtype = "超伸び型";
            } elseif ($straight_score >= $ex_total + 2 && $st_score >= 4) {
                $dtype = "攻め型";
            } elseif ($ex_total >= $straight_score + 2 && $mawari_score >= 4) {
                $dtype = "差し型";
            } else {
                $dtype = "バランス";
            }

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
                'tenkai_key'      => ($dtype === "超伸び型") ? 1 : 0,
                'tenkai_morai'    => 0,
                'final_2nd_score' => 0,
            ];
        }

        foreach ($calculated_list as $b => &$t) {
            if ($b === 2 || $b === 4) {
                $t['tenkai_morai'] = 1;
            } else {
                $t['tenkai_morai'] = 0;
            }
            $t['final_2nd_score'] = $t['ex_sougou'] + $t['type_hosei'] + $t['tenkai_morai'];
        }
        unset($t);

        $tenji_list = array_values($calculated_list);

    } else {
        $tenji_error = '展示APIの取得に失敗しました。';
    }
}

// -------------------------------------------------------------
// ■ 最終予想ロジック（Excel完全一致）
// -------------------------------------------------------------
$final_predictions = [];

for ($i = 0; $i < 6; $i++) {
    $boat = $i + 1;
    $waku = $boat;

    $boat_kimarite = $kimarite_data[(string)$boat] ?? $kimarite_data[$i] ?? [];
    $kimarite_6m   = $boat_kimarite['6month'] ?? $boat_kimarite ?? [];

    // 3連対率柔軟取得
    $raw_rate6 = $boat_kimarite['three_in_rate_6m'] ?? $kimarite_6m['three_in_rate_6m'] ?? $boat_kimarite['rate6'] ?? 0;
    $raw_rate3 = $boat_kimarite['three_in_rate_3m'] ?? $kimarite_6m['three_in_rate_3m'] ?? $boat_kimarite['rate3'] ?? 0;

    $rate6 = ($raw_rate6 <= 1.0 && $raw_rate6 > 0) ? $raw_rate6 * 100 : (float)$raw_rate6;
    $rate3 = ($raw_rate3 <= 1.0 && $raw_rate3 > 0) ? $raw_rate3 * 100 : (float)$raw_rate3;

    $nige   = $kimarite_6m['nige'] ?? $kimarite_6m['逃げ'] ?? 0;
    $sashi  = $kimarite_6m['sashi'] ?? $kimarite_6m['差し'] ?? 0;
    $makuri = $kimarite_6m['makuri'] ?? $kimarite_6m['まくり'] ?? 0;
    $makurizashi = $kimarite_6m['makurizashi'] ?? $kimarite_6m['まくり差し'] ?? 0;
    $sasare = $kimarite_6m['sasare'] ?? $kimarite_6m['差され'] ?? 0;
    $makurarezashi = $kimarite_6m['makurarezashi'] ?? $kimarite_6m['まくられ差し'] ?? 0;
    $nogashi = $kimarite_6m['nogashi'] ?? $kimarite_6m['逃がし'] ?? 0;

    // 展開フラグ判定
    $flg_sashi = "-";
    $flg_makuri = "-";
    $flg_makurizashi = "-";
    $flg_nogashi = "-";

    if ($boat >= 2) {
        if ($sashi > 0.12 || $sashi > 12) $flg_sashi = "★" . $boat . "差し";
        if ($makuri > 0.12 || $makuri > 12) $flg_makuri = "★" . $boat . "まくり";
        if ($makurizashi > 0.12 || $makurizashi > 12) $flg_makurizashi = "★" . $boat . "まくり差し";
    }

    if ($boat == 2 && ($nogashi > 0.4 || $nogashi > 40)) {
        $flg_nogashi = "★壁役(逃がし)";
    } elseif ($boat == 3) {
        $flg_nogashi = "-";
    }

    // 期待値
    $score_s = $tenji_list[$i]['ex_sougou'] ?? 0;
    if ($boat == 1) {
        $kitai = $rate6 * (1 + $score_s / 100);
    } else {
        $kitai = ($sashi + $makuri + $makurizashi) * (1 + $score_s / 100);
    }

    // タイプ判定
    if ($boat == 1 && ($nige >= 20 || $nige >= 0.2)) {
        $type = "逃げ型";
    } elseif ($sashi >= 5 || $sashi >= 0.05) {
        $type = "差し型";
    } elseif ($makuri >= 5 || $makuri >= 0.05 || $makurizashi >= 5 || $makurizashi >= 0.05) {
        $type = "攻め型";
    } elseif ($sasare >= 20 || $sasare >= 0.2 || $makurarezashi >= 20 || $makurarezashi >= 0.2) {
        $type = "脆い型";
    } else {
        $type = "無色";
    }

    $type_bonus = 0;
    if (in_array($type, ["逃げ型", "差し型", "攻め型"])) {
        $type_bonus = 1;
    } elseif ($type === "脆い型") {
        $type_bonus = -1;
    }

    $final2 = $tenji_list[$i]['final_2nd_score'] ?? 0;
    $final3 = $final2 + $type_bonus;

    $final_predictions[] = [
        'boat' => $boat,
        'waku' => $waku,
        'rate6' => $rate6,
        'rate3' => $rate3,
        'kitai' => $kitai,
        'flg_sashi' => $flg_sashi,
        'flg_makuri' => $flg_makuri,
        'flg_makurizashi' => $flg_makurizashi,
        'flg_nogashi' => $flg_nogashi,
        'type' => $type,
        'type_bonus' => $type_bonus,
        'final3' => $final3,
        'final2' => $final2,
        'tenkai_bonus' => $tenji_list[$i]['tenkai_morai'] ?? 0,
    ];
}

// 切る艇判定
$med_scores = array_column($final_predictions, 'final3');
sort($med_scores);
$count = count($med_scores);
if ($count % 2 == 0) {
    $median = ($med_scores[$count/2 - 1] + $med_scores[$count/2]) / 2;
} else {
    $median = $med_scores[floor($count/2)];
}

foreach ($final_predictions as &$fp) {
    $fp['kiru'] = (
        $fp['tenkai_bonus'] == 0 &&
        $fp['final3'] < $median &&
        ($fp['rate6'] < 50 || $fp['rate3'] < 50)
    ) ? 1 : 0;
}
unset($fp);

// 下部集計データ
$kiru_boats = [];
$aite_boats = [];
foreach ($final_predictions as $fp) {
    if ($fp['kiru'] == 1) {
        $kiru_boats[] = $fp['boat'];
    } else {
        $aite_boats[] = $fp['boat'];
    }
}

$sorted_p = $final_predictions;
usort($sorted_p, function($a, $b) { return $b['final3'] <=> $a['final3']; });

$honmei_head = $sorted_p[0]['boat'] ?? 1;
$taikou_head = $sorted_p[1]['boat'] ?? 2;

$aite_str = implode('・', array_diff($aite_boats, [$honmei_head]));
$kiru_str = implode('・', $kiru_boats);

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

        /* 下部集計エリア専用 */
        .summary-box {
            margin-top: 15px;
            background-color: #0f172a;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 15px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>艇 BoatRace Analytics</h1>

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
                            <th>足スコア</th>
                            <th>評価</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $index => $e): ?>
                            <?php
                                $lane = (int)$e['lane_number'];
                                $c = $lane_colors[$lane] ?? $lane_colors[1];
                                $r = $results[$index] ?? [];
                                $ashi_score = $r['ashi_score'] ?? 0;
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
                                <td><?= htmlspecialchars($ashi_score) ?></td>
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

        <!-- ■ 最終予想（Excel完全一致構造） -->
        <h2>📊 最終予想（Excel完全一致）</h2>

        <?php if (!empty($final_predictions)): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>艇番</th>
                            <th>枠番</th>
                            <th>直近6ヶ月3連対率</th>
                            <th>直近3ヶ月3連対率</th>
                            <th>↓3連対期待値</th>
                            <th>展開フラグ_差し</th>
                            <th>展開フラグ_まくり</th>
                            <th>展開フラグ_まくり差し</th>
                            <th>展開フラグ_逃し</th>
                            <th>決まり手タイプ</th>
                            <th>決まり手補正 (X)</th>
                            <th>三次予想スコア</th>
                            <th>切る艇</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($final_predictions as $fp): ?>
                            <?php
                                $boat = $fp['boat'];
                                $c = $lane_colors[$boat] ?? $lane_colors[1];
                            ?>
                            <tr>
                                <td><?= $boat ?></td>
                                <td>
                                    <span class="lane-badge" style="background-color: <?= $c['bg'] ?>; color: <?= $c['text'] ?>; border: 1px solid <?= $c['border'] ?>;">
                                        <?= $fp['waku'] ?>
                                    </span>
                                </td>
                                <td><?= number_format($fp['rate6'], 1) ?>%</td>
                                <td><?= number_format($fp['rate3'], 1) ?>%</td>
                                <td><?= number_format($fp['kitai'], 1) ?>%</td>
                                <td><?= htmlspecialchars($fp['flg_sashi']) ?></td>
                                <td><?= htmlspecialchars($fp['flg_makuri']) ?></td>
                                <td><?= htmlspecialchars($fp['flg_makurizashi']) ?></td>
                                <td><?= htmlspecialchars($fp['flg_nogashi']) ?></td>
                                <td><?= htmlspecialchars($fp['type']) ?></td>
                                <td><?= $fp['type_bonus'] ?></td>
                                <td class="score-highlight" style="font-size: 14px;"><?= $fp['final3'] ?></td>
                                <td>
                                    <?php if ($fp['kiru'] == 1): ?>
                                        <span style="color:#ef4444; font-weight:bold;">切り</span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- ■ 集計・買い目テーブル（下部） -->
            <div class="summary-box">
                <table>
                    <thead>
                        <tr>
                            <th>本命/対抗</th>
                            <th>頭</th>
                            <th>相手候補</th>
                            <th>切る艇</th>
                            <th>相手候補(加工)</th>
                            <th>切る艇(加工)</th>
                            <th>買い目候補 (3連単)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="font-weight:bold; color:#38bdf8;">本命</td>
                            <td><?= $honmei_head ?></td>
                            <td><?= htmlspecialchars($aite_str) ?></td>
                            <td><?= htmlspecialchars($kiru_str) ?></td>
                            <td><?= str_replace('・', '', $aite_str) ?></td>
                            <td><?= str_replace('・', '', $kiru_str) ?></td>
                            <td class="score-highlight"><?= $honmei_head ?>-<?= str_replace('・', '', $aite_str) ?>-<?= str_replace('・', '', $aite_str) ?><?= str_replace('・', '', $kiru_str) ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight:bold; color:#f59e0b;">対抗</td>
                            <td><?= $taikou_head ?></td>
                            <td><?= htmlspecialchars($aite_str) ?></td>
                            <td><?= htmlspecialchars($kiru_str) ?></td>
                            <td><?= str_replace('・', '', $aite_str) ?></td>
                            <td><?= str_replace('・', '', $kiru_str) ?></td>
                            <td class="score-highlight"><?= $taikou_head ?>-<?= str_replace('・', '', $aite_str) ?>-<?= str_replace('・', '', $aite_str) ?><?= str_replace('・', '', $kiru_str) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-data">最終予想データが存在しません。</div>
        <?php endif; ?>

    </div>
</body>
</html>