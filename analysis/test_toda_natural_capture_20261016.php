<?php
declare(strict_types=1);
require_once __DIR__ . '/fetch_toda_exhibition_20261016.php';
function tdaTest(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
tdaTest(tda1016Action(7*60,false,0) === ['action'=>'FETCH','reason'=>'FIRST_FETCH_WINDOW'], 'first_window');
tdaTest(tda1016Action(4*60,false,1) === ['action'=>'FETCH','reason'=>'RETRY_FETCH_WINDOW'], 'retry_window');
tdaTest(tda1016Action(2*60,false,1)['reason'] === 'SAFE_CUTOFF_REACHED', 'safe_cutoff');
tdaTest(tda1016Action(7*60,false,2)['reason'] === 'RETRY_LIMIT_REACHED', 'retry_limit');
tdaTest(tda1016TargetRace('20261016TDA12') && !tda1016TargetRace('20261016TMG12'), 'venue_guard');
$outside=tda1016Arguments(['test','--dry-run','--now=2026-10-17T08:00:00+09:00']);
tdaTest($outside['now']->format('Y-m-d') !== TDA_1016_DATE, 'date_guard');
echo "PASS\n";
