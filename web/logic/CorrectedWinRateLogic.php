<?php

class CorrectedWinRateLogic
{
    public function calculate(string $raceCode): array
    {
        // 通常22場はSTEP8-4完全一致のexact版を維持する。
        // AMG/TKYはstraight_timeが実質存在しないため、2期間検証済みの
        // EX_TOTAL3(展示+周回+周り足)専用exact版を使用する。
        $placeCode = substr($raceCode, 8, 3);
        $scriptName = in_array($placeCode, ['AMG', 'TKY'], true)
            ? 'corrected_winrate_live_exact_amg_tky.py'
            : 'corrected_winrate_live_exact.py';

        $script = realpath(__DIR__ . '/../../forecast/' . $scriptName);
        if ($script === false) {
            return [
                'status' => 'error',
                'boats' => [],
                'error' => '補正後1着率スクリプトが見つかりません',
            ];
        }

        $python = '/usr/bin/python3';
        $cmd = $python . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($raceCode);
        $raw = shell_exec($cmd);

        if ($raw === null || trim($raw) === '') {
            return [
                'status' => 'error',
                'boats' => [],
                'error' => '補正後1着率の計算結果を取得できませんでした',
            ];
        }

        $data = json_decode(trim($raw), true);
        if (!is_array($data)) {
            return [
                'status' => 'error',
                'boats' => [],
                'error' => '補正後1着率JSONの解析に失敗しました',
            ];
        }

        if (($data['status'] ?? '') !== 'ok') {
            return [
                'status' => 'error',
                'boats' => [],
                'error' => (string)($data['error'] ?? '補正後1着率の計算に失敗しました'),
            ];
        }

        return $data;
    }
}
