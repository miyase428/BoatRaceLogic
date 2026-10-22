<?php

declare(strict_types=1);
ini_set('session.save_path', sys_get_temp_dir());
require_once __DIR__ . '/../web/logic/OfficialExhibitionManualRequest.php';

function manualRequestAssert(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$cases = 0;
$_SESSION = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$token = OfficialExhibitionManualRequest::token();
manualRequestAssert(preg_match('/^[a-f0-9]{64}$/', $token) === 1, 'token is a 256-bit hex value'); $cases++;
manualRequestAssert(!OfficialExhibitionManualRequest::validatePost($token), 'GET cannot invoke manual official endpoint'); $cases++;
$_SERVER['REQUEST_METHOD'] = 'POST';
manualRequestAssert(!OfficialExhibitionManualRequest::validatePost(str_repeat('0', 64)), 'incorrect token is rejected'); $cases++;
manualRequestAssert(OfficialExhibitionManualRequest::validatePost($token), 'same-session POST token is accepted'); $cases++;
OfficialExhibitionManualRequest::closeForInternalRequest();
manualRequestAssert(session_status() !== PHP_SESSION_ACTIVE, 'outer request releases the session lock before internal HTTP'); $cases++;
echo "PASS {$cases} manual request CSRF cases\n";
