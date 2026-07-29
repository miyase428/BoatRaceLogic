<?php
require_once __DIR__ . '/../../common/db_connect.php';
require_once __DIR__ . '/../../config/place_map.php';

require_once __DIR__ . '/../api/ApiClient.php';
require_once __DIR__ . '/../logic/ExhibitionLogic.php';
require_once __DIR__ . '/../logic/PredictionLogic.php';
require_once __DIR__ . '/../logic/SamLogic.php';
require_once __DIR__ . '/../logic/SlitLogic.php';

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

        $apiClient        = new ApiClient();
        $exhibitionLogic  = new ExhibitionLogic();
        $predictionLogic  = new PredictionLogic();
        $samLogic         = new SamLogic();
        $slitLogic        = new SlitLogic();

        // 1. 出走表データ
        [$entries, $results, $api_error] = $apiClient->fetchCalcScores($race_code);

        // 2. 決まり手データ
        [$kimarite_data, $kimarite_error] = $apiClient->fetchKimarite($race_code, $in_course);

        // -------------------------------------------------------------
        // ★【②展示情報の更新処理】（「展示情報を更新」ボタン押下時）
        // -------------------------------------------------------------
        $update_message = '';
        $debug_msg = '';

        if (isset($_POST["update_exhibition"])) {
            $target_race_code = $_POST["race_code"] ?? $race_code;
            
            // 展示API経由等でスクレイピング・DB登録を実行
            [$update_message, $debug_msg] = $apiClient->updateExhibition($target_race_code);
        }

        // -------------------------------------------------------------
        // 3. 展示データ（①初回は既存/ハイフン、②更新後は最新DBを取得）
        // -------------------------------------------------------------
        [$tenji_list, $tenji_error] = $apiClient->fetchTenji($race_code, $results, $selected_place);

        // -------------------------------------------------------------
        // 4. 最終予想ロジック（③最新のtenji_listを使って再計算）
        // -------------------------------------------------------------
        $tenji_test_data = $apiClient->fetchTenjiTest($race_code, $tenji_list);
        $final_predictions = $predictionLogic->buildFinalPredictions(
            $tenji_list,
            $kimarite_data,
            $tenji_test_data
        );
        $summary = $predictionLogic->buildSummary($final_predictions);

        // -------------------------------------------------------------
        // 5. サム理論マスタ & ロジック適用
        // -------------------------------------------------------------
        // ApiClientから [マスタデータ, エラーメッセージ] のペアで受け取る
        // 5. サム理論マスタ & ロジック適用
        [$sam_master_data, $sam_error] = $apiClient->fetchSamMaster($selected_place);
        [$sam_applied_list, $overall_avg] = $samLogic->applySamTheory($tenji_list, $sam_master_data);
        // 6. スリット体系
        [$slit_data, $slit_pattern] = $apiClient->fetchSlit($race_code);
        $feature_name = $slitLogic->getFeatureNames();

        // 7. lane colors（枠番カラー）
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
            'entries'         => $entries,
            'results'         => $results,
            'api_error'       => $api_error,
            'kimarite_data'   => $kimarite_data,
            'kimarite_error'  => $kimarite_error,
            'tenji_list'      => $tenji_list,
            'tenji_error'     => $tenji_error,
            'update_message'  => $update_message,
            'debug_msg'       => $debug_msg,
            'final_predictions' => $final_predictions,
            'sam_applied_list'  => $sam_applied_list,
            'overall_avg'       => $overall_avg,           // ★ ビューに渡す
            'sam_error'         => $sam_error,             // サム理論APIのエラーメッセージ
            'sam_intervals'     => SamLogic::INTERVALS,    // 区間定義（表示用）
            'sam_metrics'       => SamLogic::METRICS,      // メトリクス定義（表示用）
            'slit_data'       => $slit_data,
            'slit_pattern'    => $slit_pattern,
            'feature_name'    => $feature_name,
            'lane_colors'     => $lane_colors,
        ];

        return array_merge($viewData, $summary);
    }
}