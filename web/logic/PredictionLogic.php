<?php

class PredictionLogic
{
    private function to_dec($val): float
    {
        $f = (float)$val;
        return ($f > 1.0) ? $f / 100.0 : $f;
    }

    public function buildFinalPredictions(array $tenji_list, array $kimarite_data, array $tenji_test_data): array
    {
        // 1コース決まり手
        $k1 = $kimarite_data['1']['6month'] ?? $kimarite_data['1'] ?? [];
        $k1_nige_dec     = $this->to_dec($k1['nige'] ?? 0);
        $k1_sashi_dec    = $this->to_dec($k1['sashi'] ?? 0);
        $k1_makuri_dec   = $this->to_dec($k1['makuri'] ?? 0);
        $k1_makuri_z_dec = $this->to_dec($k1['makurizashi'] ?? 0);

        $final_predictions = [];

        for ($i = 1; $i <= 6; $i++) {
            $boat = $i;
            $waku = $boat;

            $api_item = $tenji_test_data[$i - 1] ?? [];
            $rate6_dec = (float)($api_item['three_in_rate_6m'] ?? 0);
            $rate3_dec = (float)($api_item['three_in_rate_3m'] ?? 0);

            $t_data = $tenji_list[$i - 1] ?? [];
            $k_data = $kimarite_data[(string)$boat]['6month'] ?? $kimarite_data[(string)$boat] ?? [];

            $score_s = (float)($t_data['ex_sougou'] ?? 0);

            if ($i === 1) {
                $kitai_dec = $k1_nige_dec * (1.0 + ($score_s / 100.0));
            } else {
                $sashi_dec   = $this->to_dec($k_data['sashi'] ?? 0);
                $makuri_dec  = $this->to_dec($k_data['makuri'] ?? 0);
                $makuriz_dec = $this->to_dec($k_data['makurizashi'] ?? 0);

                $kitai_dec = ($sashi_dec + $makuri_dec + $makuriz_dec) * (1.0 + ($score_s / 100.0));
            }

            $flg_sashi = "-";
            $flg_makuri = "-";
            $flg_makurizashi = "-";
            $flg_nogashi = "-";

            if ($i >= 2) {
                $curr_sashi   = $this->to_dec($k_data['sashi'] ?? 0);
                $curr_makuri  = $this->to_dec($k_data['makuri'] ?? 0);
                $curr_makuriz = $this->to_dec($k_data['makurizashi'] ?? 0);

                if ($k1_sashi_dec > 0.12 && $curr_sashi > 0.12) {
                    $flg_sashi = "★{$i}差し";
                }
                if ($k1_makuri_dec > 0.12 && $curr_makuri > 0.12) {
                    $flg_makuri = "★{$i}まくり";
                }
                if ($k1_makuri_z_dec > 0.12 && $curr_makuriz > 0.12) {
                    $flg_makurizashi = "★{$i}まくり差し";
                }
            }

            if ($i === 2) {
                $nogashi_dec = $this->to_dec($k_data['nogashi'] ?? 0);
                if ($nogashi_dec > 0.4) {
                    $flg_nogashi = "★壁役(逃がし)";
                }
            } elseif ($i === 3) {
                $st_score_3 = (float)($tenji_list[2]['st_score'] ?? 0);
                $stFactor = 1.0 + ($st_score_3 - 3.0) * 0.1;

                $k3_makuri  = $this->to_dec($k_data['makuri'] ?? 0);
                $k3_makuriz = $this->to_dec($k_data['makurizashi'] ?? 0);
                $blockIndex = ($k3_makuri + $k3_makuriz) * $stFactor;

                if ($blockIndex > 0.12) {
                    $flg_nogashi = "★外ブロック";
                }
            }

            $nige_d      = $this->to_dec($k_data['nige'] ?? 0);
            $sashi_d     = $this->to_dec($k_data['sashi'] ?? 0);
            $makuri_d    = $this->to_dec($k_data['makuri'] ?? 0);
            $makuriz_d   = $this->to_dec($k_data['makurizashi'] ?? 0);
            $sasare_d    = $this->to_dec($k_data['sasare'] ?? 0);
            $makurarez_d = $this->to_dec($k_data['makurarezashi'] ?? 0);

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

        // 切る艇判定
        $m_scores = array_values(array_column($final_predictions, 'final3'));
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

        return $final_predictions;
    }

    /**
     * 最終予想結果から「本命・対抗・切る艇・買い目」などの集計変数を抽出するヘルパー
     */
public function buildSummary(array $final_predictions): array
    {
        $kiru_boats = [];
        for ($i = 1; $i <= 6; $i++) {
            if (isset($final_predictions[$i]) && ($final_predictions[$i]['kiru'] ?? 0) == 1) {
                $kiru_boats[] = $i;
            }
        }

        // final3 の降順にソート（キーを保持）
        $sorted_preds = $final_predictions;
        uasort($sorted_preds, function($a, $b) {
            return ($b['final3'] ?? 0) <=> ($a['final3'] ?? 0);
        });

        // スコア順の艇番リスト（例: [6, 4, 5, 2, 1, 3]）
        $rank_boats = array_column(array_values($sorted_preds), 'boat');

        $honmei_head = $rank_boats[0] ?? 1;
        $taikou_head = $rank_boats[1] ?? 2;

        // 【集計用クロージャ】
        $calc_for_head = function($head) use ($rank_boats, $kiru_boats) {
            $aite = []; // 2着候補（上位最大3艇）
            $third = []; // 3着候補（切る艇以外すべて）

            foreach ($rank_boats as $b) {
                if ($b == $head) continue;               // 頭は除外
                if (in_array($b, $kiru_boats)) continue; // 切る艇は除外

                // 3着候補には切る艇以外すべて追加
                $third[] = $b;

                // 2着（相手）候補は上位最大3艇まで
                if (count($aite) < 3) {
                    $aite[] = $b;
                }
            }

            sort($aite);  // 見映え用に昇順ソート
            sort($third); // 見映え用に昇順ソート

            return [$aite, $third];
        };

        [$honmei_aite, $honmei_third] = $calc_for_head($honmei_head);
        [$taikou_aite, $taikou_third] = $calc_for_head($taikou_head);

        // 表示用文字列（ドット区切り）
        $honmei_aite_str = implode('・', $honmei_aite);
        $taikou_aite_str = implode('・', $taikou_aite);
        $kiru_str        = implode('・', $kiru_boats);

        // 買い目用文字列（結合）
        $honmei_aite_kako  = implode('', $honmei_aite);
        $honmei_third_kako = implode('', $honmei_third);

        $taikou_aite_kako  = implode('', $taikou_aite);
        $taikou_third_kako = implode('', $taikou_third);

        $kiru_kako         = implode('', $kiru_boats);

        // 買い目 3連単 (例: 6 - 245 - 12345)
        $honmei_kai = $honmei_head . '-' . $honmei_aite_kako . '-' . $honmei_third_kako;
        $taikou_kai = $taikou_head . '-' . $taikou_aite_kako . '-' . $taikou_third_kako;

        return [
            'honmei_head'      => $honmei_head,
            'taikou_head'      => $taikou_head,
            'honmei_aite_str'  => $honmei_aite_str,
            'taikou_aite_str'  => $taikou_aite_str,
            'kiru_str'         => $kiru_str,
            'honmei_aite_kako' => $honmei_aite_kako,
            'taikou_aite_kako' => $taikou_aite_kako,
            'kiru_kako'        => $kiru_kako,
            'honmei_kai'       => $honmei_kai,
            'taikou_kai'       => $taikou_kai,
        ];
    }
}