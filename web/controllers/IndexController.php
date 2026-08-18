<?php
require_once __DIR__ . '/../../common/db_connect.php';
require_once __DIR__ . '/../../config/place_map.php';

require_once __DIR__ . '/../api/ApiClientProduction.php';
require_once __DIR__ . '/../logic/ExhibitionLogic.php';
require_once __DIR__ . '/../logic/PredictionLogicProduction.php';
require_once __DIR__ . '/../logic/SamLogic.php';
require_once __DIR__ . '/../logic/SlitLogic.php';
require_once __DIR__ . '/../logic/BaseWinRateLogic.php';
require_once __DIR__ . '/../logic/CorrectedWinRateLogic.php';

class IndexController
{
    public function handle(): array
    {
        // require_once で読み込んだ place_map.php の配列を取得
        $place_map = require __DIR__ . '/../../config/place_map.php';

        // フォーム入力値
        $selected_date   = $_GET['date']  ?? date('Y-m-d');
        $selected_place  = $_GET['place'] ?? 'OMR';
        $selected_race   = $_GET['race']  ?? '12';

        // 仮想進入は「コース順の艇番」で入力する。
        // 例: 126345 = 1C=1号艇 / 2C=2号艇 / 3C=6号艇 / 4C=3号艇 / 5C=4号艇 / 6C=5号艇。
        $simulate_entry = (($_GET['simulate_entry'] ?? '') === '1');
        $virtual_entry = preg_replace('/\s+/', '', (string)($_GET['virtual_entry'] ?? '123456'));
        $virtual_entry_error = '';
        $virtual_course_by_boat = [];
        $virtual_boat_by_course = [];

        if ($simulate_entry) {
            $digits = str_split($virtual_entry);
            $sorted = $digits;
            sort($sorted);

            if (strlen($virtual_entry) !== 6 || $sorted !== ['1', '2', '3', '4', '5', '6']) {
                $virtual_entry_error = '仮想進入は1～6号艇を1回ずつ使う6桁で入力してください。例: 126345';
            } else {
                for ($course = 1; $course <= 6; $course++) {
                    $boat = (int)$virtual_entry[$course - 1];
                    $virtual_boat_by_course[$course] = $boat;
                    $virtual_course_by_boat[$boat] = $course;
                }
                ksort($virtual_course_by_boat);
                ksort($virtual_boat_by_course);
            }
        }

        $simulation_active = $simulate_entry && $virtual_entry_error === '';

        $formatted_date  = date('Ymd', strtotime($selected_date));
        $race_code       = $formatted_date . $selected_place . sprintf('%02d', $selected_race);

        // 場名マップ（画面表示用）
        $place_names = [
            'KRY' => '桐生',   'TDA' => '戸田',   'EDG' => '江戸川', 'HWJ' => '平和島',
            'TMG' => '多摩川', 'HMN' => '浜名湖', 'GMG' => '蒲郡',   'TKN' => '常滑',
            'TSU' => '津',     'MKN' => '三国',   'BWK' => 'びわこ', 'SME' => '住之江',
            'AMG' => '尼崎',   'NRT' => '鳴門',   'MRG' => '丸亀',   'KJM' => '児島',
            'MYJ' => '宮島',   'TKY' => '徳山',   'SMS' => '下関',   'WKM' => '若松',
            'ASY' => '芦屋',   'FKO' => '福岡',   'KRT' => '唐津',   'OMR' => '大村',
        ];

        $apiClient             = new ApiClientProduction();
        $exhibitionLogic       = new ExhibitionLogic();
        $predictionLogic       = new PredictionLogicProduction();
        $samLogic              = new SamLogic();
        $slitLogic             = new SlitLogic();
        $baseWinRateLogic      = new BaseWinRateLogic();
        $correctedWinRateLogic = new CorrectedWinRateLogic();

        // 1. 出走表データ
        [$entries, $results, $api_error] = $apiClient->fetchCalcScores($race_code);

        // 1-2. 基本1着率
        // 通常時は従来どおり枠=コース。仮想進入モードだけ指定コースへ置き換える。
        $base_win_rate_data = $baseWinRateLogic->calculate(
            $race_code,
            $simulation_active ? $virtual_course_by_boat : []
        );

        // -------------------------------------------------------------
        // 2. 展示情報の更新処理（「展示情報を更新」ボタン押下時）
        // -------------------------------------------------------------
        $update_message = '';
        $debug_msg = '';

        if (isset($_POST["update_exhibition"])) {
            $target_race_code = $_POST["race_code"] ?? $race_code;

            // 展示API経由等でスクレイピング・DB登録を実行
            [$update_message, $debug_msg] = $apiClient->updateExhibition($target_race_code);
        }

        // -------------------------------------------------------------
        // 3. 展示データ（更新後は最新DBを取得）
        // -------------------------------------------------------------
        [$tenji_list, $tenji_error] = $apiClient->fetchTenji($race_code, $results, $selected_place);

        // -------------------------------------------------------------
        // 3-2. 実際の展示進入マップ
        // boat -> course / course -> boat。ここは仮想進入でも書き換えない。
        // -------------------------------------------------------------
        $entry_course_by_boat = [];
        $boat_by_entry_course = [];
        $entry_map_ready = count($tenji_list) === 6;

        if ($entry_map_ready) {
            foreach ($tenji_list as $idx => $t) {
                $boat = (int)($t['teiban'] ?? ($idx + 1));
                $course = (int)($t['tenji_course'] ?? 0);

                if (
                    $boat < 1 || $boat > 6
                    || $course < 1 || $course > 6
                    || isset($entry_course_by_boat[$boat])
                    || isset($boat_by_entry_course[$course])
                    || !is_numeric($t['exhibition'] ?? null)
                    || !is_numeric($t['st'] ?? null)
                ) {
                    $entry_map_ready = false;
                    break;
                }

                $entry_course_by_boat[$boat] = $course;
                $boat_by_entry_course[$course] = $boat;
            }
        }

        if (!$entry_map_ready || count($entry_course_by_boat) !== 6 || count($boat_by_entry_course) !== 6) {
            $entry_map_ready = false;
            $entry_course_by_boat = [];
            $boat_by_entry_course = [];
        } else {
            ksort($entry_course_by_boat);
            ksort($boat_by_entry_course);
        }

        // 画面表示用の「コース順の艇番」。
        $exhibition_entry_order = '123456';
        if ($entry_map_ready) {
            $exhibition_entry_order = '';
            for ($course = 1; $course <= 6; $course++) {
                $exhibition_entry_order .= (string)$boat_by_entry_course[$course];
            }
        }

        // -------------------------------------------------------------
        // 3-3. 予想に使う進入マップ
        // 通常時 = 展示進入、仮想進入ON = 手入力した試算進入。
        // -------------------------------------------------------------
        if ($simulation_active) {
            $prediction_course_by_boat = $virtual_course_by_boat;
            $prediction_boat_by_course = $virtual_boat_by_course;
            $prediction_entry_order = $virtual_entry;
        } elseif ($entry_map_ready) {
            $prediction_course_by_boat = $entry_course_by_boat;
            $prediction_boat_by_course = $boat_by_entry_course;
            $prediction_entry_order = $exhibition_entry_order;
        } else {
            $prediction_course_by_boat = array_combine(range(1, 6), range(1, 6));
            $prediction_boat_by_course = array_combine(range(1, 6), range(1, 6));
            $prediction_entry_order = '123456';
        }

        // kimarite_api等の既存内部形式は「艇番 -> コース」の6桁。
        $effective_in_course = '';
        for ($boat = 1; $boat <= 6; $boat++) {
            $effective_in_course .= (string)($prediction_course_by_boat[$boat] ?? $boat);
        }

        $entry_changed = $entry_map_ready && $exhibition_entry_order !== '123456';
        $prediction_entry_changed = $prediction_entry_order !== '123456';

        // 展示値そのものは実測のまま、tenji_courseだけ試算配置へ差し替える。
        $prediction_tenji_list = $tenji_list;
        if ($simulation_active) {
            foreach ($prediction_tenji_list as &$t) {
                $boat = (int)($t['teiban'] ?? 0);
                if ($boat >= 1 && $boat <= 6 && isset($prediction_course_by_boat[$boat])) {
                    $t['tenji_course'] = (int)$prediction_course_by_boat[$boat];
                }
            }
            unset($t);
        }

        // -------------------------------------------------------------
        // 4. 決まり手データ
        // -------------------------------------------------------------
        [$kimarite_data, $kimarite_error] = $apiClient->fetchKimarite(
            $race_code,
            $effective_in_course
        );

        // -------------------------------------------------------------
        // 5. 最終予想ロジック
        // 仮想進入時はtenji_courseだけ試算配置にし、展示値は実測値を維持する。
        // -------------------------------------------------------------
        $tenji_test_data = $apiClient->fetchTenjiTest($race_code, $prediction_tenji_list);
        $final_predictions = $predictionLogic->buildFinalPredictions(
            $prediction_tenji_list,
            $kimarite_data,
            $tenji_test_data,
            $results
        );
        $summary = $predictionLogic->buildSummary($final_predictions);

        // 最終予想の計算後だけ表示用に「枠 / 予想進入」を併記する。
        if ($prediction_entry_changed) {
            foreach ($final_predictions as $boat => &$fp) {
                $course = (int)($fp['course'] ?? ($prediction_course_by_boat[$boat] ?? $boat));
                $fp['waku'] = $boat . '枠 / ' . $course . 'C';
            }
            unset($fp);
        }

        // -------------------------------------------------------------
        // 6. サム理論マスタ & ロジック適用
        // SUMの画面表示は実際の展示進入・展示値を表示する。
        // -------------------------------------------------------------
        [$sam_master_data, $sam_error] = $apiClient->fetchSamMaster($selected_place);
        [$sam_applied_list, $overall_avg] = $samLogic->applySamTheory($tenji_list, $sam_master_data);

        // 7. スリット体系
        // この独立表示は実際の展示進入。補正後1着率の仮想モード内部では専用PIDを再計算する。
        [$slit_data, $slit_pattern] = $apiClient->fetchSlit($race_code);
        $feature_name = $slitLogic->getFeatureNames();

        // 「3の先攻め」は艇番ではなく3コース位置を表すため表示を明示する。
        if (($slit_pattern['name'] ?? '') === '3の先攻め') {
            $slit_pattern['name'] = '3コース先攻め';
        }

        // 実展示で進入変更があった時はスリット説明に course -> boat を明示する。
        if ($entry_changed) {
            $entryParts = [];
            for ($course = 1; $course <= 6; $course++) {
                $boat = (int)($boat_by_entry_course[$course] ?? 0);
                if ($boat > 0) {
                    $entryParts[] = $course . 'C=' . $boat . '号艇';
                }
            }

            if ($entryParts) {
                $baseDesc = trim((string)($slit_pattern['desc'] ?? ''));
                $mapDesc = '展示進入: ' . implode(' / ', $entryParts);
                $slit_pattern['desc'] = $baseDesc !== '' ? $baseDesc . ' ｜ ' . $mapDesc : $mapDesc;
            }
        }

        if ($simulation_active) {
            $baseDesc = trim((string)($slit_pattern['desc'] ?? ''));
            $note = 'このスリット欄は展示実測。仮想進入の補正後1着率では試算進入でPIDを再計算。';
            $slit_pattern['desc'] = $baseDesc !== '' ? $baseDesc . ' ｜ ' . $note : $note;
        }

        // 7-2. 補正後1着率
        // 通常22場は展示5項目完備、AMG/TKYは検証済みEX_TOTAL3のためstraight不要。
        $correctedReady = count($tenji_list) === 6;
        $seenCourses = [];
        $requiresStraight = !in_array($selected_place, ['AMG', 'TKY'], true);

        if ($correctedReady) {
            foreach ($tenji_list as $t) {
                $course = (int)($t['tenji_course'] ?? 0);
                $straightMissing = $requiresStraight && !is_numeric($t['straight'] ?? null);

                if (
                    $course < 1 || $course > 6
                    || isset($seenCourses[$course])
                    || !is_numeric($t['exhibition'] ?? null)
                    || !is_numeric($t['st'] ?? null)
                    || !is_numeric($t['lap'] ?? null)
                    || !is_numeric($t['mawari'] ?? null)
                    || $straightMissing
                ) {
                    $correctedReady = false;
                    break;
                }
                $seenCourses[$course] = true;
            }
        }

        if ($correctedReady && count($seenCourses) === 6) {
            $corrected_win_rate_data = $correctedWinRateLogic->calculate(
                $race_code,
                $simulation_active ? $effective_in_course : null
            );
        } else {
            $corrected_win_rate_data = [
                'status' => 'waiting',
                'boats' => [],
                'error' => '展示情報待ち',
            ];
        }

        // 8. lane colors（枠番カラー）
        $lane_colors = [
            1 => ['bg' => '#f8fafc', 'text' => '#0f172a', 'border' => '#e2e8f0'],
            2 => ['bg' => '#1e293b', 'text' => '#f8fafc', 'border' => '#475569'],
            3 => ['bg' => '#ef4444', 'text' => '#ffffff', 'border' => '#dc2626'],
            4 => ['bg' => '#3b82f6', 'text' => '#ffffff', 'border' => '#2563eb'],
            5 => ['bg' => '#eab308', 'text' => '#0f172a', 'border' => '#ca8a04'],
            6 => ['bg' => '#22c55e', 'text' => '#ffffff', 'border' => '#16a34a'],
        ];

        // SUMは実展示の course を変えず、表示直前だけ「4C（5号艇）」形式にする。
        if ($entry_changed) {
            foreach ($sam_applied_list as &$s) {
                $boat = (int)($s['teiban'] ?? 0);
                $course = (int)($s['course'] ?? 0);
                if ($boat < 1 || $boat > 6 || $course < 1 || $course > 6) {
                    continue;
                }

                $courseLabel = $course . 'C（' . $boat . '号艇）';
                $lane_colors[$courseLabel] = $lane_colors[$boat] ?? $lane_colors[$course];
                $s['course'] = $courseLabel;
            }
            unset($s);
        }

        $viewData = [
            'selected_date'   => $selected_date,
            'selected_place'  => $selected_place,
            'selected_race'   => $selected_race,
            'race_code'       => $race_code,
            'place_map'       => $place_map,
            'place_names'     => $place_names,
            // 旧ビューが初期HTMLを作るための互換値。DOMContentLoaded後に新UIへ置換する。
            'in_course'       => '123456',
            'simulate_entry'  => $simulate_entry,
            'simulation_active' => $simulation_active,
            'virtual_entry'   => $virtual_entry,
            'virtual_entry_error' => $virtual_entry_error,
            'exhibition_entry_order' => $exhibition_entry_order,
            'prediction_entry_order' => $prediction_entry_order,
            'effective_in_course' => $effective_in_course,
            'entry_map_ready' => $entry_map_ready,
            'entry_changed' => $entry_changed,
            'prediction_entry_changed' => $prediction_entry_changed,
            // web/index.phpの最終予想表示はこのmapを見るため、仮想時だけ予想mapを渡す。
            'entry_course_by_boat' => $simulation_active ? $prediction_course_by_boat : $entry_course_by_boat,
            // SUM/スリット表示は実展示を維持するため、こちらは常に実展示map。
            'boat_by_entry_course' => $boat_by_entry_course,
            'prediction_course_by_boat' => $prediction_course_by_boat,
            'prediction_boat_by_course' => $prediction_boat_by_course,
            'entries'         => $entries,
            'results'         => $results,
            'api_error'       => $api_error,
            'base_win_rate_data' => $base_win_rate_data,
            'corrected_win_rate_data' => $corrected_win_rate_data,
            'kimarite_data'   => $kimarite_data,
            'kimarite_error'  => $kimarite_error,
            'tenji_list'      => $tenji_list,
            'tenji_error'     => $tenji_error,
            'update_message'  => $update_message,
            'debug_msg'       => $debug_msg,
            'final_predictions' => $final_predictions,
            'sam_applied_list'  => $sam_applied_list,
            'overall_avg'       => $overall_avg,
            'sam_master_data'   => $sam_master_data,
            'sam_error'         => $sam_error,
            'sam_intervals'     => SamLogic::INTERVALS,
            'sam_metrics'       => SamLogic::METRICS,
            'slit_data'       => $slit_data,
            'slit_pattern'    => $slit_pattern,
            'feature_name'    => $feature_name,
            'lane_colors'     => $lane_colors,
        ];

        return array_merge($viewData, $summary);
    }
}
