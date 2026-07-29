<?php

class ApiClient
{
    public function fetchCalcScores(string $race_code): array
    {
        $entries = [];
        $results = [];
        $api_error = '';

        $api_url = "http://localhost/calc_scores.php?race_code=" . urlencode($race_code);
        $json_data = @file_get_contents($api_url);

        if ($json_data !== false) {
            $response = json_decode($json_data, true);
            if (($response['status'] ?? '') === 'ok') {
                $entries = $response['entries'] ?? [];
                $results = $response['results'] ?? [];
            } else {
                $api_error = '出走表データが見つかりませんでした。';
            }
        } else {
            $api_error = '出走表APIの呼び出しに失敗しました。';
        }

        return [$entries, $results, $api_error];
    }

    public function fetchKimarite(string $race_code, string $in_course): array
    {
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

        return [$kimarite_data, $kimarite_error];
    }

    public function fetchTenji(string $race_code, array $results, string $selected_place): array
    {
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

                $JKL_rule = [
                    'KRY' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
                    'TDA' => ['J' => 'exhibition', 'K' => 'mawari',   'L' => 'straight'],
                    // 省略せず、他場も元のindex.phpと同様に定義してOK
                    'OMR' => ['J' => 'exhibition', 'K' => 'lap',      'L' => 'straight'],
                ];

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

                    $tenji_J        = $item[$rule['J']] ?? '-';
                    $tenji_K        = $item[$rule['K']] ?? '-';
                    $tenji_L        = $item[$rule['L']] ?? '-';
                    $tenji_selected = $tenji_L;

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
                    $t['tenkai_morai'] = ($b === 2 || $b === 4) ? 1 : 0;
                    $t['final_2nd_score'] = $t['ex_sougou'] + $t['type_hosei'] + $t['tenkai_morai'];
                }
                unset($t);

                $tenji_list = array_values($calculated_list);
            } else {
                $tenji_error = '展示APIの取得に失敗しました。';
            }
        }

        return [$tenji_list, $tenji_error];
    }

    public function fetchTenjiTest(string $race_code, array $tenji_list): array
    {
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
        return json_decode($jsonString, true) ?? [];
    }

    public function fetchSamMaster(string $selected_place): array
    {
        $place_map = require __DIR__ . '/../../config/place_map.php';
        $sam_master_data = [];
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
            $sam_master_data = $parsed_sam[$jyo_num] ?? $parsed_sam;
        }

        return $sam_master_data;
    }

    public function fetchSlit(string $race_code): array
    {
        $slit_data = [];
        $slit_pattern = ['id' => '-', 'name' => '不明', 'desc' => ''];

        if (!empty($race_code)) {
            $ch = curl_init("http://192.168.0.208:80/slit_api.php");
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
                if (isset($json['features'])) {
                    $slit_pattern['features'] = $json['features'];
                }

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

        return [$slit_data, $slit_pattern];
    }
}
