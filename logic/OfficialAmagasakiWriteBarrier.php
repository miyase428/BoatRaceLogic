<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialAmagasakiLegacyWriteGuard.php';

/**
 * A transaction-scoped per-race PostgreSQL advisory lock shared by the
 * official saver and every legacy AMG writer. It closes the race between a
 * legacy fetch and its later save without changing non-AMG transactions.
 */
final class OfficialAmagasakiWriteBarrier
{
    public static function isTarget(string $raceCode): bool
    {
        return preg_match('/^\d{8}AMG(0[1-9]|1[0-2])$/', $raceCode) === 1
            && substr($raceCode, 0, 8) >= OfficialExhibitionSourceRouter::OFFICIAL_START_DATE;
    }

    public static function lock(PDO $pdo, string $raceCode): void
    {
        $statement = $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(CAST(:race_code AS text)))');
        $statement->execute([':race_code' => $raceCode]);
    }
}

/** @return bool True when this call opened a transaction that must be completed. */
function officialAmagasakiBeginLegacyWrite(PDO $pdo, string $raceCode, string $entry): bool
{
    if (!OfficialAmagasakiWriteBarrier::isTarget($raceCode)) return false;
    if ($pdo->inTransaction()) throw new RuntimeException('legacy AMG write requires its own transaction boundary');
    $pdo->beginTransaction();
    try {
        OfficialAmagasakiWriteBarrier::lock($pdo, $raceCode);
        $decision = officialAmagasakiLegacyWriteDecision($pdo, $raceCode, $entry);
        if (!$decision['allow_legacy']) throw new RuntimeException('OFFICIAL_AMAGASAKI_LEGACY_WRITE_BLOCKED:' . $decision['reason']);
        return true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function officialAmagasakiFinishLegacyWrite(PDO $pdo, bool $openedTransaction, bool $success): void
{
    if (!$openedTransaction) return;
    if ($success) $pdo->commit();
    elseif ($pdo->inTransaction()) $pdo->rollBack();
}
