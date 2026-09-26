<?php
declare(strict_types=1);

function allowed_trade_durations(): array
{
    $configured = setting('trade_durations', app_config('trade_durations'));
    if (!is_array($configured)) {
        $configured = app_config('trade_durations');
    }
    $durations = array_values(array_unique(array_filter(array_map('intval', $configured), static fn (int $value): bool => $value >= 5 && $value <= 86400)));
    sort($durations);
    return $durations ?: [5, 10, 15, 30, 60, 120, 300];
}

/**
 * The finance wallet intentionally keeps the historical `demo_balance` column
 * name for backwards-compatible installs. Trading now exposes that value as
 * the Live account and stores the separate practice account in
 * `demo_trade_balance`.
 */
function trade_account_type(): string
{
    $stored = $_SESSION['trade_account'] ?? null;
    if ($stored === null) {
        $user = current_user();
        $stored = $user['trade_account_type'] ?? 'live';
    }
    $type = strtolower((string)$stored);
    $type = in_array($type, ['live', 'demo'], true) ? $type : 'live';
    if ($type === 'demo' && !(bool)setting('demo_trading_enabled', true)) {
        $_SESSION['trade_account'] = 'live';
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId > 0) {
            db()->prepare("UPDATE users SET trade_account_type='live',updated_at=NOW() WHERE id=?")->execute([$userId]);
            current_user(true);
        }
        return 'live';
    }
    return $type;
}

function set_trade_account_type(string $type, ?int $userId = null): string
{
    $type = strtolower(trim($type));
    if (!in_array($type, ['live', 'demo'], true)) {
        throw new InvalidArgumentException('Choose Live Account or Demo Account.');
    }
    if ($type === 'demo' && !(bool)setting('demo_trading_enabled', true)) {
        throw new RuntimeException('Demo Account is currently disabled by the administrator.');
    }
    $_SESSION['trade_account'] = $type;
    $targetUserId = $userId ?? (int)($_SESSION['user_id'] ?? 0);
    if ($targetUserId > 0) {
        $stmt = db()->prepare('UPDATE users SET trade_account_type=?,updated_at=NOW() WHERE id=?');
        $stmt->execute([$type, $targetUserId]);
        current_user(true);
    }
    return $type;
}

function trade_balance_column(string $accountType): string
{
    return $accountType === 'demo' ? 'demo_trade_balance' : 'demo_balance';
}

function trade_balance_from_user(array $user, ?string $accountType = null): float
{
    $type = $accountType ?? trade_account_type();
    return (float)($user[trade_balance_column($type)] ?? 0);
}

function get_active_trading_control(int $userId, int $assetId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    try {
        $stmt = db()->prepare(
            "SELECT id, asset_id, user_id, direction, win_rate
             FROM trading_controls
             WHERE is_active = 1
               AND (expires_at IS NULL OR expires_at > NOW())
               AND (asset_id IS NULL OR asset_id = 0 OR asset_id = ?)
               AND (user_id IS NULL OR user_id = 0 OR user_id = ?)
             ORDER BY
               (asset_id IS NOT NULL AND asset_id != 0 AND user_id IS NOT NULL AND user_id != 0 AND user_id = ?) DESC,
               (user_id IS NOT NULL AND user_id != 0 AND user_id = ?) DESC,
               (asset_id IS NOT NULL AND asset_id != 0 AND (user_id IS NULL OR user_id = 0)) DESC,
               id DESC
             LIMIT 1"
        );
        $stmt->execute([$assetId, $userId, $userId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $ignored) {
        // Table may not exist on fresh installs; fall through to natural settlement.
        return null;
    }
}

/**
 * Returns the active user_trading_sessions row for a user (and optional asset).
 * Returns null if no active session exists or the table doesn't exist yet.
 */
function get_user_trading_session(int $userId, ?int $assetId = null): ?array
{
    try {
        $stmt = db()->prepare(
            "SELECT id, user_id, asset_id, payout_override, is_blocked, block_reason, expires_at
             FROM user_trading_sessions
             WHERE user_id = ?
               AND (expires_at IS NULL OR expires_at > NOW())
               AND (asset_id IS NULL OR asset_id = ?)
             ORDER BY
               (asset_id IS NOT NULL) DESC
             LIMIT 1"
        );
        $stmt->execute([$userId, $assetId ?? 0]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $ignored) {
        return null;
    }
}


function settle_open_trades(int $userId): int
{
    $stmt = db()->prepare("SELECT t.id FROM trades t WHERE t.user_id = ? AND t.status = 'open' AND t.expires_at <= NOW() ORDER BY t.id ASC LIMIT 100");
    $stmt->execute([$userId]);
    $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    $settled = 0;

    foreach ($ids as $tradeId) {
        $didSettle = db_transaction(function (PDO $pdo) use ($tradeId, $userId): bool {
            $stmt = $pdo->prepare("SELECT t.*,a.id as asset_id,a.symbol,a.display_name,a.base_price,a.decimal_places,a.volatility,a.market_seed,a.price_source,a.api_symbol,c.price source_price,c.source source_name,c.fetched_at source_fetched_at FROM trades t JOIN market_assets a ON a.id=t.asset_id LEFT JOIN market_price_cache c ON c.asset_id=a.id WHERE t.id=? AND t.user_id=? AND t.status='open' FOR UPDATE");
            $stmt->execute([$tradeId, $userId]);
            $trade = $stmt->fetch();
            if (!$trade) {
                return false;
            }

            $asset = [
                'symbol' => $trade['symbol'], 'display_name' => $trade['display_name'], 'base_price' => $trade['base_price'],
                'decimal_places' => $trade['decimal_places'], 'volatility' => $trade['volatility'], 'market_seed' => $trade['market_seed'],
                'price_source' => $trade['price_source'], 'api_symbol' => $trade['api_symbol'], 'source_price' => $trade['source_price'],
            ];
            // Compute the close price at the exact expiry second (stored in DB with
            // second-level precision). The settlement always anchors to this fixed
            // timestamp so the result is idempotent and cannot change on a retry.
            $expiryMs = (int)strtotime((string)$trade['expires_at']) * 1000;
            $closePrice = market_price_at($asset, $expiryMs, false, (int)$trade['user_id']);
            $entryPrice = (float)$trade['entry_price'];
            $tick = pow(10, -(int)$trade['decimal_places']);
            $difference = $closePrice - $entryPrice;

            $status = null;
            $control = get_active_trading_control($userId, (int)$trade['asset_id']);
            if ($control !== null) {
                $status = determine_trade_outcome($trade, $control);
            }

            if (!$status) {
                // Use 1 full tick as the draw zone so micro-fluctuations at exactly
                // the expiry second don't produce surprise win/loss outcomes.
                if (abs($difference) < $tick) {
                    $status = 'draw';
                } else {
                    $wentUp = $difference > 0;
                    $isWin = ($trade['direction'] === 'up' && $wentUp) || ($trade['direction'] === 'down' && !$wentUp);
                    $status = $isWin ? 'won' : 'lost';
                }
            } else {
                // Align recorded close price with forced outcome for seamless visual consistency across all markets
                $decimals = max(0, min(8, (int)($trade['decimal_places'] ?? 2)));
                if ($status === 'won') {
                    if ($trade['direction'] === 'up' && $closePrice <= $entryPrice) {
                        $closePrice = round($entryPrice + ($tick * 2), $decimals);
                    } elseif ($trade['direction'] === 'down' && $closePrice >= $entryPrice) {
                        $closePrice = round(max($tick, $entryPrice - ($tick * 2)), $decimals);
                    }
                } elseif ($status === 'lost') {
                    if ($trade['direction'] === 'up' && $closePrice >= $entryPrice) {
                        $closePrice = round(max($tick, $entryPrice - ($tick * 2)), $decimals);
                    } elseif ($trade['direction'] === 'down' && $closePrice <= $entryPrice) {
                        $closePrice = round($entryPrice + ($tick * 2), $decimals);
                    }
                }
            }

            $accountType = ($trade['account_type'] ?? 'live') === 'demo' ? 'demo' : 'live';
            $balanceColumn = trade_balance_column($accountType);
            $userStmt = $pdo->prepare('SELECT `' . $balanceColumn . '` FROM users WHERE id = ? FOR UPDATE');
            $userStmt->execute([$userId]);
            $before = (float)$userStmt->fetchColumn();
            $credit = 0.0;
            $profitLoss = -(float)$trade['amount'];
            $transactionType = null;

            if ($status === 'won') {
                $profit = round((float)$trade['amount'] * ((float)$trade['payout_percent'] / 100), 2);
                $credit = round((float)$trade['amount'] + $profit, 2);
                $profitLoss = $profit;
                $transactionType = 'trade_win';
            } elseif ($status === 'draw') {
                $credit = (float)$trade['amount'];
                $profitLoss = 0.0;
                $transactionType = 'trade_refund';
            }

            $after = round($before + $credit, 2);
            if ($credit > 0) {
                $updateBalance = $pdo->prepare('UPDATE users SET `' . $balanceColumn . '` = ?, updated_at = NOW() WHERE id = ?');
                $updateBalance->execute([$after, $userId]);

                $ledger = $pdo->prepare('INSERT INTO wallet_transactions (user_id, account_type, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                $ledger->execute([$userId, $accountType, $transactionType, $credit, $before, $after, 'trade', $tradeId, $status === 'won' ? 'Trade return and profit' : 'Draw refund']);
            }

            $update = $pdo->prepare('UPDATE trades SET close_price = ?, status = ?, profit_loss = ?, balance_after = ?, settled_at = NOW(), updated_at = NOW() WHERE id = ?');
            $update->execute([$closePrice, $status, $profitLoss, $after, $tradeId]);
            return true;
        });

        if ($didSettle) {
            $settled++;
        }
    }

    current_user(true);
    return $settled;
}

function place_trade(int $userId, string $symbol, string $direction, float $amount, int $durationSeconds, ?string $requestedAccountType = null, string $clientRequestId = '', ?int $targetCloseTime = null, ?float $clientEntryPrice = null): array
{
    $direction = strtolower(trim($direction));
    if (!in_array($direction, ['up', 'down'], true)) {
        throw new InvalidArgumentException('Choose Up or Down.');
    }

    $now = time();
    $expiresAtTimestamp = 0;
    if ($targetCloseTime !== null && $targetCloseTime > 0) {
        $remaining = $targetCloseTime - $now;
        if ($remaining >= 5 && $remaining <= 86400) {
            $durationSeconds = $remaining;
            $expiresAtTimestamp = $targetCloseTime;
        } else {
            $durationSeconds = max(5, min(86400, $durationSeconds));
            $expiresAtTimestamp = $now + $durationSeconds;
        }
    } else {
        $durationSeconds = max(5, min(86400, $durationSeconds));
        $expiresAtTimestamp = $now + $durationSeconds;
    }

    $minimum = (float)setting('minimum_trade_amount', 1);
    $maximum = (float)setting('maximum_trade_amount', 1000);
    // Financial amounts are normalised to integer cents before comparison and
    // subtraction. A $1.00 stake therefore always debits exactly 100 cents.
    $amountCents = (int)round($amount * 100);
    $minimumCents = (int)round($minimum * 100);
    $maximumCents = (int)round($maximum * 100);
    $amount = $amountCents / 100;
    if ($amountCents < $minimumCents || $amountCents > $maximumCents) {
        throw new InvalidArgumentException('Trade amount must be between ' . money($minimum) . ' and ' . money($maximum) . '.');
    }
    if (!(bool)setting('trading_enabled', true)) {
        throw new RuntimeException('New trades are temporarily paused.');
    }

    settle_open_trades($userId);
    $asset = market_asset($symbol, false);
    if (!$asset) {
        throw new InvalidArgumentException('Selected market is unavailable.');
    }
    if (!(bool)($asset['is_active'] ?? true)) {
        throw new RuntimeException('Market is closed for this asset.');
    }
    $accountType = $requestedAccountType === null ? trade_account_type() : strtolower(trim($requestedAccountType));
    if (!in_array($accountType, ['live', 'demo'], true)) {
        throw new InvalidArgumentException('Choose Live Account or Demo Account.');
    }
    if ($accountType === 'demo' && !(bool)setting('demo_trading_enabled', true)) {
        throw new RuntimeException('Demo Account is currently disabled by the administrator.');
    }
    $clientRequestId = trim($clientRequestId);
    if ($clientRequestId !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $clientRequestId)) {
        throw new InvalidArgumentException('Invalid trade request identifier.');
    }
    $balanceColumn = trade_balance_column($accountType);

    return db_transaction(function (PDO $pdo) use ($userId, $asset, $direction, $amount, $durationSeconds, $expiresAtTimestamp, $accountType, $balanceColumn, $clientRequestId, $clientEntryPrice): array {
        $userStmt = $pdo->prepare('SELECT id, status, demo_balance, demo_trade_balance, currency FROM users WHERE id = ? FOR UPDATE');
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        if (!$user || $user['status'] !== 'active') {
            throw new RuntimeException('Account is not available.');
        }

        $before = (float)$user[$balanceColumn];
        $beforeCents = (int)round($before * 100);
        $amountCents = (int)round($amount * 100);
        if ($clientRequestId !== '') {
            $duplicateStmt = $pdo->prepare('SELECT t.public_id,t.account_type,a.symbol,a.display_name,a.decimal_places,t.direction,t.amount,t.payout_percent,t.entry_price,t.close_price,t.opened_at,t.expires_at,t.settled_at,t.status,t.profit_loss FROM trades t JOIN market_assets a ON a.id=t.asset_id WHERE t.user_id=? AND t.client_request_id=? LIMIT 1');
            $duplicateStmt->execute([$userId, $clientRequestId]);
            $existing = $duplicateStmt->fetch();
            if ($existing) {
                $existingType = ($existing['account_type'] ?? 'live') === 'demo' ? 'demo' : 'live';
                $payload = trade_payload($existing);
                $payload['balance'] = (float)$user[trade_balance_column($existingType)];
                $payload['clientRequestId'] = $clientRequestId;
                $payload['duplicate'] = true;
                return $payload;
            }
        }
        if ($beforeCents < $amountCents) {
            throw new RuntimeException($accountType === 'demo' ? 'Insufficient demo balance.' : 'Insufficient wallet balance.');
        }

        // Check if the user is currently blocked from trading (live account only).
        if ($accountType === 'live') {
            $session = get_user_trading_session($userId, (int)$asset['id']);
            if ($session && (int)$session['is_blocked'] === 1) {
                $reason = trim((string)($session['block_reason'] ?? ''));
                throw new RuntimeException('Trading is currently restricted on your account' . ($reason !== '' ? ': ' . $reason : '.'));
            }
        } else {
            $session = null;
        }

        // Determine effective payout: per-user override > asset default.
        $effectivePayout = (float)$asset['payout_percent'];
        if ($session && $session['payout_override'] !== null) {
            $effectivePayout = max(0.0, min(100.0, (float)$session['payout_override']));
        }

        $now = time();
        $entryNowMs = (int)floor(microtime(true) * 1000);
        $liveTargetPrice = market_price_at($asset, $entryNowMs, true);

        $entryPrice = 0.0;
        if ($clientEntryPrice !== null && $clientEntryPrice > 0) {
            $ref = $liveTargetPrice > 0 ? $liveTargetPrice : (float)($asset['base_price'] ?? 0);
            if ($ref > 0 && abs($clientEntryPrice - $ref) / $ref < 0.05) {
                $decimals = max(0, min(8, (int)($asset['decimal_places'] ?? 2)));
                $entryPrice = round($clientEntryPrice, $decimals);
            }
        }

        if ($entryPrice <= 0.0) {
            $assetWithTarget = array_merge($asset, [
                'target_price' => $liveTargetPrice,
                'closed_price' => $liveTargetPrice,
                'price' => $liveTargetPrice,
            ]);
            $entryPrice = market_price_at($assetWithTarget, $entryNowMs, true);
        }

        try {
            $decimals = max(0, min(8, (int)($asset['decimal_places'] ?? 2)));
            $cachePrice = round($entryPrice, $decimals);
            db()->prepare('INSERT INTO market_price_cache (asset_id,price,source,fetched_at) VALUES (?,?,"live",NOW()) ON DUPLICATE KEY UPDATE price=VALUES(price),source=VALUES(source),fetched_at=NOW()')
                ->execute([(int)$asset['id'], $cachePrice]);
        } catch (Throwable $ignored) {}
        $expiresAt = date('Y-m-d H:i:s', $expiresAtTimestamp);
        $after = ($beforeCents - $amountCents) / 100;
        $publicId = public_id('T');

        $balance = $pdo->prepare('UPDATE users SET `' . $balanceColumn . '` = ?, updated_at = NOW() WHERE id = ?');
        $balance->execute([$after, $userId]);

        $tradeStmt = $pdo->prepare("INSERT INTO trades (public_id, user_id, asset_id, account_type, client_request_id, direction, amount, payout_percent, entry_price, opened_at, expires_at, status, profit_loss, balance_before, balance_after, engine_version, client_ip, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), ?, 'open', 0, ?, ?, 'market-engine-v3', ?, NOW(), NOW())");
        $tradeStmt->execute([$publicId, $userId, (int)$asset['id'], $accountType, $clientRequestId !== '' ? $clientRequestId : null, $direction, $amount, $effectivePayout, $entryPrice, $now, $expiresAt, $before, $after, client_ip()]);
        $tradeId = (int)$pdo->lastInsertId();

        $ledger = $pdo->prepare('INSERT INTO wallet_transactions (user_id, account_type, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $ledger->execute([$userId, $accountType, 'trade_stake', -$amount, $before, $after, 'trade', $tradeId, $asset['display_name'] . ' ' . strtoupper($direction) . ' trade']);

        return [
            'id' => $publicId, 'symbol' => $asset['symbol'], 'name' => $asset['display_name'], 'direction' => $direction,
            'amount' => $amount, 'payout' => $effectivePayout, 'entryPrice' => $entryPrice,
            'openedAt' => $now * 1000, 'expiresAt' => $expiresAtTimestamp * 1000, 'duration' => $durationSeconds, 'status' => 'open',
            'balance' => $after, 'balanceBefore' => $before, 'balanceAfter' => $after, 'stakeDebited' => $amount,
            'accountType' => $accountType, 'isDemo' => $accountType === 'demo',
            'clientRequestId' => $clientRequestId !== '' ? $clientRequestId : null, 'duplicate' => false,
        ];
    });
}

function sell_trade(int $userId, string $tradePublicId): array
{
    $tradePublicId = trim($tradePublicId);
    if ($tradePublicId === '') {
        throw new InvalidArgumentException('Trade ID is required.');
    }

    settle_open_trades($userId);

    return db_transaction(function (PDO $pdo) use ($userId, $tradePublicId): array {
        $stmt = $pdo->prepare("SELECT t.*, a.symbol, a.display_name, a.decimal_places, a.volatility, a.base_price, a.price_source, a.api_symbol, c.price source_price FROM trades t JOIN market_assets a ON a.id = t.asset_id LEFT JOIN market_price_cache c ON c.asset_id = a.id WHERE (t.public_id = ? OR CAST(t.id AS CHAR) = ?) AND t.user_id = ? FOR UPDATE");
        $stmt->execute([$tradePublicId, $tradePublicId, $userId]);
        $trade = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$trade) {
            throw new InvalidArgumentException('Trade not found.');
        }

        if ($trade['status'] !== 'open') {
            throw new RuntimeException('This trade is already closed.');
        }

        $nowMs = (int)floor(microtime(true) * 1000);
        $openedAtMs = (int)strtotime((string)$trade['opened_at']) * 1000;
        $expiresAtMs = (int)strtotime((string)$trade['expires_at']) * 1000;

        if ($nowMs >= $expiresAtMs) {
            throw new RuntimeException('Trade expiration reached.');
        }

        $asset = [
            'id' => (int)$trade['asset_id'],
            'symbol' => $trade['symbol'],
            'display_name' => $trade['display_name'],
            'decimal_places' => $trade['decimal_places'],
            'volatility' => $trade['volatility'],
            'base_price' => $trade['base_price'],
            'price_source' => $trade['price_source'],
            'api_symbol' => $trade['api_symbol'],
            'source_price' => $trade['source_price'],
        ];

        $closePrice = market_price_at($asset, $nowMs);
        $entryPrice = (float)$trade['entry_price'];
        $amount = (float)$trade['amount'];
        $payout = (float)$trade['payout_percent'];

        $totalDurationMs = max(1000, $expiresAtMs - $openedAtMs);
        $remainingMs = max(0, $expiresAtMs - $nowMs);
        $remainingRatio = $remainingMs / (float)$totalDurationMs;

        $diff = $closePrice - $entryPrice;
        $isFavored = ($trade['direction'] === 'up' && $diff > 0) || ($trade['direction'] === 'down' && $diff < 0);

        if ($isFavored) {
            $potentialProfit = $amount * ($payout / 100.0);
            $sellPrice = $amount + ($potentialProfit * (1.0 - ($remainingRatio * 0.4)));
        } else {
            $sellPrice = $amount * max(0.15, min(0.80, $remainingRatio * 0.85));
        }

        $sellPrice = round(max(0.01, $sellPrice), 2);
        $profitLoss = round($sellPrice - $amount, 2);

        $accountType = ($trade['account_type'] ?? 'live') === 'demo' ? 'demo' : 'live';
        $balanceColumn = trade_balance_column($accountType);

        $userStmt = $pdo->prepare('SELECT `' . $balanceColumn . '` FROM users WHERE id = ? FOR UPDATE');
        $userStmt->execute([$userId]);
        $before = (float)$userStmt->fetchColumn();
        $after = round($before + $sellPrice, 2);

        $updateBalance = $pdo->prepare('UPDATE users SET `' . $balanceColumn . '` = ?, updated_at = NOW() WHERE id = ?');
        $updateBalance->execute([$after, $userId]);

        $ledger = $pdo->prepare('INSERT INTO wallet_transactions (user_id, account_type, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $ledger->execute([$userId, $accountType, 'trade_win', $sellPrice, $before, $after, 'trade', (int)$trade['id'], 'Early trade sale refund']);

        $update = $pdo->prepare('UPDATE trades SET close_price = ?, status = "closed", profit_loss = ?, balance_after = ?, settled_at = NOW(), updated_at = NOW() WHERE id = ?');
        $update->execute([$closePrice, $profitLoss, $after, (int)$trade['id']]);

        $updatedTrade = array_merge($trade, [
            'close_price' => $closePrice,
            'status' => 'closed',
            'profit_loss' => $profitLoss,
            'settled_at' => date('Y-m-d H:i:s'),
        ]);

        $payload = trade_payload($updatedTrade);
        $payload['balance'] = $after;
        $payload['refundAmount'] = $sellPrice;

        return $payload;
    });
}

function user_trade_state(int $userId, int $recentLimit = 15): array
{
    settle_open_trades($userId);
    $accountType = trade_account_type();

    $stmt = db()->prepare("SELECT t.public_id,t.account_type,a.symbol,a.display_name,a.decimal_places,t.direction,t.amount,t.payout_percent,t.entry_price,t.close_price,t.opened_at,t.expires_at,t.settled_at,t.status,t.profit_loss FROM trades t JOIN market_assets a ON a.id = t.asset_id WHERE t.user_id = ? AND t.account_type = ? ORDER BY t.id DESC LIMIT " . max(1, min(50, $recentLimit)));
    $stmt->execute([$userId, $accountType]);
    $trades = [];
    foreach ($stmt->fetchAll() as $row) {
        $trades[] = trade_payload($row);
    }

    $user = current_user(true);
    if (!$user && $userId > 0) {
        $userStmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch() ?: [];
    }
    $liveBalance = (float)($user['demo_balance'] ?? 0);
    $demoBalance = (float)($user['demo_trade_balance'] ?? 0);
    return [
        'balance' => $accountType === 'demo' ? $demoBalance : $liveBalance,
        'liveBalance' => $liveBalance,
        'demoBalance' => $demoBalance,
        'accountType' => $accountType,
        'isDemo' => $accountType === 'demo',
        'currency' => (string)($user['currency'] ?? 'USD'),
        'trades' => $trades,
        'serverTime' => (int)floor(microtime(true) * 1000),
    ];
}

function trade_payload(array $row): array
{
    $accountType = ($row['account_type'] ?? 'live') === 'demo' ? 'demo' : 'live';
    $openedAt = strtotime((string)$row['opened_at']) * 1000;
    $expiresAt = strtotime((string)$row['expires_at']) * 1000;
    return [
        'id' => $row['public_id'], 'symbol' => $row['symbol'], 'name' => $row['display_name'], 'decimals' => (int)$row['decimal_places'],
        'direction' => $row['direction'], 'amount' => (float)$row['amount'], 'payout' => (float)$row['payout_percent'],
        'entryPrice' => (float)$row['entry_price'], 'closePrice' => $row['close_price'] === null ? null : (float)$row['close_price'],
        'openedAt' => $openedAt, 'expiresAt' => $expiresAt,
        'duration' => max(1, (int)round(($expiresAt - $openedAt) / 1000)),
        'settledAt' => $row['settled_at'] ? strtotime((string)$row['settled_at']) * 1000 : null,
        'status' => $row['status'], 'profitLoss' => (float)$row['profit_loss'], 'accountType' => $accountType, 'isDemo' => $accountType === 'demo',
    ];
}

function expire_deposit_orders(?int $userId = null): int
{
    return db_transaction(function (PDO $pdo) use ($userId): int {
        $sql = "SELECT id FROM fund_requests WHERE request_type='deposit' AND status='awaiting_payment' AND payment_expires_at IS NOT NULL AND payment_expires_at<=NOW()";
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND user_id=?';
            $params[] = $userId;
        }
        $sql .= ' FOR UPDATE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) {
            return 0;
        }
        $expire = $pdo->prepare("UPDATE fund_requests SET status='expired',updated_at=NOW() WHERE id=? AND status='awaiting_payment'");
        $cancelPromo = $pdo->prepare("UPDATE promo_redemptions SET status='cancelled',updated_at=NOW() WHERE fund_request_id=? AND status='reserved'");
        foreach ($ids as $requestId) {
            $expire->execute([$requestId]);
            $cancelPromo->execute([$requestId]);
        }
        return count($ids);
    });
}

function create_deposit_order(
    int $userId,
    float $amount,
    int $methodId,
    string $note = '',
    string $promoCode = ''
): array {
    if (!(bool)setting('deposits_enabled', true)) {
        throw new RuntimeException('Deposit requests are temporarily paused.');
    }
    $method = payment_method_by_id($methodId, 'deposit');
    if (!$method) {
        throw new InvalidArgumentException('Choose an available payment method.');
    }
    if (trim((string)$method['account_identifier']) === '' && trim((string)$method['qr_filename']) === '') {
        throw new RuntimeException('This payment method is not configured yet. Choose another method or contact support.');
    }

    $amountCents = (int)round($amount * 100);
    $minimumCents = (int)round(max((float)setting('minimum_demo_deposit', 10), (float)$method['minimum_amount']) * 100);
    $maximumCents = (int)round(min(100000.0, (float)$method['maximum_amount']) * 100);
    if ($amountCents < $minimumCents || $amountCents > $maximumCents) {
        throw new InvalidArgumentException('Amount must be between ' . money($minimumCents / 100) . ' and ' . money($maximumCents / 100) . '.');
    }
    $amount = $amountCents / 100;
    $note = trim($note);
    if (strlen($note) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note)) {
        throw new InvalidArgumentException('Order note is too long or contains unsupported characters.');
    }

    expire_deposit_orders($userId);
    $minutes = max(5, min(60, (int)setting('payment_order_minutes', 15)));
    $expiresAt = date('Y-m-d H:i:s', time() + ($minutes * 60));
    $result = db_transaction(function (PDO $pdo) use ($userId, $amount, $method, $note, $promoCode, $expiresAt): array {
        $userStmt = $pdo->prepare("SELECT id FROM users WHERE id=? AND status='active' FOR UPDATE");
        $userStmt->execute([$userId]);
        if (!$userStmt->fetchColumn()) {
            throw new RuntimeException('Account not found.');
        }
        // The user row remains locked while active orders are counted and the
        // new order is inserted. Two fast clicks therefore cannot bypass the
        // three-order limit or create an uncontrolled burst of payment orders.
        $pending = $pdo->prepare("SELECT COUNT(*) FROM fund_requests WHERE user_id=? AND request_type='deposit' AND status IN ('awaiting_payment','pending')");
        $pending->execute([$userId]);
        if ((int)$pending->fetchColumn() >= 3) {
            throw new RuntimeException('You already have three active deposit orders. Complete, cancel, or wait for an order to expire.');
        }

        $promo = null;
        $bonus = 0.0;
        if (trim($promoCode) !== '') {
            $promo = active_promo($pdo, $promoCode, 'deposit_bonus', $userId, $amount);
            $bonus = promo_credit_amount($promo, $amount);
        }
        $fee = round(($amount * ((float)$method['fee_percent'] / 100)) + (float)$method['fee_fixed'], 2);
        $publicId = public_id('D');
        $stmt = $pdo->prepare("INSERT INTO fund_requests (public_id,user_id,request_type,amount,status,payment_method_id,promo_code_id,balance_reserved,bonus_amount,fee_amount,user_note,payment_expires_at,created_at,updated_at) VALUES (?,?,'deposit',?,'awaiting_payment',?,?,0,?,?,?, ?,NOW(),NOW())");
        $stmt->execute([
            $publicId, $userId, $amount, (int)$method['id'], $promo ? (int)$promo['id'] : null,
            $bonus, $fee, $note !== '' ? $note : null, $expiresAt,
        ]);
        $requestId = (int)$pdo->lastInsertId();
        if ($promo) {
            $pdo->prepare("INSERT INTO promo_redemptions (promo_code_id,user_id,fund_request_id,credit_amount,status,created_at,updated_at) VALUES (?,?,?,?,'reserved',NOW(),NOW())")->execute([(int)$promo['id'], $userId, $requestId, $bonus]);
        }
        return [
            'id' => $publicId,
            'database_id' => $requestId,
            'type' => 'deposit',
            'amount' => $amount,
            'fee' => $fee,
            'payment_total' => round($amount + $fee, 2),
            'bonus' => $bonus,
            'method' => (string)$method['name'],
            'status' => 'awaiting_payment',
            'expires_at' => $expiresAt,
            'redirect' => '/en/pay/?order=' . rawurlencode($publicId),
        ];
    });
    return $result;
}

function confirm_deposit_order(int $userId, int $requestId, string $reference, ?string $proofFilename, string $note = ''): array
{
    $reference = trim($reference);
    $note = trim($note);
    if ($reference !== '' && !preg_match('/^[A-Za-z0-9._:\/-]{3,120}$/', $reference)) {
        throw new InvalidArgumentException('Transaction reference contains unsupported characters.');
    }
    if (strlen($note) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note)) {
        throw new InvalidArgumentException('Payment note is too long or contains unsupported characters.');
    }

    $result = db_transaction(function (PDO $pdo) use ($userId, $requestId, $reference, $proofFilename, $note): array {
        $stmt = $pdo->prepare("SELECT r.*,m.name method_name,m.require_reference,m.require_proof FROM fund_requests r JOIN payment_methods m ON m.id=r.payment_method_id WHERE r.id=? AND r.user_id=? AND r.request_type='deposit' LIMIT 1 FOR UPDATE");
        $stmt->execute([$requestId, $userId]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new RuntimeException('Deposit order was not found.');
        }
        if ((string)$order['status'] === 'pending') {
            return ['id' => $order['public_id'], 'status' => 'pending', 'duplicate' => true];
        }
        if ((string)$order['status'] !== 'awaiting_payment') {
            throw new RuntimeException('This deposit order can no longer be confirmed.');
        }
        if (!$order['payment_expires_at'] || strtotime((string)$order['payment_expires_at']) <= time()) {
            $pdo->prepare("UPDATE fund_requests SET status='expired',updated_at=NOW() WHERE id=?")->execute([$requestId]);
            $pdo->prepare("UPDATE promo_redemptions SET status='cancelled',updated_at=NOW() WHERE fund_request_id=? AND status='reserved'")->execute([$requestId]);
            return ['id' => $order['public_id'], 'status' => 'expired', 'expired' => true];
        }
        if ((int)$order['require_reference'] === 1 && (strlen($reference) < 3 || strlen($reference) > 120)) {
            throw new InvalidArgumentException('Enter the UTR, Binance order ID, or transaction reference.');
        }
        if ((int)$order['require_proof'] === 1 && !$proofFilename) {
            throw new InvalidArgumentException('Upload the payment screenshot for this method.');
        }
        $savedNote = $note !== '' ? $note : (string)($order['user_note'] ?? '');
        $update = $pdo->prepare("UPDATE fund_requests SET status='pending',user_reference=?,proof_filename=COALESCE(?,proof_filename),user_note=?,payment_confirmed_at=NOW(),updated_at=NOW() WHERE id=? AND status='awaiting_payment'");
        $update->execute([$reference !== '' ? $reference : null, $proofFilename, $savedNote !== '' ? $savedNote : null, $requestId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Payment confirmation could not be saved. Refresh and try again.');
        }
        return ['id' => $order['public_id'], 'status' => 'pending', 'duplicate' => false, 'order_data' => $order];
    });
    if (!empty($result['expired'])) {
        throw new RuntimeException('This payment order has expired. Create a new order.');
    }

    if (!empty($result['status']) && $result['status'] === 'pending' && empty($result['duplicate'])) {
        try {
            $userStmt = db()->prepare('SELECT id, name, email, currency FROM users WHERE id = ? LIMIT 1');
            $userStmt->execute([$userId]);
            $depositUser = $userStmt->fetch();
            if ($depositUser) {
                $orderData = $result['order_data'] ?? [];
                send_deposit_placed_email($depositUser, [
                    'public_id' => $result['id'],
                    'amount' => (float)($orderData['amount'] ?? 0.00),
                    'currency' => $depositUser['currency'] ?? 'USD',
                    'method' => (string)($orderData['method_name'] ?? 'Payment Gateway'),
                    'reference' => $reference,
                ]);
            }
        } catch (Throwable $mailErr) {
            mailer_log("Deposit confirmed email dispatch error for order {$result['id']}: " . $mailErr->getMessage());
        }
    }

    return $result;
}

function create_fund_request(
    int $userId,
    string $requestType,
    float $amount,
    string $note = '',
    int $methodId = 0,
    string $reference = '',
    ?string $proofFilename = null,
    array $destination = [],
    string $promoCode = ''
): array
{
    $requestType = strtolower(trim($requestType));
    if (!in_array($requestType, ['deposit', 'withdrawal'], true)) {
        throw new InvalidArgumentException('Invalid request type.');
    }

    $enabledKey = $requestType === 'deposit' ? 'deposits_enabled' : 'withdrawals_enabled';
    if (!(bool)setting($enabledKey, true)) {
        throw new RuntimeException(ucfirst($requestType) . ' requests are temporarily paused.');
    }

    $method = payment_method_by_id($methodId, $requestType);
    if (!$method) {
        throw new InvalidArgumentException('Choose an available payment method.');
    }

    $amount = round($amount, 2);
    $minKey = $requestType === 'deposit' ? 'minimum_demo_deposit' : 'minimum_demo_withdrawal';
    $minimum = max((float)setting($minKey, $requestType === 'deposit' ? 10 : 20), (float)$method['minimum_amount']);
    $maximum = min(100000.0, (float)$method['maximum_amount']);
    if ($amount < $minimum || $amount > $maximum) {
        throw new InvalidArgumentException('Amount must be between ' . money($minimum) . ' and ' . money($maximum) . '.');
    }

    $reference = trim($reference);
    if ((int)$method['require_reference'] === 1 && $requestType === 'deposit' && (strlen($reference) < 3 || strlen($reference) > 120)) {
        throw new InvalidArgumentException('Enter the UTR, order ID, or transaction reference.');
    }
    if ($reference !== '' && !preg_match('/^[A-Za-z0-9._:\/-]{3,120}$/', $reference)) {
        throw new InvalidArgumentException('Transaction reference contains unsupported characters.');
    }
    if ((int)$method['require_proof'] === 1 && $requestType === 'deposit' && !$proofFilename) {
        throw new InvalidArgumentException('Upload the payment screenshot for this method.');
    }

    $destinationData = null;
    if ($requestType === 'withdrawal') {
        $accountName = trim((string)($destination['account_name'] ?? ''));
        $identifier = trim((string)($destination['account_identifier'] ?? ''));
        if (strlen($identifier) < 3 || strlen($identifier) > 190 || strlen($accountName) > 120) {
            throw new InvalidArgumentException('Enter valid withdrawal account details.');
        }
        $destinationData = json_encode([
            'account_name' => $accountName,
            'account_identifier' => $identifier,
            'network' => (string)($method['network'] ?? ''),
            'method' => (string)$method['name'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $pending = db()->prepare("SELECT COUNT(*) FROM fund_requests WHERE user_id = ? AND request_type = ? AND status = 'pending'");
    $pending->execute([$userId, $requestType]);
    if ((int)$pending->fetchColumn() >= 3) {
        throw new RuntimeException('You already have three pending requests of this type.');
    }

    $result = db_transaction(function (PDO $pdo) use ($userId, $requestType, $amount, $note, $method, $reference, $proofFilename, $destinationData, $promoCode): array {
        $promo = null;
        $bonus = 0.0;
        if ($requestType === 'deposit' && trim($promoCode) !== '') {
            $promo = active_promo($pdo, $promoCode, 'deposit_bonus', $userId, $amount);
            $bonus = promo_credit_amount($promo, $amount);
        }

        $userStmt = $pdo->prepare('SELECT demo_balance FROM users WHERE id = ? FOR UPDATE');
        $userStmt->execute([$userId]);
        $beforeValue = $userStmt->fetchColumn();
        if ($beforeValue === false) {
            throw new RuntimeException('Account not found.');
        }
        $before = (float)$beforeValue;
        $fee = round(($amount * ((float)$method['fee_percent'] / 100)) + (float)$method['fee_fixed'], 2);
        $reservedAmount = round($amount + ($requestType === 'withdrawal' ? $fee : 0), 2);
        $reserved = 0;
        if ($requestType === 'withdrawal') {
            if ($before < $reservedAmount) {
                throw new RuntimeException('Insufficient wallet balance for this withdrawal.');
            }
            $after = round($before - $reservedAmount, 2);
            $pdo->prepare('UPDATE users SET demo_balance = ?, updated_at = NOW() WHERE id = ?')->execute([$after, $userId]);
            $reserved = 1;
        }

        $publicId = public_id($requestType === 'deposit' ? 'D' : 'W');
        $stmt = $pdo->prepare("INSERT INTO fund_requests (public_id,user_id,request_type,amount,status,payment_method_id,promo_code_id,user_reference,proof_filename,destination_json,balance_reserved,bonus_amount,fee_amount,user_note,created_at,updated_at) VALUES (?,?,?,?,'pending',?,?,?,?,?,?,?,?,?,NOW(),NOW())");
        $stmt->execute([
            $publicId, $userId, $requestType, $amount, (int)$method['id'], $promo ? (int)$promo['id'] : null,
            $reference !== '' ? $reference : null, $proofFilename, $destinationData, $reserved, $bonus, $fee, substr(trim($note), 0, 500),
        ]);
        $requestId = (int)$pdo->lastInsertId();

        if ($requestType === 'withdrawal') {
            $after = round($before - $reservedAmount, 2);
            $ledger = $pdo->prepare("INSERT INTO wallet_transactions (user_id,transaction_type,amount,balance_before,balance_after,reference_type,reference_id,description,created_at) VALUES (?,'withdrawal_hold',?,?,?,?,?,'Withdrawal amount reserved',NOW())");
            $ledger->execute([$userId, -$reservedAmount, $before, $after, 'fund_request', $requestId]);
        }
        if ($promo) {
            $pdo->prepare("INSERT INTO promo_redemptions (promo_code_id,user_id,fund_request_id,credit_amount,status,created_at,updated_at) VALUES (?,?,?,?,'reserved',NOW(),NOW())")->execute([(int)$promo['id'], $userId, $requestId, $bonus]);
        }

        return ['id' => $publicId, 'database_id' => $requestId, 'type' => $requestType, 'amount' => $amount, 'fee' => $fee, 'bonus' => $bonus, 'method' => $method['name'], 'status' => 'pending'];
    });
    current_user(true);

    if ($requestType === 'deposit') {
        try {
            $userStmt = db()->prepare('SELECT id, name, email, currency FROM users WHERE id = ? LIMIT 1');
            $userStmt->execute([$userId]);
            $depositUser = $userStmt->fetch();
            if ($depositUser) {
                send_deposit_placed_email($depositUser, [
                    'public_id' => $result['id'],
                    'amount' => $amount,
                    'currency' => $depositUser['currency'] ?? 'USD',
                    'method' => $method['name'],
                    'reference' => $reference,
                ]);
            }
        } catch (Throwable $mailErr) {
            mailer_log("Deposit placed email dispatch error for order {$result['id']}: " . $mailErr->getMessage());
        }
    }

    return $result;
}

function review_fund_request(int $adminId, int $requestId, string $decision, string $adminNote = ''): void
{
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new InvalidArgumentException('Invalid review decision.');
    }

    db_transaction(function (PDO $pdo) use ($adminId, $requestId, $decision, $adminNote): void {
        $stmt = $pdo->prepare("SELECT * FROM fund_requests WHERE id = ? AND status = 'pending' FOR UPDATE");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        if (!$request) {
            throw new RuntimeException('Request was already reviewed or does not exist.');
        }

        if ($decision === 'approved') {
            $userStmt = $pdo->prepare('SELECT demo_balance FROM users WHERE id = ? FOR UPDATE');
            $userStmt->execute([(int)$request['user_id']]);
            $before = (float)$userStmt->fetchColumn();
            if ($request['request_type'] === 'deposit') {
                $deposit = (float)$request['amount'];
                $afterDeposit = round($before + $deposit, 2);
                $after = round($afterDeposit + (float)$request['bonus_amount'], 2);
                $pdo->prepare('UPDATE users SET demo_balance = ?, updated_at = NOW() WHERE id = ?')->execute([$after, (int)$request['user_id']]);
                $ledger = $pdo->prepare('INSERT INTO wallet_transactions (user_id,transaction_type,amount,balance_before,balance_after,reference_type,reference_id,description,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())');
                $ledger->execute([(int)$request['user_id'], 'deposit_credit', $deposit, $before, $afterDeposit, 'fund_request', $requestId, 'Approved deposit request', $adminId]);
                if ((float)$request['bonus_amount'] > 0) {
                    $ledger->execute([(int)$request['user_id'], 'deposit_bonus', (float)$request['bonus_amount'], $afterDeposit, $after, 'fund_request', $requestId, 'Deposit coupon bonus', $adminId]);
                    $pdo->prepare("UPDATE promo_redemptions SET status='credited',updated_at=NOW() WHERE fund_request_id=? AND status='reserved'")->execute([$requestId]);
                    if ($request['promo_code_id']) {
                        $pdo->prepare('UPDATE promo_codes SET usage_count=usage_count+1,updated_at=NOW() WHERE id=?')->execute([(int)$request['promo_code_id']]);
                    }
                }
                credit_referral_bonus_for_deposit($pdo, (int)$request['user_id'], $requestId, $deposit, $adminId);
            } elseif (!(bool)$request['balance_reserved']) {
                $reservedAmount = round((float)$request['amount'] + (float)$request['fee_amount'], 2);
                $after = round($before - $reservedAmount, 2);
                if ($after < 0) {
                    throw new RuntimeException('User no longer has enough wallet balance for this withdrawal.');
                }
                $pdo->prepare('UPDATE users SET demo_balance=?,updated_at=NOW() WHERE id=?')->execute([$after, (int)$request['user_id']]);
                $pdo->prepare("INSERT INTO wallet_transactions (user_id,transaction_type,amount,balance_before,balance_after,reference_type,reference_id,description,created_by,created_at) VALUES (?,'withdrawal_hold',?,?,?,?,?,'Withdrawal amount reserved',?,NOW())")->execute([(int)$request['user_id'], -$reservedAmount, $before, $after, 'fund_request', $requestId, $adminId]);
                $pdo->prepare('UPDATE fund_requests SET balance_reserved=1 WHERE id=?')->execute([$requestId]);
            }
        } elseif ($request['request_type'] === 'withdrawal' && (bool)$request['balance_reserved']) {
            $userStmt = $pdo->prepare('SELECT demo_balance FROM users WHERE id = ? FOR UPDATE');
            $userStmt->execute([(int)$request['user_id']]);
            $before = (float)$userStmt->fetchColumn();
            $refund = round((float)$request['amount'] + (float)$request['fee_amount'], 2);
            $after = round($before + $refund, 2);
            $pdo->prepare('UPDATE users SET demo_balance=?,updated_at=NOW() WHERE id=?')->execute([$after, (int)$request['user_id']]);
            $pdo->prepare("INSERT INTO wallet_transactions (user_id,transaction_type,amount,balance_before,balance_after,reference_type,reference_id,description,created_by,created_at) VALUES (?,'withdrawal_refund',?,?,?,?,?,'Rejected withdrawal refund',?,NOW())")->execute([(int)$request['user_id'], $refund, $before, $after, 'fund_request', $requestId, $adminId]);
        }

        if ($decision === 'rejected' && $request['promo_code_id']) {
            $pdo->prepare("UPDATE promo_redemptions SET status='cancelled',updated_at=NOW() WHERE fund_request_id=? AND status='reserved'")->execute([$requestId]);
        }

        $update = $pdo->prepare('UPDATE fund_requests SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?');
        $update->execute([$decision, substr(trim($adminNote), 0, 500), $adminId, $requestId]);
    });

    admin_audit($adminId, 'fund_request_' . $decision, 'fund_request', $requestId, ['note' => substr($adminNote, 0, 200)]);

    try {
        $stmt = db()->prepare('SELECT * FROM fund_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $req = $stmt->fetch();
        if ($req && $req['request_type'] === 'deposit') {
            $userStmt = db()->prepare('SELECT id, name, email, currency FROM users WHERE id = ? LIMIT 1');
            $userStmt->execute([(int)$req['user_id']]);
            $targetUser = $userStmt->fetch();

            $methodName = 'Online Payment';
            if ($req['payment_method_id']) {
                $methodStmt = db()->prepare('SELECT name FROM payment_methods WHERE id = ? LIMIT 1');
                $methodStmt->execute([(int)$req['payment_method_id']]);
                $methodName = $methodStmt->fetchColumn() ?: 'Online Payment';
            }

            if ($targetUser) {
                $depositData = [
                    'public_id' => $req['public_id'] ?: 'D' . $requestId,
                    'amount' => (float)$req['amount'],
                    'currency' => $targetUser['currency'] ?: 'USD',
                    'method' => $methodName,
                    'reference' => (string)($req['user_reference'] ?? ''),
                    'admin_note' => (string)($req['admin_note'] ?? $adminNote),
                ];

                if ($decision === 'approved') {
                    send_deposit_successful_email($targetUser, $depositData);
                } elseif ($decision === 'rejected') {
                    send_deposit_rejected_email($targetUser, $depositData);
                }
            }
        }
    } catch (Throwable $mailErr) {
        mailer_log("Deposit review email dispatch failed for request #{$requestId}: " . $mailErr->getMessage());
    }
}

function mark_fund_request_paid(int $adminId, int $requestId, string $providerReference, string $adminNote = ''): void
{
    $providerReference = trim($providerReference);
    if (strlen($providerReference) < 3 || strlen($providerReference) > 120) {
        throw new InvalidArgumentException('Provider reference is required before marking a withdrawal paid.');
    }
    db_transaction(function (PDO $pdo) use ($adminId, $requestId, $providerReference, $adminNote): void {
        $stmt = $pdo->prepare("SELECT * FROM fund_requests WHERE id=? AND request_type='withdrawal' AND status='approved' FOR UPDATE");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        if (!$request) {
            throw new RuntimeException('Only an approved withdrawal can be marked paid.');
        }
        $userStmt = $pdo->prepare('SELECT demo_balance FROM users WHERE id=? FOR UPDATE');
        $userStmt->execute([(int)$request['user_id']]);
        $balance = (float)$userStmt->fetchColumn();
        $pdo->prepare("UPDATE fund_requests SET status='paid',provider_reference=?,admin_note=?,paid_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$providerReference, substr(trim($adminNote), 0, 500), $requestId]);
        $pdo->prepare("INSERT INTO wallet_transactions (user_id,transaction_type,amount,balance_before,balance_after,reference_type,reference_id,description,created_by,created_at) VALUES (?,'withdrawal_paid',0,?,?, 'fund_request',?,'Withdrawal marked paid',?,NOW())")->execute([(int)$request['user_id'], $balance, $balance, $requestId, $adminId]);
    });
    admin_audit($adminId, 'fund_request_paid', 'fund_request', $requestId, ['provider_reference' => $providerReference]);
}

function cancel_fund_request(int $userId, int $requestId): void
{
    db_transaction(function (PDO $pdo) use ($userId, $requestId): void {
        $stmt = $pdo->prepare("SELECT * FROM fund_requests WHERE id=? AND user_id=? AND status IN ('awaiting_payment','pending') FOR UPDATE");
        $stmt->execute([$requestId, $userId]);
        $request = $stmt->fetch();
        if (!$request) {
            throw new RuntimeException('This request cannot be cancelled.');
        }
        if ($request['request_type'] === 'withdrawal' && (bool)$request['balance_reserved']) {
            $userStmt = $pdo->prepare('SELECT demo_balance FROM users WHERE id=? FOR UPDATE');
            $userStmt->execute([$userId]);
            $before = (float)$userStmt->fetchColumn();
            $refund = round((float)$request['amount'] + (float)$request['fee_amount'], 2);
            $after = round($before + $refund, 2);
            $pdo->prepare('UPDATE users SET demo_balance=?,updated_at=NOW() WHERE id=?')->execute([$after, $userId]);
            $pdo->prepare("INSERT INTO wallet_transactions (user_id,transaction_type,amount,balance_before,balance_after,reference_type,reference_id,description,created_at) VALUES (?,'withdrawal_refund',?,?,?,?,?,'Cancelled withdrawal refund',NOW())")->execute([$userId, $refund, $before, $after, 'fund_request', $requestId]);
        }
        $pdo->prepare("UPDATE fund_requests SET status='cancelled',updated_at=NOW() WHERE id=?")->execute([$requestId]);
        $pdo->prepare("UPDATE promo_redemptions SET status='cancelled',updated_at=NOW() WHERE fund_request_id=? AND status='reserved'")->execute([$requestId]);
    });
    current_user(true);
}

function adjust_account_balance(int $adminId, int $userId, float $amount, string $reason, string $accountType = 'live'): void
{
    $amount = round($amount, 2);
    $reason = trim($reason);
    $accountType = $accountType === 'demo' ? 'demo' : 'live';
    if ($amount == 0.0 || abs($amount) > 1000000 || strlen($reason) < 3) {
        throw new InvalidArgumentException('Enter a non-zero amount up to 1,000,000 and a reason.');
    }
    $balanceColumn = trade_balance_column($accountType);

    db_transaction(function (PDO $pdo) use ($adminId, $userId, $amount, $reason, $accountType, $balanceColumn): void {
        $stmt = $pdo->prepare('SELECT `' . $balanceColumn . '` FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            throw new RuntimeException('User not found.');
        }
        $before = (float)$value;
        $after = round($before + $amount, 2);
        if ($after < 0) {
            throw new RuntimeException('Adjustment cannot make the balance negative.');
        }

        $update = $pdo->prepare('UPDATE users SET `' . $balanceColumn . '` = ?, updated_at = NOW() WHERE id = ?');
        $update->execute([$after, $userId]);
        $ledger = $pdo->prepare('INSERT INTO wallet_transactions (user_id, account_type, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $ledger->execute([$userId, $accountType, 'admin_adjustment', $amount, $before, $after, 'admin', $adminId, substr($reason, 0, 255), $adminId]);
    });

    admin_audit($adminId, 'balance_adjustment', 'user', $userId, ['account' => $accountType, 'amount' => $amount, 'reason' => substr($reason, 0, 200)]);
}

// Backward-compatible wrapper used by older admin templates.
function adjust_demo_balance(int $adminId, int $userId, float $amount, string $reason): void
{
    adjust_account_balance($adminId, $userId, $amount, $reason, 'live');
}
