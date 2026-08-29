<?php
declare(strict_types=1);

function currency_exchange_rates(): array
{
    $configured = setting('currency_exchange_rates', []);
    if (!is_array($configured)) {
        return [];
    }

    $rates = [];
    foreach ($configured as $code => $rate) {
        $currency = strtoupper(trim((string)$code));
        $value = (float)$rate;
        if (preg_match('/^[A-Z]{3}$/', $currency) && is_finite($value) && $value > 0 && $value <= 1000000000) {
            $rates[$currency] = $value;
        }
    }
    ksort($rates);
    return $rates;
}

function currency_exchange_quote(array $user, string $targetCurrency): array
{
    if (!(bool)setting('currency_exchange_enabled', true)) {
        throw new RuntimeException('Account currency exchange is temporarily disabled.');
    }

    $source = strtoupper((string)($user['currency'] ?? 'USD'));
    $target = strtoupper(trim($targetCurrency));
    $rates = currency_exchange_rates();
    if ($target === $source || !isset($rates[$source], $rates[$target])) {
        throw new InvalidArgumentException('Choose another configured account currency.');
    }

    $rate = $rates[$target] / $rates[$source];
    $feePercent = max(0.0, min(25.0, (float)setting('currency_exchange_fee_percent', 3)));
    $liveBefore = round((float)$user['demo_balance'], 2);
    $demoBefore = round((float)$user['demo_trade_balance'], 2);
    $liveGross = round($liveBefore * $rate, 2);
    $feeAmount = round($liveGross * ($feePercent / 100), 2);

    return [
        'fromCurrency' => $source,
        'toCurrency' => $target,
        'rate' => $rate,
        'feePercent' => $feePercent,
        'feeAmount' => $feeAmount,
        'liveBefore' => $liveBefore,
        'liveAfter' => max(0.0, round($liveGross - $feeAmount, 2)),
        // The practice wallet follows the same FX ratio but is not charged a
        // real-wallet exchange fee.
        'demoBefore' => $demoBefore,
        'demoAfter' => max(0.0, round($demoBefore * $rate, 2)),
    ];
}

function exchange_account_currency(int $userId, string $targetCurrency): array
{
    if ($userId < 1) {
        throw new InvalidArgumentException('Account is invalid.');
    }

    // Settle due trades first. A still-open trade or payment order is blocked
    // below so amounts can never change currency halfway through its lifecycle.
    settle_open_trades($userId);

    $result = db_transaction(function (PDO $pdo) use ($userId, $targetCurrency): array {
        $userStmt = $pdo->prepare('SELECT id,currency,demo_balance,demo_trade_balance FROM users WHERE id=? AND role=\'user\' AND status=\'active\' LIMIT 1 FOR UPDATE');
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        if (!$user) {
            throw new RuntimeException('Account was not found.');
        }

        $openTrades = $pdo->prepare("SELECT COUNT(*) FROM trades WHERE user_id=? AND status='open'");
        $openTrades->execute([$userId]);
        if ((int)$openTrades->fetchColumn() > 0) {
            throw new RuntimeException('Close or wait for all open trades before changing currency.');
        }

        $activeOrders = $pdo->prepare("SELECT COUNT(*) FROM fund_requests WHERE user_id=? AND (status IN ('awaiting_payment','pending') OR (request_type='withdrawal' AND status='approved'))");
        $activeOrders->execute([$userId]);
        if ((int)$activeOrders->fetchColumn() > 0) {
            throw new RuntimeException('Complete or cancel active deposit and withdrawal orders before changing currency.');
        }

        $quote = currency_exchange_quote($user, $targetCurrency);
        $update = $pdo->prepare('UPDATE users SET currency=?,demo_balance=?,demo_trade_balance=?,updated_at=NOW() WHERE id=?');
        $update->execute([$quote['toCurrency'], $quote['liveAfter'], $quote['demoAfter'], $userId]);

        $audit = $pdo->prepare('INSERT INTO currency_exchanges (user_id,from_currency,to_currency,exchange_rate,fee_percent,fee_amount,live_balance_before,live_balance_after,demo_balance_before,demo_balance_after,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())');
        $audit->execute([
            $userId,
            $quote['fromCurrency'],
            $quote['toCurrency'],
            $quote['rate'],
            $quote['feePercent'],
            $quote['feeAmount'],
            $quote['liveBefore'],
            $quote['liveAfter'],
            $quote['demoBefore'],
            $quote['demoAfter'],
        ]);
        $quote['id'] = (int)$pdo->lastInsertId();
        return $quote;
    });

    current_user(true);
    return $result;
}
