<?php

class CorrectedWinRateLogic
{
    public function calculate(string $raceCode): array
    {
        $script = realpath(__DIR__ . '/../../forecast/corrected_winrate_live.py');
        if ($script === false) {
            return [
                'status' => 'error',
                'boats' => [],
                'error' => '補正後1着率スクリプトが見つかりません',
            ];
        }

        $python = '/usr/bin/python3';
        $cmd = $python . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($raceCode) . ' 2>&1';
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
