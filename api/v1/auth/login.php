<?php
/** api/v1/auth/login.php — POST /api/v1/auth/login */
require_once __DIR__ . '/../../../includes/auth.php';

$data = json_input();
$email = trim($data['email'] ?? '');
$pass  = (string)($data['password'] ?? '');

if (!login_user($email, $pass)) json_response(['error' => 'Invalid credentials'], 401);

json_response([
    'message' => 'Login successful',
    'user'    => current_user(),
]);
