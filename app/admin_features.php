<?php
declare(strict_types=1);

function admin_optional_datetime(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        throw new InvalidArgumentException('Enter a valid date and time.');
    }
    return date('Y-m-d H:i:s', $timestamp);
}

function admin_handle_extension_action(array $admin, string $action): ?string
{
    if ($action === 'referral_settings_save') {
        require_admin_capability($admin, 'manage_agents');
        $enabled = isset($_POST['referral_program_enabled']) ? '1' : '0';
        $reward = round((float)($_POST['referral_reward_amount'] ?? 0), 2);
        $minimum = round((float)($_POST['referral_minimum_deposit'] ?? 0), 2);
        if ($reward < 0 || $reward > 1000000 || $minimum < 0.01 || $minimum > 100000000) {
            throw new InvalidArgumentException('Referral reward or qualifying minimum is invalid.');
        }
        $upsert = db()->prepare('INSERT INTO site_settings (setting_key,setting_value,setting_type,group_name,is_public,updated_by,updated_at) VALUES (?,?,?,?,1,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),setting_type=VALUES(setting_type),group_name=VALUES(group_name),is_public=1,updated_by=VALUES(updated_by),updated_at=NOW()');
        $upsert->execute(['referral_program_enabled', $enabled, 'boolean', 'affiliate', (int)$admin['id']]);
        $upsert->execute(['referral_reward_amount', (string)$reward, 'number', 'affiliate', (int)$admin['id']]);
        $upsert->execute(['referral_minimum_deposit', (string)$minimum, 'number', 'affiliate', (int)$admin['id']]);
        admin_audit((int)$admin['id'], 'referral_settings_updated', 'site_settings', null, ['enabled' => $enabled === '1', 'reward' => $reward, 'minimum_deposit' => $minimum]);
        return 'Affiliate reward settings saved.';
    }

    if ($action === 'agent_save') {
        require_admin_capability($admin, 'manage_agents');
        $agentId = (int)($_POST['agent_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $code = normalize_referral_code((string)($_POST['referral_code'] ?? ''));
        $commission = round((float)($_POST['commission_percent'] ?? 0), 2);
        $status = (string)($_POST['status'] ?? 'active');
        if (strlen($name) < 2 || strlen($name) > 80 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[A-Z0-9_-]{3,32}$/', $code) || $commission < 0 || $commission > 50 || !in_array($status, ['active', 'blocked'], true)) {
            throw new InvalidArgumentException('Agent name, email, referral code, commission, or status is invalid.');
        }
        $linkedUserStmt = db()->prepare("SELECT id FROM users WHERE email=? AND role='user' LIMIT 1");
        $linkedUserStmt->execute([$email]);
        $linkedUserId = $linkedUserStmt->fetchColumn();
        if ($agentId > 0) {
            $stmt = db()->prepare('UPDATE agents SET user_id=COALESCE(user_id,?),name=?,email=?,referral_code=?,commission_percent=?,status=?,updated_at=NOW() WHERE id=?');
            $stmt->execute([$linkedUserId !== false ? (int)$linkedUserId : null, $name, $email, $code, $commission, $status, $agentId]);
            if ($stmt->rowCount() < 1) {
                $check = db()->prepare('SELECT COUNT(*) FROM agents WHERE id=?');
                $check->execute([$agentId]);
                if (!(int)$check->fetchColumn()) {
                    throw new RuntimeException('Agent not found.');
                }
            }
        } else {
            $stmt = db()->prepare('INSERT INTO agents (user_id,name,email,referral_code,commission_percent,status,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())');
            $stmt->execute([$linkedUserId !== false ? (int)$linkedUserId : null, $name, $email, $code, $commission, $status, (int)$admin['id']]);
            $agentId = (int)db()->lastInsertId();
        }
        admin_audit((int)$admin['id'], 'agent_saved', 'agent', $agentId, ['referral_code' => $code, 'status' => $status]);
        return 'Agent and referral code saved.';
    }

    if ($action === 'agent_assign') {
        require_admin_capability($admin, 'manage_agents');
        $agentId = (int)($_POST['agent_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        $agentCheck = db()->prepare("SELECT id,user_id FROM agents WHERE id=? AND status='active'");
        $agentCheck->execute([$agentId]);
        $agentRow = $agentCheck->fetch();
        $agentOwnerId = (int)($agentRow['user_id'] ?? 0);
        $userCheck = db()->prepare("SELECT COUNT(*) FROM users WHERE id=? AND role='user'");
        $userCheck->execute([$userId]);
        if (!$agentRow || !(int)$userCheck->fetchColumn()) {
            throw new InvalidArgumentException('Choose an active agent and a valid user.');
        }
        if ($agentOwnerId === $userId) {
            throw new InvalidArgumentException('A user cannot refer their own account.');
        }
        $existingReferral = db()->prepare('SELECT reward_status FROM agent_referrals WHERE user_id=? LIMIT 1');
        $existingReferral->execute([$userId]);
        if ($existingReferral->fetchColumn() === 'credited') {
            throw new RuntimeException('A rewarded referral cannot be reassigned.');
        }
        $stmt = db()->prepare("INSERT INTO agent_referrals (agent_id,user_id,reward_status,reward_amount,created_at) VALUES (?,?,'pending',0,NOW()) ON DUPLICATE KEY UPDATE agent_id=VALUES(agent_id),reward_status='pending',qualified_fund_request_id=NULL,reward_amount=0,credited_at=NULL");
        $stmt->execute([$agentId, $userId]);
        admin_audit((int)$admin['id'], 'referral_assigned', 'user', $userId, ['agent_id' => $agentId]);
        return 'User referral assignment saved.';
    }

    if ($action === 'notification_create') {
        require_admin_capability($admin, 'manage_notifications');
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        $level = (string)($_POST['level'] ?? 'info');
        $startsAt = admin_optional_datetime((string)($_POST['starts_at'] ?? ''));
        $expiresAt = admin_optional_datetime((string)($_POST['expires_at'] ?? ''));
        if (strlen($title) < 3 || strlen($title) > 120 || strlen($message) < 5 || strlen($message) > 2000 || !in_array($level, ['info', 'success', 'warning'], true)) {
            throw new InvalidArgumentException('Notification title, message, or style is invalid.');
        }
        if ($targetUserId > 0) {
            $target = db()->prepare("SELECT COUNT(*) FROM users WHERE id=? AND role='user' AND status='active'");
            $target->execute([$targetUserId]);
            if (!(int)$target->fetchColumn()) {
                throw new InvalidArgumentException('Choose an active user or send to everyone.');
            }
        }
        if ($expiresAt !== null && strtotime($expiresAt) <= strtotime($startsAt ?? 'now')) {
            throw new InvalidArgumentException('Notification expiry must be after its start time.');
        }
        $stmt = db()->prepare('INSERT INTO user_notifications (user_id,title,message,level,is_active,starts_at,expires_at,created_by,created_at,updated_at) VALUES (?,?,?,?,1,?,?,?,?,NOW())');
        $stmt->execute([$targetUserId > 0 ? $targetUserId : null, $title, $message, $level, $startsAt ?: date('Y-m-d H:i:s'), $expiresAt, (int)$admin['id'], date('Y-m-d H:i:s')]);
        $notificationId = (int)db()->lastInsertId();
        admin_audit((int)$admin['id'], 'notification_created', 'notification', $notificationId, ['user_id' => $targetUserId ?: null]);
        return $targetUserId > 0 ? 'User notification published.' : 'Platform-wide notification published.';
    }

    if ($action === 'notification_toggle') {
        require_admin_capability($admin, 'manage_notifications');
        $notificationId = (int)($_POST['notification_id'] ?? 0);
        $active = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
        $stmt = db()->prepare('UPDATE user_notifications SET is_active=?,updated_at=NOW() WHERE id=?');
        $stmt->execute([$active, $notificationId]);
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('Notification was not found or was already in that state.');
        }
        admin_audit((int)$admin['id'], 'notification_status_changed', 'notification', $notificationId, ['active' => $active]);
        return $active ? 'Notification enabled.' : 'Notification disabled.';
    }

    if ($action === 'site_logo_save') {
        require_admin_capability($admin, 'manage_branding');
        $old = trim((string)setting('logo_filename', ''));
        $remove = isset($_POST['remove_logo']);
        $new = $remove ? null : store_protected_image($_FILES['site_logo'] ?? [], 'site-assets', 'site-logo');
        if (!$remove && $new === null) {
            throw new InvalidArgumentException('Choose a JPG, PNG, or WebP logo image.');
        }
        $filename = $new ?? '';
        $stmt = db()->prepare("INSERT INTO site_settings (setting_key,setting_value,setting_type,group_name,is_public,updated_by,updated_at) VALUES ('logo_filename',?,'string','general',0,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=NOW()");
        $stmt->execute([$filename, (int)$admin['id']]);
        if ($old !== '' && $old !== $filename) {
            remove_protected_image($old, 'site-assets');
        }
        admin_audit((int)$admin['id'], $remove ? 'site_logo_removed' : 'site_logo_updated', 'site_settings');
        return $remove ? 'Custom logo removed; the default logo is active.' : 'Site logo updated.';
    }

    return null;
}

function render_admin_finance_page(): void
{
    $currency = (string)setting('wallet_currency', 'USD');
    $metrics = [
        'wallets' => (float)db()->query("SELECT COALESCE(SUM(demo_balance),0) FROM users WHERE role='user'")->fetchColumn(),
        'deposits' => (float)db()->query("SELECT COALESCE(SUM(amount+bonus_amount),0) FROM fund_requests WHERE request_type='deposit' AND status IN ('approved','paid')")->fetchColumn(),
        'withdrawals' => (float)db()->query("SELECT COALESCE(SUM(amount+fee_amount),0) FROM fund_requests WHERE request_type='withdrawal' AND status='paid'")->fetchColumn(),
        'reserved' => (float)db()->query("SELECT COALESCE(SUM(amount+fee_amount),0) FROM fund_requests WHERE request_type='withdrawal' AND status IN ('pending','approved') AND balance_reserved=1")->fetchColumn(),
    ];
    $type = trim((string)($_GET['type'] ?? ''));
    $allowedTypes = ['signup_bonus','trade_stake','trade_win','trade_refund','deposit_credit','deposit_bonus','referral_bonus','withdrawal_hold','withdrawal_refund','withdrawal_paid','gift_code','admin_adjustment'];
    $params = [];
    $where = '';
    if (in_array($type, $allowedTypes, true)) {
        $where = ' WHERE w.transaction_type=?';
        $params[] = $type;
    }
    $stmt = db()->prepare('SELECT w.*,u.name,u.email FROM wallet_transactions w JOIN users u ON u.id=w.user_id' . $where . ' ORDER BY w.id DESC LIMIT 250');
    $stmt->execute($params);
    $ledger = $stmt->fetchAll();
    ?>
    <section class="metric-grid metric-grid--four">
        <article class="metric-card"><span>Total live wallets</span><strong><?= e(money($metrics['wallets'], $currency)) ?></strong><small>Current user balances</small></article>
        <article class="metric-card"><span>Approved deposits</span><strong><?= e(money($metrics['deposits'], $currency)) ?></strong><small>Credits and approved bonuses</small></article>
        <article class="metric-card"><span>Paid withdrawals</span><strong><?= e(money($metrics['withdrawals'], $currency)) ?></strong><small>Completed payout records</small></article>
        <article class="metric-card"><span>Reserved withdrawals</span><strong><?= e(money($metrics['reserved'], $currency)) ?></strong><small>Pending and approved holds</small></article>
    </section>
    <section class="admin-card"><div class="admin-card-head admin-card-head--filters"><div><span class="eyebrow">Finance ledger</span><h2>Latest wallet movements</h2></div><form class="admin-filters" method="get"><input type="hidden" name="page" value="finance"><select name="type"><option value="">All activity</option><?php foreach ($allowedTypes as $option): ?><option value="<?= e($option) ?>" <?= $type === $option ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $option))) ?></option><?php endforeach; ?></select><button class="button button--ghost button--small">Filter</button></form></div><div class="admin-table-wrap"><table><thead><tr><th>Time</th><th>User</th><th>Account</th><th>Activity</th><th>Amount</th><th>Before → after</th><th>Reference</th></tr></thead><tbody><?php if (!$ledger): ?><tr><td colspan="7" class="table-empty">No wallet entries found.</td></tr><?php endif; ?><?php foreach ($ledger as $row): ?><tr><td><?= e(date('d M Y H:i:s', strtotime($row['created_at']))) ?></td><td><strong><?= e($row['name']) ?></strong><small><?= e($row['email']) ?></small></td><td><span class="status <?= $row['account_type'] === 'demo' ? 'status--open' : 'status--active' ?>"><?= e(ucfirst($row['account_type'])) ?></span></td><td><?= e(ucwords(str_replace('_', ' ', $row['transaction_type']))) ?><small><?= e((string)$row['description']) ?></small></td><td class="<?= (float)$row['amount'] >= 0 ? 'amount-positive' : 'amount-negative' ?>"><?= e(money($row['amount'], $currency)) ?></td><td class="mono"><?= e(number_format((float)$row['balance_before'], 2)) ?> → <?= e(number_format((float)$row['balance_after'], 2)) ?></td><td><?= e((string)$row['reference_type']) ?><?= $row['reference_id'] ? ' #' . (int)$row['reference_id'] : '' ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    <?php
}

function render_admin_agents_page_legacy(): void
{
    $agents = db()->query("SELECT a.*,COUNT(DISTINCT r.user_id) referral_count,COALESCE(SUM(t.amount),0) trade_volume FROM agents a LEFT JOIN agent_referrals r ON r.agent_id=a.id LEFT JOIN trades t ON t.user_id=r.user_id AND t.account_type='live' GROUP BY a.id ORDER BY a.id DESC")->fetchAll();
    $users = db()->query("SELECT id,name,email FROM users WHERE role='user' ORDER BY id DESC LIMIT 500")->fetchAll();
    $referrals = db()->query('SELECT r.created_at,a.name agent_name,a.referral_code,u.name user_name,u.email FROM agent_referrals r JOIN agents a ON a.id=r.agent_id JOIN users u ON u.id=r.user_id ORDER BY r.id DESC LIMIT 150')->fetchAll();
    ?>
    <section class="admin-page-intro"><div><span class="eyebrow">Referral operations</span><h2>Agents &amp; referral tracking</h2><p>Create unique referral codes, assign users, and review live-account trade volume without exposing administrator access.</p></div></section>
    <section class="admin-grid admin-grid--agents"><article class="admin-card"><div class="admin-card-head"><div><span class="eyebrow">New agent</span><h2>Add agent</h2></div></div><form method="post" class="admin-editor-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="agent_save"><input type="hidden" name="return_page" value="agents"><div class="field-grid"><label class="field"><span>Name</span><input name="name" minlength="2" maxlength="80" required></label><label class="field"><span>Email</span><input type="email" name="email" required></label></div><div class="field-grid"><label class="field"><span>Referral code</span><input name="referral_code" pattern="[A-Za-z0-9_-]{3,32}" required></label><label class="field"><span>Commission %</span><input type="number" name="commission_percent" min="0" max="50" step="0.01" value="0"></label></div><input type="hidden" name="status" value="active"><button class="button button--primary">Add agent</button></form></article><article class="admin-card"><div class="admin-card-head"><div><span class="eyebrow">Manual assignment</span><h2>Assign user referral</h2></div></div><form method="post" class="admin-editor-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="agent_assign"><input type="hidden" name="return_page" value="agents"><label class="field"><span>Agent</span><select name="agent_id" required><option value="">Choose agent</option><?php foreach ($agents as $agent): if ($agent['status'] !== 'active') continue; ?><option value="<?= (int)$agent['id'] ?>"><?= e($agent['name']) ?> · <?= e($agent['referral_code']) ?></option><?php endforeach; ?></select></label><label class="field"><span>User</span><select name="user_id" required><option value="">Choose user</option><?php foreach ($users as $user): ?><option value="<?= (int)$user['id'] ?>"><?= e($user['name']) ?> · <?= e($user['email']) ?></option><?php endforeach; ?></select></label><button class="button button--ghost">Save assignment</button></form></article></section>
    <section class="agent-admin-grid"><?php foreach ($agents as $agent): ?><article class="admin-card agent-card"><div class="admin-card-head"><div><span class="eyebrow"><?= e($agent['referral_code']) ?></span><h2><?= e($agent['name']) ?></h2></div><span class="status status--<?= e($agent['status']) ?>"><?= e($agent['status']) ?></span></div><div class="agent-stats"><span><small>Referrals</small><strong><?= number_format((int)$agent['referral_count']) ?></strong></span><span><small>Live volume</small><strong><?= e(money($agent['trade_volume'])) ?></strong></span></div><form method="post" class="admin-editor-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="agent_save"><input type="hidden" name="return_page" value="agents"><input type="hidden" name="agent_id" value="<?= (int)$agent['id'] ?>"><div class="field-grid"><label class="field"><span>Name</span><input name="name" value="<?= e($agent['name']) ?>" required></label><label class="field"><span>Email</span><input type="email" name="email" value="<?= e($agent['email']) ?>" required></label></div><div class="field-grid"><label class="field"><span>Code</span><input name="referral_code" value="<?= e($agent['referral_code']) ?>" required></label><label class="field"><span>Commission %</span><input type="number" name="commission_percent" min="0" max="50" step="0.01" value="<?= e($agent['commission_percent']) ?>"></label></div><label class="field"><span>Status</span><select name="status"><option value="active" <?= $agent['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="blocked" <?= $agent['status'] === 'blocked' ? 'selected' : '' ?>>Blocked</option></select></label><button class="button button--ghost button--small">Save agent</button></form><small class="agent-link">Registration link: /en/sign-up/?ref=<?= e(rawurlencode($agent['referral_code'])) ?></small></article><?php endforeach; ?></section>
    <section class="admin-card"><div class="admin-card-head"><div><span class="eyebrow">Referral history</span><h2>Latest assigned users</h2></div></div><div class="admin-table-wrap"><table><thead><tr><th>Joined</th><th>Agent</th><th>Code</th><th>User</th></tr></thead><tbody><?php if (!$referrals): ?><tr><td colspan="4" class="table-empty">No referred users yet.</td></tr><?php endif; ?><?php foreach ($referrals as $row): ?><tr><td><?= e(date('d M Y H:i', strtotime($row['created_at']))) ?></td><td><strong><?= e($row['agent_name']) ?></strong></td><td><code><?= e($row['referral_code']) ?></code></td><td><strong><?= e($row['user_name']) ?></strong><small><?= e($row['email']) ?></small></td></tr><?php endforeach; ?></tbody></table></div></section>
    <?php
}

function render_admin_agents_page(): void
{
    $agents = db()->query("SELECT a.*,COUNT(DISTINCT r.user_id) referral_count,SUM(CASE WHEN r.reward_status='credited' THEN 1 ELSE 0 END) qualified_count,COALESCE(SUM(r.reward_amount),0) reward_total,COALESCE(SUM(tv.live_volume),0) trade_volume FROM agents a LEFT JOIN agent_referrals r ON r.agent_id=a.id LEFT JOIN (SELECT user_id,SUM(amount) live_volume FROM trades WHERE account_type='live' GROUP BY user_id) tv ON tv.user_id=r.user_id GROUP BY a.id ORDER BY a.id DESC")->fetchAll();
    $users = db()->query("SELECT id,name,email FROM users WHERE role='user' ORDER BY id DESC LIMIT 500")->fetchAll();
    $referrals = db()->query("SELECT r.created_at,r.reward_status,r.reward_amount,r.credited_at,r.qualified_fund_request_id,a.name agent_name,a.referral_code,u.name user_name,u.email FROM agent_referrals r JOIN agents a ON a.id=r.agent_id JOIN users u ON u.id=r.user_id ORDER BY r.id DESC LIMIT 150")->fetchAll();
    $enabled = (bool)setting('referral_program_enabled', true);
    $reward = (float)setting('referral_reward_amount', 5);
    $minimum = (float)setting('referral_minimum_deposit', 10);
    $automaticProfiles = count(array_filter($agents, static fn (array $agent): bool => (int)$agent['user_id'] > 0));
    ?>
    <section class="admin-page-intro"><div><span class="eyebrow">Affiliate operations</span><h2>Affiliate &amp; referral tracking</h2><p>Every user receives an active referral code automatically. Administrator approval is not required; block is reserved for abuse control.</p></div></section>

    <section class="admin-grid admin-grid--agents">
        <article class="admin-card">
            <div class="admin-card-head"><div><span class="eyebrow">Reward rule</span><h2>Affiliate bonus settings</h2></div><span class="status status--<?= $enabled ? 'active' : 'blocked' ?>"><?= $enabled ? 'Enabled' : 'Paused' ?></span></div>
            <form method="post" class="admin-editor-form">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="referral_settings_save"><input type="hidden" name="return_page" value="agents">
                <label class="setting-toggle"><span><strong>Referral rewards enabled</strong><small>Links and registrations keep working while a paused reward is not credited.</small></span><span class="switch"><input type="checkbox" name="referral_program_enabled" value="1" <?= $enabled ? 'checked' : '' ?>><span></span></span></label>
                <div class="field-grid"><label class="field"><span>Inviter bonus</span><input type="number" name="referral_reward_amount" min="0" max="1000000" step="0.01" value="<?= e(number_format($reward, 2, '.', '')) ?>" required></label><label class="field"><span>Minimum approved deposit</span><input type="number" name="referral_minimum_deposit" min="0.01" max="100000000" step="0.01" value="<?= e(number_format($minimum, 2, '.', '')) ?>" required></label></div>
                <p class="admin-form-note">The inviter is credited once, after the invited user's first administrator-approved deposit that meets this minimum.</p>
                <button class="button button--primary">Save reward settings</button>
            </form>
        </article>

        <article class="admin-card">
            <div class="admin-card-head"><div><span class="eyebrow">Automatic access</span><h2><?= number_format($automaticProfiles) ?> user profiles</h2></div></div>
            <div class="admin-info-banner"><span>Registration creates the user's own Affiliate profile immediately. Existing users are backfilled during the update and on first Affiliate visit.</span></div>
            <form method="post" class="admin-editor-form">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="agent_assign"><input type="hidden" name="return_page" value="agents">
                <label class="field"><span>Manual referrer correction</span><select name="agent_id" required><option value="">Choose affiliate</option><?php foreach ($agents as $agent): if ($agent['status'] !== 'active') continue; ?><option value="<?= (int)$agent['id'] ?>"><?= e($agent['name']) ?> · <?= e($agent['referral_code']) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Invited user</span><select name="user_id" required><option value="">Choose user</option><?php foreach ($users as $item): ?><option value="<?= (int)$item['id'] ?>"><?= e($item['name']) ?> · <?= e($item['email']) ?></option><?php endforeach; ?></select></label>
                <button class="button button--ghost">Save correction</button>
            </form>
        </article>
    </section>

    <section class="admin-card">
        <div class="admin-card-head"><div><span class="eyebrow">Optional external partner</span><h2>Add manual affiliate</h2></div><span class="admin-lock-note">Users are already automatic</span></div>
        <form method="post" class="admin-editor-form">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="agent_save"><input type="hidden" name="return_page" value="agents">
            <div class="field-grid field-grid--3"><label class="field"><span>Name</span><input name="name" minlength="2" maxlength="80" required></label><label class="field"><span>Email</span><input type="email" name="email" required></label><label class="field"><span>Referral code</span><input name="referral_code" pattern="[A-Za-z0-9_-]{3,32}" required></label></div>
            <input type="hidden" name="commission_percent" value="0"><input type="hidden" name="status" value="active"><button class="button button--ghost button--small">Add manual affiliate</button>
        </form>
    </section>

    <section class="agent-admin-grid">
        <?php foreach ($agents as $agent): ?>
            <article class="admin-card agent-card">
                <div class="admin-card-head"><div><span class="eyebrow"><?= e($agent['referral_code']) ?></span><h2><?= e($agent['name']) ?></h2></div><span class="status status--<?= e($agent['status']) ?>"><?= e($agent['status']) ?></span></div>
                <div class="agent-stats"><span><small>Invited</small><strong><?= number_format((int)$agent['referral_count']) ?></strong></span><span><small>Qualified</small><strong><?= number_format((int)$agent['qualified_count']) ?></strong></span><span><small>Bonus</small><strong><?= e(money((float)$agent['reward_total'])) ?></strong></span><span><small>Live volume</small><strong><?= e(money((float)$agent['trade_volume'])) ?></strong></span></div>
                <form method="post" class="admin-editor-form">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="agent_save"><input type="hidden" name="return_page" value="agents"><input type="hidden" name="agent_id" value="<?= (int)$agent['id'] ?>">
                    <div class="field-grid"><label class="field"><span>Name</span><input name="name" value="<?= e($agent['name']) ?>" required></label><label class="field"><span>Email</span><input type="email" name="email" value="<?= e($agent['email']) ?>" required></label></div>
                    <div class="field-grid"><label class="field"><span>Code</span><input name="referral_code" value="<?= e($agent['referral_code']) ?>" required></label><label class="field"><span>Status</span><select name="status"><option value="active" <?= $agent['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="blocked" <?= $agent['status'] === 'blocked' ? 'selected' : '' ?>>Blocked</option></select></label></div>
                    <input type="hidden" name="commission_percent" value="<?= e($agent['commission_percent']) ?>"><button class="button button--ghost button--small">Save affiliate</button>
                </form>
                <small class="agent-link"><?= (int)$agent['user_id'] > 0 ? 'Automatic user profile' : 'Manual partner' ?> · /en/sign-up/?ref=<?= e(rawurlencode((string)$agent['referral_code'])) ?></small>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="admin-card"><div class="admin-card-head"><div><span class="eyebrow">Referral history</span><h2>Latest invited users</h2></div></div><div class="admin-table-wrap"><table><thead><tr><th>Joined</th><th>Affiliate</th><th>Invited user</th><th>Reward state</th><th>Bonus</th></tr></thead><tbody>
        <?php if (!$referrals): ?><tr><td colspan="5" class="table-empty">No invited users yet.</td></tr><?php endif; ?>
        <?php foreach ($referrals as $row): ?><tr><td><?= e(date('d M Y H:i', strtotime((string)$row['created_at']))) ?></td><td><strong><?= e($row['agent_name']) ?></strong><small><?= e($row['referral_code']) ?></small></td><td><strong><?= e($row['user_name']) ?></strong><small><?= e($row['email']) ?></small></td><td><span class="status status--<?= $row['reward_status'] === 'credited' ? 'active' : 'open' ?>"><?= e(ucfirst((string)$row['reward_status'])) ?></span><?php if ($row['qualified_fund_request_id']): ?><small>Deposit #<?= (int)$row['qualified_fund_request_id'] ?></small><?php endif; ?></td><td class="<?= (float)$row['reward_amount'] > 0 ? 'amount-positive' : '' ?>"><?= e(money((float)$row['reward_amount'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
    <?php
}

function render_admin_notifications_page(): void
{
    $users = db()->query("SELECT id,name,email FROM users WHERE role='user' AND status='active' ORDER BY id DESC LIMIT 500")->fetchAll();
    $notifications = db()->query('SELECT n.*,u.name user_name,u.email user_email,a.name creator_name FROM user_notifications n LEFT JOIN users u ON u.id=n.user_id LEFT JOIN users a ON a.id=n.created_by ORDER BY n.id DESC LIMIT 200')->fetchAll();
    ?>
    <section class="admin-grid admin-grid--notifications"><article class="admin-card"><div class="admin-card-head"><div><span class="eyebrow">Message center</span><h2>Publish notification</h2></div></div><form method="post" class="admin-editor-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="notification_create"><input type="hidden" name="return_page" value="notifications"><label class="field"><span>Audience</span><select name="user_id"><option value="0">All active users</option><?php foreach ($users as $user): ?><option value="<?= (int)$user['id'] ?>"><?= e($user['name']) ?> · <?= e($user['email']) ?></option><?php endforeach; ?></select></label><label class="field"><span>Title</span><input name="title" minlength="3" maxlength="120" required></label><label class="field"><span>Message</span><textarea name="message" minlength="5" maxlength="2000" rows="5" required></textarea></label><div class="field-grid"><label class="field"><span>Style</span><select name="level"><option value="info">Information</option><option value="success">Success</option><option value="warning">Warning</option></select></label><label class="field"><span>Starts at</span><input type="datetime-local" name="starts_at"></label></div><label class="field"><span>Expires at <small>(optional)</small></span><input type="datetime-local" name="expires_at"></label><button class="button button--primary">Publish notification</button></form></article><article class="admin-card"><div class="admin-card-head"><div><span class="eyebrow">Delivery</span><h2>How it works</h2></div></div><div class="admin-info-banner"><span>Active notifications appear inside the signed-in trade room. A direct user message takes priority over a broadcast; expiry and disable controls stop delivery immediately.</span></div></article></section>
    <section class="notification-admin-grid"><?php if (!$notifications): ?><div class="admin-empty"><strong>No notifications yet</strong><span>Publish the first message above.</span></div><?php endif; ?><?php foreach ($notifications as $row): ?><article class="admin-card notification-card"><header><div><span class="eyebrow"><?= $row['user_id'] ? 'Direct user' : 'All users' ?></span><h3><?= e($row['title']) ?></h3></div><span class="status status--<?= $row['is_active'] ? 'active' : 'blocked' ?>"><?= $row['is_active'] ? 'Active' : 'Disabled' ?></span></header><p><?= nl2br(e($row['message'])) ?></p><small><?= $row['user_id'] ? e($row['user_name'] . ' · ' . $row['user_email']) : 'Broadcast' ?> · <?= e(date('d M Y H:i', strtotime($row['created_at']))) ?></small><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="notification_toggle"><input type="hidden" name="return_page" value="notifications"><input type="hidden" name="notification_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="is_active" value="<?= $row['is_active'] ? '0' : '1' ?>"><button class="button button--ghost button--small"><?= $row['is_active'] ? 'Disable' : 'Enable' ?></button></form></article><?php endforeach; ?></section>
    <?php
}

function render_admin_branding_page(): void
{
    $customLogo = trim((string)setting('logo_filename', ''));
    ?>
    <section class="admin-grid admin-grid--branding"><article class="admin-card branding-preview"><div class="admin-card-head"><div><span class="eyebrow">Current identity</span><h2>Logo preview</h2></div></div><div class="branding-logo-preview"><?= brand_logo(false) ?></div><p>The default mark remains active until a custom JPG, PNG, or WebP image is uploaded. Platform name and site text stay under Settings.</p><a class="button button--ghost button--small" href="/admin/?page=settings">Open identity settings</a></article><article class="admin-card"><div class="admin-card-head"><div><span class="eyebrow">Site asset</span><h2><?= $customLogo !== '' ? 'Replace logo' : 'Upload logo' ?></h2></div></div><form method="post" enctype="multipart/form-data" class="admin-editor-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="site_logo_save"><input type="hidden" name="return_page" value="branding"><label class="field"><span>Logo image</span><input type="file" name="site_logo" accept="image/jpeg,image/png,image/webp" <?= $customLogo === '' ? 'required' : '' ?>></label><?php if ($customLogo !== ''): ?><label class="check-field"><input type="checkbox" name="remove_logo" value="1"><span>Remove custom logo and restore the default mark</span></label><?php endif; ?><button class="button button--primary">Save logo</button></form></article></section>
    <?php
}

// ---------------------------------------------------------------------------
// Trading Control System
// ---------------------------------------------------------------------------

function admin_handle_trading_control_action(array $admin, string $action): ?string
{
    if ($action === 'trading_control_save') {
        require_admin_capability($admin, 'manage_markets');
        $assetId = (int)($_POST['asset_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        $direction = (string)($_POST['direction'] ?? 'none');
        $winRateRaw = trim((string)($_POST['win_rate'] ?? ''));
        $expiresAt = trim((string)($_POST['expires_at'] ?? ''));
        $label = trim((string)($_POST['label'] ?? ''));
        $controlId = (int)($_POST['control_id'] ?? 0);

        if (!in_array($direction, ['up', 'down', 'none'], true)) {
            throw new InvalidArgumentException('Direction must be Up, Down, or None.');
        }
        $winRate = null;
        if ($winRateRaw !== '') {
            $winRate = round((float)$winRateRaw, 2);
            if ($winRate < 0 || $winRate > 100) {
                throw new InvalidArgumentException('Win rate must be between 0 and 100.');
            }
        } elseif ($direction === 'up' || $direction === 'down') {
            $winRate = 100.00;
        }
        $expiresAtValue = null;
        if ($expiresAt !== '') {
            $ts = strtotime($expiresAt);
            if ($ts === false || $ts <= time()) {
                throw new InvalidArgumentException('Expiry must be a future date and time.');
            }
            $expiresAtValue = date('Y-m-d H:i:s', $ts);
        }
        if (strlen($label) > 120) {
            throw new InvalidArgumentException('Label must be 120 characters or less.');
        }
        // Validate optional asset_id and user_id
        if ($assetId > 0) {
            $check = db()->prepare('SELECT COUNT(*) FROM market_assets WHERE id=?');
            $check->execute([$assetId]);
            if (!(int)$check->fetchColumn()) {
                throw new InvalidArgumentException('Selected market asset was not found.');
            }
        }
        if ($userId > 0) {
            $check = db()->prepare("SELECT COUNT(*) FROM users WHERE id=? AND role='user'");
            $check->execute([$userId]);
            if (!(int)$check->fetchColumn()) {
                throw new InvalidArgumentException('Selected user was not found.');
            }
        }

        if ($controlId > 0) {
            $stmt = db()->prepare('UPDATE trading_controls SET asset_id=?,user_id=?,direction=?,win_rate=?,expires_at=?,label=?,is_active=1,updated_at=NOW() WHERE id=?');
            $stmt->execute([$assetId > 0 ? $assetId : null, $userId > 0 ? $userId : null, $direction, $winRate, $expiresAtValue, $label !== '' ? $label : null, $controlId]);
        } else {
            $stmt = db()->prepare('INSERT INTO trading_controls (asset_id,user_id,direction,win_rate,expires_at,label,is_active,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,1,?,NOW(),NOW())');
            $stmt->execute([$assetId > 0 ? $assetId : null, $userId > 0 ? $userId : null, $direction, $winRate, $expiresAtValue, $label !== '' ? $label : null, (int)$admin['id']]);
            $controlId = (int)db()->lastInsertId();
        }
        admin_audit((int)$admin['id'], 'trading_control_saved', 'trading_control', $controlId, ['direction' => $direction, 'win_rate' => $winRate, 'asset_id' => $assetId ?: null, 'user_id' => $userId ?: null]);
        return 'Trading control saved.';
    }

    if ($action === 'trading_control_delete') {
        require_admin_capability($admin, 'manage_markets');
        $controlId = (int)($_POST['control_id'] ?? 0);
        if ($controlId < 1) {
            throw new InvalidArgumentException('Invalid control ID.');
        }
        db()->prepare('DELETE FROM trading_controls WHERE id=?')->execute([$controlId]);
        admin_audit((int)$admin['id'], 'trading_control_deleted', 'trading_control', $controlId);
        return 'Trading control deleted.';
    }

    if ($action === 'trading_control_clear_all') {
        require_admin_capability($admin, 'manage_markets');
        $count = (int)db()->query('SELECT COUNT(*) FROM trading_controls')->fetchColumn();
        db()->exec('DELETE FROM trading_controls');
        admin_audit((int)$admin['id'], 'trading_controls_cleared', 'trading_control', null, ['removed' => $count]);
        return 'All trading controls cleared (' . $count . ' removed).';
    }

    if ($action === 'trading_control_toggle') {
        require_admin_capability($admin, 'manage_markets');
        $controlId = (int)($_POST['control_id'] ?? 0);
        if ($controlId < 1) {
            throw new InvalidArgumentException('Invalid control ID.');
        }
        $row = db()->prepare('SELECT id, is_active FROM trading_controls WHERE id=?');
        $row->execute([$controlId]);
        $current = $row->fetch();
        if (!$current) {
            throw new RuntimeException('Control not found.');
        }
        $newState = (int)$current['is_active'] === 1 ? 0 : 1;
        db()->prepare('UPDATE trading_controls SET is_active=?, updated_at=NOW() WHERE id=?')->execute([$newState, $controlId]);
        admin_audit((int)$admin['id'], 'trading_control_toggled', 'trading_control', $controlId, ['is_active' => $newState]);
        return 'Trading control ' . ($newState ? 'activated' : 'paused') . '.';
    }

    if ($action === 'user_session_save') {
        require_admin_capability($admin, 'manage_markets');
        $userId      = (int)($_POST['session_user_id'] ?? 0);
        $assetId     = (int)($_POST['session_asset_id'] ?? 0);
        $isBlocked   = isset($_POST['is_blocked']) ? 1 : 0;
        $blockReason = trim((string)($_POST['block_reason'] ?? ''));
        $payoutRaw   = trim((string)($_POST['payout_override'] ?? ''));
        $expiresAt   = trim((string)($_POST['session_expires_at'] ?? ''));
        $sessionId   = (int)($_POST['session_id'] ?? 0);

        if ($userId < 1) {
            throw new InvalidArgumentException('Select a user.');
        }
        $checkUser = db()->prepare("SELECT COUNT(*) FROM users WHERE id=? AND role='user'");
        $checkUser->execute([$userId]);
        if (!(int)$checkUser->fetchColumn()) {
            throw new InvalidArgumentException('User not found.');
        }
        $payoutOverride = null;
        if ($payoutRaw !== '') {
            $payoutOverride = round((float)$payoutRaw, 2);
            if ($payoutOverride < 0 || $payoutOverride > 100) {
                throw new InvalidArgumentException('Payout override must be 0–100%.');
            }
        }
        $expiresAtValue = null;
        if ($expiresAt !== '') {
            $ts = strtotime($expiresAt);
            if ($ts === false || $ts <= time()) {
                throw new InvalidArgumentException('Expiry must be a future date and time.');
            }
            $expiresAtValue = date('Y-m-d H:i:s', $ts);
        }
        if (strlen($blockReason) > 255) {
            throw new InvalidArgumentException('Block reason must be 255 characters or less.');
        }

        if ($sessionId > 0) {
            db()->prepare('UPDATE user_trading_sessions SET asset_id=?,payout_override=?,is_blocked=?,block_reason=?,expires_at=?,updated_at=NOW() WHERE id=?')
                ->execute([$assetId > 0 ? $assetId : null, $payoutOverride, $isBlocked, $blockReason !== '' ? $blockReason : null, $expiresAtValue, $sessionId]);
        } else {
            db()->prepare('INSERT INTO user_trading_sessions (user_id,asset_id,payout_override,is_blocked,block_reason,expires_at,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())')
                ->execute([$userId, $assetId > 0 ? $assetId : null, $payoutOverride, $isBlocked, $blockReason !== '' ? $blockReason : null, $expiresAtValue, (int)$admin['id']]);
            $sessionId = (int)db()->lastInsertId();
        }
        admin_audit((int)$admin['id'], 'user_session_saved', 'user_trading_session', $sessionId, ['user_id' => $userId, 'is_blocked' => $isBlocked, 'payout_override' => $payoutOverride]);
        return 'User trading session saved.';
    }

    if ($action === 'user_session_delete') {
        require_admin_capability($admin, 'manage_markets');
        $sessionId = (int)($_POST['session_id'] ?? 0);
        if ($sessionId < 1) {
            throw new InvalidArgumentException('Invalid session ID.');
        }
        db()->prepare('DELETE FROM user_trading_sessions WHERE id=?')->execute([$sessionId]);
        admin_audit((int)$admin['id'], 'user_session_deleted', 'user_trading_session', $sessionId);
        return 'User trading session removed.';
    }

    if ($action === 'user_session_clear_all') {
        require_admin_capability($admin, 'manage_markets');
        $count = (int)db()->query('SELECT COUNT(*) FROM user_trading_sessions')->fetchColumn();
        db()->exec('DELETE FROM user_trading_sessions');
        admin_audit((int)$admin['id'], 'user_sessions_cleared', 'user_trading_session', null, ['removed' => $count]);
        return 'All user sessions cleared (' . $count . ' removed).';
    }

    return null;
}

function render_admin_trading_control_page(): void
{
    $controls = db()->query(
        'SELECT tc.*,a.symbol,a.display_name asset_name,u.name user_name,u.email user_email,ab.name creator_name
         FROM trading_controls tc
         LEFT JOIN market_assets a ON a.id=tc.asset_id
         LEFT JOIN users u ON u.id=tc.user_id
         LEFT JOIN users ab ON ab.id=tc.created_by
         ORDER BY tc.id DESC LIMIT 200'
    )->fetchAll();

    // Load user trading sessions safely (table may not exist on old installs)
    try {
        $sessions = db()->query(
            'SELECT s.*,u.name user_name,u.email user_email,a.symbol asset_symbol,a.display_name asset_name,ab.name creator_name
             FROM user_trading_sessions s
             JOIN users u ON u.id=s.user_id
             LEFT JOIN market_assets a ON a.id=s.asset_id
             LEFT JOIN users ab ON ab.id=s.created_by
             ORDER BY s.id DESC LIMIT 200'
        )->fetchAll();
    } catch (Throwable $ignored) {
        $sessions = [];
    }

    $assets = db()->query('SELECT id,symbol,display_name FROM market_assets WHERE is_active=1 ORDER BY sort_order ASC,display_name ASC')->fetchAll();
    $users = db()->query("SELECT id,name,email FROM users WHERE role='user' AND status='active' ORDER BY id DESC LIMIT 500")->fetchAll();

    $activeCount = 0;
    $blockedCount = 0;
    $now = time();
    foreach ($controls as $c) {
        if ($c['is_active'] && (!$c['expires_at'] || strtotime((string)$c['expires_at']) > $now)) {
            $activeCount++;
        }
    }
    foreach ($sessions as $s) {
        if ((int)$s['is_blocked'] === 1 && (!$s['expires_at'] || strtotime((string)$s['expires_at']) > $now)) {
            $blockedCount++;
        }
    }
    ?>

    <section class="admin-page-intro tc-intro">
        <div>
            <span class="eyebrow">Outcome management</span>
            <h2><?= icon('sliders',22) ?> Trading Control Center</h2>
            <p>Force trade outcomes by direction (UP/DOWN) or probability (win rate %). Per-user payout overrides and trading blocks are available below. Controls are layered — the most specific rule applies. All actions are audit logged.</p>
        </div>
        <div class="tc-intro-stats">
            <span class="tc-stat <?= $activeCount > 0 ? 'tc-stat--active' : '' ?>">
                <?= icon('activity',18) ?>
                <span>
                    <strong><?= $activeCount ?></strong>
                    <small>Active controls</small>
                </span>
            </span>
            <?php if ($blockedCount > 0): ?>
            <span class="tc-stat" style="border-color:rgba(255,100,89,.3);background:rgba(240,100,89,.07);color:#f06459;">
                <?= icon('lock',18) ?>
                <span>
                    <strong><?= $blockedCount ?></strong>
                    <small>Blocked users</small>
                </span>
            </span>
            <?php endif; ?>
            <?php if ($activeCount > 0): ?>
            <form method="post" class="tc-clear-all-form">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="trading_control_clear_all">
                <input type="hidden" name="return_page" value="trading">
                <button class="button button--danger button--small" onclick="return confirm('Clear ALL controls? This cannot be undone.')">
                    <?= icon('trash',14) ?> Clear all
                </button>
            </form>
            <?php endif; ?>
        </div>
    </section>

    <section class="admin-grid admin-grid--tc">

        <!-- CREATE / ADJUST CONTROL CARD -->
        <article class="admin-card tc-create-card">
            <div class="admin-card-head" style="display:flex;justify-content:space-between;align-items:center;">
                <div><span class="eyebrow" id="tc-form-eyebrow">New rule</span><h2 id="tc-form-title"><?= icon('plus',18) ?> Add control</h2></div>
                <button type="button" class="button button--ghost button--small" id="tc-cancel-edit-btn" style="display:none;" onclick="resetTcForm()">
                    <?= icon('x', 14) ?> Cancel edit
                </button>
            </div>
            <form method="post" class="admin-editor-form tc-create-form" id="tc-create-form">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="trading_control_save">
                <input type="hidden" name="return_page" value="trading">
                <input type="hidden" name="control_id" id="tc-control-id" value="0">

                <!-- TARGETING -->
                <div class="tc-section-label"><?= icon('users',15) ?> Target</div>
                <div class="field-grid">
                    <label class="field">
                        <span>Market asset <small>(blank = all)</small></span>
                        <select name="asset_id" id="tc-asset-id">
                            <option value="0">All assets</option>
                            <?php foreach ($assets as $a): ?>
                            <option value="<?= (int)$a['id'] ?>"><?= e($a['symbol']) ?> — <?= e($a['display_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field">
                        <span>User <small>(blank = all users)</small></span>
                        <select name="user_id" id="tc-user-id">
                            <option value="0">All users</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?> — <?= e($u['email']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <!-- DIRECTION CONTROL -->
                <div class="tc-section-label"><?= icon('trend-up',15) ?> Forced direction</div>
                <div class="tc-direction-group" id="tc-dir-group">
                    <label class="tc-dir-btn tc-dir-btn--up">
                        <input type="radio" name="direction" value="up" id="dir-up">
                        <span><?= icon('arrow-up',18) ?> UP wins</span>
                    </label>
                    <label class="tc-dir-btn tc-dir-btn--none">
                        <input type="radio" name="direction" value="none" id="dir-none" checked>
                        <span><?= icon('minus',18) ?> Natural</span>
                    </label>
                    <label class="tc-dir-btn tc-dir-btn--down">
                        <input type="radio" name="direction" value="down" id="dir-down">
                        <span><?= icon('arrow-down',18) ?> DOWN wins</span>
                    </label>
                </div>

                <!-- WIN RATE CONTROL -->
                <div class="tc-winrate-row" id="tc-winrate-row">
                    <div class="tc-section-label"><?= icon('percent',15) ?> Win rate probability <small id="tc-wr-desc">— applies to forced direction or natural trading</small></div>
                    <label class="field">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                            <span>Win probability % <small>(blank = default 100% for forced or natural market)</small></span>
                            <span id="win-rate-label" class="tc-wr-badge">— Natural</span>
                        </div>
                        <div class="tc-slider-wrap">
                            <input type="range" id="win-rate-slider" min="0" max="100" step="1" value="50">
                            <input type="number" id="win-rate-num" name="win_rate" min="0" max="100" step="0.1" placeholder="e.g. 50">
                            <button type="button" class="tc-preset-btn tc-preset-btn--clear" id="tc-wr-clear-btn" title="Clear win rate override">Clear</button>
                        </div>
                        <div class="tc-presets">
                            <span class="tc-presets-label">Presets:</span>
                            <button type="button" class="tc-preset-btn" data-val="10">10%</button>
                            <button type="button" class="tc-preset-btn" data-val="25">25%</button>
                            <button type="button" class="tc-preset-btn" data-val="40">40%</button>
                            <button type="button" class="tc-preset-btn" data-val="50">50%</button>
                            <button type="button" class="tc-preset-btn" data-val="70">70%</button>
                            <button type="button" class="tc-preset-btn" data-val="80">80%</button>
                            <button type="button" class="tc-preset-btn" data-val="90">90%</button>
                            <button type="button" class="tc-preset-btn" data-val="100">100%</button>
                        </div>
                    </label>
                </div>

                <!-- EXPIRY + LABEL -->
                <div class="field-grid">
                    <label class="field">
                        <span>Expires at <small>(blank = never)</small></span>
                        <input type="datetime-local" name="expires_at" id="tc-expires-at">
                    </label>
                    <label class="field">
                        <span>Admin label <small>(optional)</small></span>
                        <input name="label" id="tc-label" maxlength="120" placeholder="e.g. Campaign test 1">
                    </label>
                </div>

                <button class="button button--primary button--wide" id="tc-save-btn">
                    <?= icon('check',16) ?> Save control
                </button>
            </form>
        </article>

        <!-- GUIDE CARD -->
        <article class="admin-card tc-guide-card">
            <div class="admin-card-head"><div><span class="eyebrow">How it works</span><h2><?= icon('info',18) ?> Control logic</h2></div></div>
            <div class="tc-guide-list">
                <div><span class="tc-guide-icon tc-guide-icon--up"><?= icon('arrow-up',16) ?></span><div><strong>UP wins</strong><p>Any trade bet on UP will win; any trade bet on DOWN will lose. Price chart is unaffected.</p></div></div>
                <div><span class="tc-guide-icon tc-guide-icon--down"><?= icon('arrow-down',16) ?></span><div><strong>DOWN wins</strong><p>Any trade bet on DOWN will win; any trade bet on UP will lose.</p></div></div>
                <div><span class="tc-guide-icon"><?= icon('percent',16) ?></span><div><strong>Win rate</strong><p>Set a % probability (e.g. 30 = 30% of trades win). Uses deterministic seeding per trade — same trade always gets the same result.</p></div></div>
                <div><span class="tc-guide-icon"><?= icon('users',16) ?></span><div><strong>Targeting</strong><p>User+Asset &gt; Asset only &gt; User only &gt; Global. Most specific rule wins.</p></div></div>
                <div><span class="tc-guide-icon"><?= icon('clock',16) ?></span><div><strong>Expiry</strong><p>Controls auto-deactivate when expiry passes. Natural settlement resumes automatically.</p></div></div>
            </div>
        </article>
    </section>

    <!-- ACTIVE CONTROLS TABLE -->
    <section class="admin-card tc-table-card">
        <div class="admin-card-head">
            <div><span class="eyebrow">Active rules</span><h2><?= count($controls) ?> control<?= count($controls) !== 1 ? 's' : '' ?></h2></div>
            <?php if ($activeCount > 0): ?>
            <span class="status status--active"><?= $activeCount ?> active</span>
            <?php endif; ?>
        </div>
        <?php if (!$controls): ?>
        <div class="admin-empty">
            <?= icon('check',28) ?>
            <strong>No controls set</strong>
            <span>Trades settle naturally from market prices.</span>
        </div>
        <?php else: ?>
        <div class="admin-table-wrap">
        <table class="tc-table">
            <thead><tr>
                <th>ID</th>
                <th>Asset</th>
                <th>User</th>
                <th>Direction</th>
                <th>Win Rate</th>
                <th>Status</th>
                <th>Expires</th>
                <th>Label</th>
                <th>Created by</th>
                <th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($controls as $c):
                $isExpired = $c['expires_at'] && strtotime((string)$c['expires_at']) <= $now;
                $isLive = $c['is_active'] && !$isExpired;
            ?>
            <tr class="<?= $isLive ? 'tc-row--active' : 'tc-row--inactive' ?>">
                <td><code>#<?= (int)$c['id'] ?></code></td>
                <td><?= $c['asset_id'] ? '<strong>' . e($c['symbol']) . '</strong><small>' . e($c['asset_name']) . '</small>' : '<span class="tc-all-badge">All assets</span>' ?></td>
                <td><?= $c['user_id'] ? '<strong>' . e($c['user_name']) . '</strong><small>' . e($c['user_email']) . '</small>' : '<span class="tc-all-badge">All users</span>' ?></td>
                <td>
                    <?php if ($c['direction'] === 'up'): ?>
                        <span class="tc-dir tc-dir--up"><?= icon('arrow-up',14) ?> UP wins</span>
                    <?php elseif ($c['direction'] === 'down'): ?>
                        <span class="tc-dir tc-dir--down"><?= icon('arrow-down',14) ?> DOWN wins</span>
                    <?php else: ?>
                        <span class="tc-dir tc-dir--none"><?= icon('minus',14) ?> Natural</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($c['win_rate'] !== null): ?>
                        <span class="tc-wr-pill"><?= number_format((float)$c['win_rate'], 1) ?>%</span>
                    <?php elseif ($c['direction'] === 'up' || $c['direction'] === 'down'): ?>
                        <span class="tc-wr-pill">100.0%</span>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td><span class="status status--<?= $isLive ? 'active' : 'blocked' ?>"><?= $isLive ? 'Live' : ($isExpired ? 'Expired' : 'Disabled') ?></span></td>
                <td><?= $c['expires_at'] ? e(date('d M Y H:i', strtotime((string)$c['expires_at']))) : '<span class="muted">Never</span>' ?></td>
                <td><?= $c['label'] ? e($c['label']) : '<span class="muted">—</span>' ?></td>
                <td><?= $c['creator_name'] ? e($c['creator_name']) : '<span class="muted">System</span>' ?></td>
                <td>
                    <div style="display:flex;gap:5px;align-items:center;">
                        <button type="button" class="button button--ghost button--small tc-edit-btn" title="Adjust / Edit this control"
                                data-id="<?= (int)$c['id'] ?>"
                                data-asset="<?= (int)($c['asset_id'] ?? 0) ?>"
                                data-user="<?= (int)($c['user_id'] ?? 0) ?>"
                                data-dir="<?= e($c['direction']) ?>"
                                data-wr="<?= $c['win_rate'] !== null ? e((string)$c['win_rate']) : '' ?>"
                                data-expires="<?= $c['expires_at'] ? e(date('Y-m-d\TH:i', strtotime((string)$c['expires_at']))) : '' ?>"
                                data-label="<?= e($c['label'] ?? '') ?>">
                            <?= icon('edit', 13) ?>
                        </button>
                        <form method="post" style="display:contents;">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="trading_control_toggle">
                            <input type="hidden" name="return_page" value="trading">
                            <input type="hidden" name="control_id" value="<?= (int)$c['id'] ?>">
                            <button class="button button--ghost button--small" title="<?= $c['is_active'] ? 'Pause' : 'Activate' ?>">
                                <?= icon($c['is_active'] ? 'pause' : 'play', 13) ?>
                            </button>
                        </form>
                        <form method="post" class="tc-delete-form" style="display:contents;">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="trading_control_delete">
                            <input type="hidden" name="return_page" value="trading">
                            <input type="hidden" name="control_id" value="<?= (int)$c['id'] ?>">
                            <button class="button button--danger button--small" onclick="return confirm('Delete this control?')">
                                <?= icon('trash',13) ?>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>

    <!-- USER TRADING SESSIONS (Block / Payout Override) -->
    <section class="admin-grid admin-grid--tc" style="margin-top:18px;">
        <!-- CREATE SESSION FORM -->
        <article class="admin-card">
            <div class="admin-card-head">
                <div><span class="eyebrow">Per-user control</span><h2><?= icon('user',18) ?> User session</h2></div>
            </div>
            <form method="post" class="admin-editor-form">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="user_session_save">
                <input type="hidden" name="return_page" value="trading">

                <label class="field">
                    <span>User</span>
                    <select name="session_user_id" required>
                        <option value="0">— Select user —</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?> — <?= e($u['email']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span>Asset <small>(blank = all)</small></span>
                    <select name="session_asset_id">
                        <option value="0">All assets</option>
                        <?php foreach ($assets as $a): ?>
                        <option value="<?= (int)$a['id'] ?>"><?= e($a['symbol']) ?> — <?= e($a['display_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="tc-section-label"><?= icon('percent',15) ?> Payout override</div>
                <label class="field">
                    <span>Custom payout % <small>(blank = use asset default)</small></span>
                    <input type="number" name="payout_override" min="0" max="100" step="0.01" placeholder="e.g. 50">
                </label>

                <div class="tc-section-label"><?= icon('lock',15) ?> Trading block</div>
                <label class="setting-toggle" style="margin-bottom:10px;">
                    <span><strong>Block this user from placing trades</strong><small>Only affects live account trades.</small></span>
                    <span class="switch"><input type="checkbox" name="is_blocked" value="1"><span></span></span>
                </label>
                <label class="field">
                    <span>Block reason <small>(optional, shown to user)</small></span>
                    <input name="block_reason" maxlength="255" placeholder="e.g. Suspicious activity review">
                </label>

                <label class="field">
                    <span>Expires at <small>(blank = permanent)</small></span>
                    <input type="datetime-local" name="session_expires_at">
                </label>

                <button class="button button--primary button--wide">
                    <?= icon('check',16) ?> Save user session
                </button>
            </form>
        </article>

        <!-- SESSIONS TABLE -->
        <article class="admin-card">
            <div class="admin-card-head">
                <div><span class="eyebrow">User overrides</span><h2><?= count($sessions) ?> session<?= count($sessions) !== 1 ? 's' : '' ?></h2></div>
                <?php if ($blockedCount > 0): ?>
                <span class="status status--blocked"><?= $blockedCount ?> blocked</span>
                <?php endif; ?>
                <?php if ($sessions): ?>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="user_session_clear_all">
                    <input type="hidden" name="return_page" value="trading">
                    <button class="button button--danger button--small" onclick="return confirm('Remove ALL user sessions?')">
                        <?= icon('trash',14) ?> Clear all
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php if (!$sessions): ?>
            <div class="admin-empty">
                <?= icon('user',28) ?>
                <strong>No user sessions</strong>
                <span>No per-user blocks or payout overrides active.</span>
            </div>
            <?php else: ?>
            <div class="admin-table-wrap">
            <table class="tc-table">
                <thead><tr>
                    <th>User</th>
                    <th>Asset</th>
                    <th>Payout</th>
                    <th>Blocked</th>
                    <th>Expires</th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($sessions as $s):
                    $sLive = !$s['expires_at'] || strtotime((string)$s['expires_at']) > $now;
                ?>
                <tr class="<?= $sLive && (int)$s['is_blocked'] ? 'tc-row--inactive' : ($sLive ? 'tc-row--active' : 'tc-row--inactive') ?>">
                    <td><strong><?= e($s['user_name']) ?></strong><small><?= e($s['user_email']) ?></small></td>
                    <td><?= $s['asset_id'] ? '<strong>' . e($s['asset_symbol']) . '</strong>' : '<span class="tc-all-badge">All</span>' ?></td>
                    <td><?= $s['payout_override'] !== null ? '<span class="tc-wr-pill">' . number_format((float)$s['payout_override'], 1) . '%</span>' : '<span class="muted">Default</span>' ?></td>
                    <td><?php if ((int)$s['is_blocked']): ?>
                        <span class="tc-dir tc-dir--down"><?= icon('lock',12) ?> Blocked</span>
                        <?php if ($s['block_reason']): ?><small style="display:block;margin-top:3px;"><?= e($s['block_reason']) ?></small><?php endif; ?>
                    <?php else: ?>
                        <span class="tc-dir tc-dir--none">Free</span>
                    <?php endif; ?></td>
                    <td><?= $s['expires_at'] ? e(date('d M Y H:i', strtotime((string)$s['expires_at']))) : '<span class="muted">Never</span>' ?></td>
                    <td>
                        <form method="post" class="tc-delete-form">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="user_session_delete">
                            <input type="hidden" name="return_page" value="trading">
                            <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                            <button class="button button--danger button--small" onclick="return confirm('Remove this session?')"><?= icon('trash',13) ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </article>
    </section>

    <script>
    (function() {
        var dirInputs = document.querySelectorAll('input[name="direction"]');
        var winRateRow = document.getElementById('tc-winrate-row');
        var slider = document.getElementById('win-rate-slider');
        var numInput = document.getElementById('win-rate-num');
        var badge = document.getElementById('win-rate-label');
        var clearBtn = document.getElementById('tc-wr-clear-btn');
        var presetBtns = document.querySelectorAll('.tc-presets .tc-preset-btn');

        function updateWrRow() {
            var selected = document.querySelector('input[name="direction"]:checked');
            var dir = selected ? selected.value : 'none';
            var desc = document.getElementById('tc-wr-desc');
            if (desc) {
                if (dir === 'up') desc.textContent = '— sets UP trade win probability (e.g. 100% = always win, 80% = 80% win rate for UP)';
                else if (dir === 'down') desc.textContent = '— sets DOWN trade win probability (e.g. 100% = always win, 80% = 80% win rate for DOWN)';
                else desc.textContent = '— sets overall win probability across all trades';
            }
            setWinRate(numInput ? numInput.value : '', false);
        }
        dirInputs.forEach(function(el) { el.addEventListener('change', updateWrRow); });
        updateWrRow();

        function updateSliderTrack(val, isNull) {
            if (!slider) return;
            var num = isNull ? 50 : Math.max(0, Math.min(100, val));
            slider.style.setProperty('--pct', num + '%');
            var r = Math.round(255 - (255 * num / 100));
            var g = Math.round(255 * num / 100);
            var thumbColor = isNull ? '#90caff' : 'rgb(' + r + ',' + g + ',80)';
            slider.style.setProperty('--thumb-color', thumbColor);
        }

        function setWinRate(val, fromInput) {
            var selected = document.querySelector('input[name="direction"]:checked');
            var dir = selected ? selected.value : 'none';
            var suffix = dir === 'up' ? ' (UP)' : (dir === 'down' ? ' (DOWN)' : '');

            if (val === '' || val === null || typeof val === 'undefined' || isNaN(parseFloat(val))) {
                if (!fromInput && numInput) numInput.value = '';
                if (slider) slider.value = dir !== 'none' ? 100 : 50;
                if (badge) {
                    badge.textContent = dir !== 'none' ? ('100.0%' + suffix) : '— Natural';
                    badge.className = 'tc-wr-badge' + (dir !== 'none' ? ' tc-wr-badge--high' : '');
                }
                updateSliderTrack(dir !== 'none' ? 100 : 50, dir === 'none');
            } else {
                var n = Math.max(0, Math.min(100, parseFloat(val)));
                if (!fromInput && numInput) numInput.value = n;
                if (slider) slider.value = Math.round(n);
                if (badge) {
                    badge.textContent = n.toFixed(1) + '%' + suffix;
                    badge.className = 'tc-wr-badge' + (n >= 60 ? ' tc-wr-badge--high' : (n <= 40 ? ' tc-wr-badge--low' : ' tc-wr-badge--mid'));
                }
                updateSliderTrack(n, false);
            }
        }

        if (slider) {
            slider.addEventListener('input', function() {
                setWinRate(this.value, false);
            });
        }
        if (numInput) {
            numInput.addEventListener('input', function() {
                setWinRate(this.value, true);
            });
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', function(e) {
                e.preventDefault();
                setWinRate('', false);
            });
        }
        presetBtns.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                setWinRate(this.dataset.val, false);
            });
        });

        // Initialize state
        setWinRate(numInput ? numInput.value : '', false);

        // Edit button handler to adjust existing controls
        document.querySelectorAll('.tc-edit-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = this.dataset.id;
                var assetId = this.dataset.asset || '0';
                var userId = this.dataset.user || '0';
                var dir = this.dataset.dir || 'none';
                var wr = this.dataset.wr || '';
                var expires = this.dataset.expires || '';
                var label = this.dataset.label || '';

                document.getElementById('tc-control-id').value = id;
                document.getElementById('tc-asset-id').value = assetId;
                document.getElementById('tc-user-id').value = userId;

                var dirRadio = document.getElementById('dir-' + dir) || document.getElementById('dir-none');
                dirRadio.checked = true;
                updateWrRow();

                setWinRate(wr, false);

                document.getElementById('tc-expires-at').value = expires;
                document.getElementById('tc-label').value = label;

                document.getElementById('tc-form-eyebrow').textContent = 'Adjust rule #' + id;
                document.getElementById('tc-form-title').innerHTML = '<span style="color:#20ce78;">&#9998;</span> Edit control #' + id;
                document.getElementById('tc-save-btn').innerHTML = '<?= icon('check',16) ?> Update control #' + id;
                document.getElementById('tc-cancel-edit-btn').style.display = 'inline-flex';

                document.getElementById('tc-create-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        });

        window.resetTcForm = function() {
            document.getElementById('tc-control-id').value = '0';
            document.getElementById('tc-create-form').reset();
            document.getElementById('dir-none').checked = true;
            updateWrRow();
            setWinRate('', false);
            document.getElementById('tc-form-eyebrow').textContent = 'New rule';
            document.getElementById('tc-form-title').innerHTML = '<?= icon('plus',18) ?> Add control';
            document.getElementById('tc-save-btn').innerHTML = '<?= icon('check',16) ?> Save control';
            document.getElementById('tc-cancel-edit-btn').style.display = 'none';
        };
    })();
    </script>
    <?php
}

