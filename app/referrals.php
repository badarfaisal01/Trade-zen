<?php
declare(strict_types=1);

function normalize_referral_code(string $code): string
{
    return strtoupper(trim($code));
}

function generate_user_referral_code(PDO $pdo, int $userId): string
{
    if ($userId < 1) {
        throw new InvalidArgumentException('A valid user is required to create a referral code.');
    }

    // The user id makes the normal code deterministic and unique. The retry
    // suffix handles a legacy/manual agent that already owns that exact code.
    $base = 'AK' . str_pad(strtoupper(base_convert((string)$userId, 10, 36)), 6, '0', STR_PAD_LEFT);
    $candidate = $base;
    $check = $pdo->prepare('SELECT COUNT(*) FROM agents WHERE referral_code=?');
    for ($attempt = 0; $attempt < 12; $attempt++) {
        $check->execute([$candidate]);
        if ((int)$check->fetchColumn() === 0) {
            return $candidate;
        }
        $candidate = substr($base, 0, 24) . strtoupper(bin2hex(random_bytes(3)));
    }

    throw new RuntimeException('A unique referral code could not be generated. Try again.');
}

function ensure_user_affiliate(array $user, ?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    $userId = (int)($user['id'] ?? 0);
    $name = trim((string)($user['name'] ?? ''));
    $email = strtolower(trim((string)($user['email'] ?? '')));
    if ($userId < 1 || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('A valid user account is required for Affiliate.');
    }

    $byUser = $pdo->prepare('SELECT * FROM agents WHERE user_id=? LIMIT 1');
    $byUser->execute([$userId]);
    $agent = $byUser->fetch();
    if ($agent) {
        return $agent;
    }

    // Adopt a pre-4.4.6 agent that was matched only by email. This preserves
    // its existing code/history and removes the old administrator-approval gate.
    $byEmail = $pdo->prepare('SELECT * FROM agents WHERE email=? LIMIT 1');
    $byEmail->execute([$email]);
    $legacy = $byEmail->fetch();
    if ($legacy) {
        $link = $pdo->prepare('UPDATE agents SET user_id=?,updated_at=NOW() WHERE id=? AND user_id IS NULL');
        $link->execute([$userId, (int)$legacy['id']]);
        $byUser->execute([$userId]);
        return $byUser->fetch() ?: $legacy;
    }

    $code = generate_user_referral_code($pdo, $userId);
    try {
        $insert = $pdo->prepare("INSERT INTO agents (user_id,name,email,referral_code,commission_percent,status,created_by,created_at,updated_at) VALUES (?,?,?,?,0,'active',NULL,NOW(),NOW())");
        $insert->execute([$userId, substr($name, 0, 80), $email, $code]);
    } catch (PDOException $exception) {
        // A simultaneous first visit/registration can race to create the same
        // automatic profile. Return the winner instead of showing an error.
        if ((string)$exception->getCode() !== '23000') {
            throw $exception;
        }
    }

    $byUser->execute([$userId]);
    $agent = $byUser->fetch();
    if (!$agent) {
        throw new RuntimeException('The Affiliate profile could not be prepared.');
    }
    return $agent;
}

function active_agent_by_code(string $code): ?array
{
    $normalized = normalize_referral_code($code);
    if (!preg_match('/^[A-Z0-9_-]{3,32}$/', $normalized)) {
        return null;
    }
    $stmt = db()->prepare("SELECT id,user_id,name,email,referral_code,commission_percent FROM agents WHERE referral_code=? AND status='active' LIMIT 1");
    $stmt->execute([$normalized]);
    $agent = $stmt->fetch();
    return $agent ?: null;
}

function attach_user_referral(PDO $pdo, int $agentId, int $userId): void
{
    if ($agentId < 1 || $userId < 1) {
        throw new InvalidArgumentException('Invalid referral assignment.');
    }
    $owner = $pdo->prepare('SELECT user_id FROM agents WHERE id=? LIMIT 1');
    $owner->execute([$agentId]);
    $ownerId = (int)$owner->fetchColumn();
    if ($ownerId > 0 && $ownerId === $userId) {
        throw new InvalidArgumentException('A user cannot refer their own account.');
    }
    $stmt = $pdo->prepare("INSERT INTO agent_referrals (agent_id,user_id,reward_status,reward_amount,created_at) VALUES (?,?,'pending',0,NOW())");
    $stmt->execute([$agentId, $userId]);
}

function credit_referral_bonus_for_deposit(PDO $pdo, int $referredUserId, int $fundRequestId, float $depositAmount, int $adminId): float
{
    if (!(bool)setting('referral_program_enabled', true)) {
        return 0.0;
    }

    $depositCents = (int)round($depositAmount * 100);
    $minimumCents = (int)round(max(0.01, (float)setting('referral_minimum_deposit', 10)) * 100);
    $rewardCents = (int)round(max(0.0, (float)setting('referral_reward_amount', 5)) * 100);
    if ($depositCents < $minimumCents || $rewardCents < 1) {
        return 0.0;
    }

    $referralStmt = $pdo->prepare("SELECT r.id,r.reward_status,a.user_id referrer_user_id,a.status agent_status FROM agent_referrals r JOIN agents a ON a.id=r.agent_id WHERE r.user_id=? LIMIT 1 FOR UPDATE");
    $referralStmt->execute([$referredUserId]);
    $referral = $referralStmt->fetch();
    if (!$referral || $referral['reward_status'] !== 'pending' || $referral['agent_status'] !== 'active') {
        return 0.0;
    }

    $referrerUserId = (int)$referral['referrer_user_id'];
    if ($referrerUserId < 1 || $referrerUserId === $referredUserId) {
        return 0.0;
    }

    $userStmt = $pdo->prepare("SELECT demo_balance FROM users WHERE id=? AND role='user' AND status='active' FOR UPDATE");
    $userStmt->execute([$referrerUserId]);
    $beforeValue = $userStmt->fetchColumn();
    if ($beforeValue === false) {
        return 0.0;
    }

    $beforeCents = (int)round((float)$beforeValue * 100);
    $afterCents = $beforeCents + $rewardCents;
    $reward = $rewardCents / 100;
    $after = $afterCents / 100;

    // The conditional transition is the financial idempotency guard. Even if
    // an administrator repeats the approval request, only one transaction can
    // move this referral from pending to credited.
    $qualify = $pdo->prepare("UPDATE agent_referrals SET reward_status='credited',qualified_fund_request_id=?,reward_amount=?,credited_at=NOW() WHERE id=? AND reward_status='pending'");
    $qualify->execute([$fundRequestId, $reward, (int)$referral['id']]);
    if ($qualify->rowCount() !== 1) {
        return 0.0;
    }

    $pdo->prepare('UPDATE users SET demo_balance=?,updated_at=NOW() WHERE id=?')->execute([$after, $referrerUserId]);
    $ledger = $pdo->prepare("INSERT INTO wallet_transactions (user_id,account_type,transaction_type,amount,balance_before,balance_after,reference_type,reference_id,description,created_by,created_at) VALUES (?,'live','referral_bonus',?,?,?,?,?,'Referral qualifying-deposit bonus',?,NOW())");
    $ledger->execute([$referrerUserId, $reward, $beforeCents / 100, $after, 'agent_referral', (int)$referral['id'], $adminId]);
    return $reward;
}

function mask_referral_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'Invited user';
    }
    $first = function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1);
    return strtoupper($first) . str_repeat('•', 4);
}

function mask_referral_email(string $email): string
{
    $parts = explode('@', strtolower(trim($email)), 2);
    if (count($parts) !== 2) {
        return 'hidden';
    }
    $prefix = substr($parts[0], 0, min(2, strlen($parts[0])));
    return $prefix . '***@' . $parts[1];
}
