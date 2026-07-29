<?php

if (!class_exists('SamLogic')) {

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

}