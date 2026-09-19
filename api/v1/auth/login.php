<?php
/** api/v1/auth/login.php — POST /api/v1/auth/login */
require_once __DIR__ . '/../../../includes/auth.php';

// Self-contained json_response()/json_input() - see api/v1/categories/index.php,
// api/v1/listings/index.php, api/v1/favorites/index.php, and
// api/v1/rentals/index.php earlier in this audit for the identical fix and
// full reasoning. Not currently called by any frontend code (the real
// login flow goes through pages/login.php's form submission), but would
// crash unconditionally on every request without this.
if (!function_exists('json_response')) {
    function json_response($data, int $code = 200): void {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
if (!function_exists('json_input')) {
    function json_input(): array {
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : $_POST;
    }
}

$data = json_input();
$email = trim($data['email'] ?? '');
$pass  = (string)($data['password'] ?? '');

if (!login_user($email, $pass)) json_response(['error' => 'Invalid credentials'], 401);

json_response([
    'message' => 'Login successful',
    'user'    => current_user(),
]);
