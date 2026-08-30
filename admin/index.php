<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require_once BASE_PATH . '/app/admin_features.php';
require_once BASE_PATH . '/app/admin_market_api.php';

$admin = current_user();
if (!$admin) {
    redirect('/admin/login.php');
}
$admin = require_admin();
expire_deposit_orders();

$allowedPages = ['dashboard','users','trades','requests','finance','payments','coupons','support','assets','agents','notifications','branding','admins','settings','audit','trading','market_api'];
$page = (string)($_GET['page'] ?? 'dashboard');
if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}

function admin_write_setting(PDOStatement $statement, string $key, $value, string $type, string $group, int $adminId, bool $public = false): void
{
    $stored = $type === 'json' ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string)$value;
    $statement->execute([$key, $stored, $type, $group, $public ? 1 : 0, $adminId]);
}

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $returnPage = (string)($_POST['return_page'] ?? $page);
    if (!in_array($returnPage, $allowedPages, true)) {
        $returnPage = 'dashboard';
    }
    $wantsJson = request_expects_json();
    $resultOk = true;
    $resultMessage = 'Action completed successfully.';

    try {
        if ($action === 'review_request') {
            require_admin_capability($admin, 'review_requests');
            review_fund_request((int)$admin['id'], (int)($_POST['request_id'] ?? 0), (string)($_POST['decision'] ?? ''), (string)($_POST['admin_note'] ?? ''));
            flash('success', 'Fund request reviewed successfully.');
        } elseif ($action === 'mark_request_paid') {
            require_admin_capability($admin, 'review_requests');
            mark_fund_request_paid((int)$admin['id'], (int)($_POST['request_id'] ?? 0), (string)($_POST['provider_reference'] ?? ''), (string)($_POST['admin_note'] ?? ''));
            flash('success', 'Withdrawal marked paid with its provider reference.');
        } elseif ($action === 'user_status') {
            require_admin_capability($admin, 'manage_users');
            $userId = (int)($_POST['user_id'] ?? 0);
            $statusValue = (string)($_POST['status'] ?? '');
            if ($userId < 1 || $userId === (int)$admin['id'] || !in_array($statusValue, ['active','blocked'], true)) {
                throw new InvalidArgumentException('Invalid user status request.');
            }
            $targetStmt = db()->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
            $targetStmt->execute([$userId]);
            $targetRole = $targetStmt->fetchColumn();
            if ($targetRole !== 'user') {
                throw new RuntimeException('Use the Administrators page for staff accounts.');
            }
            db()->prepare('UPDATE users SET status=?,updated_at=NOW() WHERE id=?')->execute([$statusValue, $userId]);
            admin_audit((int)$admin['id'], 'user_status_changed', 'user', $userId, ['status' => $statusValue]);
            flash('success', 'User status updated.');
        } elseif ($action === 'verification_review') {
            require_admin_capability($admin, 'manage_users');
            $userId = (int)($_POST['user_id'] ?? 0);
            $decision = (string)($_POST['decision'] ?? '');
            $note = trim((string)($_POST['verification_note'] ?? ''));
            if ($userId < 1 || !in_array($decision, ['verified','rejected'], true) || strlen($note) > 500 || ($decision === 'rejected' && strlen($note) < 3)) {
                throw new InvalidArgumentException('Choose a verification decision and add a rejection reason when required.');
            }
            $stmt = db()->prepare("UPDATE users SET verification_status=?,verification_reviewed_at=NOW(),verification_note=?,updated_at=NOW() WHERE id=? AND role='user'");
            $stmt->execute([$decision, $note !== '' ? $note : null, $userId]);
            if ($stmt->rowCount() < 1) throw new RuntimeException('User verification record was not found.');
            admin_audit((int)$admin['id'], 'verification_' . $decision, 'user', $userId, ['note' => substr($note, 0, 200)]);
            flash('success', 'User verification marked ' . $decision . '.');
        } elseif ($action === 'balance_adjustment') {
            require_admin_capability($admin, 'manage_users');
            $accountType = (string)($_POST['account_type'] ?? 'live');
            $targetId = (int)($_POST['user_id'] ?? 0);
            if (!in_array($accountType, ['live','demo'], true)) {
                throw new InvalidArgumentException('Choose a valid Live or Demo balance.');
            }
            $targetStmt = db()->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
            $targetStmt->execute([$targetId]);
            if ($targetStmt->fetchColumn() !== 'user') {
                throw new RuntimeException('Balance adjustments are available only for user accounts.');
            }
            adjust_account_balance((int)$admin['id'], $targetId, (float)($_POST['amount'] ?? 0), (string)($_POST['reason'] ?? ''), $accountType);
            flash('success', ucfirst($accountType === 'demo' ? 'demo' : 'live') . ' balance adjusted and logged.');
        } elseif ($action === 'user_create') {
            require_admin_capability($admin, 'manage_users');
            $name = trim((string)($_POST['name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $password = (string)($_POST['password'] ?? '');
            $country = strtoupper(trim((string)($_POST['country'] ?? 'IN')));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $liveBalance = round(max(0, min(100000000, (float)($_POST['live_balance'] ?? 0))), 2);
            $demoBalance = round(max(0, min(100000000, (float)($_POST['demo_balance'] ?? setting('practice_starting_balance', 1000)))), 2);
            if (strlen($name) < 2 || strlen($name) > 80 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/^[A-Z]{2}$/', $country) || strlen($phone) > 30) {
                throw new InvalidArgumentException('User details are invalid. Password must be 10+ characters with letters and numbers.');
            }
            $currency = strtoupper((string)setting('wallet_currency', 'USD'));
            $newUserId = db_transaction(function (PDO $pdo) use ($name, $email, $password, $liveBalance, $demoBalance, $currency, $country, $phone): int {
                $stmt = $pdo->prepare("INSERT INTO users (name,email,password_hash,role,status,demo_balance,demo_trade_balance,currency,country,phone,created_at,updated_at) VALUES (?,?,?,'user','active',?,?,?,?,?,NOW(),NOW())");
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $liveBalance, $demoBalance, $currency, $country, $phone ?: null]);
                $userId = (int)$pdo->lastInsertId();
                ensure_user_affiliate(['id' => $userId, 'name' => $name, 'email' => $email], $pdo);
                return $userId;
            });
            admin_audit((int)$admin['id'], 'user_created', 'user', $newUserId, ['email' => $email, 'live_balance' => $liveBalance, 'demo_balance' => $demoBalance]);
            flash('success', 'User account created with separate Live and Demo balances.');
        } elseif ($action === 'user_update') {
            require_admin_capability($admin, 'manage_users');
            $userId = (int)($_POST['user_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $country = strtoupper(trim((string)($_POST['country'] ?? 'IN')));
            $phone = trim((string)($_POST['phone'] ?? ''));
            if ($userId < 1 || strlen($name) < 2 || strlen($name) > 80 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[A-Z]{2}$/', $country) || strlen($phone) > 30) {
                throw new InvalidArgumentException('User profile details are invalid.');
            }
            $check = db()->prepare("SELECT role FROM users WHERE id=? AND role='user' LIMIT 1");
            $check->execute([$userId]);
            if (!$check->fetchColumn()) throw new RuntimeException('User not found or protected.');
            db_transaction(function (PDO $pdo) use ($name, $email, $country, $phone, $userId): void {
                $pdo->prepare('UPDATE users SET name=?,email=?,country=?,phone=?,updated_at=NOW() WHERE id=?')->execute([$name, $email, $country, $phone ?: null, $userId]);
                // Keep the user's automatic Affiliate identity aligned with
                // administrator profile corrections.
                $pdo->prepare('UPDATE agents SET name=?,email=?,updated_at=NOW() WHERE user_id=?')->execute([$name, $email, $userId]);
            });
            admin_audit((int)$admin['id'], 'user_profile_updated', 'user', $userId, ['email' => $email, 'country' => $country]);
            flash('success', 'User information updated.');
        } elseif ($action === 'user_password_reset') {
            require_admin_capability($admin, 'manage_users');
            $userId = (int)($_POST['user_id'] ?? 0);
            $password = (string)($_POST['password'] ?? '');
            if ($userId < 1 || strlen($password) < 10 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
                throw new InvalidArgumentException('Use a password of at least 10 characters with a letter and number.');
            }
            $stmt = db()->prepare("UPDATE users SET password_hash=?,force_password_change=0,updated_at=NOW() WHERE id=? AND role='user'");
            $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
            if ($stmt->rowCount() < 1) throw new RuntimeException('User not found or password was unchanged.');
            admin_audit((int)$admin['id'], 'user_password_reset', 'user', $userId);
            flash('success', 'User password changed successfully.');
        } elseif ($action === 'user_delete') {
            require_admin_capability($admin, 'manage_users');
            $userId = (int)($_POST['user_id'] ?? 0);
            $confirm = (string)($_POST['confirm_delete'] ?? '');
            $target = db()->prepare("SELECT role,status,email FROM users WHERE id=? LIMIT 1");
            $target->execute([$userId]);
            $targetUser = $target->fetch();
            if (!$targetUser || $targetUser['role'] !== 'user' || $targetUser['status'] !== 'blocked' || $confirm !== 'DELETE') {
                throw new RuntimeException('For safety, block an unused user first and type DELETE to remove it.');
            }
            $historyCount = 0;
            foreach (['trades','fund_requests','wallet_transactions','support_tickets'] as $table) {
                $countStmt = db()->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE user_id=?');
                $countStmt->execute([$userId]);
                $historyCount += (int)$countStmt->fetchColumn();
            }
            $referralHistory = db()->prepare('SELECT COUNT(*) FROM agent_referrals r JOIN agents a ON a.id=r.agent_id WHERE r.user_id=? OR a.user_id=?');
            $referralHistory->execute([$userId, $userId]);
            $historyCount += (int)$referralHistory->fetchColumn();
            if ($historyCount > 0) throw new RuntimeException('This user has financial, support, or referral history and cannot be deleted. Keep the account blocked to preserve records.');
            db_transaction(function (PDO $pdo) use ($userId): void {
                // Explicit removal also keeps upgraded databases clean when an
                // older host could not add the optional agents.user_id FK.
                $pdo->prepare('DELETE FROM agents WHERE user_id=?')->execute([$userId]);
                $pdo->prepare("DELETE FROM users WHERE id=? AND role='user'")->execute([$userId]);
            });
            admin_audit((int)$admin['id'], 'user_deleted', 'user', $userId, ['email' => $targetUser['email']]);
            flash('success', 'Unused user account deleted.');
        } elseif ($action === 'market_create') {
            require_admin_capability($admin, 'manage_markets');
            $symbol = strtoupper(trim((string)($_POST['symbol'] ?? '')));
            $name = trim((string)($_POST['display_name'] ?? ''));
            $category = trim((string)($_POST['category'] ?? 'Forex'));
            $basePrice = (float)($_POST['base_price'] ?? 0);
            $decimals = max(0, min(8, (int)($_POST['decimal_places'] ?? 5)));
            $payout = round((float)($_POST['payout_percent'] ?? 82), 2);
            $volatility = (float)($_POST['volatility'] ?? 0.0025);
            $source = (string)($_POST['price_source'] ?? 'internal');
            $apiSymbol = strtoupper(trim((string)($_POST['api_symbol'] ?? '')));
            if (!preg_match('/^[A-Z0-9._:-]{2,30}$/', $symbol) || strlen($name) < 2 || strlen($name) > 80 || strlen($category) < 2 || strlen($category) > 30 || $basePrice <= 0 || $payout < 1 || $payout > 95 || $volatility < 0.00001 || $volatility > 0.2 || !in_array($source, ['internal','manual','api'], true)) {
                throw new InvalidArgumentException('New market details are invalid.');
            }
            $seed = random_int(10000, 2147483000);
            $stmt = db()->prepare('INSERT INTO market_assets (symbol,display_name,category,base_price,decimal_places,payout_percent,volatility,market_seed,price_source,api_symbol,is_active,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,?,NOW(),NOW())');
            $stmt->execute([$symbol,$name,$category,$basePrice,$decimals,$payout,$volatility,$seed,$source,$apiSymbol ?: null,(int)($_POST['sort_order'] ?? 0)]);
            $assetId = (int)db()->lastInsertId();
            admin_audit((int)$admin['id'], 'market_created', 'market_asset', $assetId, ['symbol'=>$symbol,'source'=>$source]);
            flash('success', 'Market added. Its source applies globally to all users.');
        } elseif ($action === 'asset_update') {
            require_admin_capability($admin, 'manage_markets');
            $assetId = (int)($_POST['asset_id'] ?? 0);
            $name = trim((string)($_POST['display_name'] ?? ''));
            $payout = round((float)($_POST['payout_percent'] ?? 0), 2);
            $basePrice = (float)($_POST['base_price'] ?? 0);
            $volatility = (float)($_POST['volatility'] ?? 0);
            $source = (string)($_POST['price_source'] ?? 'internal');
            $apiSymbol = strtoupper(trim((string)($_POST['api_symbol'] ?? '')));
            $sort = max(-9999, min(9999, (int)($_POST['sort_order'] ?? 0)));
            $active = isset($_POST['is_active']) ? 1 : 0;
            if ($assetId < 1 || strlen($name) < 3 || strlen($name) > 80 || $payout < 1 || $payout > 95 || $basePrice <= 0 || $basePrice > 1000000000000 || $volatility < 0.00001 || $volatility > 0.2 || !in_array($source, ['internal','manual','api'], true) || ($apiSymbol !== '' && !preg_match('/^[A-Z0-9._:-]{2,60}$/', $apiSymbol))) {
                throw new InvalidArgumentException('Market name, payout, price, volatility, or source is invalid.');
            }
            $stmt = db()->prepare('UPDATE market_assets SET display_name=?,payout_percent=?,base_price=?,volatility=?,price_source=?,api_symbol=?,sort_order=?,is_active=?,updated_at=NOW() WHERE id=?');
            $stmt->execute([$name, $payout, $basePrice, $volatility, $source, $apiSymbol ?: null, $sort, $active, $assetId]);
            admin_audit((int)$admin['id'], 'market_updated', 'market_asset', $assetId, ['name' => $name, 'payout' => $payout, 'source' => $source, 'active' => $active]);
            flash('success', 'Market settings updated.');
        } elseif ($action === 'market_api_refresh') {
            require_admin_capability($admin, 'manage_markets');
            $assetId = (int)($_POST['asset_id'] ?? 0);
            $price = refresh_market_api_price($assetId);
            admin_audit((int)$admin['id'], 'market_api_refreshed', 'market_asset', $assetId, ['price' => $price]);
            flash('success', 'API price refreshed: ' . number_format($price, 8, '.', ''));
        } elseif ($action === 'payment_method_save') {
            require_admin_capability($admin, 'manage_payments');
            $methodId = save_payment_method((int)$admin['id'], $_POST, $_FILES);
            flash('success', 'Payment method #' . $methodId . ' saved.');
        } elseif ($action === 'promo_save') {
            require_admin_capability($admin, 'manage_promos');
            $promoId = save_promo_code((int)$admin['id'], $_POST);
            flash('success', 'Gift/coupon #' . $promoId . ' saved.');
        } elseif ($action === 'support_reply') {
            require_admin_capability($admin, 'manage_support');
            reply_support_ticket((int)$admin['id'], (int)($_POST['ticket_id'] ?? 0), (string)($_POST['admin_response'] ?? ''), (string)($_POST['ticket_status'] ?? 'answered'));
            flash('success', 'Support response saved.');
        } elseif ($action === 'settings_update') {
            require_admin_capability($admin, 'manage_settings');
            $platformName = trim((string)($_POST['platform_name'] ?? ''));
            $tagline = trim((string)($_POST['platform_tagline'] ?? ''));
            $domain = rtrim(trim((string)($_POST['platform_domain'] ?? '')), '/');
            $supportEmail = strtolower(trim((string)($_POST['support_email'] ?? '')));
            $walletCurrency = strtoupper(trim((string)($_POST['wallet_currency'] ?? 'USD')));
            $apiUrl = trim((string)($_POST['market_api_url'] ?? ''));
            $apiPath = trim((string)($_POST['market_api_price_path'] ?? 'price'));
            if (strlen($platformName) < 2 || strlen($platformName) > 80 || strlen($tagline) < 3 || strlen($tagline) > 180 || !filter_var($domain, FILTER_VALIDATE_URL) || strtolower((string)parse_url($domain, PHP_URL_SCHEME)) !== 'https' || !filter_var($supportEmail, FILTER_VALIDATE_EMAIL) || !preg_match('/^[A-Z]{3}$/', $walletCurrency)) {
                throw new InvalidArgumentException('Platform identity, HTTPS domain, support email, or currency is invalid.');
            }
            if ($apiUrl !== '') {
                $validationUrl = str_replace('{symbol}', 'EURUSD', $apiUrl);
                if (!filter_var($validationUrl, FILTER_VALIDATE_URL) || strtolower((string)parse_url($validationUrl, PHP_URL_SCHEME)) !== 'https' || strpos($apiUrl, '{symbol}') === false) {
                    throw new InvalidArgumentException('Market API URL must be HTTPS and contain {symbol}.');
                }
            }
            if (!preg_match('/^[A-Za-z0-9_.{}-]{1,190}$/', $apiPath)) {
                throw new InvalidArgumentException('Market API price path is invalid.');
            }
            $numbers = [
                'demo_starting_balance' => (float)($_POST['demo_starting_balance'] ?? 0),
                'practice_starting_balance' => (float)($_POST['practice_starting_balance'] ?? 1000),
                'minimum_trade_amount' => (float)($_POST['minimum_trade_amount'] ?? 0),
                'maximum_trade_amount' => (float)($_POST['maximum_trade_amount'] ?? 0),
                'minimum_demo_deposit' => (float)($_POST['minimum_demo_deposit'] ?? 0),
                'minimum_demo_withdrawal' => (float)($_POST['minimum_demo_withdrawal'] ?? 0),
                'payment_proof_max_mb' => (float)($_POST['payment_proof_max_mb'] ?? 5),
                'payment_order_minutes' => (float)($_POST['payment_order_minutes'] ?? 15),
                'chart_history_candles' => (float)($_POST['chart_history_candles'] ?? 140),
                'chart_tick_density' => (float)($_POST['chart_tick_density'] ?? 10),
                'chart_quote_interval_ms' => (float)($_POST['chart_quote_interval_ms'] ?? 650),
                'currency_exchange_fee_percent' => (float)($_POST['currency_exchange_fee_percent'] ?? 3),
            ];
            if ($numbers['demo_starting_balance'] < 0 || $numbers['practice_starting_balance'] < 0 || $numbers['practice_starting_balance'] > 100000000 || $numbers['minimum_trade_amount'] <= 0 || $numbers['maximum_trade_amount'] < $numbers['minimum_trade_amount'] || $numbers['maximum_trade_amount'] > 100000 || $numbers['minimum_demo_deposit'] <= 0 || $numbers['minimum_demo_withdrawal'] <= 0 || $numbers['payment_proof_max_mb'] < 1 || $numbers['payment_proof_max_mb'] > 10 || $numbers['payment_order_minutes'] < 5 || $numbers['payment_order_minutes'] > 60 || $numbers['chart_history_candles'] < 60 || $numbers['chart_history_candles'] > 180 || $numbers['chart_tick_density'] < 4 || $numbers['chart_tick_density'] > 16 || $numbers['chart_quote_interval_ms'] < 250 || $numbers['chart_quote_interval_ms'] > 2000 || $numbers['currency_exchange_fee_percent'] < 0 || $numbers['currency_exchange_fee_percent'] > 25) {
                throw new InvalidArgumentException('Wallet, upload, or trade limits are invalid.');
            }
            $exchangeRates = [];
            foreach (['USD','INR','EUR','GBP'] as $currencyCode) {
                $rate = (float)($_POST['exchange_rate_' . strtolower($currencyCode)] ?? 0);
                if (!is_finite($rate) || $rate <= 0 || $rate > 1000000000) {
                    throw new InvalidArgumentException('Every account-currency rate must be a positive number.');
                }
                $exchangeRates[$currencyCode] = $rate;
            }
            $durations = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,]+/', (string)($_POST['trade_durations'] ?? '')) ?: []), static function (int $value): bool { return $value >= 5 && $value <= 3600; })));
            sort($durations);
            if (!$durations) {
                throw new InvalidArgumentException('Add at least one duration between 5 and 3600 seconds.');
            }
            $upsert = db()->prepare('INSERT INTO site_settings (setting_key,setting_value,setting_type,group_name,is_public,updated_by,updated_at) VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),setting_type=VALUES(setting_type),group_name=VALUES(group_name),is_public=VALUES(is_public),updated_by=VALUES(updated_by),updated_at=NOW()');
            admin_write_setting($upsert, 'platform_name', $platformName, 'string', 'general', (int)$admin['id'], true);
            admin_write_setting($upsert, 'platform_tagline', $tagline, 'string', 'general', (int)$admin['id'], true);
            admin_write_setting($upsert, 'platform_domain', $domain, 'string', 'general', (int)$admin['id'], true);
            admin_write_setting($upsert, 'support_email', $supportEmail, 'string', 'general', (int)$admin['id'], true);
            admin_write_setting($upsert, 'wallet_currency', $walletCurrency, 'string', 'wallet', (int)$admin['id'], true);
            foreach ($numbers as $key => $value) {
                admin_write_setting($upsert, $key, $value, 'number', (strpos($key, 'trade') !== false || strpos($key, 'chart_') === 0 || $key === 'practice_starting_balance') ? 'trading' : 'wallet', (int)$admin['id'], in_array($key, ['minimum_trade_amount','maximum_trade_amount','minimum_demo_deposit','minimum_demo_withdrawal','chart_history_candles','chart_tick_density','chart_quote_interval_ms'], true));
            }
            admin_write_setting($upsert, 'trade_durations', $durations, 'json', 'trading', (int)$admin['id'], true);
            admin_write_setting($upsert, 'currency_exchange_rates', $exchangeRates, 'json', 'wallet', (int)$admin['id']);
            foreach (['trading_enabled','demo_trading_enabled','deposits_enabled','withdrawals_enabled','currency_exchange_enabled','maintenance_mode'] as $key) {
                admin_write_setting($upsert, $key, isset($_POST[$key]) ? '1' : '0', 'boolean', $key === 'maintenance_mode' ? 'general' : (in_array($key, ['trading_enabled','demo_trading_enabled'], true) ? 'trading' : 'wallet'), (int)$admin['id'], true);
            }
            admin_write_setting($upsert, 'market_api_url', $apiUrl, 'string', 'trading', (int)$admin['id']);
            admin_write_setting($upsert, 'market_api_price_path', $apiPath, 'string', 'trading', (int)$admin['id']);
            $newApiKey = trim((string)($_POST['market_api_key'] ?? ''));
            if ($newApiKey !== '' || isset($_POST['clear_market_api_key'])) {
                if (strlen($newApiKey) > 500) {
                    throw new InvalidArgumentException('Market API key is too long.');
                }
                admin_write_setting($upsert, 'market_api_key', $newApiKey, 'string', 'trading', (int)$admin['id']);
            }
            admin_audit((int)$admin['id'], 'settings_updated', 'site_settings', null, ['sections' => ['identity','wallet','trading','chart','currency_exchange','market_api']]);
            flash('success', 'Platform settings saved.');
        } elseif ($action === 'admin_create') {
            require_admin_capability($admin, 'manage_admins');
            $name = trim((string)($_POST['name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $password = (string)($_POST['password'] ?? '');
            $role = (string)($_POST['role'] ?? 'manager');
            if (strlen($name) < 2 || strlen($name) > 80 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password) || !in_array($role, ['manager','admin'], true)) {
                throw new InvalidArgumentException('Administrator details are invalid. Use a 12+ character password.');
            }
            $currency = strtoupper((string)setting('wallet_currency', 'USD'));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                $currency = 'USD';
            }
            $stmt = db()->prepare("INSERT INTO users (name,email,password_hash,role,status,demo_balance,demo_trade_balance,currency,country,force_password_change,created_at,updated_at) VALUES (?,?,?,?,'active',0,0,?,'IN',1,NOW(),NOW())");
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $currency]);
            $newId = (int)db()->lastInsertId();
            admin_audit((int)$admin['id'], 'administrator_created', 'user', $newId, ['role' => $role]);
            flash('success', 'Administrator account created.');
        } elseif ($action === 'admin_update') {
            require_admin_capability($admin, 'manage_admins');
            $targetId = (int)($_POST['user_id'] ?? 0);
            $role = (string)($_POST['role'] ?? 'manager');
            $statusValue = (string)($_POST['status'] ?? 'active');
            if ($targetId < 1 || $targetId === (int)$admin['id'] || !in_array($role, ['manager','admin'], true) || !in_array($statusValue, ['active','blocked'], true)) {
                throw new InvalidArgumentException('Invalid administrator update.');
            }
            $check = db()->prepare("SELECT role FROM users WHERE id=? AND role IN ('manager','admin') LIMIT 1");
            $check->execute([$targetId]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('Administrator not found or protected.');
            }
            db()->prepare('UPDATE users SET role=?,status=?,updated_at=NOW() WHERE id=?')->execute([$role, $statusValue, $targetId]);
            admin_audit((int)$admin['id'], 'administrator_updated', 'user', $targetId, ['role' => $role, 'status' => $statusValue]);
            flash('success', 'Administrator permissions updated.');
        } elseif ($action === 'announcement_create') {
            require_admin_capability($admin, 'manage_settings');
            $title = trim((string)($_POST['title'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));
            $level = (string)($_POST['level'] ?? 'info');
            if (strlen($title) < 3 || strlen($title) > 120 || strlen($message) < 5 || strlen($message) > 1000 || !in_array($level, ['info','success','warning'], true)) {
                throw new InvalidArgumentException('Announcement details are invalid.');
            }
            $bannerImage = store_protected_image($_FILES['banner_image'] ?? [], 'announcements', 'banner');
            db()->exec('UPDATE announcements SET is_active=0,updated_at=NOW()');
            $stmt = db()->prepare('INSERT INTO announcements (title,message,image_filename,level,is_active,starts_at,ends_at,created_by,created_at,updated_at) VALUES (?,?,?,?,1,NOW(),DATE_ADD(NOW(),INTERVAL 1 YEAR),?,NOW(),NOW())');
            $stmt->execute([$title, $message, $bannerImage, $level, (int)$admin['id']]);
            $id = (int)db()->lastInsertId();
            admin_audit((int)$admin['id'], 'announcement_created', 'announcement', $id, ['title' => $title]);
            flash('success', 'Platform announcement published.');
        } elseif ($action === 'announcement_disable') {
            require_admin_capability($admin, 'manage_settings');
            db()->exec('UPDATE announcements SET is_active=0,updated_at=NOW() WHERE is_active=1');
            admin_audit((int)$admin['id'], 'announcement_disabled', 'announcement');
            flash('success', 'Trade-room announcement disabled.');
        } elseif ($action === 'test_api') {
            require_admin_capability($admin, 'manage_markets');
            $testResults = test_market_api_connection();
            $messages = [];
            if ($testResults['twelvedata']) {
                $messages[] = 'Twelve Data API connected successfully!';
            } elseif (!empty($testResults['errors']['twelvedata'])) {
                $messages[] = 'Twelve Data error: ' . $testResults['errors']['twelvedata'];
            }
            if ($testResults['metals']) {
                $messages[] = 'Metals API connected successfully!';
            } elseif (!empty($testResults['errors']['metals'])) {
                $messages[] = 'Metals API error: ' . $testResults['errors']['metals'];
            }
            $resultMessage = implode(' | ', $messages);
            flash('success', $resultMessage);
        } else {
            $extensionMessage = admin_handle_trading_control_action($admin, $action);
            if ($extensionMessage === null) {
                $extensionMessage = admin_handle_extension_action($admin, $action);
            }
            if ($extensionMessage === null) {
                throw new InvalidArgumentException('Unknown administrator action.');
            }
            $resultMessage = $extensionMessage;
            flash('success', $extensionMessage);
        }
    } catch (PDOException $exception) {
        $resultOk = false;
        $resultMessage = (string)$exception->getCode() === '23000' ? 'That code, email, or value already exists.' : 'Database operation failed.';
        flash('error', $resultMessage);
    } catch (Throwable $exception) {
        $resultOk = false;
        $resultMessage = $exception->getMessage();
        flash('error', $resultMessage);
    }
    if ($wantsJson) {
        $queuedFlashes = pull_flashes();
        if ($resultMessage === 'Action completed successfully.' && $queuedFlashes) {
            $lastFlash = end($queuedFlashes);
            $resultMessage = (string)($lastFlash['message'] ?? $resultMessage);
        }
        json_response([
            'ok' => $resultOk,
            'message' => $resultMessage,
            'data' => [
                'redirect' => '/admin/?page=' . rawurlencode($returnPage),
                'reload' => false,
                'action' => $action,
                'page' => $returnPage,
            ],
        ], $resultOk ? 200 : 422);
    }
    redirect('/admin/?page=' . rawurlencode($returnPage));
}

$restrictedPageCapabilities = ['users'=>'manage_users','payments'=>'manage_payments','coupons'=>'manage_promos','assets'=>'manage_markets','trading'=>'manage_markets','market_api'=>'manage_markets','agents'=>'manage_agents','notifications'=>'manage_notifications','branding'=>'manage_branding','admins'=>'manage_admins','settings'=>'manage_settings'];
if (isset($restrictedPageCapabilities[$page]) && !admin_can($admin, $restrictedPageCapabilities[$page])) {
    flash('error', 'Your administrator role does not allow access to that page.');
    redirect('/admin/');
}

$titles = ['dashboard'=>'Dashboard','users'=>'Users','trades'=>'Trades','requests'=>'Fund requests','finance'=>'Finance','payments'=>'Payment methods','coupons'=>'Gift & coupons','support'=>'Support desk','assets'=>'Trading control','trading'=>'Trading Control Center','market_api'=>'Market APIs','agents'=>'Agents & referrals','notifications'=>'Notifications','branding'=>'Branding','admins'=>'Administrators','settings'=>'Platform settings','audit'=>'Audit log'];
render_admin_start($titles[$page], $page, $admin);

if ($page === 'dashboard'):
    $metrics = [
        'users' => (int)db()->query("SELECT COUNT(*) FROM users WHERE role='user'")->fetchColumn(),
        'active_today' => (int)db()->query('SELECT COUNT(DISTINCT user_id) FROM trades WHERE created_at>=CURDATE()')->fetchColumn(),
        'open_trades' => (int)db()->query("SELECT COUNT(*) FROM trades WHERE status='open'")->fetchColumn(),
        'pending_requests' => (int)db()->query("SELECT COUNT(*) FROM fund_requests WHERE status='pending'")->fetchColumn(),
        'pending_deposits' => (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM fund_requests WHERE request_type='deposit' AND status='pending'")->fetchColumn(),
        'pending_withdrawals' => (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM fund_requests WHERE request_type='withdrawal' AND status='pending'")->fetchColumn(),
        'support_open' => (int)db()->query("SELECT COUNT(*) FROM support_tickets WHERE status='open'")->fetchColumn(),
        'volume_today' => (float)db()->query('SELECT COALESCE(SUM(amount),0) FROM trades WHERE created_at>=CURDATE()')->fetchColumn(),
    ];
    $recentTrades = db()->query('SELECT t.public_id,t.account_type,u.name,u.email,a.display_name,t.direction,t.amount,t.status,t.profit_loss,t.created_at FROM trades t JOIN users u ON u.id=t.user_id JOIN market_assets a ON a.id=t.asset_id ORDER BY t.id DESC LIMIT 8')->fetchAll();
    $pendingRequests = db()->query("SELECT r.id,r.public_id,r.request_type,r.amount,r.created_at,u.name,u.email,m.name method_name FROM fund_requests r JOIN users u ON u.id=r.user_id LEFT JOIN payment_methods m ON m.id=r.payment_method_id WHERE r.status='pending' ORDER BY r.id ASC LIMIT 7")->fetchAll();
    $chartQuery = db()->query("SELECT DATE(created_at) trade_day,COUNT(*) trade_count,COALESCE(SUM(amount),0) volume FROM trades WHERE created_at>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) GROUP BY DATE(created_at)")->fetchAll();
    $chartMap = []; foreach ($chartQuery as $chartRow) { $chartMap[$chartRow['trade_day']] = $chartRow; }
    $tradeChart = []; $maxChartVolume = 1.0;
    for ($offset=6; $offset>=0; $offset--) { $day=date('Y-m-d',strtotime('-'.$offset.' day')); $row=$chartMap[$day]??['trade_count'=>0,'volume'=>0]; $volume=(float)$row['volume']; $maxChartVolume=max($maxChartVolume,$volume); $tradeChart[]=['day'=>$day,'count'=>(int)$row['trade_count'],'volume'=>$volume]; }
?>
<section class="admin-metrics admin-metrics--wide"><article><span class="metric-icon metric-icon--green"><?= icon('users',22) ?></span><div><small>Total users</small><strong><?= number_format($metrics['users']) ?></strong><em><?= number_format($metrics['active_today']) ?> active today</em></div></article><article><span class="metric-icon metric-icon--blue"><?= icon('activity',22) ?></span><div><small>Open trades</small><strong><?= number_format($metrics['open_trades']) ?></strong><em><?= e(money($metrics['volume_today'])) ?> volume today</em></div></article><article><span class="metric-icon metric-icon--amber"><?= icon('request',22) ?></span><div><small>Pending requests</small><strong><?= number_format($metrics['pending_requests']) ?></strong><em><?= e(money($metrics['pending_deposits'])) ?> deposits</em></div></article><article><span class="metric-icon metric-icon--red"><?= icon('wallet',22) ?></span><div><small>Withdrawal queue</small><strong><?= e(money($metrics['pending_withdrawals'])) ?></strong><em>Reserved user balances</em></div></article><article><span class="metric-icon metric-icon--purple"><?= icon('support',22) ?></span><div><small>Open support</small><strong><?= number_format($metrics['support_open']) ?></strong><em>Awaiting response</em></div></article></section>
<section class="admin-card admin-chart-card"><div class="admin-card-head"><div><span class="eyebrow">7 day overview</span><h2>Trading activity</h2></div><span class="admin-chart-legend"><?= icon('activity',16) ?> Volume &amp; trade count</span></div><div class="admin-volume-chart"><?php foreach($tradeChart as $point): $height=max(5,(int)round(($point['volume']/$maxChartVolume)*100)); ?><div class="admin-volume-column" title="<?= e(date('D d M',strtotime($point['day']))) ?> · <?= e(money($point['volume'])) ?> · <?= (int)$point['count'] ?> trades"><div class="admin-volume-value"><?= $point['volume']>0?e(money($point['volume'])):'—' ?></div><div class="admin-volume-track"><i style="height:<?= $height ?>%"></i></div><strong><?= e(date('D',strtotime($point['day']))) ?></strong><small><?= (int)$point['count'] ?> trades</small></div><?php endforeach; ?></div></section>
<section class="admin-grid admin-grid--dashboard"><article class="admin-card"><div class="admin-card-head"><div><span class="eyebrow">Activity</span><h2>Recent trades</h2></div><a href="?page=trades">View all</a></div><div class="admin-table-wrap"><table><thead><tr><th>Account</th><th>User</th><th>Market</th><th>Amount</th><th>Result</th><th>P/L</th></tr></thead><tbody><?php if (!$recentTrades): ?><tr><td colspan="6" class="table-empty">No trades yet.</td></tr><?php endif; ?><?php foreach ($recentTrades as $trade): ?><tr><td><span class="status <?= ($trade['account_type']??'live')==='demo' ? 'status--open' : 'status--active' ?>"><?= ($trade['account_type']??'live')==='demo' ? 'Demo' : 'Live' ?></span></td><td><strong><?= e($trade['name']) ?></strong><small><?= e($trade['email']) ?></small></td><td><?= e($trade['display_name']) ?> <span class="direction direction--<?= e($trade['direction']) ?>"><?= e(strtoupper($trade['direction'])) ?></span></td><td><?= e(money($trade['amount'])) ?></td><td><span class="status status--<?= e($trade['status']) ?>"><?= e($trade['status']) ?></span></td><td class="<?= (float)$trade['profit_loss']>=0?'amount-positive':'amount-negative' ?>"><?= e(money($trade['profit_loss'])) ?></td></tr><?php endforeach; ?></tbody></table></div></article><article class="admin-card"><div class="admin-card-head"><div><span class="eyebrow">Needs review</span><h2>Fund requests</h2></div><a href="?page=requests">Open queue</a></div><div class="admin-request-list"><?php if (!$pendingRequests): ?><div class="admin-empty"><?= icon('check',24) ?><strong>Queue is clear</strong><span>No pending requests.</span></div><?php endif; ?><?php foreach ($pendingRequests as $request): ?><div><span class="metric-icon <?= $request['request_type']==='deposit'?'metric-icon--green':'metric-icon--red' ?>"><?= icon($request['request_type']==='deposit'?'plus':'minus',18) ?></span><span><strong><?= e($request['name']) ?></strong><small><?= e($request['public_id']) ?> · <?= e($request['method_name'] ?: ucfirst($request['request_type'])) ?></small></span><b><?= e(money($request['amount'])) ?></b></div><?php endforeach; ?></div></article></section>
<?php
elseif ($page === 'users'):
    $q = trim((string)($_GET['q'] ?? ''));
    $statusFilter = in_array((string)($_GET['status'] ?? ''), ['active','blocked'], true) ? (string)$_GET['status'] : '';
    $params=[]; $where=" WHERE role='user'";
    if ($q !== '') { $where .= ' AND (name LIKE ? OR email LIKE ? OR id=?)'; $like='%'.$q.'%'; $params=[$like,$like,(int)$q]; }
    if ($statusFilter !== '') { $where .= ' AND status=?'; $params[]=$statusFilter; }
    $count=db()->prepare('SELECT COUNT(*) FROM users'.$where); $count->execute($params); $pager=pagination((int)$count->fetchColumn(),max(1,(int)($_GET['p']??1)),25);
    $stmt=db()->prepare('SELECT id,name,email,status,demo_balance,demo_trade_balance,currency,country,phone,verification_status,verification_submitted_at,verification_note,last_login_at,created_at FROM users'.$where.' ORDER BY id DESC LIMIT 25 OFFSET '.(int)$pager['offset']); $stmt->execute($params); $users=$stmt->fetchAll();
?>
<section class="admin-user-tools">
    <article class="admin-card admin-create-user-card">
        <div class="admin-card-head"><div><span class="eyebrow">User management</span><h2><?= icon('user-plus',20) ?> Add user</h2></div><span class="admin-lock-note">Live + Demo accounts</span></div>
        <form method="post" class="admin-user-create-form">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="user_create"><input type="hidden" name="return_page" value="users">
            <div class="field-grid field-grid--3"><label class="field"><span>Full name</span><input name="name" minlength="2" maxlength="80" required></label><label class="field"><span>Email</span><input type="email" name="email" required></label><label class="field"><span>Temporary password</span><input type="password" name="password" minlength="10" required></label></div>
            <div class="field-grid field-grid--4"><label class="field"><span>Country code</span><input name="country" value="IN" maxlength="2" pattern="[A-Za-z]{2}" required></label><label class="field"><span>Phone</span><input name="phone" maxlength="30"></label><label class="field"><span>Live balance</span><input type="number" name="live_balance" min="0" step="0.01" value="0"></label><label class="field"><span>Demo balance</span><input type="number" name="demo_balance" min="0" step="0.01" value="<?= e((string)setting('practice_starting_balance',1000)) ?>"></label></div>
            <button class="button button--primary button--small"><?= icon('plus',15) ?> Create user</button>
        </form>
    </article>
</section>
<section class="admin-card"><div class="admin-card-head admin-card-head--filters"><div><span class="eyebrow">Accounts</span><h2><?= number_format($pager['total']) ?> users</h2></div><form class="admin-filters" method="get"><input type="hidden" name="page" value="users"><label><?= icon('search',16) ?><input name="q" value="<?= e($q) ?>" placeholder="Name, email, or ID"></label><select name="status"><option value="">All status</option><option value="active" <?= $statusFilter==='active'?'selected':'' ?>>Active</option><option value="blocked" <?= $statusFilter==='blocked'?'selected':'' ?>>Blocked</option></select><button class="button button--ghost button--small">Filter</button></form></div>
<div class="admin-table-wrap"><table><thead><tr><th>User</th><th>Contact</th><th>Live balance</th><th>Demo balance</th><th>Status</th><th>Last login</th><th>Actions</th></tr></thead><tbody>
<?php if(!$users): ?><tr><td colspan="7" class="table-empty">No users found.</td></tr><?php endif; ?>
<?php foreach($users as $row): ?>
<tr>
<td><span class="table-user"><span class="avatar"><?= e(strtoupper(substr($row['name'],0,1))) ?></span><span><strong><?= e($row['name']) ?></strong><small>#<?= (int)$row['id'] ?> · <?= e($row['email']) ?></small></span></span></td>
<td><?= e($row['country']) ?><small><?= e($row['phone'] ?: 'No phone') ?></small></td>
<td><strong><?= e(money($row['demo_balance'],$row['currency'])) ?></strong><small>Finance wallet</small></td>
<td><strong class="amount-positive"><?= e(money($row['demo_trade_balance'],$row['currency'])) ?></strong><small>Practice account</small></td>
<td><span class="status status--<?= e($row['status']) ?>"><?= e($row['status']) ?></span><small class="verification-state verification-state--<?= e($row['verification_status']?:'unverified') ?>"><?= e(ucfirst($row['verification_status']?:'unverified')) ?> identity</small></td>
<td><?= $row['last_login_at']?e(date('d M Y H:i',strtotime($row['last_login_at']))):'Never' ?></td>
<td><details class="action-menu action-menu--advanced"><summary>Manage</summary><div class="advanced-user-menu">
<form method="post" class="admin-inline-user-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="user_status"><input type="hidden" name="return_page" value="users"><input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="status" value="<?= $row['status']==='active'?'blocked':'active' ?>"><button><?= icon($row['status']==='active'?'x':'check',15) ?> <?= $row['status']==='active'?'Ban / block user':'Activate user' ?></button></form>
<?php if(($row['verification_status']??'unverified')!=='verified'): ?><form method="post" class="verification-review-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="verification_review"><input type="hidden" name="return_page" value="users"><input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>"><label>Identity review note<input name="verification_note" maxlength="500" placeholder="Required when rejecting"></label><span><button class="button button--primary button--small" name="decision" value="verified">Verify</button><button class="button button--ghost button--small" name="decision" value="rejected">Reject</button></span></form><?php endif; ?>
<form method="post" class="balance-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="balance_adjustment"><input type="hidden" name="return_page" value="users"><input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="account_type" value="live"><label>Live balance adjustment<input type="number" name="amount" step="0.01" placeholder="+100 or -50" required></label><label>Audit reason<input name="reason" minlength="3" maxlength="255" required></label><button class="button button--primary button--small">Adjust live</button></form>
<form method="post" class="balance-form demo-balance-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="balance_adjustment"><input type="hidden" name="return_page" value="users"><input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="account_type" value="demo"><label>Demo balance adjustment<input type="number" name="amount" step="0.01" placeholder="+1000 or -100" required></label><label>Audit reason<input name="reason" minlength="3" maxlength="255" value="Demo account adjustment" required></label><button class="button button--ghost button--small">Adjust demo</button></form>
<details class="admin-user-edit"><summary><?= icon('user',14) ?> Edit user information</summary><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="user_update"><input type="hidden" name="return_page" value="users"><input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>"><label>Name<input name="name" value="<?= e($row['name']) ?>" required></label><label>Email<input type="email" name="email" value="<?= e($row['email']) ?>" required></label><label>Country<input name="country" value="<?= e($row['country']) ?>" maxlength="2" required></label><label>Phone<input name="phone" value="<?= e((string)$row['phone']) ?>" maxlength="30"></label><button class="button button--ghost button--small">Save information</button></form></details>
<details class="admin-user-edit"><summary><?= icon('key',14) ?> Change password</summary><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="user_password_reset"><input type="hidden" name="return_page" value="users"><input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>"><label>New password<input type="password" name="password" minlength="10" required></label><button class="button button--ghost button--small">Set password</button></form></details>
<?php if($row['status']==='blocked'): ?><details class="admin-user-delete"><summary><?= icon('trash',14) ?> Delete unused user</summary><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="user_delete"><input type="hidden" name="return_page" value="users"><input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>"><label>Type DELETE<input name="confirm_delete" pattern="DELETE" required></label><small>Deletion is blocked when financial, trade, or support history exists.</small><button class="button button--danger button--small">Delete</button></form></details><?php endif; ?>
</div></details></td>
</tr>
<?php endforeach; ?>
</tbody></table></div><?php if($pager['pages']>1): ?><nav class="admin-pagination"><?php for($i=1;$i<=$pager['pages'];$i++): ?><a class="<?= $i===$pager['page']?'active':'' ?>" href="?page=users&q=<?= rawurlencode($q) ?>&status=<?= e($statusFilter) ?>&p=<?= $i ?>"><?= $i ?></a><?php endfor; ?></nav><?php endif; ?></section>
<?php
elseif ($page === 'trades'):
    $tradeStatus=in_array((string)($_GET['status']??''),['open','won','lost','draw'],true)?(string)$_GET['status']:'';
    $params=[];$where='';if($tradeStatus!==''){$where=' WHERE t.status=?';$params[]=$tradeStatus;}
    $count=db()->prepare('SELECT COUNT(*) FROM trades t'.$where);$count->execute($params);$pager=pagination((int)$count->fetchColumn(),max(1,(int)($_GET['p']??1)),35);
    $stmt=db()->prepare('SELECT t.public_id,t.account_type,t.direction,t.amount,t.payout_percent,t.entry_price,t.close_price,t.status,t.profit_loss,t.opened_at,u.name,u.email,a.display_name,a.decimal_places FROM trades t JOIN users u ON u.id=t.user_id JOIN market_assets a ON a.id=t.asset_id'.$where.' ORDER BY t.id DESC LIMIT 35 OFFSET '.(int)$pager['offset']);$stmt->execute($params);$trades=$stmt->fetchAll();
?>
<section class="admin-card"><div class="admin-card-head admin-card-head--filters"><div><span class="eyebrow">Settlement log</span><h2><?= number_format($pager['total']) ?> trades</h2></div><form class="admin-filters"><input type="hidden" name="page" value="trades"><select name="status"><option value="">All results</option><?php foreach(['open','won','lost','draw'] as $s): ?><option value="<?= $s ?>" <?= $tradeStatus===$s?'selected':'' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select><button class="button button--ghost button--small">Filter</button></form></div><div class="admin-info-banner"><?= icon('shield',18) ?><span>Trade records are append-only and settle from recorded market prices; administrators cannot force individual outcomes.</span></div><div class="admin-table-wrap"><table><thead><tr><th>Trade</th><th>Account</th><th>User</th><th>Market</th><th>Direction</th><th>Entry → close</th><th>Amount</th><th>Result</th><th>P/L</th><th>Opened</th></tr></thead><tbody><?php if(!$trades): ?><tr><td colspan="10" class="table-empty">No trades found.</td></tr><?php endif; ?><?php foreach($trades as $row): ?><tr><td><code><?= e($row['public_id']) ?></code></td><td><span class="status <?= ($row['account_type']??'live')==='demo' ? 'status--open' : 'status--active' ?>"><?= ($row['account_type']??'live')==='demo' ? 'Demo' : 'Live' ?></span></td><td><strong><?= e($row['name']) ?></strong><small><?= e($row['email']) ?></small></td><td><?= e($row['display_name']) ?></td><td><span class="direction direction--<?= e($row['direction']) ?>"><?= icon($row['direction']==='up'?'arrow-up':'arrow-down',14) ?><?= e(strtoupper($row['direction'])) ?></span></td><td><span class="mono"><?= e(number_format((float)$row['entry_price'],(int)$row['decimal_places'],'.','')) ?> → <?= $row['close_price']===null?'—':e(number_format((float)$row['close_price'],(int)$row['decimal_places'],'.','')) ?></span></td><td><?= e(money($row['amount'])) ?><small><?= e(number_format((float)$row['payout_percent'],0)) ?>% payout</small></td><td><span class="status status--<?= e($row['status']) ?>"><?= e($row['status']) ?></span></td><td class="<?= (float)$row['profit_loss']>=0?'amount-positive':'amount-negative' ?>"><?= e(money($row['profit_loss'])) ?></td><td><?= e(date('d M H:i:s',strtotime($row['opened_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php
elseif ($page === 'requests'):
    $requestStatus=in_array((string)($_GET['status']??'pending'),['awaiting_payment','pending','approved','paid','rejected','cancelled','expired','all'],true)?(string)$_GET['status']:'pending';
    $where=$requestStatus==='all'?'':' WHERE r.status=?';$params=$requestStatus==='all'?[]:[$requestStatus];
    $stmt=db()->prepare("SELECT r.*,u.name,u.email,m.name method_name,m.network,p.code promo_code,reviewer.name reviewer_name FROM fund_requests r JOIN users u ON u.id=r.user_id LEFT JOIN payment_methods m ON m.id=r.payment_method_id LEFT JOIN promo_codes p ON p.id=r.promo_code_id LEFT JOIN users reviewer ON reviewer.id=r.reviewed_by".$where." ORDER BY CASE WHEN r.status='pending' THEN 0 WHEN r.status='approved' THEN 1 ELSE 2 END,r.id DESC LIMIT 150");$stmt->execute($params);$requests=$stmt->fetchAll();
?>
<section class="admin-card"><div class="admin-card-head admin-card-head--filters"><div><span class="eyebrow">Finance queue</span><h2>Deposit &amp; withdrawal requests</h2></div><div class="filter-pills"><?php foreach(['pending','awaiting_payment','approved','paid','rejected','cancelled','expired','all'] as $s): ?><a class="<?= $requestStatus===$s?'active':'' ?>" href="?page=requests&status=<?= $s ?>"><?= e(ucwords(str_replace('_',' ',$s))) ?></a><?php endforeach; ?></div></div><div class="admin-info-banner admin-info-banner--amber"><?= icon('info',18) ?><span>Only user-confirmed deposits enter Pending. Awaiting payment orders cannot be approved and expire automatically. Verify the reference and proof before approval.</span></div><div class="request-review-grid"><?php if(!$requests): ?><div class="admin-empty"><?= icon('check',26) ?><strong>No requests in this view</strong><span>The selected queue is clear.</span></div><?php endif; ?><?php foreach($requests as $row): $destination=json_decode((string)$row['destination_json'],true)?:[]; ?><article class="review-card"><div class="review-card-head"><span class="metric-icon <?= $row['request_type']==='deposit'?'metric-icon--green':'metric-icon--red' ?>"><?= icon($row['request_type']==='deposit'?'plus':'minus',20) ?></span><div><span class="eyebrow"><?= e($row['request_type']) ?></span><h3><?= e(money($row['amount'])) ?></h3></div><span class="status status--<?= e($row['status']) ?>"><?= e(ucwords(str_replace('_',' ',$row['status']))) ?></span></div><dl><div><dt>Request</dt><dd><?= e($row['public_id']) ?></dd></div><div><dt>User</dt><dd><?= e($row['name']) ?><small><?= e($row['email']) ?></small></dd></div><div><dt>Method</dt><dd><?= e($row['method_name']?:'Not recorded') ?><?= $row['network']?' · '.e($row['network']):'' ?></dd></div><?php if($row['user_reference']): ?><div><dt>User ref</dt><dd><code><?= e($row['user_reference']) ?></code></dd></div><?php endif; ?><?php if($destination): ?><div><dt>Destination</dt><dd><?= e((string)($destination['account_name']??'')) ?><small><?= e((string)($destination['account_identifier']??'')) ?></small></dd></div><?php endif; ?><?php if((float)$row['bonus_amount']>0): ?><div><dt>Coupon</dt><dd><?= e($row['promo_code']?:'—') ?> · +<?= e(money($row['bonus_amount'])) ?></dd></div><?php endif; ?><?php if($row['proof_filename']): ?><div><dt>Proof</dt><dd><a class="admin-proof-link" href="/en/payment-proof/?id=<?= (int)$row['id'] ?>" target="_blank" rel="noopener">View uploaded image</a></dd></div><?php endif; ?><div><dt>Created</dt><dd><?= e(date('d M Y, h:i A',strtotime($row['created_at']))) ?></dd></div><?php if($row['payment_confirmed_at']): ?><div><dt>Confirmed</dt><dd><?= e(date('d M Y, h:i A',strtotime($row['payment_confirmed_at']))) ?></dd></div><?php endif; ?><?php if($row['payment_expires_at']): ?><div><dt>Payment deadline</dt><dd><?= e(date('d M Y, h:i A',strtotime($row['payment_expires_at']))) ?></dd></div><?php endif; ?><?php if($row['user_note']): ?><div><dt>User note</dt><dd><?= e($row['user_note']) ?></dd></div><?php endif; ?><?php if($row['provider_reference']): ?><div><dt>Provider ref</dt><dd><code><?= e($row['provider_reference']) ?></code></dd></div><?php endif; ?><?php if($row['reviewer_name']): ?><div><dt>Reviewed by</dt><dd><?= e($row['reviewer_name']) ?></dd></div><?php endif; ?></dl><?php if($row['status']==='pending'): ?><form method="post" class="review-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="review_request"><input type="hidden" name="return_page" value="requests"><input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>"><input name="admin_note" maxlength="500" placeholder="Verification note"><button class="button button--primary button--small" name="decision" value="approved">Approve</button><button class="button button--ghost button--small" name="decision" value="rejected">Reject</button></form><?php elseif($row['request_type']==='withdrawal' && $row['status']==='approved'): ?><form method="post" class="review-form review-form--paid"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="mark_request_paid"><input type="hidden" name="return_page" value="requests"><input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>"><input name="provider_reference" minlength="3" maxlength="120" placeholder="Bank/UPI/chain provider reference" required><input name="admin_note" maxlength="500" placeholder="Payout note"><button class="button button--primary button--small">Mark paid</button></form><?php else: ?><p class="review-note"><?= e($row['admin_note']?:($row['status']==='awaiting_payment'?'Waiting for user payment confirmation.':'No review note.')) ?></p><?php endif; ?></article><?php endforeach; ?></div></section>
<?php
elseif ($page === 'payments'):
    $methods=payment_methods_for('deposit',false);
?>
<section class="admin-page-intro"><div><span class="eyebrow">Payment gateway setup</span><h2>UPI, wallets, bank &amp; crypto</h2><p>Add account IDs, QR images, limits, fees, and availability. Existing methods are disabled instead of deleted to preserve request history.</p></div></section><section class="payment-admin-grid"><?php foreach($methods as $method): ?><article class="admin-card payment-admin-card"><div class="admin-card-head"><div><span class="eyebrow"><?= e($method['category']) ?></span><h2><?= e($method['name']) ?></h2></div><span class="status status--<?= $method['is_active']?'active':'blocked' ?>"><?= $method['is_active']?'Active':'Disabled' ?></span></div><form method="post" enctype="multipart/form-data" class="admin-editor-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="payment_method_save"><input type="hidden" name="return_page" value="payments"><input type="hidden" name="method_id" value="<?= (int)$method['id'] ?>"><div class="field-grid"><label class="field"><span>Code</span><input value="<?= e($method['code']) ?>" disabled></label><label class="field"><span>Name</span><input name="name" value="<?= e($method['name']) ?>" required></label></div><div class="field-grid field-grid--3"><label class="field"><span>Category</span><select name="category"><?php foreach(['upi','bank','wallet','crypto','other'] as $category): ?><option value="<?= $category ?>" <?= $method['category']===$category?'selected':'' ?>><?= ucfirst($category) ?></option><?php endforeach; ?></select></label><label class="field"><span>Icon key</span><input name="icon_key" value="<?= e($method['icon_key']) ?>"></label><label class="field"><span>Network</span><input name="network" value="<?= e((string)$method['network']) ?>"></label></div><div class="field-grid"><label class="field"><span>Account name</span><input name="account_name" value="<?= e((string)$method['account_name']) ?>"></label><label class="field"><span>UPI ID / payment ID / address</span><input name="account_identifier" value="<?= e((string)$method['account_identifier']) ?>"></label></div><label class="field"><span>User instructions</span><textarea name="instructions" rows="3" maxlength="3000"><?= e((string)$method['instructions']) ?></textarea></label><div class="field-grid field-grid--3"><label class="field"><span>Minimum</span><input type="number" name="minimum_amount" min="0.01" step="0.01" value="<?= e($method['minimum_amount']) ?>" required></label><label class="field"><span>Maximum</span><input type="number" name="maximum_amount" min="0.01" step="0.01" value="<?= e($method['maximum_amount']) ?>" required></label><label class="field"><span>Sort</span><input type="number" name="sort_order" value="<?= (int)$method['sort_order'] ?>"></label></div><div class="field-grid"><label class="field"><span>Fee %</span><input type="number" name="fee_percent" min="0" max="100" step="0.001" value="<?= e($method['fee_percent']) ?>"></label><label class="field"><span>Fixed fee</span><input type="number" name="fee_fixed" min="0" step="0.01" value="<?= e($method['fee_fixed']) ?>"></label></div><div class="payment-flags"><?php foreach(['deposit_enabled'=>'Deposit','withdrawal_enabled'=>'Withdrawal','require_reference'=>'Require reference','require_proof'=>'Require proof','is_active'=>'Active'] as $key=>$label): ?><label class="check-field"><input type="checkbox" name="<?= $key ?>" value="1" <?= $method[$key]?'checked':'' ?>><span><?= e($label) ?></span></label><?php endforeach; ?></div><div class="qr-admin-row"><?php if($method['qr_filename']): ?><img src="/en/payment-method-image/?id=<?= (int)$method['id'] ?>" alt="Current QR"><label class="check-field"><input type="checkbox" name="remove_qr" value="1"><span>Remove current QR</span></label><?php endif; ?><label class="field"><span><?= $method['qr_filename']?'Replace QR image':'Upload QR image' ?></span><input type="file" name="qr_image" accept="image/jpeg,image/png,image/webp"></label></div><button class="button button--primary button--wide">Save method</button></form></article><?php endforeach; ?><article class="admin-card payment-admin-card"><div class="admin-card-head"><div><span class="eyebrow">New method</span><h2>Add payment option</h2></div><?= icon('plus',22) ?></div><form method="post" enctype="multipart/form-data" class="admin-editor-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="payment_method_save"><input type="hidden" name="return_page" value="payments"><div class="field-grid"><label class="field"><span>Unique code</span><input name="code" pattern="[a-z0-9_-]{2,50}" required></label><label class="field"><span>Name</span><input name="name" required></label></div><div class="field-grid field-grid--3"><label class="field"><span>Category</span><select name="category"><option value="upi">UPI</option><option value="bank">Bank</option><option value="wallet">Wallet</option><option value="crypto">Crypto</option><option value="other">Other</option></select></label><label class="field"><span>Icon key</span><input name="icon_key" value="wallet"></label><label class="field"><span>Network</span><input name="network"></label></div><div class="field-grid"><label class="field"><span>Account name</span><input name="account_name"></label><label class="field"><span>Payment ID / address</span><input name="account_identifier"></label></div><label class="field"><span>Instructions</span><textarea name="instructions" rows="3"></textarea></label><div class="field-grid field-grid--3"><label class="field"><span>Minimum</span><input type="number" name="minimum_amount" value="10" min="0.01" step="0.01" required></label><label class="field"><span>Maximum</span><input type="number" name="maximum_amount" value="100000" min="0.01" step="0.01" required></label><label class="field"><span>Sort</span><input type="number" name="sort_order" value="100"></label></div><input type="hidden" name="fee_percent" value="0"><input type="hidden" name="fee_fixed" value="0"><div class="payment-flags"><label class="check-field"><input type="checkbox" name="deposit_enabled" value="1" checked><span>Deposit</span></label><label class="check-field"><input type="checkbox" name="withdrawal_enabled" value="1"><span>Withdrawal</span></label><label class="check-field"><input type="checkbox" name="require_reference" value="1" checked><span>Require reference</span></label><label class="check-field"><input type="checkbox" name="require_proof" value="1"><span>Require proof</span></label><label class="check-field"><input type="checkbox" name="is_active" value="1" checked><span>Active</span></label></div><label class="field"><span>QR image</span><input type="file" name="qr_image" accept="image/jpeg,image/png,image/webp"></label><button class="button button--primary button--wide">Add method</button></form></article></section>
<?php
elseif ($page === 'coupons'):
    $promos=db()->query('SELECT * FROM promo_codes ORDER BY id DESC')->fetchAll();
?>
<section class="admin-page-intro"><div><span class="eyebrow">Promotion controls</span><h2>Gift codes &amp; deposit coupons</h2><p>Gift codes add a fixed wallet credit. Deposit coupons reserve a fixed or percentage bonus and credit it only after approval.</p></div></section><section class="promo-admin-grid"><?php $promoRows=array_merge($promos,[null]); foreach($promoRows as $promo): $isNew=$promo===null; ?><article class="admin-card promo-card"><div class="admin-card-head"><div><span class="eyebrow"><?= $isNew?'New promotion':e($promo['code_type']) ?></span><h2><?= $isNew?'Create code':e($promo['code']) ?></h2></div><?php if(!$isNew): ?><span class="status status--<?= $promo['is_active']?'active':'blocked' ?>"><?= $promo['is_active']?'Active':'Disabled' ?></span><?php endif; ?></div><form method="post" class="admin-editor-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="promo_save"><input type="hidden" name="return_page" value="coupons"><?php if(!$isNew): ?><input type="hidden" name="promo_id" value="<?= (int)$promo['id'] ?>"><?php endif; ?><div class="field-grid"><label class="field"><span>Code</span><input name="code" value="<?= e((string)($promo['code']??'')) ?>" pattern="[A-Za-z0-9_-]{3,40}" required></label><label class="field"><span>Title</span><input name="title" value="<?= e((string)($promo['title']??'')) ?>" required></label></div><div class="field-grid"><label class="field"><span>Code type</span><select name="code_type"><option value="gift" <?= ($promo['code_type']??'gift')==='gift'?'selected':'' ?>>Gift credit</option><option value="deposit_bonus" <?= ($promo['code_type']??'')==='deposit_bonus'?'selected':'' ?>>Deposit bonus</option></select></label><label class="field"><span>Value type</span><select name="value_type"><option value="fixed" <?= ($promo['value_type']??'fixed')==='fixed'?'selected':'' ?>>Fixed</option><option value="percent" <?= ($promo['value_type']??'')==='percent'?'selected':'' ?>>Percent</option></select></label></div><div class="field-grid field-grid--3"><label class="field"><span>Value</span><input type="number" name="value_amount" min="0.01" step="0.01" value="<?= e((string)($promo['value_amount']??'10')) ?>" required></label><label class="field"><span>Minimum deposit</span><input type="number" name="minimum_amount" min="0" step="0.01" value="<?= e((string)($promo['minimum_amount']??'0')) ?>"></label><label class="field"><span>Maximum bonus</span><input type="number" name="maximum_bonus" min="0.01" step="0.01" value="<?= e((string)($promo['maximum_bonus']??'')) ?>"></label></div><div class="field-grid"><label class="field"><span>Total usage limit</span><input type="number" name="usage_limit" min="1" value="<?= e((string)($promo['usage_limit']??'')) ?>"></label><label class="field"><span>Per-user limit</span><input type="number" name="per_user_limit" min="1" max="100" value="<?= (int)($promo['per_user_limit']??1) ?>"></label></div><div class="field-grid"><label class="field"><span>Starts at</span><input type="datetime-local" name="starts_at" value="<?= !empty($promo['starts_at'])?e(date('Y-m-d\TH:i',strtotime($promo['starts_at']))):'' ?>"></label><label class="field"><span>Expires at</span><input type="datetime-local" name="expires_at" value="<?= !empty($promo['expires_at'])?e(date('Y-m-d\TH:i',strtotime($promo['expires_at']))):'' ?>"></label></div><label class="check-field"><input type="checkbox" name="is_active" value="1" <?= $isNew||$promo['is_active']?'checked':'' ?>><span>Active and redeemable</span></label><?php if(!$isNew): ?><div class="promo-usage"><span>Used <?= number_format((int)$promo['usage_count']) ?><?= $promo['usage_limit']!==null?' / '.number_format((int)$promo['usage_limit']):'' ?></span></div><?php endif; ?><button class="button button--primary button--wide"><?= $isNew?'Create code':'Save code' ?></button></form></article><?php endforeach; ?></section>
<?php
elseif ($page === 'support'):
    $supportStatus=in_array((string)($_GET['status']??'open'),['open','answered','closed','all'],true)?(string)$_GET['status']:'open';
    $where=$supportStatus==='all'?'':' WHERE s.status=?';$params=$supportStatus==='all'?[]:[$supportStatus];
    $stmt=db()->prepare('SELECT s.*,u.name,u.email,a.name assigned_name,r.public_id order_public_id,r.request_type order_type,r.amount order_amount,r.status order_status FROM support_tickets s JOIN users u ON u.id=s.user_id LEFT JOIN users a ON a.id=s.assigned_to LEFT JOIN fund_requests r ON r.id=s.fund_request_id'.$where." ORDER BY CASE WHEN s.status='open' THEN 0 WHEN s.status='answered' THEN 1 ELSE 2 END,s.id DESC LIMIT 150");$stmt->execute($params);$tickets=$stmt->fetchAll();
?>
<section class="admin-card"><div class="admin-card-head admin-card-head--filters"><div><span class="eyebrow">Help queue</span><h2>User support requests</h2></div><div class="filter-pills"><?php foreach(['open','answered','closed','all'] as $s): ?><a class="<?= $supportStatus===$s?'active':'' ?>" href="?page=support&status=<?= $s ?>"><?= ucfirst($s) ?></a><?php endforeach; ?></div></div><div class="support-admin-grid"><?php if(!$tickets): ?><div class="admin-empty"><?= icon('check',26) ?><strong>No support requests here</strong><span>The selected queue is clear.</span></div><?php endif; ?><?php foreach($tickets as $ticket): ?><article class="support-admin-card"><header><span><span class="eyebrow"><?= e($ticket['category']) ?></span><h3><?= e($ticket['subject']) ?></h3><small><?= e($ticket['public_id']) ?> · <?= e($ticket['name']) ?> · <?= e($ticket['email']) ?></small></span><b class="status status--<?= e($ticket['status']) ?>"><?= e($ticket['status']) ?></b></header><?php if($ticket['order_public_id']): ?><div class="admin-info-banner"><span><?= icon('payment',16) ?> Related <?= e($ticket['order_type']) ?> order <strong><?= e($ticket['order_public_id']) ?></strong> · <?= e(money($ticket['order_amount'])) ?> · <?= e(ucwords(str_replace('_',' ',$ticket['order_status']))) ?></span></div><?php endif; ?><p><?= nl2br(e($ticket['message'])) ?></p><?php if($ticket['admin_response']): ?><div class="support-current-response"><strong>Current response<?= $ticket['assigned_name']?' · '.e($ticket['assigned_name']):'' ?></strong><p><?= nl2br(e($ticket['admin_response'])) ?></p></div><?php endif; ?><?php if($ticket['status']!=='closed'): ?><form method="post" class="admin-editor-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="support_reply"><input type="hidden" name="return_page" value="support"><input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>"><label class="field"><span>Administrator response</span><textarea name="admin_response" rows="4" minlength="3" maxlength="5000" required><?= e((string)$ticket['admin_response']) ?></textarea></label><label class="field"><span>Status</span><select name="ticket_status"><option value="answered">Answered</option><option value="closed">Answered &amp; closed</option></select></label><button class="button button--primary button--small">Save response</button></form><?php endif; ?></article><?php endforeach; ?></div></section>
<?php
elseif ($page === 'assets'):
    $assets=market_assets(false);
?>
<section class="admin-page-intro"><div><span class="eyebrow">Market configuration</span><h2>Price sources &amp; payout</h2><p>Use the internal feed, a manual global reference price, or a cached HTTPS API feed. Changes affect every user and are audit logged.</p></div><span class="admin-lock-note"><?= icon('shield',18) ?> No per-user result controls</span></section>
<section class="admin-trading-control-strip">
    <article class="is-<?= (bool)setting('trading_enabled',true)?'live':'paused' ?>"><span><?= icon('activity',21) ?></span><div><small>Global trading</small><strong><?= (bool)setting('trading_enabled',true)?'Live':'Paused' ?></strong></div></article>
    <article class="is-demo"><span><?= icon('user',21) ?></span><div><small>Demo Account</small><strong><?= (bool)setting('demo_trading_enabled',true)?'Enabled':'Disabled' ?></strong></div></article>
    <article class="is-chart"><span><?= icon('chart',21) ?></span><div><small>Chart engine</small><strong><?= (int)setting('chart_history_candles',140) ?> candles · <?= (int)setting('chart_tick_density',10) ?> ticks</strong></div></article>
    <article class="is-speed"><span><?= icon('clock',21) ?></span><div><small>Quote refresh</small><strong><?= (int)setting('chart_quote_interval_ms',650) ?> ms</strong></div></article>
    <a href="?page=settings"><?= icon('settings',17) ?> Advanced controls</a>
</section>
<section class="admin-card market-create-card"><div class="admin-card-head"><div><span class="eyebrow">New instrument</span><h2><?= icon('plus',18) ?> Add market</h2></div><small>Manual/API source is global, never per-user.</small></div><form method="post" class="market-create-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="market_create"><input type="hidden" name="return_page" value="assets"><div class="field-grid field-grid--4"><label class="field"><span>Symbol</span><input name="symbol" placeholder="EURUSD" required></label><label class="field"><span>Display name</span><input name="display_name" placeholder="EUR/USD (OTC)" required></label><label class="field"><span>Category</span><input name="category" value="Forex" required></label><label class="field"><span>Decimals</span><input type="number" name="decimal_places" min="0" max="8" value="5"></label></div><div class="field-grid field-grid--4"><label class="field"><span>Reference price</span><input type="number" name="base_price" min="0.00000001" step="0.00000001" required></label><label class="field"><span>Payout %</span><input type="number" name="payout_percent" min="1" max="95" step="0.01" value="82"></label><label class="field"><span>Volatility</span><input type="number" name="volatility" min="0.00001" max="0.2" step="0.00001" value="0.00250"></label><label class="field"><span>Sort</span><input type="number" name="sort_order" value="0"></label></div><div class="field-grid"><label class="field"><span>Price source</span><select name="price_source"><option value="internal">Internal engine</option><option value="manual">Manual global reference</option><option value="api">HTTPS API cache</option></select></label><label class="field"><span>API symbol (optional)</span><input name="api_symbol" placeholder="EURUSD"></label></div><button class="button button--primary button--small">Add market</button></form></section>
<section class="asset-admin-grid"><?php foreach($assets as $asset): ?><article class="asset-admin-card"><form method="post" class="asset-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="asset_update"><input type="hidden" name="return_page" value="assets"><input type="hidden" name="asset_id" value="<?= (int)$asset['id'] ?>"><div class="asset-card-head"><span class="market-symbol-icon"><?= e(substr($asset['display_name'],0,1)) ?></span><div><strong><?= e($asset['symbol']) ?></strong><small><?= e($asset['category']) ?></small></div><label class="switch"><input type="checkbox" name="is_active" value="1" <?= $asset['is_active']?'checked':'' ?>><span></span></label></div><label class="field"><span>Display name</span><input name="display_name" value="<?= e($asset['display_name']) ?>" required></label><div class="field-grid"><label class="field"><span>Price source</span><select name="price_source"><option value="internal" <?= $asset['price_source']==='internal'?'selected':'' ?>>Internal feed</option><option value="manual" <?= $asset['price_source']==='manual'?'selected':'' ?>>Manual reference</option><option value="api" <?= $asset['price_source']==='api'?'selected':'' ?>>HTTPS API cache</option></select></label><label class="field"><span>API symbol</span><input name="api_symbol" value="<?= e((string)$asset['api_symbol']) ?>" placeholder="<?= e($asset['symbol']) ?>"></label></div><div class="field-grid"><label class="field"><span>Reference price</span><input type="number" name="base_price" min="0.00000001" max="1000000000000" step="0.00000001" value="<?= e($asset['base_price']) ?>" required></label><label class="field"><span>Volatility</span><input type="number" name="volatility" min="0.00001" max="0.2" step="0.00001" value="<?= e($asset['volatility']) ?>" required></label></div><div class="field-grid"><label class="field"><span>Payout %</span><input type="number" name="payout_percent" min="1" max="95" step="0.01" value="<?= e($asset['payout_percent']) ?>" required></label><label class="field"><span>Sort</span><input type="number" name="sort_order" value="<?= (int)$asset['sort_order'] ?>" required></label></div><div class="asset-engine-row"><span>Current reference</span><strong><?= e(number_format(market_reference_price($asset),(int)$asset['decimal_places'],'.','')) ?></strong></div><?php if($asset['source_fetched_at']): ?><div class="asset-engine-row"><span>API updated</span><strong><?= e(date('d M H:i:s',strtotime($asset['source_fetched_at']))) ?></strong></div><?php endif; ?><button class="button button--primary button--wide button--small">Save market</button></form><?php if($asset['price_source']==='api'): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="market_api_refresh"><input type="hidden" name="return_page" value="assets"><input type="hidden" name="asset_id" value="<?= (int)$asset['id'] ?>"><button class="button button--ghost button--wide button--small">Refresh API price now</button></form><?php endif; ?></article><?php endforeach; ?></section>
<?php
elseif ($page === 'admins'):
    $admins=db()->query("SELECT id,name,email,role,status,last_login_at,created_at FROM users WHERE role IN ('manager','admin','super_admin') ORDER BY FIELD(role,'super_admin','admin','manager'),id")->fetchAll();
?>
<section class="admin-grid admin-grid--admins"><article class="admin-card"><div class="admin-card-head"><div><span class="eyebrow">Access control</span><h2>Administrator accounts</h2></div></div><div class="admin-list"><?php foreach($admins as $row): ?><div class="admin-list-row"><span class="avatar"><?= e(strtoupper(substr($row['name'],0,1))) ?></span><div><strong><?= e($row['name']) ?></strong><small><?= e($row['email']) ?> · <?= e(ucwords(str_replace('_',' ',$row['role']))) ?></small></div><span class="status status--<?= e($row['status']) ?>"><?= e($row['status']) ?></span><?php if($row['role']!=='super_admin'&&(int)$row['id']!==(int)$admin['id']): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="admin_update"><input type="hidden" name="return_page" value="admins"><input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>"><select name="role"><option value="manager" <?= $row['role']==='manager'?'selected':'' ?>>Manager</option><option value="admin" <?= $row['role']==='admin'?'selected':'' ?>>Admin</option></select><select name="status"><option value="active" <?= $row['status']==='active'?'selected':'' ?>>Active</option><option value="blocked" <?= $row['status']==='blocked'?'selected':'' ?>>Blocked</option></select><button class="button button--ghost button--small">Update</button></form><?php else: ?><span class="protected-label"><?= icon('shield',14) ?> Protected</span><?php endif; ?></div><?php endforeach; ?></div></article><article class="admin-card create-admin-card"><div class="admin-card-head"><div><span class="eyebrow">New access</span><h2>Add administrator</h2></div></div><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="admin_create"><input type="hidden" name="return_page" value="admins"><label class="field"><span>Full name</span><input name="name" minlength="2" maxlength="80" required></label><label class="field"><span>Email</span><input type="email" name="email" required></label><label class="field"><span>Temporary password</span><input type="password" name="password" minlength="12" required></label><label class="field"><span>Role</span><select name="role"><option value="manager">Manager · requests &amp; support</option><option value="admin">Admin · users, finance, markets, settings</option></select></label><button class="button button--primary button--wide">Create administrator</button></form></article></section>
<?php
elseif ($page === 'settings'):
    $rows=db()->query('SELECT setting_key,setting_value FROM site_settings')->fetchAll();$settings=[];foreach($rows as $row){$settings[$row['setting_key']]=$row['setting_value'];}
    $currentAnnouncement=db()->query('SELECT id,title,message,image_filename,level,created_at FROM announcements WHERE is_active=1 ORDER BY id DESC LIMIT 1')->fetch();
    $isChecked=static function(string $key,string $default='1')use($settings):bool{return in_array(strtolower((string)($settings[$key]??$default)),['1','true','yes'],true);};
    $configuredExchangeRates=json_decode((string)($settings['currency_exchange_rates']??'{"USD":1,"INR":83,"EUR":0.92,"GBP":0.72}'),true);if(!is_array($configuredExchangeRates))$configuredExchangeRates=['USD'=>1,'INR'=>83,'EUR'=>0.92,'GBP'=>0.72];
?>
<?php if ($currentAnnouncement): ?>
<section class="admin-info-banner admin-info-banner--amber"><span><?= icon('info',18) ?> Current trade-room announcement: <strong><?= e($currentAnnouncement['title']) ?></strong></span><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="announcement_disable"><input type="hidden" name="return_page" value="settings"><button class="button button--ghost button--small">Disable notice</button></form></section>
<?php endif; ?>
<span id="paymentOrderSettingSeed" data-payment-order-minutes="<?= e($settings['payment_order_minutes'] ?? '15') ?>" hidden></span>
<section class="admin-grid admin-grid--settings"><article class="admin-card settings-card"><div class="admin-card-head"><div><span class="eyebrow">Global configuration</span><h2>Platform settings</h2></div></div><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="settings_update"><input type="hidden" name="return_page" value="settings"><div class="settings-section"><h3>Identity</h3><div class="field-grid"><label class="field"><span>Platform name</span><input name="platform_name" value="<?= e($settings['platform_name']??'55AK Trade') ?>" required></label><label class="field"><span>Tagline</span><input name="platform_tagline" value="<?= e($settings['platform_tagline']??'Smart market trading platform') ?>" required></label></div><div class="field-grid"><label class="field"><span>HTTPS domain</span><input type="url" name="platform_domain" value="<?= e($settings['platform_domain']??'https://trading.55ak.xyz') ?>" required></label><label class="field"><span>Support email</span><input type="email" name="support_email" value="<?= e($settings['support_email']??'support@55ak.xyz') ?>" required></label></div></div><div class="settings-section"><h3>Wallet &amp; requests</h3><div class="field-grid field-grid--3"><label class="field"><span>Live account start</span><input type="number" name="demo_starting_balance" min="0" step="0.01" value="<?= e($settings['demo_starting_balance']??'0') ?>"></label><label class="field"><span>Demo account start</span><input type="number" name="practice_starting_balance" min="0" max="100000000" step="0.01" value="<?= e($settings['practice_starting_balance']??'1000') ?>"></label><label class="field"><span>Minimum deposit</span><input type="number" name="minimum_demo_deposit" min="0.01" step="0.01" value="<?= e($settings['minimum_demo_deposit']??'10') ?>"></label><label class="field"><span>Minimum withdrawal</span><input type="number" name="minimum_demo_withdrawal" min="0.01" step="0.01" value="<?= e($settings['minimum_demo_withdrawal']??'20') ?>"></label></div><div class="field-grid"><label class="field"><span>Wallet currency</span><input name="wallet_currency" pattern="[A-Z]{3}" value="<?= e($settings['wallet_currency']??'USD') ?>"></label><label class="field"><span>Proof upload max MB</span><input type="number" name="payment_proof_max_mb" min="1" max="10" value="<?= e($settings['payment_proof_max_mb']??'5') ?>"></label></div><div class="setting-toggle-grid"><label class="setting-toggle"><span><strong>Deposits enabled</strong><small>Allow new deposit requests.</small></span><span class="switch"><input type="checkbox" name="deposits_enabled" value="1" <?= $isChecked('deposits_enabled')?'checked':'' ?>><span></span></span></label><label class="setting-toggle"><span><strong>Withdrawals enabled</strong><small>Allow new withdrawal requests.</small></span><span class="switch"><input type="checkbox" name="withdrawals_enabled" value="1" <?= $isChecked('withdrawals_enabled')?'checked':'' ?>><span></span></span></label></div></div><div class="settings-section"><h3>Trade limits</h3><div class="field-grid field-grid--3"><label class="field"><span>Minimum amount</span><input type="number" name="minimum_trade_amount" min="0.01" step="0.01" value="<?= e($settings['minimum_trade_amount']??'1') ?>"></label><label class="field"><span>Maximum amount</span><input type="number" name="maximum_trade_amount" min="0.01" step="0.01" value="<?= e($settings['maximum_trade_amount']??'1000') ?>"></label><label class="field"><span>Durations (seconds)</span><input name="trade_durations" value="<?= e(implode(',',json_decode($settings['trade_durations']??'[5,10,15,30,60,120,300]',true)?:[5,10,15,30,60])) ?>"></label></div><div class="setting-toggle-grid"><label class="setting-toggle"><span><strong>Trading enabled</strong><small>Pause new trades without affecting settlement.</small></span><span class="switch"><input type="checkbox" name="trading_enabled" value="1" <?= $isChecked('trading_enabled')?'checked':'' ?>><span></span></span></label><label class="setting-toggle"><span><strong>Demo Account enabled</strong><small>Allow users to switch to the separate practice balance.</small></span><span class="switch"><input type="checkbox" name="demo_trading_enabled" value="1" <?= $isChecked('demo_trading_enabled')?'checked':'' ?>><span></span></span></label><label class="setting-toggle"><span><strong>Maintenance mode</strong><small>Show a maintenance page to non-admin users.</small></span><span class="switch"><input type="checkbox" name="maintenance_mode" value="1" <?= $isChecked('maintenance_mode','0')?'checked':'' ?>><span></span></span></label></div></div><div class="settings-section settings-section--advanced"><h3>Advanced chart engine</h3><p class="admin-setting-copy">These global controls tune chart density and refresh speed for every trader without changing individual outcomes.</p><div class="field-grid field-grid--3"><label class="field"><span>History candles</span><input type="number" name="chart_history_candles" min="60" max="180" step="1" value="<?= e($settings['chart_history_candles']??'140') ?>"></label><label class="field"><span>Ticks per candle</span><input type="number" name="chart_tick_density" min="4" max="16" step="1" value="<?= e($settings['chart_tick_density']??'10') ?>"></label><label class="field"><span>Quote refresh (ms)</span><input type="number" name="chart_quote_interval_ms" min="250" max="2000" step="50" value="<?= e($settings['chart_quote_interval_ms']??'650') ?>"></label></div></div><div class="settings-section settings-section--exchange"><h3>Account currency exchange</h3><p class="admin-setting-copy">Rates are operator-configured relative units, not a live FX feed. Update them before enabling exchange for real wallets.</p><div class="field-grid field-grid--4"><?php foreach(['USD','INR','EUR','GBP'] as $currencyCode): ?><label class="field"><span><?= e($currencyCode) ?> rate</span><input type="number" name="exchange_rate_<?= e(strtolower($currencyCode)) ?>" min="0.000001" max="1000000000" step="0.000001" value="<?= e((string)($configuredExchangeRates[$currencyCode]??1)) ?>" required></label><?php endforeach; ?></div><div class="field-grid"><label class="field"><span>Exchange fee %</span><input type="number" name="currency_exchange_fee_percent" min="0" max="25" step="0.01" value="<?= e($settings['currency_exchange_fee_percent']??'3') ?>"></label><label class="setting-toggle"><span><strong>Currency exchange enabled</strong><small>Allow users with no open trade/order to convert.</small></span><span class="switch"><input type="checkbox" name="currency_exchange_enabled" value="1" <?= $isChecked('currency_exchange_enabled')?'checked':'' ?>><span></span></span></label></div></div><div class="settings-section"><h3>HTTPS market API</h3><label class="field"><span>Endpoint URL <small>(must contain {symbol})</small></span><input name="market_api_url" value="<?= e($settings['market_api_url']??'') ?>" placeholder="https://provider.example/price?symbol={symbol}"></label><div class="field-grid"><label class="field"><span>JSON price path</span><input name="market_api_price_path" value="<?= e($settings['market_api_price_path']??'price') ?>" placeholder="data.{symbol}.price"></label><label class="field"><span>New bearer API key</span><input type="password" name="market_api_key" autocomplete="new-password" placeholder="Leave blank to keep current key"></label></div><label class="check-field"><input type="checkbox" name="clear_market_api_key" value="1"><span>Clear the saved API key</span></label></div><button class="button button--primary">Save platform settings</button></form></article><article class="admin-card announcement-card"><div class="admin-card-head"><div><span class="eyebrow">Trade-room notice</span><h2>Announcement</h2></div></div><?php if($currentAnnouncement): ?><div class="current-announcement"><span class="status status--active">Live</span><?php if(!empty($currentAnnouncement['image_filename'])): ?><img class="admin-announcement-preview" src="/en/announcement-image/?id=<?= (int)$currentAnnouncement['id'] ?>" alt="Announcement banner"><?php endif; ?><strong><?= e($currentAnnouncement['title']) ?></strong><p><?= e($currentAnnouncement['message']) ?></p><small>Published <?= e(date('d M Y H:i',strtotime($currentAnnouncement['created_at']))) ?></small></div><?php endif; ?><form method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="announcement_create"><input type="hidden" name="return_page" value="settings"><label class="field"><span>Title</span><input name="title" minlength="3" maxlength="120" required></label><label class="field"><span>Message</span><textarea name="message" minlength="5" maxlength="1000" rows="4" required></textarea></label><label class="field"><span>Banner image <small>(optional JPG/PNG/WebP)</small></span><input type="file" name="banner_image" accept="image/jpeg,image/png,image/webp"></label><label class="field"><span>Style</span><select name="level"><option value="info">Information</option><option value="success">Success</option><option value="warning">Warning</option></select></label><button class="button button--ghost button--wide">Publish announcement</button></form></article></section>
<?php
elseif ($page === 'finance'):
    render_admin_finance_page();
elseif ($page === 'agents'):
    render_admin_agents_page();
elseif ($page === 'notifications'):
    render_admin_notifications_page();
elseif ($page === 'branding'):
    render_admin_branding_page();
elseif ($page === 'trading'):
    render_admin_trading_control_page();
elseif ($page === 'market_api'):
    render_admin_market_api_page();
elseif ($page === 'audit'):
    $logs=db()->query('SELECT l.*,u.name admin_name,u.email admin_email FROM admin_audit_logs l LEFT JOIN users u ON u.id=l.admin_id ORDER BY l.id DESC LIMIT 300')->fetchAll();
?>
<section class="admin-card"><div class="admin-card-head"><div><span class="eyebrow">Operations trail</span><h2>Latest 300 events</h2></div><span class="admin-lock-note"><?= icon('shield',16) ?> Read only</span></div><div class="admin-table-wrap"><table><thead><tr><th>Time</th><th>Administrator</th><th>Action</th><th>Target</th><th>Details</th><th>IP</th></tr></thead><tbody><?php if(!$logs): ?><tr><td colspan="6" class="table-empty">No administrator events yet.</td></tr><?php endif; ?><?php foreach($logs as $row): ?><tr><td><?= e(date('d M Y H:i:s',strtotime($row['created_at']))) ?></td><td><strong><?= e($row['admin_name']??'Deleted account') ?></strong><small><?= e($row['admin_email']??'') ?></small></td><td><span class="audit-action"><?= e(ucwords(str_replace('_',' ',$row['action']))) ?></span></td><td><?= e($row['target_type']) ?><?= $row['target_id']?' #'.(int)$row['target_id']:'' ?></td><td><code class="details-json"><?= e($row['details_json']??'{}') ?></code></td><td><span class="mono"><?= e($row['ip_address']) ?></span></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php endif;

render_admin_end();
