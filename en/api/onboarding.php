<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = require_user();

if (!is_post()) {
    json_response(['ok' => false, 'message' => 'POST is required.'], 405);
}
verify_csrf();
$data = request_data();
$action = strtolower(trim((string)($data['action'] ?? 'complete')));
if (!in_array($action, ['complete', 'skip'], true)) {
    json_response(['ok' => false, 'message' => 'Invalid onboarding action.'], 422);
}

$stmt = db()->prepare('UPDATE users SET onboarding_completed_at=COALESCE(onboarding_completed_at,NOW()),updated_at=NOW() WHERE id=?');
$stmt->execute([(int)$user['id']]);
current_user(true);
json_response([
    'ok' => true,
    'message' => $action === 'skip' ? 'Onboarding skipped.' : 'Onboarding completed.',
    'data' => ['completed' => true],
]);
