<?php

require_once __DIR__ . '/ApiClient.php';

/**
 * 本番用ApiClientラッパー。
 *
 * 旧tenkai_moraiの固定+1はスコアから完全に除去する。
 * 2・4号艇の切り保護は kiru_protect_24 として別フラグで保持する。
 */
class ApiClientProduction extends ApiClient
{
    public function fetchTenji(string $race_code, array $results, string $selected_place): array
    {
        [$tenji_list, $tenji_error] = parent::fetchTenji(
            $race_code,
            $results,
            $selected_place
        );

        foreach ($tenji_list as &$t) {
            $boat = (int)($t['teiban'] ?? 0);

            // 固定+1は廃止。画面上の旧「展開もらい補正」も全艇0にする。
            $t['tenkai_morai'] = 0;

            // 2・4号艇の切らない保護だけは、独立フラグとして維持する。
            $t['kiru_protect_24'] = ($boat === 2 || $boat === 4) ? 1 : 0;

            // 二次スコアには展開もらい点を一切加算しない。
            $t['final_2nd_score'] =
                (float)($t['ex_sougou'] ?? 0)
                + (float)($t['type_hosei'] ?? 0);
        }
        unset($t);

        return [$tenji_list, $tenji_error];
    }

    /**
     * tenji_test.php は tenji1..6 を「1～6コースにいる艇番」として受け取る。
     *
     * 親ApiClientは旧仕様として「艇番ごとの展示コース」をそのまま渡していたため、
     * 進入変更時に逆写像が必要になる。本番ラッパーで course -> boat へ変換してから
     * 親メソッドへ渡し、通常進入123456では従来と完全に同じ引数になるようにする。
     */
    public function fetchTenjiTest(string $race_code, array $tenji_list): array
    {
        $courseToBoat = [];
        $seenBoats = [];

        foreach ($tenji_list as $idx => $t) {
            $boat = (int)($t['teiban'] ?? ($idx + 1));
            $course = (int)($t['tenji_course'] ?? 0);

            if (
                $boat < 1 || $boat > 6
                || $course < 1 || $course > 6
                || isset($seenBoats[$boat])
                || isset($courseToBoat[$course])
            ) {
                // 展示進入がまだ完全でない場合は旧経路へフォールバック。
                return parent::fetchTenjiTest($race_code, $tenji_list);
            }

            $seenBoats[$boat] = true;
            $courseToBoat[$course] = $boat;
        }

        if (count($courseToBoat) !== 6 || count($seenBoats) !== 6) {
            return parent::fetchTenjiTest($race_code, $tenji_list);
        }

        $proxy = [];
        for ($course = 1; $course <= 6; $course++) {
            if (!isset($courseToBoat[$course])) {
                return parent::fetchTenjiTest($race_code, $tenji_list);
            }

            // 親メソッドは tenji_course を tenji1..6 の値として送るため、
            // ここでは「そのコースにいる艇番」を意図的に格納する。
            $proxy[] = [
                'teiban' => $courseToBoat[$course],
                'tenji_course' => $courseToBoat[$course],
            ];
        }

        return parent::fetchTenjiTest($race_code, $proxy);
    }
}
