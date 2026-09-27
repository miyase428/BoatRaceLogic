<?php
declare(strict_types=1);

/** 検証済みの場別コースサイン v1 をPythonから取得する。 */
final class TamagawaCenterSignalLogic
{
    public function calculate(string $date, bool $baseOnly = false, string $place = 'TMG'): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['status' => 'error', 'error' => 'invalid_date'];
        }
        $place = strtoupper(trim($place));
        if (!in_array($place, ['TMG', 'TDA'], true)) {
            return ['status' => 'error', 'error' => 'unsupported_place'];
        }
        $root = realpath(__DIR__ . '/../..');
        if ($root === false) {
            return ['status' => 'error', 'error' => 'root_not_found'];
        }
        $python = $root . '/.venv-models/bin/python';
        $script = realpath($root . '/forecast/tamagawa_center_signal_live_v1.py');
        if ($script === false || !is_executable($python)) {
            return ['status' => 'error', 'error' => 'runtime_not_found'];
        }
        $command = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($date)
            . ' --place ' . escapeshellarg($place);
        if ($baseOnly) {
            $command .= ' --base';
        }
        $raw = shell_exec($command);
        if (!is_string($raw) || trim($raw) === '') {
            return ['status' => 'error', 'error' => 'empty_result'];
        }
        $decoded = json_decode(trim($raw), true);
        return is_array($decoded) ? $decoded : ['status' => 'error', 'error' => 'invalid_json'];
    }
}
