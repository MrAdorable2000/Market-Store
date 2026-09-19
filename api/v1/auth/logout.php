<?php
/** api/v1/auth/logout.php — POST /api/v1/auth/logout */
require_once __DIR__ . '/../../../includes/auth.php';

// Self-contained json_response() - see api/v1/auth/login.php (this same
// audit) for the identical fix and full reasoning. Not currently called
// by any frontend code (the real logout flow goes through
// pages/logout.php), but would crash unconditionally on every request
// without this.
if (!function_exists('json_response')) {
    function json_response($data, int $code = 200): void {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

logout_user();
json_response(['message' => 'Logged out']);
