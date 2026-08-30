<?php
/**
 * Admin Market API Configuration Page
 * Allows admins to configure and test market data APIs
 */

function render_admin_market_api_page(): void
{
    $admin = current_user();
    if (!$admin) {
        return;
    }

    // Get current configuration from environment
    $twelvedataKey = getenv('TWELVEDATA_API_KEY') ?: '';
    $metalsKey = getenv('METALS_API_KEY') ?: '';
    $apiProvider = getenv('MARKET_API_PROVIDER') ?: 'twelvedata';
    
    $assets = market_assets(false);
    
    // Test results (if form was submitted)
    $testResults = null;
    $testMessage = '';
    
    if (is_post() && isset($_POST['action']) && $_POST['action'] === 'test_api') {
        verify_csrf();
        require_admin_capability($admin, 'manage_markets');
        
        try {
            $testResults = test_market_api_connection();
            $testMessage = 'API Connection Test Results:';
            if ($testResults['twelvedata']) {
                flash('success', 'Twelve Data connected successfully!');
            } else if (!empty($testResults['errors']['twelvedata'])) {
                flash('error', 'Twelve Data error: ' . $testResults['errors']['twelvedata']);
            }
            if ($testResults['metals']) {
                flash('success', 'Metals API connected successfully!');
            } else if (!empty($testResults['errors']['metals'])) {
                flash('error', 'Metals API error: ' . $testResults['errors']['metals']);
            }
        } catch (Exception $e) {
            flash('error', 'Test failed: ' . $e->getMessage());
        }
    }
    ?>

<section class="admin-page-intro">
    <div>
        <span class="eyebrow">Market Data Integration</span>
        <h2>Real Market APIs</h2>
        <p>Configure live market data from Twelve Data and Metals API. Your API keys are stored securely in the server environment.</p>
    </div>
    <span class="admin-lock-note"><?= icon('shield', 18) ?> Secure API Configuration</span>
</section>

<section class="admin-grid admin-grid--api-config">
    <article class="admin-card">
        <div class="admin-card-head">
            <div>
                <span class="eyebrow">API Provider</span>
                <h2>Market Data Configuration</h2>
            </div>
        </div>

        <div class="api-config-sections">
            <!-- Twelve Data Configuration -->
            <div class="api-config-section">
                <h3>📊 Twelve Data (Forex & Crypto)</h3>
                <p class="api-section-desc">Real-time forex pairs (EUR/USD, GBP/USD, etc.) and crypto prices. Free tier: 800 API calls/day</p>
                
                <div class="api-key-display">
                    <strong>Current Status:</strong>
                    <span class="status status--<?= !empty($twelvedataKey) ? 'active' : 'inactive' ?>">
                        <?= !empty($twelvedataKey) ? '✓ Configured' : '✗ Not Configured' ?>
                    </span>
                </div>

                <div class="api-section-actions">
                    <p class="help-text">
                        🔗 <strong>Get Free API Key:</strong> 
                        <a href="https://twelvedata.com/apikeys" target="_blank" rel="noopener">Sign up at Twelve Data</a>
                    </p>
                    <ol class="help-steps">
                        <li>Visit <code>https://twelvedata.com</code></li>
                        <li>Create a free account</li>
                        <li>Go to API Keys section</li>
                        <li>Copy your API key</li>
                        <li>Add to <code>.env</code> as <code>TWELVEDATA_API_KEY=your_key_here</code></li>
                    </ol>
                </div>

                <h4>Supported Forex Symbols:</h4>
                <div class="symbol-list">
                    <span class="symbol-tag">EURUSD</span>
                    <span class="symbol-tag">GBPUSD</span>
                    <span class="symbol-tag">USDJPY</span>
                    <span class="symbol-tag">AUDCAD</span>
                    <span class="symbol-tag">EURJPY</span>
                </div>

                <h4>Supported Crypto Symbols:</h4>
                <div class="symbol-list">
                    <span class="symbol-tag">BTCUSD</span>
                    <span class="symbol-tag">ETHUSD</span>
                </div>
            </div>

            <hr style="margin: 30px 0; border: none; border-top: 1px solid #333;">

            <!-- Metals API Configuration -->
            <div class="api-config-section">
                <h3>💎 Metals API (Gold & Precious Metals)</h3>
                <p class="api-section-desc">Real-time gold, silver, platinum prices. Free tier: 100 API calls/month</p>
                
                <div class="api-key-display">
                    <strong>Current Status:</strong>
                    <span class="status status--<?= !empty($metalsKey) ? 'active' : 'inactive' ?>">
                        <?= !empty($metalsKey) ? '✓ Configured' : '✗ Not Configured' ?>
                    </span>
                </div>

                <div class="api-section-actions">
                    <p class="help-text">
                        🔗 <strong>Get Free API Key:</strong> 
                        <a href="https://metals-api.com/start" target="_blank" rel="noopener">Sign up at Metals API</a>
                    </p>
                    <ol class="help-steps">
                        <li>Visit <code>https://metals-api.com</code></li>
                        <li>Create a free account</li>
                        <li>Get your access key from dashboard</li>
                        <li>Add to <code>.env</code> as <code>METALS_API_KEY=your_key_here</code></li>
                    </ol>
                </div>

                <h4>Supported Commodity Symbols:</h4>
                <div class="symbol-list">
                    <span class="symbol-tag">XAUUSD</span>
                    <span class="symbol-tag">XAU</span>
                    <span class="symbol-tag">GOLD</span>
                </div>
            </div>
        </div>

        <!-- Test API Connection -->
        <div class="admin-section" style="margin-top: 30px; padding-top: 30px; border-top: 1px solid #333;">
            <h3>Test API Connection</h3>
            <p>Click below to test if your API keys are configured correctly.</p>
            
            <form method="post" class="admin-editor-form">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="test_api">
                <input type="hidden" name="return_page" value="market_api">
                <button class="button button--ghost button--small" type="submit">Test API Connections</button>
            </form>

            <?php if ($testResults): ?>
            <div class="test-results">
                <h4>Test Results:</h4>
                <div class="result-item" style="margin: 10px 0;">
                    <strong>Twelve Data:</strong>
                    <?php if ($testResults['twelvedata']): ?>
                        <span class="status status--active">✓ Connected</span>
                    <?php else: ?>
                        <span class="status status--inactive">✗ Failed</span>
                        <?php if (!empty($testResults['errors']['twelvedata'])): ?>
                            <div class="error-message"><?= e($testResults['errors']['twelvedata']) ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="result-item" style="margin: 10px 0;">
                    <strong>Metals API:</strong>
                    <?php if ($testResults['metals']): ?>
                        <span class="status status--active">✓ Connected</span>
                    <?php else: ?>
                        <span class="status status--inactive">✗ Failed</span>
                        <?php if (!empty($testResults['errors']['metals'])): ?>
                            <div class="error-message"><?= e($testResults['errors']['metals']) ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </article>

    <!-- Market Assets Using Real APIs -->
    <article class="admin-card">
        <div class="admin-card-head">
            <div>
                <span class="eyebrow">Asset Configuration</span>
                <h2>Markets Using Real APIs</h2>
            </div>
        </div>

        <div class="asset-api-grid">
            <?php foreach ($assets as $asset): ?>
                <?php if ($asset['price_source'] === 'api'): ?>
                <div class="asset-api-item">
                    <strong><?= e($asset['symbol']) ?></strong>
                    <p><?= e($asset['display_name']) ?></p>
                    <small>API Symbol: <?= e($asset['api_symbol'] ?: $asset['symbol']) ?></small>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
            
            <?php $apiAssets = array_filter($assets, fn($a) => $a['price_source'] === 'api'); ?>
            <?php if (empty($apiAssets)): ?>
            <div class="admin-empty" style="grid-column: 1/-1;">
                <p>No markets configured with API source yet.</p>
                <p><a href="?page=assets">Configure markets to use API prices →</a></p>
            </div>
            <?php endif; ?>
        </div>
    </article>
</section>

<style>
.api-config-sections {
    padding: 20px 0;
}

.api-config-section {
    margin-bottom: 20px;
}

.api-config-section h3 {
    font-size: 16px;
    margin-bottom: 8px;
}

.api-section-desc {
    color: #999;
    font-size: 14px;
    margin-bottom: 15px;
}

.api-key-display {
    background: #1a1f2e;
    padding: 12px;
    border-radius: 4px;
    margin-bottom: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.api-section-actions {
    background: #0f1419;
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 15px;
}

.help-text {
    margin: 0 0 10px 0;
    color: #ccc;
}

.help-text a {
    color: #4a9eff;
    text-decoration: none;
}

.help-text a:hover {
    text-decoration: underline;
}

.help-steps {
    margin: 10px 0 0 20px;
    padding: 0;
    color: #999;
    font-size: 14px;
}

.help-steps li {
    margin-bottom: 5px;
}

.symbol-list {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 15px;
}

.symbol-tag {
    background: #1a3a52;
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 12px;
    color: #4a9eff;
    font-family: monospace;
}

.test-results {
    background: #0f1419;
    padding: 15px;
    border-radius: 4px;
    margin-top: 15px;
}

.result-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.error-message {
    color: #ff6b6b;
    font-size: 12px;
    margin-top: 5px;
}

.asset-api-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 15px;
}

.asset-api-item {
    background: #1a1f2e;
    padding: 15px;
    border-radius: 4px;
    border-left: 3px solid #4a9eff;
}

.asset-api-item strong {
    display: block;
    margin-bottom: 5px;
    color: #fff;
}

.asset-api-item p {
    margin: 0 0 10px 0;
    font-size: 14px;
    color: #999;
}

.asset-api-item small {
    display: block;
    color: #666;
    font-family: monospace;
    font-size: 12px;
}
</style>

    <?php
}
