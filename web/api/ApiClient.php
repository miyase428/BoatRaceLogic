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

    /**
     * サム理論マスタデータを取得する
     */
    public function fetchSamMaster(string $selected_place): array
    {
        $sam_master_data = [];
        $sam_error = '';

        $place_map_path = __DIR__ . '/../../config/place_map.php';
        $place_map = file_exists($place_map_path) ? require $place_map_path : [];

        $jyo_num = $place_map[$selected_place] ?? $selected_place;
        $jyo_int = (int)$jyo_num;

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
            $parsed = json_decode($sam_json, true) ?? [];

            // 文字列キー ('04') でも数値キー (4) でも取り出せるようにガード
            if (isset($parsed[$jyo_num])) {
                $sam_master_data = $parsed[$jyo_num];
            } elseif (isset($parsed[$jyo_int])) {
                $sam_master_data = $parsed[$jyo_int];
            } else {
                $sam_master_data = $parsed;
            }
        } else {
            $sam_error = 'サム理論マスタAPIの取得に失敗しました。';
        }

        return [$sam_master_data, $sam_error];
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

    public function updateExhibition(string $race_code): array
    {
        $update_message = '';
        $debug_msg = '';

        if (!empty($race_code)) {
            $target_url = "http://192.168.0.208:80/update_exhibition.php";

            $ch = curl_init($target_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                "race_code" => $race_code
            ]));
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $update_response = curl_exec($ch);
            $curl_errno = curl_errno($ch);
            $curl_error = curl_error($ch);
            $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curl_errno !== 0) {
                $debug_msg = "【cURL通信エラー】\nCode: {$curl_errno}\nError: {$curl_error}";
                $update_message = "展示情報の更新に失敗しました。";
            } else {
                $update_json = json_decode($update_response, true);
                $debug_msg = 
                    "HTTP STATUS: " . $http_code . "\n" .
                    "RACE_CODE: " . $race_code . "\n\n" .
                    "--- RAW RESPONSE ---\n" . $update_response . "\n\n" .
                    "--- JSON PARSED ---\n" . var_export($update_json, true);

                $update_message = $update_json["message"] ?? "更新処理が完了しました";
            }
        }

        return [$update_message, $debug_msg];
    }
}

class SamLogic
{
    // 区間およびメトリクスの定義をクラス定数として保持
    public const INTERVALS = ["-0.6未満", "-0.6--0.4", "-0.4--0.2", "-0.2-0.0", "0.0-0.2", "0.2-0.4", "0.4-0.6", "0.6以上"];
    public const METRICS   = ["win", "place2", "place3", "trio"];

    public function applySamTheory(array $tenji_list, array $sam_master_data): array
    {
        $sam_applied_list = [];
        $total_sum_all = 0;
        $valid_boat_count = 0;

        foreach ($tenji_list as $t) {
            $b = (int)$t['teiban'];

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
                'avg_diff' => 0,
            ];
        }

        $overall_avg = ($valid_boat_count > 0) ? ($total_sum_all / $valid_boat_count) : 0;

        foreach ($sam_applied_list as $b => &$s) {
            if ($s['sum'] > 0 && $overall_avg > 0) {
                $diff = $s['sum'] - $overall_avg;
                $s['avg_diff'] = round($diff, 3);

                $d = $s['avg_diff'];
                if ($d < -0.6)         $interval = "-0.6未満";
                elseif ($d < -0.4)    $interval = "-0.6--0.4";
                elseif ($d < -0.2)    $interval = "-0.4--0.2";
                elseif ($d < 0.0)     $interval = "-0.2-0.0";
                elseif ($d < 0.2)     $interval = "0.0-0.2";
                elseif ($d < 0.4)     $interval = "0.2-0.4";
                elseif ($d < 0.6)     $interval = "0.4-0.6";
                else                  $interval = "0.6以上";

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

        return $sam_applied_list;
    }
}