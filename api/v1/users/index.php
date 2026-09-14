<?php
/** api/v1/users/index.php — GET /api/v1/users/me */
require_once __DIR__ . '/../../../includes/auth.php';
require_login();

if (($_GET['id'] ?? '') === 'me' || empty($_GET)) {
    $uid = current_user()['id'];
    $stmt = db()->prepare(
        'SELECT u.id, u.full_name, u.email, u.phone, u.location, u.bio, u.avatar_path, u.is_verified, u.is_seller, u.created_at, r.name AS role_name
         FROM users u INNER JOIN roles r ON r.id = u.role_id
         WHERE u.id = ? LIMIT 1'
    );
    $stmt->execute([$uid]);
    $me = $stmt->fetch();
    json_response(['data' => $me]);
}

json_response(['error' => 'Not implemented yet'], 501);
