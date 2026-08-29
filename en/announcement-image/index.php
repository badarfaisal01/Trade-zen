<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_user();

$announcementId = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT image_filename FROM announcements WHERE id=? AND is_active=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW()) LIMIT 1");
$stmt->execute([$announcementId]);
$announcement = $stmt->fetch();
if (!$announcement || !$announcement['image_filename']) {
    http_response_code(404);
    exit('Image not found.');
}
stream_protected_image('announcements', (string)$announcement['image_filename']);
