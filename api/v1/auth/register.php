<?php
/** api/v1/auth/register.php — POST /api/v1/auth/register */
require_once __DIR__ . '/../../../includes/auth.php';

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
