<?php
declare(strict_types=1);

function payment_methods_for(string $flow, bool $activeOnly = true): array
{
    $column = $flow === 'withdrawal' ? 'withdrawal_enabled' : 'deposit_enabled';
    $where = $activeOnly ? "WHERE is_active = 1 AND {$column} = 1" : '';
    if ($activeOnly && $flow !== 'withdrawal') {
        // Do not advertise a Deposit option that can only fail after submit.
        // Admin can enable it for users as soon as an ID/address or QR exists.
        $where .= " AND (TRIM(COALESCE(account_identifier,''))<>'' OR TRIM(COALESCE(qr_filename,''))<>'')";
    }
    return db()->query("SELECT * FROM payment_methods {$where} ORDER BY sort_order ASC, id ASC")->fetchAll();
}

function payment_method_by_id(int $methodId, ?string $flow = null, bool $activeOnly = true): ?array
{
    if ($methodId < 1) {
        return null;
    }
    $sql = 'SELECT * FROM payment_methods WHERE id = ?';
    $params = [$methodId];
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    if ($flow === 'deposit') {
        $sql .= ' AND deposit_enabled = 1';
    } elseif ($flow === 'withdrawal') {
        $sql .= ' AND withdrawal_enabled = 1';
    }
    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function store_protected_image(array $file, string $folder, string $prefix): ?string
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
        throw new RuntimeException('The image upload did not complete. Please choose the file again.');
    }
    $maximumBytes = max(1, min(10, (int)setting('payment_proof_max_mb', 5))) * 1024 * 1024;
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > $maximumBytes) {
        throw new InvalidArgumentException('Image must be smaller than ' . (int)($maximumBytes / 1024 / 1024) . ' MB.');
    }
    $info = @getimagesize((string)$file['tmp_name']);
    if (!$info || (int)$info[0] < 80 || (int)$info[1] < 80 || (int)$info[0] > 5000 || (int)$info[1] > 5000) {
        throw new InvalidArgumentException('Upload a valid image between 80 and 5000 pixels.');
    }
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    $type = (int)($info[2] ?? 0);
    if (!isset($allowed[$type])) {
        throw new InvalidArgumentException('Only JPG, PNG, and WebP images are allowed.');
    }
    $directory = BASE_PATH . '/storage/' . trim($folder, '/');
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Protected image storage is not writable.');
    }
    $filename = preg_replace('/[^a-z0-9_-]+/i', '-', $prefix) . '-' . bin2hex(random_bytes(16)) . '.' . $allowed[$type];
    $target = $directory . '/' . $filename;
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }
    @chmod($target, 0640);
    return $filename;
}

function remove_protected_image(?string $filename, string $folder): void
{
    $name = basename((string)$filename);
    if ($name === '' || $name === '.' || $name === '..') {
        return;
    }
    $path = BASE_PATH . '/storage/' . trim($folder, '/') . '/' . $name;
    if (is_file($path)) {
        @unlink($path);
    }
}

function active_promo(PDO $pdo, string $code, string $type, int $userId, float $amount): array
{
    $normalized = strtoupper(trim($code));
    if ($normalized === '' || !preg_match('/^[A-Z0-9_-]{3,40}$/', $normalized)) {
        throw new InvalidArgumentException('Enter a valid coupon or gift code.');
    }
    $stmt = $pdo->prepare("SELECT * FROM promo_codes WHERE code = ? AND code_type = ? AND is_active = 1 AND (starts_at IS NULL OR starts_at <= NOW()) AND (expires_at IS NULL OR expires_at >= NOW()) LIMIT 1 FOR UPDATE");
    $stmt->execute([$normalized, $type]);
    $promo = $stmt->fetch();
    if (!$promo) {
        throw new RuntimeException('This code is invalid, expired, or not available for this action.');
    }
    if ($amount < (float)$promo['minimum_amount']) {
        throw new RuntimeException('This code requires a minimum amount of ' . money($promo['minimum_amount']) . '.');
    }
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM promo_redemptions WHERE promo_code_id = ? AND user_id = ? AND status IN ('reserved','credited')");
    $countStmt->execute([(int)$promo['id'], $userId]);
    if ((int)$countStmt->fetchColumn() >= (int)$promo['per_user_limit']) {
        throw new RuntimeException('You have already used this code.');
    }
    if ($promo['usage_limit'] !== null) {
        $reservedStmt = $pdo->prepare("SELECT COUNT(*) FROM promo_redemptions WHERE promo_code_id = ? AND status = 'reserved'");
        $reservedStmt->execute([(int)$promo['id']]);
        if ((int)$promo['usage_count'] + (int)$reservedStmt->fetchColumn() >= (int)$promo['usage_limit']) {
            throw new RuntimeException('This code has reached its usage limit.');
        }
    }
    return $promo;
}

function promo_credit_amount(array $promo, float $amount): float
{
    $credit = $promo['value_type'] === 'percent'
        ? round($amount * ((float)$promo['value_amount'] / 100), 2)
        : round((float)$promo['value_amount'], 2);
    if ($promo['maximum_bonus'] !== null) {
        $credit = min($credit, (float)$promo['maximum_bonus']);
    }
    return max(0.0, $credit);
}

function redeem_gift_code(int $userId, string $code): float
{
    $credit = db_transaction(function (PDO $pdo) use ($userId, $code): float {
        $promo = active_promo($pdo, $code, 'gift', $userId, 0.0);
        $credit = promo_credit_amount($promo, 0.0);
        if ($credit <= 0) {
            throw new RuntimeException('This gift code has no available credit.');
        }
        $userStmt = $pdo->prepare('SELECT demo_balance FROM users WHERE id = ? FOR UPDATE');
        $userStmt->execute([$userId]);
        $before = $userStmt->fetchColumn();
        if ($before === false) {
            throw new RuntimeException('Account not found.');
        }
        $after = round((float)$before + $credit, 2);
        $pdo->prepare('UPDATE users SET demo_balance = ?, updated_at = NOW() WHERE id = ?')->execute([$after, $userId]);
        $pdo->prepare("INSERT INTO promo_redemptions (promo_code_id,user_id,credit_amount,status,created_at,updated_at) VALUES (?,?,?,'credited',NOW(),NOW())")->execute([(int)$promo['id'], $userId, $credit]);
        $pdo->prepare('UPDATE promo_codes SET usage_count = usage_count + 1, updated_at = NOW() WHERE id = ?')->execute([(int)$promo['id']]);
        $pdo->prepare("INSERT INTO wallet_transactions (user_id,transaction_type,amount,balance_before,balance_after,reference_type,reference_id,description,created_at) VALUES (?,'gift_code',?,?,?,'promo_code',?,'Gift code credit',NOW())")->execute([$userId, $credit, (float)$before, $after, (int)$promo['id']]);
        return $credit;
    });
    current_user(true);
    return $credit;
}

function create_support_ticket(int $userId, string $category, string $subject, string $message, int $fundRequestId = 0): string
{
    $category = strtolower(trim($category));
    $subject = trim(preg_replace('/\s+/', ' ', $subject) ?? '');
    $message = trim($message);
    if (!in_array($category, ['account','payment','trade','technical','other'], true)) {
        throw new InvalidArgumentException('Choose a valid support category.');
    }
    if (strlen($subject) < 5 || strlen($subject) > 140 || strlen($message) < 10 || strlen($message) > 5000) {
        throw new InvalidArgumentException('Subject must be 5–140 characters and message 10–5000 characters.');
    }
    $open = db()->prepare("SELECT COUNT(*) FROM support_tickets WHERE user_id = ? AND status IN ('open','answered')");
    $open->execute([$userId]);
    if ((int)$open->fetchColumn() >= 5) {
        throw new RuntimeException('Close an existing support ticket before opening another one.');
    }
    $linkedRequestId = null;
    if ($fundRequestId > 0) {
        $request = db()->prepare('SELECT id FROM fund_requests WHERE id=? AND user_id=? LIMIT 1');
        $request->execute([$fundRequestId, $userId]);
        $linkedRequestId = $request->fetchColumn();
        if ($linkedRequestId === false) {
            throw new InvalidArgumentException('Choose one of your own deposit or withdrawal orders.');
        }
        $linkedRequestId = (int)$linkedRequestId;
    }
    $publicId = public_id('S');
    $stmt = db()->prepare("INSERT INTO support_tickets (public_id,user_id,fund_request_id,category,subject,message,status,created_at,updated_at) VALUES (?,?,?,?,?,?,'open',NOW(),NOW())");
    $stmt->execute([$publicId, $userId, $linkedRequestId, $category, $subject, $message]);
    return $publicId;
}

function close_support_ticket(int $userId, int $ticketId): void
{
    $stmt = db()->prepare("UPDATE support_tickets SET status='closed',updated_at=NOW() WHERE id=? AND user_id=? AND status IN ('open','answered')");
    $stmt->execute([$ticketId, $userId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('This support request cannot be closed.');
    }
}

function reply_support_ticket(int $adminId, int $ticketId, string $response, string $status = 'answered'): void
{
    $response = trim($response);
    if (strlen($response) < 3 || strlen($response) > 5000 || !in_array($status, ['answered', 'closed'], true)) {
        throw new InvalidArgumentException('Enter a response between 3 and 5000 characters.');
    }
    $stmt = db()->prepare("UPDATE support_tickets SET admin_response=?,status=?,assigned_to=?,answered_at=NOW(),updated_at=NOW() WHERE id=? AND status IN ('open','answered')");
    $stmt->execute([$response, $status, $adminId, $ticketId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Support request was not found or is already closed.');
    }
    admin_audit($adminId, 'support_ticket_' . $status, 'support_ticket', $ticketId);
}

function stream_protected_image(string $folder, string $filename, bool $private = true): void
{
    $name = basename($filename);
    if ($name === '' || $name !== $filename || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
        http_response_code(404);
        exit('Image not found.');
    }
    $path = BASE_PATH . '/storage/' . trim($folder, '/') . '/' . $name;
    if (!is_file($path)) {
        http_response_code(404);
        exit('Image not found.');
    }
    $info = @getimagesize($path);
    $allowed = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];
    $type = (int)($info[2] ?? 0);
    if (!isset($allowed[$type])) {
        http_response_code(404);
        exit('Image not found.');
    }
    header('Content-Type: ' . $allowed[$type]);
    header('Content-Length: ' . (string)filesize($path));
    header('Content-Disposition: inline; filename="image.' . pathinfo($name, PATHINFO_EXTENSION) . '"');
    header($private ? 'Cache-Control: private, no-store' : 'Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function save_payment_method(int $adminId, array $input, array $files): int
{
    $methodId = (int)($input['method_id'] ?? 0);
    $name = trim((string)($input['name'] ?? ''));
    $category = strtolower(trim((string)($input['category'] ?? 'other')));
    $iconKey = strtolower(trim((string)($input['icon_key'] ?? 'wallet')));
    $code = strtolower(trim((string)($input['code'] ?? '')));
    $code = preg_replace('/[^a-z0-9_-]+/', '-', $code) ?? '';
    $accountName = trim((string)($input['account_name'] ?? ''));
    $accountIdentifier = trim((string)($input['account_identifier'] ?? ''));
    $network = trim((string)($input['network'] ?? ''));
    $instructions = trim((string)($input['instructions'] ?? ''));
    $minimum = round((float)($input['minimum_amount'] ?? 0), 2);
    $maximum = round((float)($input['maximum_amount'] ?? 0), 2);
    $feePercent = round((float)($input['fee_percent'] ?? 0), 3);
    $feeFixed = round((float)($input['fee_fixed'] ?? 0), 2);
    $sortOrder = max(-9999, min(9999, (int)($input['sort_order'] ?? 0)));
    $flags = [
        'deposit_enabled' => isset($input['deposit_enabled']) ? 1 : 0,
        'withdrawal_enabled' => isset($input['withdrawal_enabled']) ? 1 : 0,
        'require_reference' => isset($input['require_reference']) ? 1 : 0,
        'require_proof' => isset($input['require_proof']) ? 1 : 0,
        'is_active' => isset($input['is_active']) ? 1 : 0,
    ];

    if (strlen($name) < 2 || strlen($name) > 80 || !in_array($category, ['upi','bank','wallet','crypto','other'], true)) {
        throw new InvalidArgumentException('Payment method name or category is invalid.');
    }
    if ($methodId < 1 && (strlen($code) < 2 || strlen($code) > 50)) {
        throw new InvalidArgumentException('Use a unique code between 2 and 50 characters.');
    }
    if (!preg_match('/^[a-z0-9_-]{2,30}$/', $iconKey)) {
        $iconKey = 'wallet';
    }
    if (strlen($accountName) > 120 || strlen($accountIdentifier) > 255 || strlen($network) > 60 || strlen($instructions) > 3000) {
        throw new InvalidArgumentException('Payment destination details are too long.');
    }
    if ($minimum < 0.01 || $maximum < $minimum || $maximum > 100000000 || $feePercent < 0 || $feePercent > 100 || $feeFixed < 0 || $feeFixed > $maximum) {
        throw new InvalidArgumentException('Payment limits or fees are invalid.');
    }

    $existing = null;
    if ($methodId > 0) {
        $stmt = db()->prepare('SELECT * FROM payment_methods WHERE id=? LIMIT 1');
        $stmt->execute([$methodId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            throw new RuntimeException('Payment method not found.');
        }

        $qrUploadRequested = (int)($files['qr_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
        $paymentDetailsChanged =
            (string)($existing['name'] ?? '') !== $name ||
            (string)($existing['category'] ?? '') !== $category ||
            (string)($existing['account_name'] ?? '') !== $accountName ||
            (string)($existing['account_identifier'] ?? '') !== $accountIdentifier ||
            (string)($existing['network'] ?? '') !== $network ||
            (string)($existing['instructions'] ?? '') !== $instructions ||
            (int)($existing['require_reference'] ?? 0) !== $flags['require_reference'] ||
            (int)($existing['require_proof'] ?? 0) !== $flags['require_proof'] ||
            $qrUploadRequested || isset($input['remove_qr']);
        if ($paymentDetailsChanged) {
            expire_deposit_orders();
            $activeOrders = db()->prepare("SELECT COUNT(*) FROM fund_requests WHERE payment_method_id=? AND request_type='deposit' AND status='awaiting_payment'");
            $activeOrders->execute([$methodId]);
            if ((int)$activeOrders->fetchColumn() > 0) {
                throw new RuntimeException('This method has active payment orders. Wait for them to be confirmed, cancelled, or expired before changing its payment details.');
            }
        }
    }

    $newQr = store_protected_image($files['qr_image'] ?? [], 'payment-methods', 'qr');
    $qrFilename = $newQr ?: ($existing['qr_filename'] ?? null);
    if (isset($input['remove_qr']) && !$newQr) {
        $qrFilename = null;
    }

    try {
        if ($existing) {
            $stmt = db()->prepare('UPDATE payment_methods SET name=?,category=?,icon_key=?,account_name=?,account_identifier=?,network=?,instructions=?,qr_filename=?,minimum_amount=?,maximum_amount=?,fee_percent=?,fee_fixed=?,deposit_enabled=?,withdrawal_enabled=?,require_reference=?,require_proof=?,is_active=?,sort_order=?,created_by=?,updated_at=NOW() WHERE id=?');
            $stmt->execute([
                $name, $category, $iconKey, $accountName ?: null, $accountIdentifier ?: null, $network ?: null,
                $instructions ?: null, $qrFilename, $minimum, $maximum, $feePercent, $feeFixed,
                $flags['deposit_enabled'], $flags['withdrawal_enabled'], $flags['require_reference'], $flags['require_proof'],
                $flags['is_active'], $sortOrder, $adminId, $methodId,
            ]);
        } else {
            $stmt = db()->prepare('INSERT INTO payment_methods (code,name,category,icon_key,account_name,account_identifier,network,instructions,qr_filename,minimum_amount,maximum_amount,fee_percent,fee_fixed,deposit_enabled,withdrawal_enabled,require_reference,require_proof,is_active,sort_order,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
            $stmt->execute([
                $code, $name, $category, $iconKey, $accountName ?: null, $accountIdentifier ?: null, $network ?: null,
                $instructions ?: null, $qrFilename, $minimum, $maximum, $feePercent, $feeFixed,
                $flags['deposit_enabled'], $flags['withdrawal_enabled'], $flags['require_reference'], $flags['require_proof'],
                $flags['is_active'], $sortOrder, $adminId,
            ]);
            $methodId = (int)db()->lastInsertId();
        }
    } catch (Throwable $exception) {
        if ($newQr) {
            remove_protected_image($newQr, 'payment-methods');
        }
        throw $exception;
    }

    if ($existing && ($newQr || isset($input['remove_qr'])) && $existing['qr_filename'] && $existing['qr_filename'] !== $qrFilename) {
        remove_protected_image((string)$existing['qr_filename'], 'payment-methods');
    }
    admin_audit($adminId, $existing ? 'payment_method_updated' : 'payment_method_created', 'payment_method', $methodId, ['name' => $name]);
    return $methodId;
}

function normalize_optional_datetime(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    foreach (['Y-m-d\TH:i', 'Y-m-d H:i:s'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        if ($date && $date->format($format) === $value) {
            return $date->format('Y-m-d H:i:s');
        }
    }
    throw new InvalidArgumentException('Coupon start or expiry date is invalid.');
}

function save_promo_code(int $adminId, array $input): int
{
    $promoId = (int)($input['promo_id'] ?? 0);
    $code = strtoupper(trim((string)($input['code'] ?? '')));
    $title = trim((string)($input['title'] ?? ''));
    $codeType = (string)($input['code_type'] ?? 'gift');
    $valueType = (string)($input['value_type'] ?? 'fixed');
    $value = round((float)($input['value_amount'] ?? 0), 2);
    $minimum = round((float)($input['minimum_amount'] ?? 0), 2);
    $maximumBonusRaw = trim((string)($input['maximum_bonus'] ?? ''));
    $maximumBonus = $maximumBonusRaw === '' ? null : round((float)$maximumBonusRaw, 2);
    $usageRaw = trim((string)($input['usage_limit'] ?? ''));
    $usageLimit = $usageRaw === '' ? null : (int)$usageRaw;
    $perUser = max(1, min(100, (int)($input['per_user_limit'] ?? 1)));
    $startsAt = normalize_optional_datetime((string)($input['starts_at'] ?? ''));
    $expiresAt = normalize_optional_datetime((string)($input['expires_at'] ?? ''));
    $active = isset($input['is_active']) ? 1 : 0;

    if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $code) || strlen($title) < 2 || strlen($title) > 100) {
        throw new InvalidArgumentException('Coupon code or title is invalid.');
    }
    if (!in_array($codeType, ['gift','deposit_bonus'], true) || !in_array($valueType, ['fixed','percent'], true)) {
        throw new InvalidArgumentException('Coupon type is invalid.');
    }
    if ($codeType === 'gift' && $valueType !== 'fixed') {
        throw new InvalidArgumentException('Gift codes must use a fixed credit value.');
    }
    if ($codeType === 'gift') {
        $minimum = 0.0;
        $maximumBonus = null;
    }
    if ($value <= 0 || ($valueType === 'percent' && $value > 500) || $minimum < 0 || ($maximumBonus !== null && $maximumBonus <= 0) || ($usageLimit !== null && $usageLimit < 1)) {
        throw new InvalidArgumentException('Coupon value or limits are invalid.');
    }
    if ($startsAt && $expiresAt && strtotime($expiresAt) <= strtotime($startsAt)) {
        throw new InvalidArgumentException('Coupon expiry must be after its start date.');
    }

    if ($promoId > 0) {
        $stmt = db()->prepare('UPDATE promo_codes SET code=?,title=?,code_type=?,value_type=?,value_amount=?,minimum_amount=?,maximum_bonus=?,usage_limit=?,per_user_limit=?,starts_at=?,expires_at=?,is_active=?,created_by=?,updated_at=NOW() WHERE id=?');
        $stmt->execute([$code, $title, $codeType, $valueType, $value, $minimum, $maximumBonus, $usageLimit, $perUser, $startsAt, $expiresAt, $active, $adminId, $promoId]);
        if ($stmt->rowCount() < 1) {
            $check = db()->prepare('SELECT id FROM promo_codes WHERE id=?');
            $check->execute([$promoId]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('Coupon was not found.');
            }
        }
        $action = 'promo_code_updated';
    } else {
        $stmt = db()->prepare('INSERT INTO promo_codes (code,title,code_type,value_type,value_amount,minimum_amount,maximum_bonus,usage_limit,per_user_limit,usage_count,starts_at,expires_at,is_active,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,0,?,?,?,?,NOW(),NOW())');
        $stmt->execute([$code, $title, $codeType, $valueType, $value, $minimum, $maximumBonus, $usageLimit, $perUser, $startsAt, $expiresAt, $active, $adminId]);
        $promoId = (int)db()->lastInsertId();
        $action = 'promo_code_created';
    }
    admin_audit($adminId, $action, 'promo_code', $promoId, ['code' => $code, 'type' => $codeType]);
    return $promoId;
}
