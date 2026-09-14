<?php
/**
 * Public listing-image endpoint.
 * Supports both storage formats used by Isoko Ryacu:
 *   1) normal filesystem path in listing_images.image_path
 *   2) db-blob:<id> with optional image_blob/image_mime columns
 *
 * IMPORTANT: this endpoint is defensive about older databases where the
 * BLOB columns have not been migrated yet. It never assumes those columns
 * exist, so an image request cannot take down the homepage.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../config/database.php';

$id = (int)($_GET['id'] ?? 0);
$listingId = (int)($_GET['listing_id'] ?? 0);
if ($id < 1 && $listingId < 1) {
    http_response_code(404);
    exit;
}

try {
    $pdo = db();

    // Detect optional BLOB columns without assuming a migrated schema.
    $hasBlob = false;
    $hasMime = false;
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM listing_images')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $col) {
            $name = strtolower((string)($col['Field'] ?? ''));
            if ($name === 'image_blob') $hasBlob = true;
            if ($name === 'image_mime') $hasMime = true;
        }
    } catch (Throwable $e) {
        // Fall back to the original filesystem-only schema.
    }

    $select = 'id, image_path';
    if ($hasBlob) $select .= ', image_blob';
    if ($hasMime) $select .= ', image_mime';

    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT {$select} FROM listing_images WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
    } else {
        $stmt = $pdo->prepare("SELECT {$select} FROM listing_images WHERE listing_id = ? ORDER BY is_primary DESC, display_order ASC, id ASC LIMIT 1");
        $stmt->execute([$listingId]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        exit;
    }

    // Prefer a real database image when present.
    if ($hasBlob && !empty($row['image_blob'])) {
        $mime = $hasMime ? (string)($row['image_mime'] ?? '') : '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            $mime = 'image/jpeg';
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($row['image_blob']));
        header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');
        header('X-Content-Type-Options: nosniff');
        echo $row['image_blob'];
        exit;
    }

    $path = trim((string)($row['image_path'] ?? ''));
    if ($path === '' || str_starts_with($path, 'db-blob:')) {
        http_response_code(404);
        exit;
    }

    if (preg_match('#^https?://#i', $path)) {
        header('Location: ' . $path, true, 302);
        exit;
    }

    $appRoot = realpath(__DIR__ . '/../../../');
    if ($appRoot === false) {
        http_response_code(404);
        exit;
    }

    $relative = ltrim(str_replace('\\', '/', $path), '/');
    $file = realpath($appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    $rootPrefix = rtrim($appRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($file === false || !is_file($file) || strncmp($file, $rootPrefix, strlen($rootPrefix)) !== 0) {
        http_response_code(404);
        exit;
    }

    $mime = function_exists('mime_content_type') ? (string)mime_content_type($file) : '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        $mime = $map[$ext] ?? '';
    }
    if ($mime === '') {
        http_response_code(415);
        exit;
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
} catch (Throwable $e) {
    http_response_code(404);
}
