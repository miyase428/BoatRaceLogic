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

    /**
     * 仮想進入用スリット体系。
     *
     * laneToCourse は「艇番 -> コース」の6桁。
     * 展示ST自体はDBの実測値を使い、選手のコース配置だけを仮想進入へ差し替えて
     * C_ST_RANK / pattern_id を再計算する。
     */
    public function fetchSlitVirtual(string $race_code, string $laneToCourse): array
    {
        $emptyPattern = ['id' => '-', 'name' => '不明', 'desc' => ''];

        if (!preg_match('/^[0-9A-Z]+$/', $race_code)) {
            return [[], $emptyPattern];
        }

        $digits = str_split($laneToCourse);
        sort($digits);
        if (strlen($laneToCourse) !== 6 || $digits !== ['1', '2', '3', '4', '5', '6']) {
            return [[], $emptyPattern];
        }

        $base = realpath(__DIR__ . '/../../theories/course_correction');
        if ($base === false) {
            return [[], $emptyPattern];
        }

        $script = $base . '/predict_pattern_virtual.py';
        $buffPath = $base . '/buff_debuff_slit.json';
        if (!is_file($script) || !is_file($buffPath)) {
            return [[], $emptyPattern];
        }

        $cmd = '/usr/bin/python3 '
            . escapeshellarg($script) . ' '
            . escapeshellarg($race_code) . ' '
            . escapeshellarg($laneToCourse)
            . ' 2>&1';

        $raw = shell_exec($cmd);
        $predict = is_string($raw) ? json_decode(trim($raw), true) : null;
        if (!is_array($predict) || !empty($predict['error'])) {
            return [[], $emptyPattern];
        }

        $pid = (int)($predict['pattern_id'] ?? 0);
        if ($pid < 1 || $pid > 12) {
            return [[], $emptyPattern];
        }

        $buffAll = json_decode((string)file_get_contents($buffPath), true);
        $slitData = is_array($buffAll) ? ($buffAll[(string)$pid] ?? []) : [];

        $master = [
            1  => ['name' => '内側先行',     'desc' => '1〜3が速い。最も広い条件。最後に判定。'],
            2  => ['name' => '横一線',       'desc' => '最も広い条件。後ろで判定。'],
            3  => ['name' => '1・2先行',     'desc' => '1と2が速い。1（内側先行）より条件が狭い。'],
            4  => ['name' => 'スロー先行',   'desc' => '1〜3が先行。3（1・2先行）と重複するため後。'],
            5  => ['name' => 'カベなし',     'desc' => '2が遅れる。個別艇の遅れとして6・7より後。'],
            6  => ['name' => '2・3遅れ',     'desc' => 'センター凹みの別パターン。7より優先度低い。'],
            7  => ['name' => '中凹み',       'desc' => '3・4が遅れる。センター凹みとして特徴が強い。'],
            8  => ['name' => '3の先攻め',    'desc' => '3が突出。個別艇の特徴として9より優先。'],
            9  => ['name' => '中ぶくれ',     'desc' => '3・4が先行。8と重複するため後に判定。'],
            10 => ['name' => '1が遅れる',    'desc' => 'イン遅れは展開に大きく影響。優先度高い。'],
            11 => ['name' => '外側先行',     'desc' => '456が上位。12と重複しやすいので次に判定。'],
            12 => ['name' => 'ダッシュ先行', 'desc' => '456が圧倒的に速い。外側先行の上位互換。最優先。'],
        ];

        $pattern = [
            'id' => $pid,
            'name' => $master[$pid]['name'] ?? '不明',
            'desc' => $master[$pid]['desc'] ?? '',
            'features' => $predict['features'] ?? [],
            'predict_detail' => $predict,
        ];

        return [$slitData, $pattern];
    }

    /**
     * 展示更新は同一Ubuntuサーバ内の処理なので、LAN IPではなくlocalhost経由で呼ぶ。
     * Playwright側のページ待機より短い30秒タイムアウトで先に諦めないよう90秒まで待つ。
     * update_exhibition.php が返すJSONの成功/失敗内容を画面メッセージへ反映する。
     */
    public function updateExhibition(string $race_code): array
    {
        if ($race_code === '') {
            return ['展示情報の更新に失敗しました: race_codeがありません。', 'race_code is empty'];
        }

        $targetUrl = 'http://127.0.0.1:80/update_exhibition.php';
        $ch = curl_init($targetUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'race_code' => $race_code,
        ]));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);

        $response = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrno !== 0) {
            $debug = "cURL error {$curlErrno}: {$curlError}";
            return [
                "展示情報の更新に失敗しました: {$curlError}",
                $debug,
            ];
        }

        $json = is_string($response) ? json_decode($response, true) : null;
        $debug = "HTTP STATUS: {$httpCode}\nRACE_CODE: {$race_code}\nRAW RESPONSE: " . (string)$response;

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = is_array($json) && !empty($json['message'])
                ? (string)$json['message']
                : "HTTP {$httpCode}";
            return ["展示情報の更新に失敗しました: {$message}", $debug];
        }

        if (!is_array($json)) {
            return ['展示情報の更新に失敗しました: 更新APIから正しい応答がありません。', $debug];
        }

        if (($json['success'] ?? false) !== true) {
            $message = (string)($json['message'] ?? '原因不明のエラー');
            return ["展示情報の更新に失敗しました: {$message}", $debug];
        }

        return [
            (string)($json['message'] ?? '展示情報を更新しました。'),
            $debug,
        ];
    }
}
