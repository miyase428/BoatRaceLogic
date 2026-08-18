<?php

class CorrectedWinRateLogic
{
    public function calculate(string $raceCode, ?string $virtualLaneToCourse = null): array
    {
        if ($virtualLaneToCourse !== null) {
            if (!preg_match('/^[1-6]{6}$/', $virtualLaneToCourse)) {
                return [
                    'status' => 'error',
                    'boats' => [],
                    'error' => '仮想進入の形式が不正です',
                ];
            }

            $digits = str_split($virtualLaneToCourse);
            sort($digits);
            if ($digits !== ['1', '2', '3', '4', '5', '6']) {
                return [
                    'status' => 'error',
                    'boats' => [],
                    'error' => '仮想進入は1～6を1回ずつ指定してください',
                ];
            }
        }

        // 既存のexact / AMG-TKY / 仮想進入チェーンは専用Pythonラッパー側で選択する。
        // その最終 corrected_rate にだけ、2期間ホールドアウト検証済みの
        // RAW_TEMP後段較正を適用する。既存チェーン本体は変更しない。
        $scriptName = 'corrected_winrate_live_calibrated.py';
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
        if ($virtualLaneToCourse !== null) {
            $cmd .= ' ' . escapeshellarg($virtualLaneToCourse);
        }

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
