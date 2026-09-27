<?php

declare(strict_types=1);

/** 学習済みAI展開予想 v1のPython推論ブリッジ。 */
final class AiTenkaiRateLogic
{
    public function calculate(
        string $raceCode,
        array $aiWinBoats,
        array $kimariteData,
        array $courseByBoat
    ): array {
        $root = realpath(__DIR__ . '/../..');
        if ($root === false) {
            return $this->error('AI展開予想: ルートを取得できません');
        }
        $python = $root . '/.venv-models/bin/python';
        $script = realpath($root . '/forecast/ai_tenkai_live_v1.py');
        if (!is_executable($python) || $script === false) {
            return $this->error('AI展開予想スクリプトを実行できません');
        }
        $payload = json_encode([
            'race_code' => $raceCode,
            'ai_win_boats' => $aiWinBoats,
            'kimarite_data' => $kimariteData,
            'course_by_boat' => $courseByBoat,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            return $this->error('AI展開予想の入力JSONを作成できません');
        }
        $process = proc_open(
            [$python, $script],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root
        );
        if (!is_resource($process)) {
            return $this->error('AI展開予想プロセスを開始できません');
        }
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $data = json_decode(trim((string)$stdout), true);
        if (!is_array($data)) {
            return $this->error(
                'AI展開予想JSONの解析に失敗しました'
                . ($stderr !== '' ? ': ' . trim($stderr) : '')
            );
        }
        return $data;
    }

    private function error(string $message): array
    {
        return ['status' => 'error', 'events' => [], 'top5' => [], 'error' => $message];
    }
}
