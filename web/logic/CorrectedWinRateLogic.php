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

        return $this->runScript(
            'corrected_winrate_live_calibrated.py',
            [$raceCode, ...($virtualLaneToCourse !== null ? [$virtualLaneToCourse] : [])]
        );
    }

    /**
     * 展示5指標が全NULLの欠場艇を1艇だけ除外した、実質5艇立て専用。
     * 通常6艇の検証済みチェーンは変更せず、専用Pythonへ分離する。
     */
    public function calculateEffective(string $raceCode, array $activeBoats): array
    {
        $active = array_values(array_unique(array_map('intval', $activeBoats)));
        sort($active, SORT_NUMERIC);
        if (count($active) !== 5) {
            return [
                'status' => 'error',
                'boats' => [],
                'error' => '実質5艇立ては有効艇5艇が必要です',
            ];
        }
        foreach ($active as $boat) {
            if ($boat < 1 || $boat > 6) {
                return [
                    'status' => 'error',
                    'boats' => [],
                    'error' => '有効艇番が不正です',
                ];
            }
        }

        return $this->runScript(
            'corrected_winrate_live_effective.py',
            [$raceCode, implode(',', $active)]
        );
    }

    private function runScript(string $scriptName, array $args): array
    {
        $script = realpath(__DIR__ . '/../../forecast/' . $scriptName);
        if ($script === false) {
            return [
                'status' => 'error',
                'boats' => [],
                'error' => '補正後1着率スクリプトが見つかりません',
            ];
        }

        $python = '/usr/bin/python3';
        $cmd = $python . ' ' . escapeshellarg($script);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg((string)$arg);
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
