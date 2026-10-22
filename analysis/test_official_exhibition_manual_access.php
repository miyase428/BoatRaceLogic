<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/OfficialExhibitionManualAccess.php';

function accessAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$cases = 0;
$server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_TAILSCALE_USER_LOGIN' => 'operator@example.com'];
accessAssert(OfficialExhibitionManualAccess::authorize($server, '')['reason'] === 'MANUAL_OFFICIAL_ALLOWED_USERS_NOT_CONFIGURED', 'missing allow-list fails closed'); $cases++;
accessAssert(OfficialExhibitionManualAccess::authorize($server, 'other@example.com')['reason'] === 'MANUAL_OFFICIAL_USER_NOT_ALLOWED', 'unlisted identity is rejected'); $cases++;
accessAssert(OfficialExhibitionManualAccess::authorize($server, ' operator@example.com ')['allowed'], 'listed Tailscale identity is accepted'); $cases++;
accessAssert(OfficialExhibitionManualAccess::authorize($server + [OfficialExhibitionManualAccess::ALLOWED_USERS_ENV => 'operator@example.com'])['allowed'], 'Apache SetEnv allow-list is accepted'); $cases++;
accessAssert(OfficialExhibitionManualAccess::authorize(['REMOTE_ADDR' => '192.168.0.12', 'HTTP_TAILSCALE_USER_LOGIN' => 'operator@example.com'], 'operator@example.com')['reason'] === 'MANUAL_OFFICIAL_REQUIRES_TAILSCALE_SERVE', 'direct LAN header spoof is rejected'); $cases++;
accessAssert(OfficialExhibitionManualAccess::authorize(['REMOTE_ADDR' => '127.0.0.1'], 'operator@example.com')['reason'] === 'MANUAL_OFFICIAL_IDENTITY_MISSING', 'loopback without trusted identity is rejected'); $cases++;
accessAssert(OfficialExhibitionManualAccess::isLoopback('::1'), 'IPv6 loopback is accepted for internal endpoint'); $cases++;
$legacyEndpoint = (string)file_get_contents(__DIR__ . '/../public/update_exhibition.php');
$manualEndpoint = (string)file_get_contents(__DIR__ . '/../public/update_official_exhibition_manual.php');
accessAssert(str_contains($legacyEndpoint, 'LEGACY_EXHIBITION_UPDATE_INTERNAL_ONLY') && str_contains($legacyEndpoint, "REQUEST_METHOD'] !== 'POST'"), 'legacy update endpoint is POST and localhost only'); $cases++;
accessAssert(str_contains($manualEndpoint, 'MANUAL_OFFICIAL_INTERNAL_ENDPOINT_ONLY'), 'manual child endpoint is localhost only'); $cases++;

echo "PASS {$cases} manual access cases\n";
