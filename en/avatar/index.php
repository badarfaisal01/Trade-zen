<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = require_user();

$userId = (int)($_GET['id'] ?? $user['id']);
if ($userId !== (int)$user['id'] && !is_admin($user)) {
    http_response_code(404);
    exit('Image not found.');
}
$stmt = db()->prepare('SELECT avatar_filename FROM users WHERE id=? AND status=? LIMIT 1');
$stmt->execute([$userId, 'active']);
$filename = $stmt->fetchColumn();
if (!$filename) {
    http_response_code(404);
    exit('Image not found.');
}
stream_protected_image('avatars', (string)$filename);
