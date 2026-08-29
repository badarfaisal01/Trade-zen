INSERT INTO market_assets (symbol, display_name, category, base_price, decimal_places, payout_percent, volatility, market_seed, is_active, sort_order, created_at, updated_at) VALUES
('EURUSD', 'EUR/USD', 'Forex', 1.08642, 5, 87.00, 0.00210000, 550101, 1, 10, NOW(), NOW()),
('GBPUSD', 'GBP/USD', 'Forex', 1.27135, 5, 84.00, 0.00230000, 550102, 1, 20, NOW(), NOW()),
('USDJPY', 'USD/JPY', 'Forex', 146.825, 3, 82.00, 0.00200000, 550103, 1, 30, NOW(), NOW()),
('AUDCAD', 'AUD/CAD', 'Forex', 0.89612, 5, 80.00, 0.00250000, 550104, 1, 40, NOW(), NOW()),
('EURJPY', 'EUR/JPY', 'Forex', 159.510, 3, 83.00, 0.00230000, 550105, 1, 50, NOW(), NOW()),
('BTCUSD', 'Bitcoin / USD', 'Crypto', 68650.00, 2, 78.00, 0.00650000, 550201, 1, 60, NOW(), NOW()),
('ETHUSD', 'Ethereum / USD', 'Crypto', 3560.00, 2, 80.00, 0.00600000, 550202, 1, 70, NOW(), NOW()),
('XAUUSD', 'Gold / USD', 'Commodities', 2448.50, 2, 81.00, 0.00310000, 550301, 1, 80, NOW(), NOW())
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), category = VALUES(category), updated_at = NOW();

INSERT INTO site_settings (setting_key, setting_value, setting_type, group_name, is_public, updated_at) VALUES
('platform_name', '55AK Trade', 'string', 'general', 1, NOW()),
('platform_tagline', 'Smart market trading platform', 'string', 'general', 1, NOW()),
('platform_domain', 'https://trading.55ak.xyz', 'string', 'general', 1, NOW()),
('support_email', 'support@55ak.xyz', 'string', 'general', 1, NOW()),
('demo_starting_balance', '0', 'number', 'wallet', 1, NOW()),
('practice_starting_balance', '1000', 'number', 'trading', 1, NOW()),
('minimum_trade_amount', '1', 'number', 'trading', 1, NOW()),
('maximum_trade_amount', '1000', 'number', 'trading', 1, NOW()),
('trade_durations', '[5,10,15,30,60,120,300]', 'json', 'trading', 1, NOW()),
('chart_history_candles', '140', 'number', 'trading', 1, NOW()),
('chart_tick_density', '10', 'number', 'trading', 1, NOW()),
('chart_quote_interval_ms', '650', 'number', 'trading', 1, NOW()),
('trading_enabled', '1', 'boolean', 'trading', 1, NOW()),
('demo_trading_enabled', '1', 'boolean', 'trading', 1, NOW()),
('referral_program_enabled', '1', 'boolean', 'affiliate', 1, NOW()),
('referral_reward_amount', '5', 'number', 'affiliate', 1, NOW()),
('referral_minimum_deposit', '10', 'number', 'affiliate', 1, NOW()),
('minimum_demo_deposit', '10', 'number', 'wallet', 1, NOW()),
('minimum_demo_withdrawal', '20', 'number', 'wallet', 1, NOW()),
('maintenance_mode', '0', 'boolean', 'general', 1, NOW())
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type), group_name = VALUES(group_name), is_public = VALUES(is_public), updated_at = NOW();

INSERT INTO site_settings (setting_key, setting_value, setting_type, group_name, is_public, updated_at) VALUES
('wallet_currency', 'USD', 'string', 'wallet', 1, NOW()),
('deposits_enabled', '1', 'boolean', 'wallet', 1, NOW()),
('currency_exchange_enabled', '1', 'boolean', 'wallet', 1, NOW()),
('currency_exchange_fee_percent', '3', 'number', 'wallet', 1, NOW()),
('currency_exchange_rates', '{"USD":1,"INR":83,"EUR":0.92,"GBP":0.72}', 'json', 'wallet', 0, NOW()),
('withdrawals_enabled', '1', 'boolean', 'wallet', 1, NOW()),
('payment_proof_max_mb', '5', 'number', 'wallet', 0, NOW()),
('payment_order_minutes', '15', 'number', 'wallet', 1, NOW()),
('market_data_mode', 'internal', 'string', 'trading', 1, NOW()),
('market_api_url', '', 'string', 'trading', 0, NOW()),
('market_api_key', '', 'string', 'trading', 0, NOW()),
('market_api_price_path', 'price', 'string', 'trading', 0, NOW())
ON DUPLICATE KEY UPDATE setting_type = VALUES(setting_type), group_name = VALUES(group_name), is_public = VALUES(is_public), updated_at = NOW();

INSERT INTO payment_methods (code, name, category, icon_key, account_name, account_identifier, network, instructions, qr_filename, minimum_amount, maximum_amount, deposit_enabled, withdrawal_enabled, require_reference, require_proof, is_active, sort_order, created_at, updated_at) VALUES
('upi', 'UPI', 'upi', 'upi', '55AK Trade', 'deepansu@ybl', 'UPI', 'Pay to the displayed UPI ID or QR, then submit the successful UTR. Never share a UPI PIN or OTP.', 'upi-deepansu-ybl.png', 10, 100000, 1, 1, 1, 1, 1, 10, NOW(), NOW()),
('phonepe', 'PhonePe', 'wallet', 'phonepe', '55AK Trade', 'deepansu@ybl', 'UPI / PhonePe', 'Pay through PhonePe to the displayed UPI ID or QR, then submit the successful UTR. Never share a UPI PIN or OTP.', 'upi-deepansu-ybl.png', 10, 100000, 1, 1, 1, 1, 1, 20, NOW(), NOW()),
('paytm', 'Paytm', 'wallet', 'paytm', NULL, NULL, NULL, 'Complete the payment and submit the transaction reference for review.', NULL, 10, 100000, 1, 1, 1, 1, 1, 30, NOW(), NOW()),
('binance-pay', 'Binance Pay', 'crypto', 'binance', '55AK Trade', '234543334', 'Binance Pay', 'Send through Binance Pay to the displayed Pay ID, then submit the Binance order ID.', 'binance-pay-234543334.png', 10, 100000, 1, 1, 1, 0, 1, 40, NOW(), NOW()),
('usdt-trc20', 'USDT (TRC-20)', 'crypto', 'usdt', NULL, NULL, 'TRC-20', 'Send USDT only on the TRC-20 network and submit the transaction hash.', NULL, 10, 100000, 1, 1, 1, 0, 1, 50, NOW(), NOW()),
('usdt-bep20', 'USDT (BEP-20)', 'crypto', 'usdt', NULL, NULL, 'BEP-20', 'Send USDT only on the BEP-20 network and submit the transaction hash.', NULL, 10, 100000, 1, 1, 1, 0, 1, 60, NOW(), NOW())
ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category), icon_key = VALUES(icon_key), network = COALESCE(NULLIF(network,''), VALUES(network)), account_name = COALESCE(NULLIF(account_name,''), VALUES(account_name)), account_identifier = COALESCE(NULLIF(account_identifier,''), VALUES(account_identifier)), qr_filename = COALESCE(NULLIF(qr_filename,''), VALUES(qr_filename)), instructions = VALUES(instructions), updated_at = NOW();

INSERT IGNORE INTO schema_migrations (version, applied_at) VALUES ('4.1.2', NOW());
