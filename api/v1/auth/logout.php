<?php
/** api/v1/auth/logout.php — POST /api/v1/auth/logout */
require_once __DIR__ . '/../../../includes/auth.php';
logout_user();
json_response(['message' => 'Logged out']);
