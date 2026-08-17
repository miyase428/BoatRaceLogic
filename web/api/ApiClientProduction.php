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
}
