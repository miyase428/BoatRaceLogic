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
        $in_course       = $_GET['in_course'] ?? '123456';

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

        // 1-2. 展示前の基本1着率
        // 場×コース → 選手×コース(K=20) → 選手×場×コース(K=10) → 6艇100%正規化
        $base_win_rate_data = $baseWinRateLogic->calculate($race_code);

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
        // 3-2. 展示進入マップを1回だけ構築
        // lane -> course / course -> lane をWeb全体の共通定義として使う。
        // 展示がまだ揃っていない場合は、従来どおりフォームのin_courseを使う。
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

        $effective_in_course = $in_course;
        if ($entry_map_ready) {
            $effective_in_course = '';
            for ($boat = 1; $boat <= 6; $boat++) {
                $effective_in_course .= (string)$entry_course_by_boat[$boat];
            }
        }
        $entry_changed = $entry_map_ready && $effective_in_course !== '123456';

        // -------------------------------------------------------------
        // 4. 決まり手データ
        // 展示が揃った後に取得し、進入変更時は実際の lane -> course を自動反映する。
        // -------------------------------------------------------------
        [$kimarite_data, $kimarite_error] = $apiClient->fetchKimarite(
            $race_code,
            $effective_in_course
        );

        // -------------------------------------------------------------
        // 5. 最終予想ロジック
        // ApiClientProduction / PredictionLogicProduction が進入マップを使って
        // course順へ並べ替えて計算し、最後に艇番へ戻す。
        // -------------------------------------------------------------
        $tenji_test_data = $apiClient->fetchTenjiTest($race_code, $tenji_list);
        $final_predictions = $predictionLogic->buildFinalPredictions(
            $tenji_list,
            $kimarite_data,
            $tenji_test_data,
            $results
        );
        $summary = $predictionLogic->buildSummary($final_predictions);

        // -------------------------------------------------------------
        // 6. サム理論マスタ & ロジック適用
        // -------------------------------------------------------------
        [$sam_master_data, $sam_error] = $apiClient->fetchSamMaster($selected_place);
        [$sam_applied_list, $overall_avg] = $samLogic->applySamTheory($tenji_list, $sam_master_data);

        // 7. スリット体系
        [$slit_data, $slit_pattern] = $apiClient->fetchSlit($race_code);
        $feature_name = $slitLogic->getFeatureNames();

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
            $corrected_win_rate_data = $correctedWinRateLogic->calculate($race_code);
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

        $viewData = [
            'selected_date'   => $selected_date,
            'selected_place'  => $selected_place,
            'selected_race'   => $selected_race,
            'race_code'       => $race_code,
            'place_map'       => $place_map,
            'place_names'     => $place_names,
            'in_course'       => $in_course,
            'effective_in_course' => $effective_in_course,
            'entry_map_ready' => $entry_map_ready,
            'entry_changed' => $entry_changed,
            'entry_course_by_boat' => $entry_course_by_boat,
            'boat_by_entry_course' => $boat_by_entry_course,
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
