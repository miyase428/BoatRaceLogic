<?php

declare(strict_types=1);

/** Authorization boundary for the browser-only official exhibition action. */
final class OfficialExhibitionManualAccess
{
    public const ALLOWED_USERS_ENV = 'BOATRACE_OFFICIAL_MANUAL_TAILSCALE_USERS';

    /** @return array{allowed:bool,reason:string,user:string} */
    public static function authorize(array $server, ?string $configuredUsers = null): array
    {
        $remote = trim((string)($server['REMOTE_ADDR'] ?? ''));
        if (!self::isLoopback($remote)) {
            return ['allowed' => false, 'reason' => 'MANUAL_OFFICIAL_REQUIRES_TAILSCALE_SERVE', 'user' => ''];
        }

        $user = trim((string)($server['HTTP_TAILSCALE_USER_LOGIN'] ?? ''));
        if ($user === '') {
            return ['allowed' => false, 'reason' => 'MANUAL_OFFICIAL_IDENTITY_MISSING', 'user' => ''];
        }

        $raw = $configuredUsers
            ?? ($server[self::ALLOWED_USERS_ENV] ?? getenv(self::ALLOWED_USERS_ENV));
        $allowedUsers = array_values(array_unique(array_filter(array_map(
            static fn(string $value): string => strtolower(trim($value)),
            explode(',', is_string($raw) ? $raw : '')
        ), static fn(string $value): bool => $value !== '')));
        if ($allowedUsers === []) {
            return ['allowed' => false, 'reason' => 'MANUAL_OFFICIAL_ALLOWED_USERS_NOT_CONFIGURED', 'user' => $user];
        }
        if (!in_array(strtolower($user), $allowedUsers, true)) {
            return ['allowed' => false, 'reason' => 'MANUAL_OFFICIAL_USER_NOT_ALLOWED', 'user' => $user];
        }
        return ['allowed' => true, 'reason' => '', 'user' => $user];
    }

    public static function isLoopback(string $address): bool
    {
        return $address === '127.0.0.1' || $address === '::1';
    }
}
