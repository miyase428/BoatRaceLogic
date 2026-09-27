<?php

/**
 * 学習済みの展示後AI1着率 v5 をPythonから呼び出す本番ブリッジ。
 * 表示・本命頭・3連単確率で同じ推論結果を共用する。
 */
class AiWinRateLogic
{
    public function calculate(string $raceCode, ?string $virtualLaneToCourse = null): array
    {
        if ($virtualLaneToCourse !== null && !preg_match('/^[1-6]{6}$/', $virtualLaneToCourse)) {
            return $this->error('AI1着率：仮想進入の形式が不正です');
        }

        $repoRoot = realpath(__DIR__ . '/../..');
        $v5Script = $repoRoot !== false ? realpath($repoRoot . '/forecast/ai_winrate_live_v5.py') : false;
        $v4Script = $repoRoot !== false ? realpath($repoRoot . '/forecast/ai_winrate_live_v4.py') : false;
        $v2Script = $repoRoot !== false ? realpath($repoRoot . '/forecast/ai_winrate_live_v2.py') : false;
        // venvのpythonはシンボリックリンクなので、realpathで実体へ解決すると
        // 仮想環境が無効になる。呼び出しパスはそのまま保持する。
        $modelPython = $repoRoot !== false ? $repoRoot . '/.venv-models/bin/python' : false;
        $useV5 = $v5Script !== false && $modelPython !== false && is_executable($modelPython);
        $useV4 = !$useV5 && $v4Script !== false && $modelPython !== false && is_executable($modelPython);
        $useV2 = !$useV5 && !$useV4 && $v2Script !== false && $modelPython !== false && is_executable($modelPython);
        $script = $useV5
            ? $v5Script
            : ($useV4
                ? $v4Script
                : ($useV2 ? $v2Script : realpath(__DIR__ . '/../../forecast/ai_winrate_live.py')));
        $python = ($useV5 || $useV4 || $useV2) ? $modelPython : '/usr/bin/python3';
        if ($script === false) {
            return $this->error('AI1着率スクリプトが見つかりません');
        }

        $cmd = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($raceCode);
        if ($virtualLaneToCourse !== null) {
            $cmd .= ' ' . escapeshellarg($virtualLaneToCourse);
        }

        $raw = shell_exec($cmd);
        if ($raw === null || trim($raw) === '') {
            return $this->error('AI1着率の計算結果を取得できませんでした');
        }

        $data = json_decode(trim($raw), true);
        if (!is_array($data)) {
            return $this->error('AI1着率JSONの解析に失敗しました');
        }

        if (($data['status'] ?? '') !== 'ok') {
            return [
                'status' => (string)($data['status'] ?? 'error'),
                'boats' => [],
                'error' => (string)($data['error'] ?? 'AI1着率の計算に失敗しました'),
            ];
        }

        return $data;
    }

    private function error(string $message): array
    {
        return ['status' => 'error', 'boats' => [], 'error' => $message];
    }
}
