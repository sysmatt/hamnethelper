<?php
/**
 * Shared bootstrap for every api/*.php endpoint.
 *
 * Page loads (index.php, net.php) require simplewebauth/auth.php directly, which redirects to
 * the login page on a missing/expired session -- correct for a browser navigation. API
 * endpoints are called via fetch() from JS, where a redirect just returns login-page HTML with
 * a 200 status that the caller can't parse as JSON. So this checks the same session
 * simplewebauth set (same cookie name / lifetime / gc_maxlifetime as simplewebauth/auth.php)
 * but responds with a 401 JSON body on failure instead of redirecting.
 *
 * NOTE: this intentionally duplicates the session-validation logic in simplewebauth/auth.php
 * and auth_check.php rather than modifying that shared repo (it's reused by other tools too).
 * If simplewebauth's session mechanics ever change, this needs to change with it.
 */

define('AUTH_SESSION_LIFETIME', 28800);
define('AUTH_SESSION_NAME', 'phpauth');

ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', (string) AUTH_SESSION_LIFETIME);

if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}

session_name(AUTH_SESSION_NAME);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

function hnh_json(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function hnh_error(string $message, int $status = 400): never
{
    hnh_json(['error' => $message], $status);
}

$authenticated = isset($_SESSION['auth_user'], $_SESSION['auth_time'])
    && (time() - $_SESSION['auth_time']) < AUTH_SESSION_LIFETIME;

if (!$authenticated) {
    hnh_error('Not authenticated', 401);
}

$_SESSION['auth_time'] = time(); // slide the expiry, same as simplewebauth/auth.php

function hnh_user(): string
{
    return $_SESSION['auth_user'] ?? '';
}

require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/net_store.php';

try {
    $hnh_config = hnh_config();
} catch (RuntimeException $e) {
    hnh_error($e->getMessage(), 500);
}

function hnh_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        hnh_error('Invalid JSON body', 400);
    }
    return $data;
}

/** Validates a net id (path-traversal guard) and returns it unchanged. */
function hnh_valid_net_id(string $id): string
{
    if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id)) {
        hnh_error('Invalid net id', 400);
    }
    return $id;
}
