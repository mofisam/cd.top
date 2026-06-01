<?php

function isSecureRequest(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $forwardedProto = strtolower(trim(explode(',', (string) $forwardedProto)[0]));

    return $forwardedProto === 'https';
}

function setAuthSessionCookie(string $sessionToken, int $ttlSeconds = 604800): void {
    setcookie('session_token', $sessionToken, [
        'expires' => time() + $ttlSeconds,
        'path' => '/',
        'secure' => isSecureRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clearAuthSessionCookie(): void {
    setcookie('session_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => isSecureRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
