<?php
declare(strict_types=1);

require_once __DIR__ . '/../../common/db_connect.php';
require_once __DIR__ . '/../../web/api/ApiClientProduction.php';
require_once __DIR__ . '/../../web/logic/PredictionLogicProduction.php';

class FinalPredictionExporter
{
    private PDO $pdo;
    private ApiClientProduction $apiClient;
    private PredictionLogicProduction $predictionLogic;

    public function __construct()
    {
        $this->pdo = getPDO();
        $this->apiClient = new ApiClientProduction();
        $this->predictionLogic = new PredictionLogicProduction();
    }

    /**
     * 指定したrace_codeの最終予想を取得する
     */
    public function exportRace(string $raceCode): array
    {
        // ----------------------------------------------------
        // race_master
        // ----------------------------------------------------

        $stmt = $this->pdo->prepare(
            <<<SQL
            SELECT
                race_code,
                race_day,
                race_date,
                stadium_name,
                race_number
            FROM boat_race.race_master
            WHERE race_code = :race_code
            LIMIT 1
            SQL
        );

        $stmt->execute([
            ':race_code' => $raceCode
        ]);

        $raceMaster = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$raceMaster) {
            throw new RuntimeException(
                "race_masterに該当レースがありません: {$raceCode}"
            );
        }


        // ----------------------------------------------------
        // race_result_detail
        // ----------------------------------------------------

        $stmt = $this->pdo->prepare(
            <<<SQL
            SELECT
                rank,
                lane_number,
                player_id,
                player_name,
                motor_number,
                boat_number,
                exhibition_time,
                entry_course,
                start_timing,
                goal_time,
                technique
            FROM boat_race.race_result_detail
            WHERE race_code = :race_code
            ORDER BY lane_number
            SQL
        );

        $stmt->execute([
            ':race_code' => $raceCode
        ]);

        $resultDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);


        if (!$resultDetails) {
            throw new RuntimeException(
                "race_result_detailに結果がありません: {$raceCode}"
            );
        }


        // ----------------------------------------------------
        // 実結果を艇番別に整理
        // ----------------------------------------------------

        $actualByLane = [];

        foreach ($resultDetails as $row) {

            $lane = (int)$row['lane_number'];

            if ($lane < 1 || $lane > 6) {
                continue;
            }

            $actualByLane[$lane] = [
                'rank' =>
                    ($row['rank'] !== null &&
                     $row['rank'] !== '')
                        ? (int)$row['rank']
                        : null,

                'player_id' =>
                    $row['player_id'] ?? '',

                'player_name' =>
                    $row['player_name'] ?? '',
            ];
        }


        // ----------------------------------------------------
        // 実着順 → 艇番
        // ----------------------------------------------------

        $actualByRank = [];

        foreach ($actualByLane as $lane => $data) {

            $rank = $data['rank'];

            if (
                $rank !== null &&
                $rank >= 1 &&
                $rank <= 6
            ) {
                $actualByRank[$rank] = $lane;
            }
        }

        $actual1st = $actualByRank[1] ?? '';
        $actual2nd = $actualByRank[2] ?? '';
        $actual3rd = $actualByRank[3] ?? '';

        $actualTrifecta = '';

        if (
            $actual1st !== '' &&
            $actual2nd !== '' &&
            $actual3rd !== ''
        ) {
            $actualTrifecta =
                "{$actual1st}-{$actual2nd}-{$actual3rd}";
        }


        // ----------------------------------------------------
        // 一次評価
        // ----------------------------------------------------

        [$entries, $results, $calcError] =
            $this->apiClient->fetchCalcScores($raceCode);

        if ($calcError !== '') {
            throw new RuntimeException(
                "calc_scoresエラー: {$calcError}"
            );
        }

        if (
            !is_array($results) ||
            count($results) < 6
        ) {
            throw new RuntimeException(
                "一次評価が6艇分取得できません"
            );
        }


        // ----------------------------------------------------
        // 場コード
        // ----------------------------------------------------

        $placeCode = substr($raceCode, 8, 3);


        // ----------------------------------------------------
        // 決まり手
        // ----------------------------------------------------

        [$kimariteData, $kimariteError] =
            $this->apiClient->fetchKimarite(
                $raceCode,
                '123456'
            );

        if ($kimariteError !== '') {
            $kimariteData = [];
        }


        // ----------------------------------------------------
        // 展示情報
        // ----------------------------------------------------

        [$tenjiList, $tenjiError] =
            $this->apiClient->fetchTenji(
                $raceCode,
                $results,
                $placeCode
            );

        if (
            !is_array($tenjiList) ||
            count($tenjiList) < 6
        ) {
            throw new RuntimeException(
                "展示情報が6艇分取得できません"
            );
        }


        // ----------------------------------------------------
        // 3連対率
        // ----------------------------------------------------

        $tenjiTestData =
            $this->apiClient->fetchTenjiTest(
                $raceCode,
                $tenjiList
            );

        if (!is_array($tenjiTestData)) {
            $tenjiTestData = [];
        }


        // ----------------------------------------------------
        // 最終予想
        // ----------------------------------------------------

        $finalPredictions =
            $this->predictionLogic->buildFinalPredictions(
                $tenjiList,
                $kimariteData,
                $tenjiTestData,
                $results
            );

        $summary =
            $this->predictionLogic->buildSummary(
                $finalPredictions
            );


        // ----------------------------------------------------
        // 艇別データ
        // ----------------------------------------------------

        $boatRows = [];

        for ($lane = 1; $lane <= 6; $lane++) {

            $result =
                $results[$lane - 1] ?? [];

            $entry =
                $entries[$lane - 1] ?? [];

            $tenji =
                $tenjiList[$lane - 1] ?? [];

            $test =
                $tenjiTestData[$lane - 1] ?? [];

            $final =
                $finalPredictions[$lane] ?? [];

            $actual =
                $actualByLane[$lane] ?? [];


            $boatRows[$lane] = [

                'lane_number' =>
                    $lane,

                /*
                 * 選手名はentriesを正とする。
                 * race_result_detailに5着・6着が
                 * 存在しないレースにも対応。
                 */
                'player_id' =>
                    $actual['player_id'] ?? '',

                'player_name' =>
                    $entry['player_name']
                    ?? '',


                // 一次評価
                'first_total_score' =>
                    $result['total_score'] ?? 0,

                'first_type' =>
                    $result['type'] ?? '',

                'first_eval' =>
                    $result['ichiji_eval'] ?? '',


                // 3連対率
                'three_in_rate_6m' =>
                    $final['rate6_dec']
                    ?? ($test['three_in_rate_6m'] ?? 0),

                'three_in_rate_3m' =>
                    $final['rate3_dec']
                    ?? ($test['three_in_rate_3m'] ?? 0),


                // 二次評価
                'second_score' =>
                    $tenji['final_2nd_score'] ?? 0,


                // 最終評価
                'kitai' =>
                    $final['kitai_dec'] ?? 0,

                'final_type' =>
                    $final['type'] ?? '',

                'type_bonus' =>
                    $final['typeBonus'] ?? 0,

                'final3' =>
                    $final['final3'] ?? 0,

                'get_bonus' =>
                    $final['getBonus'] ?? 0,

                'kiru' =>
                    $final['kiru'] ?? 0,


                // 実結果
                'actual_rank' =>
                    $actual['rank'] ?? '',
            ];
        }


        // ----------------------------------------------------
        // 順位
        // ----------------------------------------------------

        $firstRank =
            $this->makeRankMap(
                $boatRows,
                'first_total_score'
            );

        $secondRank =
            $this->makeRankMap(
                $boatRows,
                'second_score'
            );

        /*
        * STEP 4適用後の最終順位
        *
        * buildSummary() の rank_boats は、
        * 一次差5～10 × 二次差1～2 の昇格ルールを
        * 適用した後の実際の最終順位。
        */
        $finalRank = [];

        $effectiveRankBoats =
            $summary['rank_boats'] ?? [];

        foreach ($effectiveRankBoats as $index => $lane) {

            $finalRank[(int)$lane] =
                $index + 1;
        }

        /*
        * 念のためrank_boatsが取得できなかった場合は
        * 従来のfinal3順位へフォールバック
        */
        if (count($finalRank) < 6) {

            $finalRank =
                $this->makeRankMap(
                    $boatRows,
                    'final3'
                );
        }


        // ----------------------------------------------------
        // 結果を返す
        // ----------------------------------------------------

        return [
            'race_master' =>
                $raceMaster,

            'boats' =>
                $boatRows,

            'ranks' => [
                'first' =>
                    $firstRank,

                'second' =>
                    $secondRank,

                'final' =>
                    $finalRank,
            ],

            'summary' =>
                $summary,

            'actual' => [
                'first' =>
                    $actual1st,

                'second' =>
                    $actual2nd,

                'third' =>
                    $actual3rd,

                'trifecta' =>
                    $actualTrifecta,
            ],
        ];
    }


    /**
     * スコアから順位を作成
     */
    private function makeRankMap(
        array $rows,
        string $scoreKey
    ): array {

        $scores = [];

        foreach ($rows as $lane => $row) {

            $scores[$lane] =
                (float)($row[$scoreKey] ?? 0);
        }


        uksort(
            $scores,
            static function (
                string $laneA,
                string $laneB
            ) use ($scores): int {

                $scoreA = $scores[$laneA];
                $scoreB = $scores[$laneB];

                if ($scoreA == $scoreB) {
                    return (int)$laneA
                        <=> (int)$laneB;
                }

                return $scoreA < $scoreB
                    ? 1
                    : -1;
            }
        );


        $rankMap = [];
        $rank = 1;

        foreach ($scores as $lane => $score) {

            $rankMap[(int)$lane] =
                $rank;

            $rank++;
        }

        return $rankMap;
    }
}