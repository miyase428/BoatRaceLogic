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
// 3. 展示データの取得
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

        // ★ 1. 競艇場ごとの J/K/L 列マッピング定義（Excel SAM理論準拠）
        // ※ J, K, L にどのタイムキー（exhibition, lap, mawari, straight）を割り当てるか定義
        $JKL_rule = [
            'KRY' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'TDA' => ['J' => 'exhibition', 'K' => 'mawari',   'L' => 'straight'],
            'EDG' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'HWJ' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'TMG' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'HMN' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'GMG' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'TKN' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'TSU' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'MKN' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'BWK' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'SME' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'AMG' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'NRT' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'MRG' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'KJM' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'MYJ' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'TKY' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'SMS' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'WKM' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'ASY' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'FKO' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'KRT' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
            'OMR' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
        ];

        // 選択された場のルールを取得（デフォルトは標準的な構成）
        $rule = $JKL_rule[$selected_place] ?? ['J' => 'exhibition', 'K' => 'lap', 'L' => 'straight'];

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

            // ★ 2. 場別の定義に基づいた J/K/L データの新規切り出し
            $tenji_J        = $item[$rule['J']] ?? '-';
            $tenji_K        = $item[$rule['K']] ?? '-';
            $tenji_L        = $item[$rule['L']] ?? '-';
            $tenji_selected = $tenji_L; // ExcelのL列（メイン評価値）

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
                // ★ 新規追加: J/K/L と評価対象値
                'tenji_J'         => $tenji_J,
                'tenji_K'         => $tenji_K,
                'tenji_L'         => $tenji_L,
                'tenji_selected'  => $tenji_selected,
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
// ■ 最終予想ロジック（VBA完全再現・tenji_test.php 連携版）
// -------------------------------------------------------------

// 1. tenji_test.php からデータ取得 (VBAと同等処理)
$apiUrl = "http://192.168.0.208:80/tenji_test.php?" . http_build_query([
    'race_code' => $race_code,
    'tenji1'    => $tenji_list[0]['tenji_course'] ?? 0,
    'tenji2'    => $tenji_list[1]['tenji_course'] ?? 0,
    'tenji3'    => $tenji_list[2]['tenji_course'] ?? 0,
    'tenji4'    => $tenji_list[3]['tenji_course'] ?? 0,
    'tenji5'    => $tenji_list[4]['tenji_course'] ?? 0,
    'tenji6'    => $tenji_list[5]['tenji_course'] ?? 0,
]);

$jsonString = @file_get_contents($apiUrl);
$tenji_test_data = json_decode($jsonString, true) ?? [];

// 数値・パーセンテージ文字列を小数（0.802など）に変換する汎用関数
$parse_rate = function($val) {
    if (is_null($val) || $val === '') return 0.0;
    $clean = (float)preg_replace('/[^0-9.]/', '', (string)$val);
    return ($clean > 1.0) ? $clean / 100.0 : $clean;
};

$to_dec = function($val) {
    $f = (float)$val;
    return ($f > 1.0) ? $f / 100.0 : $f;
};

// 1コース（21行目相当）の決まり手データ
$k1 = $kimarite_data['1']['6month'] ?? $kimarite_data['1'] ?? [];

$k1_nige_dec     = $to_dec($k1['nige'] ?? 0);
$k1_sashi_dec    = $to_dec($k1['sashi'] ?? 0);
$k1_makuri_dec   = $to_dec($k1['makuri'] ?? 0);
$k1_makuri_z_dec = $to_dec($k1['makurizashi'] ?? 0);

$final_predictions = [];

for ($i = 1; $i <= 6; $i++) {
    $boat = $i;
    $waku = $boat;

    // ★連対率は tenji_test.php のレスポンスから直接取得
    $api_item = $tenji_test_data[$i - 1] ?? [];
    $rate6_dec = (float)$api_item['three_in_rate_6m'] ?? 0;
    $rate3_dec = (float)$api_item['three_in_rate_3m'] ?? 0;

    // 展示データ（スコア等用）
    $t_data = $tenji_list[$i - 1] ?? [];

    // 決まり手データ（1年/6ヶ月の6ヶ月優先）
    $k_data = $kimarite_data[(string)$boat]['6month'] ?? $kimarite_data[(string)$boat] ?? [];

    // --- 期待値計算（F50:F55） ---
    $score_s = (float)($t_data['ex_sougou'] ?? 0);

    if ($i === 1) {
        $kitai_dec = $k1_nige_dec * (1.0 + ($score_s / 100.0));
    } else {
        $sashi_dec   = $to_dec($k_data['sashi'] ?? 0);
        $makuri_dec  = $to_dec($k_data['makuri'] ?? 0);
        $makuriz_dec = $to_dec($k_data['makurizashi'] ?? 0);

        $kitai_dec = ($sashi_dec + $makuri_dec + $makuriz_dec) * (1.0 + ($score_s / 100.0));
    }

    // --- 展開フラグ判定 (G50:J55) ---
    $flg_sashi = "-";
    $flg_makuri = "-";
    $flg_makurizashi = "-";
    $flg_nogashi = "-";

    if ($i >= 2) {
        $curr_sashi   = $to_dec($k_data['sashi'] ?? 0);
        $curr_makuri  = $to_dec($k_data['makuri'] ?? 0);
        $curr_makuriz = $to_dec($k_data['makurizashi'] ?? 0);

        if ($k1_sashi_dec > 0.12 && $curr_sashi > 0.12) {
            $flg_sashi = "★" . $i . "差し";
        }
        if ($k1_makuri_dec > 0.12 && $curr_makuri > 0.12) {
            $flg_makuri = "★" . $i . "まくり";
        }
        if ($k1_makuri_z_dec > 0.12 && $curr_makuriz > 0.12) {
            $flg_makurizashi = "★" . $i . "まくり差し";
        }
    }

    // 展開フラグ_逃し
    if ($i === 2) {
        $nogashi_dec = $to_dec($k_data['nogashi'] ?? 0);
        if ($nogashi_dec > 0.4) {
            $flg_nogashi = "★壁役(逃がし)";
        }
    } elseif ($i === 3) {
        $st_score_3 = (float)($tenji_list[2]['st_score'] ?? 0);
        $stFactor = 1.0 + ($st_score_3 - 3.0) * 0.1;

        $k3_makuri  = $to_dec($k_data['makuri'] ?? 0);
        $k3_makuriz = $to_dec($k_data['makurizashi'] ?? 0);
        $blockIndex = ($k3_makuri + $k3_makuriz) * $stFactor;

        if ($blockIndex > 0.12) {
            $flg_nogashi = "★外ブロック";
        }
    }

    // --- 決まり手タイプ（K50:K55） ---
    $nige_d      = $to_dec($k_data['nige'] ?? 0);
    $sashi_d     = $to_dec($k_data['sashi'] ?? 0);
    $makuri_d    = $to_dec($k_data['makuri'] ?? 0);
    $makuriz_d   = $to_dec($k_data['makurizashi'] ?? 0);
    $sasare_d    = $to_dec($k_data['sasare'] ?? 0);
    $makurarez_d = $to_dec($k_data['makurarezashi'] ?? 0);

    if ($nige_d >= 0.2 && $waku === 1) {
        $type = "逃げ型";
    } elseif ($sashi_d >= 0.05) {
        $type = "差し型";
    } elseif ($makuri_d >= 0.05 || $makuriz_d >= 0.05) {
        $type = "攻め型";
    } elseif ($sasare_d >= 0.2 || $makurarez_d >= 0.2) {
        $type = "脆い型";
    } else {
        $type = "無色";
    }

    // --- 決まり手補正（L50:L55） ---
    switch ($type) {
        case "攻め型":
        case "差し型":
        case "逃げ型":
            $typeBonus = 1;
            break;
        case "脆い型":
            $typeBonus = -1;
            break;
        default:
            $typeBonus = 0;
            break;
    }

    // --- 三次予想スコア（M50:M55） ---
    $final2 = (float)($t_data['final_2nd_score'] ?? 0);
    $final3 = $final2 + $typeBonus;

    $final_predictions[$i] = [
        'boat'            => $boat,
        'waku'            => $waku,
        'rate6_dec'       => $rate6_dec,
        'rate3_dec'       => $rate3_dec,
        'kitai_dec'       => $kitai_dec,
        'flg_sashi'       => $flg_sashi,
        'flg_makuri'      => $flg_makuri,
        'flg_makurizashi' => $flg_makurizashi,
        'flg_nogashi'     => $flg_nogashi,
        'type'            => $type,
        'typeBonus'       => $typeBonus,
        'final3'          => $final3,
        'getBonus'        => (float)($t_data['tenkai_morai'] ?? 0),
    ];
}

// --- 切る艇判定（N50:N55） ---
// --- 切る艇判定（N50:N55） ---
$m_scores = array_values(array_column($final_predictions, 'final3')); // ★ array_values でインデックスを 0~5 にリセット
sort($m_scores);
$count = count($m_scores);
if ($count % 2 === 0) {
    $med = ($m_scores[$count/2 - 1] + $m_scores[$count/2]) / 2.0;
} else {
    $med = $m_scores[floor($count/2)];
}

for ($i = 1; $i <= 6; $i++) {
    $fp = &$final_predictions[$i];
    if ($fp['getBonus'] == 0 && $fp['final3'] < $med && ($fp['rate6_dec'] < 0.5 || $fp['rate3_dec'] < 0.5)) {
        $fp['kiru'] = 1;
    } else {
        $fp['kiru'] = 0;
    }
}
unset($fp);

// --- 下部集計エリア文字列の作成 ---
$kiru_boats = [];
$aite_boats = [];
for ($i = 1; $i <= 6; $i++) {
    if ($final_predictions[$i]['kiru'] == 1) {
        $kiru_boats[] = $i;
    } else {
        $aite_boats[] = $i;
    }
}

// 三次予想スコア順で本命(1位)・対抗(2位)を判定
$sorted_preds = $final_predictions;
usort($sorted_preds, function($a, $b) { return $b['final3'] <=> $a['final3']; });

$honmei_head = $sorted_preds[0]['boat'] ?? 1;
$taikou_head = $sorted_preds[1]['boat'] ?? 2;

// 相手候補（頭を除く）
$honmei_aite = array_diff($aite_boats, [$honmei_head]);
$taikou_aite = array_diff($aite_boats, [$taikou_head]);

$honmei_aite_str = implode('・', $honmei_aite);
$taikou_aite_str = implode('・', $taikou_aite);
$kiru_str        = implode('・', $kiru_boats);

$honmei_aite_kako = implode('', $honmei_aite);
$taikou_aite_kako = implode('', $taikou_aite);
$kiru_kako        = implode('', $kiru_boats);

// 買い目候補 3連単 (例: 2-341-3415)
$honmei_kai = $honmei_head . '-' . $honmei_aite_kako . '-' . $honmei_aite_kako . $kiru_kako;
$taikou_kai = $taikou_head . '-' . $taikou_aite_kako . '-' . $taikou_aite_kako . $kiru_kako;

// -------------------------------------------------------------
// ■ サム理論マスタデータの取得 (sum_api.php 連携)
// -------------------------------------------------------------
$sam_master_data = [];
$sam_error = '';

// 場所コード ($selected_place) から数値2桁の場コード（例: OMR -> 24 など）を取得/変換
// ※ $place_map に「'OMR' => '24'」等のマッピングがある前提
$jyo_num = $place_map[$selected_place] ?? $selected_place; 

$post_data = http_build_query(['jyo' => $jyo_num]);
$opts = [
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $post_data,
        'timeout' => 3
    ]
];
$context = stream_context_create($opts);
$sam_json = @file_get_contents("http://192.168.0.208:80/sum_api.php", false, $context);

if ($sam_json !== false) {
    $parsed_sam = json_decode($sam_json, true) ?? [];
    // APIレスポンスが $parsed_sam[$jyo_num] の階層構造になっている場合に対応
    $sam_master_data = $parsed_sam[$jyo_num] ?? $parsed_sam;
} else {
    $sam_error = 'サム理論マスタAPIの取得に失敗しました。';
}

// サム理論の区間・メトリクスの表示順定義
$sam_intervals = ["-0.6未満", "-0.6--0.4", "-0.4--0.2", "-0.2-0.0", "0.0-0.2", "0.2-0.4", "0.4-0.6", "0.6以上"];
$sam_metrics   = ["win", "place2", "place3", "trio"];

// -------------------------------------------------------------
// ■ 展示サム理論のリアルタイム適用計算
// -------------------------------------------------------------
$sam_applied_list = [];
$total_sum_all = 0;
$valid_boat_count = 0;

// 1. 各艇の合計値（J + K + L または 展示+周回+直線）を計算
foreach ($tenji_list as $t) {
    $b = (int)$t['teiban'];
    
    // J/K/Lの数値変換（ハイフン等の場合は0）
    $val_j = is_numeric($t['tenji_J']) ? (float)$t['tenji_J'] : 0;
    $val_k = is_numeric($t['tenji_K']) ? (float)$t['tenji_K'] : 0;
    $val_l = is_numeric($t['tenji_L']) ? (float)$t['tenji_L'] : 0;
    
    $sum = $val_j + $val_k + $val_l;
    
    if ($sum > 0) {
        $total_sum_all += $sum;
        $valid_boat_count++;
    }

    $sam_applied_list[$b] = [
        'teiban'   => $b,
        'course'   => (int)($t['tenji_course'] ?? $b),
        'val_j'    => $val_j,
        'val_k'    => $val_k,
        'val_l'    => $val_l,
        'sum'      => $sum,
        'avg_diff' => 0, // 後で計算
    ];
}

// 2. 全体平均の算出
$overall_avg = ($valid_boat_count > 0) ? ($total_sum_all / $valid_boat_count) : 0;

// 3. 平均差とマスタ参照（1着率〜3連対率の取得）
foreach ($sam_applied_list as $b => &$s) {
    if ($s['sum'] > 0 && $overall_avg > 0) {
        $diff = $s['sum'] - $overall_avg;
        $s['avg_diff'] = round($diff, 3);

        // 区間の判定
        $d = $s['avg_diff'];
        if ($d < -0.6)         $interval = "-0.6未満";
        elseif ($d < -0.4)    $interval = "-0.6--0.4";
        elseif ($d < -0.2)    $interval = "-0.4--0.2";
        elseif ($d < 0.0)     $interval = "-0.2-0.0";
        elseif ($d < 0.2)     $interval = "0.0-0.2";
        elseif ($d < 0.4)     $interval = "0.2-0.4";
        elseif ($d < 0.6)     $interval = "0.4-0.6";
        else                  $interval = "0.6以上";

        // サムマスタから該当コース・該当区間の確率を取得
        $c_str = (string)$s['course'];
        $m_data = $sam_master_data[$c_str][$interval] ?? [];

        $s['interval'] = $interval;
        $s['win']      = (float)($m_data['win'] ?? 0);
        $s['place2']   = (float)($m_data['place2'] ?? 0);
        $s['place3']   = (float)($m_data['place3'] ?? 0);
        $s['trio']     = (float)($m_data['trio'] ?? 0);
    } else {
        $s['avg_diff'] = 0;
        $s['interval'] = '-';
        $s['win'] = $s['place2'] = $s['place3'] = $s['trio'] = 0;
    }
}
unset($s);

// -------------------------------------------------------------
// ■ スリット体系データの取得とマスタ参照
// -------------------------------------------------------------
// パターン辞書マスタ（添付画像より）
$slit_pattern_master = [
    1  => ['name' => '内側先行',   'desc' => '1〜3が速い。最も広い条件。最後に判定。'],
    2  => ['name' => '横一線',     'desc' => '最も広い条件。後ろで判定。'],
    3  => ['name' => '1・2先行',   'desc' => '1と2が速い。1（内側先行）より条件が狭い。'],
    4  => ['name' => 'スロー先行', 'desc' => '1〜3が先行。3（1・2先行）と重複するため後。'],
    5  => ['name' => 'カベなし',   'desc' => '2が遅れる。個別艇の遅れとして6・7より後。'],
    6  => ['name' => '2・3遅れ',   'desc' => 'センター凹みの別パターン。7より優先度低い。'],
    7  => ['name' => '中凹み',     'desc' => '3・4が遅れる。センター凹みとして特徴が強い。'],
    8  => ['name' => '3の先攻め',  'desc' => '3が突出。個別艇の特徴として9より優先。'],
    9  => ['name' => '中ぶくれ',   'desc' => '3・4が先行。8と重複するため後に判定。'],
    10 => ['name' => '1が遅れる',  'desc' => 'イン遅れは展開に大きく影響。優先度高い。'],
    11 => ['name' => '外側先行',   'desc' => '456が上位。12と重複しやすいので次に判定。'],
    12 => ['name' => 'ダッシュ先行', 'desc' => '456が圧倒的に速い。外側先行の上位互換。最優先。'],
];

$slit_data = [];
$slit_pattern = ['id' => '-', 'name' => '不明', 'desc' => ''];

if (!empty($race_code)) {
    // API呼び出し（ローカル環境の slit_api.php へリクエスト）
    $ch = curl_init("http://192.168.0.208:80/slit_api.php"); // ※環境のポート番号に合わせてください
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['race_code' => $race_code]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $json = json_decode($response, true);
        if (isset($json['buff_debuff'])) {
            $slit_data = $json['buff_debuff'];
        }
        if (isset($json['pattern_id'])) {
            $pid = (int)$json['pattern_id'];
            $slit_pattern['id'] = $pid;
            if (isset($slit_pattern_master[$pid])) {
                $slit_pattern['name'] = $slit_pattern_master[$pid]['name'];
                $slit_pattern['desc'] = $slit_pattern_master[$pid]['desc'];
            }
        }
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

        .table-container { overflow-x: auto; margin-top: 10px; }
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

        /* サム理論表示用 */
        .sam-table td, .sam-table th { font-family: monospace; }
        .sam-course-bg-1 { background-color: rgba(248, 250, 252, 0.05); }
        .sam-course-bg-2 { background-color: rgba(30, 41, 59, 0.5); }
        .sam-course-bg-3 { background-color: rgba(239, 68, 68, 0.1); }
        .sam-course-bg-4 { background-color: rgba(59, 130, 246, 0.1); }
        .sam-course-bg-5 { background-color: rgba(234, 179, 8, 0.1); }
        .sam-course-bg-6 { background-color: rgba(34, 197, 94, 0.1); }
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
                        <!-- ★ JKL評価用の列を追加 -->
                            <th style="color:#a5b4fc;">J列</th>
                            <th style="color:#a5b4fc;">K列</th>
                            <th style="color:#38bdf8;">L列(メイン評価)</th>
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
                        <!-- ★ 追加: J / K / L の値を表示（L列は目立つように強調） -->
                                <td style="color:#c7d2fe;"><?= htmlspecialchars($t['tenji_J']) ?></td>
                                <td style="color:#c7d2fe;"><?= htmlspecialchars($t['tenji_K']) ?></td>
                                <td class="score-highlight"><?= htmlspecialchars($t['tenji_L']) ?></td>
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

        <!-- ■ 最終予想（Excel/VBA完全一致） -->
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
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                            <?php
                                $fp = $final_predictions[$i];
                                $c  = $lane_colors[$i] ?? $lane_colors[1];
                            ?>
                            <tr>
                                <td><?= $fp['boat'] ?></td>
                                <td>
                                    <span class="lane-badge" style="background-color: <?= $c['bg'] ?>; color: <?= $c['text'] ?>; border: 1px solid <?= $c['border'] ?>;">
                                        <?= $fp['waku'] ?>
                                    </span>
                                </td>
                                <td><?= number_format($fp['rate6_dec'] * 100, 1) ?>%</td>
                                <td><?= number_format($fp['rate3_dec'] * 100, 1) ?>%</td>
                                <td><?= number_format($fp['kitai_dec'] * 100, 1) ?>%</td>
                                <td><?= htmlspecialchars($fp['flg_sashi']) ?></td>
                                <td><?= htmlspecialchars($fp['flg_makuri']) ?></td>
                                <td><?= htmlspecialchars($fp['flg_makurizashi']) ?></td>
                                <td><?= htmlspecialchars($fp['flg_nogashi']) ?></td>
                                <td><?= htmlspecialchars($fp['type']) ?></td>
                                <td><?= $fp['typeBonus'] ?></td>
                                <td class="score-highlight" style="font-size: 14px;"><?= $fp['final3'] ?></td>
                                <td>
                                    <?php if ($fp['kiru'] == 1): ?>
                                        <span style="color:#ef4444; font-weight:bold;">1</span>
                                    <?php else: ?>
                                        0
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endfor; ?>
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
                            <td><?= htmlspecialchars($honmei_aite_str) ?></td>
                            <td><?= htmlspecialchars($kiru_str) ?></td>
                            <td><?= htmlspecialchars($honmei_aite_kako) ?></td>
                            <td><?= htmlspecialchars($kiru_kako) ?></td>
                            <td class="score-highlight"><?= htmlspecialchars($honmei_kai) ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight:bold; color:#f59e0b;">対抗</td>
                            <td><?= $taikou_head ?></td>
                            <td><?= htmlspecialchars($taikou_aite_str) ?></td>
                            <td><?= htmlspecialchars($kiru_str) ?></td>
                            <td><?= htmlspecialchars($taikou_aite_kako) ?></td>
                            <td><?= htmlspecialchars($kiru_kako) ?></td>
                            <td class="score-highlight"><?= htmlspecialchars($taikou_kai) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-data">最終予想データが存在しません。</div>
        <?php endif; ?>

<!-- ■ サム理論マスタデータ -->
        <h2>📐 サム理論（コース・区間別マスタ）</h2>
        <?php if (!empty($sam_master_data)): ?>
            <div class="table-container">
                <table class="sam-table">
                    <thead>
                        <tr>
                            <th>コース</th>
                            <th>区間</th>
                            <th>win</th>
                            <th>place2</th>
                            <th>place3</th>
                            <th>trio</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($course = 1; $course <= 6; $course++): ?>
                            <?php 
                                $c_str = (string)$course;
                                $course_data = $sam_master_data[$c_str] ?? [];
                                $c = $lane_colors[$course] ?? $lane_colors[1];
                                $bg_class = "sam-course-bg-" . $course;
                            ?>
                            <?php foreach ($sam_intervals as $idx => $interval): ?>
                                <?php $row_metrics = $course_data[$interval] ?? []; ?>
                                <tr class="<?= $bg_class ?> <?= ($idx === 0) ? 'border-top-course' : '' ?>">
                                    <?php if ($idx === 0): ?>
                                        <td rowspan="8" style="vertical-align: middle;">
                                            <span class="lane-badge" style="background-color: <?= $c['bg'] ?>; color: <?= $c['text'] ?>; border: 1px solid <?= $c['border'] ?>;">
                                                <?= $course ?>
                                            </span>
                                        </td>
                                    <?php endif; ?>
                                    <td style="text-align: center; color: #a5b4fc;"><?= htmlspecialchars($interval) ?></td>
                                    <?php foreach ($sam_metrics as $m): ?>
                                        <?php 
                                            $val = (float)($row_metrics[$m] ?? 0);
                                            // 0を跨ぐ正負の色分け（プラスなら水色、マイナスなら赤系など）
                                            $color_style = "";
                                            if ($val > 0) $color_style = "color: #38bdf8;";
                                            elseif ($val < 0) $color_style = "color: #f87171;";
                                        ?>
                                        <td style="<?= $color_style ?>">
                                            <?= number_format($val * 100, 0) ?>%
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-data"><?= htmlspecialchars($sam_error ?: 'サム理論マスタデータが存在しません。') ?></div>
        <?php endif; ?>

        <!-- ■ 展示サム理論 (Excel完全再現) -->
<h2>📐 展示サム理論（レース適用値）</h2>
<?php if (!empty($sam_applied_list)): ?>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>コース</th>
                    <th>J列</th>
                    <th>K列</th>
                    <th>L列</th>
                    <th>合計</th>
                    <th>平均差</th>
                    <th>1着率</th>
                    <th>2着率</th>
                    <th>3着率</th>
                    <th>3連対率</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sam_applied_list as $s): ?>
                    <?php $c = $lane_colors[$s['course']] ?? $lane_colors[1]; ?>
                    <tr>
                        <td>
                            <span class="lane-badge" style="background-color: <?= $c['bg'] ?>; color: <?= $c['text'] ?>; border: 1px solid <?= $c['border'] ?>;">
                                <?= $s['course'] ?>
                            </span>
                        </td>
                        <td><?= number_format($s['val_j'], 2) ?></td>
                        <td><?= number_format($s['val_k'], 2) ?></td>
                        <td><?= number_format($s['val_l'], 2) ?></td>
                        <td style="font-weight: bold;"><?= number_format($s['sum'], 2) ?></td>
                        <td style="color: <?= $s['avg_diff'] < 0 ? '#38bdf8' : '#f87171' ?>; font-weight: bold;">
                            <?= sprintf('%+.3f', $s['avg_diff']) ?>
                        </td>
                        <td style="color: <?= $s['win'] > 0 ? '#38bdf8' : ($s['win'] < 0 ? '#f87171' : '#fff') ?>;">
                            <?= number_format($s['win'] * 100, 0) ?>%
                        </td>
                        <td style="color: <?= $s['place2'] > 0 ? '#38bdf8' : ($s['place2'] < 0 ? '#f87171' : '#fff') ?>;">
                            <?= number_format($s['place2'] * 100, 0) ?>%
                        </td>
                        <td style="color: <?= $s['place3'] > 0 ? '#38bdf8' : ($s['place3'] < 0 ? '#f87171' : '#fff') ?>;">
                            <?= number_format($s['place3'] * 100, 0) ?>%
                        </td>
                        <td style="color: <?= $s['trio'] > 0 ? '#38bdf8' : ($s['trio'] < 0 ? '#f87171' : '#fff') ?>; font-weight: bold;">
                            <?= number_format($s['trio'] * 100, 0) ?>%
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background-color: #0f172a; font-weight: bold;">
                    <td colspan="4" style="text-align: right; color: #94a3b8;">全体平均:</td>
                    <td style="color: #38bdf8;"><?= number_format($overall_avg, 3) ?></td>
                    <td colspan="5"></td>
                </tr>
            </tfoot>
        </table>
    </div>
<?php endif; ?>

<!-- ■ スリット体系 -->
<div style="margin-top: 30px; background-color: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 20px;">
    <h2 style="font-size: 18px; font-weight: bold; color: #f8fafc; margin-bottom: 15px;">📊 スリット体系</h2>

    <?php if (!empty($slit_data)): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>コース</th>
                        <th>1着率</th>
                        <th>2着率</th>
                        <th>3着率</th>
                        <th>3連対率</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($c = 1; $c <= 6; $c++): ?>
                        <?php 
                            $metrics = $slit_data[$c] ?? $slit_data[(string)$c] ?? [];
                            $color = $lane_colors[$c] ?? $lane_colors[1];
                        ?>
                        <tr>
                            <td>
                                <span class="lane-badge" style="background-color: <?= $color['bg'] ?>; color: <?= $color['text'] ?>; border: 1px solid <?= $color['border'] ?>;">
                                    <?= $c ?>
                                </span>
                            </td>
                            <td><?= sprintf('%.2e', $metrics['win'] ?? 0) ?></td>
                            <td><?= sprintf('%.2e', $metrics['place2'] ?? 0) ?></td>
                            <td><?= sprintf('%.2e', $metrics['place3'] ?? 0) ?></td>
                            <td style="font-weight: bold;"><?= sprintf('%.2e', $metrics['trio'] ?? 0) ?></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <!-- パターン情報表示エリア -->
        <div style="margin-top: 15px; padding: 12px; background-color: #1e293b; border-radius: 6px; border-left: 4px solid #38bdf8;">
            <div style="display: flex; gap: 20px; align-items: center; margin-bottom: 6px;">
                <div><span style="color: #94a3b8; font-size: 12px;">パターンID:</span> <strong style="color: #f8fafc; font-size: 16px;"><?= htmlspecialchars($slit_pattern['id']) ?></strong></div>
                <div><span style="color: #94a3b8; font-size: 12px;">パターン名:</span> <span class="badge" style="background-color: #0284c7; color: #fff; padding: 3px 8px; border-radius: 4px; font-weight: bold;"><?= htmlspecialchars($slit_pattern['name']) ?></span></div>
            </div>
            <div style="font-size: 13px; color: #cbd5e1;">
                <span style="color: #94a3b8;">説明:</span> <?= htmlspecialchars($slit_pattern['desc']) ?>
            </div>
        </div>
    <?php else: ?>
        <p style="color: #94a3b8;">※スリット体系データが取得できませんでした。</p>
    <?php endif; ?>
</div>
    </div>
</body>
</html>