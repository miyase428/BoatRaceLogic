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

            // ★★★ Excelと完全一致：展示補正スコア = ex_total - stable_score
            $ex_hosei      = $ex_total - $stable_score;

            // S列: 展示総合スコア (O + P + Q)
            $ex_sougou     = $ex_total + $attack_pot + $stable_score;

            // U列: 展示タイプ名（Excelロジックと一致）
            if ($lap_score === 5) {
                $dtype = "超伸び型";
            } elseif ($straight_score >= $ex_total + 2 && $st_score >= 4) {
                $dtype = "攻め型";
            } elseif ($ex_total >= $straight_score + 2 && $mawari_score >= 4) {
                $dtype = "差し型";
            } elseif ($straight_score === 5) {
                $dtype = "伸び型";
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
                'tenkai_key'      => ($dtype === "超伸び型") ? 1 : 0,
                'tenkai_morai'    => 0,
                'final_2nd_score' => 0,
            ];
        }

        // ★ W列：展開もらい補正 ＆ X列：最終二次予想スコア
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
