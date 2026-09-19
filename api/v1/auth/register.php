<?php
/** api/v1/auth/register.php — POST /api/v1/auth/register */
require_once __DIR__ . '/../../../includes/auth.php';

// Self-contained json_response()/json_input() - see api/v1/auth/login.php
// (this same audit) for the identical fix and full reasoning. Not
// currently called by any frontend code (the real registration flow goes
// through pages/register.php's form submission), but would crash
// unconditionally on every request without this.
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
$result = register_user($data);
if (isset($result['error'])) json_response(['error' => $result['error']], 400);

// Optional: auto-login after registration in the API as well
if (!empty($data['email']) && !empty($data['password'])) login_user($data['email'], $data['password']);

json_response([
    'message' => 'Account created',
    'user_id' => $result['id'],
    'session' => is_logged_in() ? current_user() : null,
], 201);
