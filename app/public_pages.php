<?php
declare(strict_types=1);

function render_public_information_page(string $slug): void
{
    $supportEmail = (string)setting('support_email', 'support@example.com');
    $pages = [
        'about' => [
            'title' => 'About us',
            'eyebrow' => 'Platform information',
            'intro' => 'A self-hosted trading interface with separate Live and Demo balances, server-recorded trades, and administrator-reviewed payment requests.',
            'sections' => [
                ['How the platform works', 'The trading room sends authenticated requests to this site’s own PHP API. Entry prices, expiry prices, balances, and results are recorded in the database.'],
                ['Account controls', 'Users can switch between a Live wallet and a separate Demo balance. Deposits and withdrawals use a review workflow with references, proof, status history, and an audit trail.'],
                ['Responsible operation', 'The operator is responsible for licensing, customer disclosures, identity checks, accounting, privacy, and every rule that applies in the countries where the service is offered.'],
            ],
        ],
        'blog' => [
            'title' => 'Platform blog',
            'eyebrow' => 'Product updates',
            'intro' => 'Short guides and operational notes for safer account and payment use.',
            'sections' => [
                ['Live and Demo accounts', 'Demo funds are separate practice credits. Switching accounts does not transfer funds between Demo and Live wallets.'],
                ['Before submitting a deposit', 'Use only an active payment method shown inside your signed-in account. Confirm its network or UPI ID and keep the provider reference.'],
                ['Protect your account', 'Use a unique password and never share OTPs, UPI PINs, card PINs, private keys, or wallet seed phrases with support or administrators.'],
            ],
        ],
        'faq' => [
            'title' => 'Frequently asked questions',
            'eyebrow' => 'Help center',
            'intro' => 'Answers to common account, balance, trade, and payment questions.',
            'sections' => [
                ['Why is my Live balance different from Demo?', 'They are two independent balances. Demo is for practice; approved deposits credit the Live wallet.'],
                ['What happens when balance is insufficient?', 'A Live trade is not opened or charged. The trade room shows an insufficient-balance message and opens the Deposit page.'],
                ['When does a deposit change my balance?', 'Only after an administrator verifies and approves the deposit request. Pending requests do not credit the wallet.'],
                ['How are withdrawals handled?', 'The requested amount and fee are reserved on submission. Rejection or cancellation refunds the reserve; paid status requires an external provider reference.'],
                ['Where can I see activity?', 'Trade history, analytics, wallet ledger, payment requests, and support replies are available after sign-in.'],
            ],
        ],
        'contact' => [
            'title' => 'Contact and support',
            'eyebrow' => 'Get help',
            'intro' => 'For account-specific help, use the signed-in Support page so the request is linked to the correct account.',
            'sections' => [
                ['Support email', $supportEmail],
                ['Payment questions', 'Include the request ID and provider reference. Never include a PIN, OTP, password, private key, or seed phrase.'],
                ['Technical questions', 'Describe the page, action, time, and exact error. Screenshots should not reveal credentials or private payment data.'],
            ],
        ],
        'terms' => [
            'title' => 'Platform terms',
            'eyebrow' => 'Legal information',
            'intro' => 'These baseline terms should be reviewed and replaced by the operator’s qualified legal counsel before public launch.',
            'sections' => [
                ['Eligibility', 'Users must meet the operator’s age, location, identity, and account eligibility requirements and provide accurate information.'],
                ['Account responsibility', 'Users are responsible for protecting credentials and for activity performed through their authenticated session.'],
                ['Payments and records', 'Payment requests remain pending until reviewed. Provider records, platform ledger entries, and audit logs may be used to investigate disputes.'],
                ['Prohibited use', 'The platform must not be used for fraud, unlawful payments, sanctions evasion, market abuse, or activity prohibited by applicable law.'],
            ],
        ],
        'privacy' => [
            'title' => 'Privacy notice',
            'eyebrow' => 'Data handling',
            'intro' => 'This notice summarizes the data handled by the self-hosted application; the operator must publish the final policy that matches its deployment.',
            'sections' => [
                ['Data collected', 'Account details, login metadata, trades, wallet activity, payment references, uploaded proof, support requests, and administrator audit events.'],
                ['Why data is used', 'To authenticate users, operate balances and trades, review requests, prevent abuse, answer support, and meet legal or accounting duties.'],
                ['Security and retention', 'Credentials are hashed and protected uploads are stored outside public asset paths. The operator controls backups, access, retention periods, and deletion procedures.'],
            ],
        ],
        'risk' => [
            'title' => 'Risk disclosure',
            'eyebrow' => 'Important notice',
            'intro' => 'Short-duration price-direction trading can result in the complete loss of each stake and may not be suitable for every person.',
            'sections' => [
                ['Financial loss', 'A losing trade loses the recorded stake. Past outcomes, Demo results, and displayed payout rates do not guarantee future results.'],
                ['Technology risk', 'Internet, hosting, database, price-feed, device, and provider failures can delay access or requests. Do not rely on the platform for emergency funds.'],
                ['Independent decision', 'Understand the product, local law, fees, payout rules, and worst-case loss before using a Live account. Seek independent professional advice when needed.'],
            ],
        ],
    ];
    if (!isset($pages[$slug])) {
        http_response_code(404);
        exit('Page not found.');
    }
    $page = $pages[$slug];
    render_document_start($page['title'], 'public-info-page', ['css/public-pages.css'], true);
    render_public_header();
    ?>
    <main class="public-info-main"><section class="public-info-hero"><span class="eyebrow"><?= e($page['eyebrow']) ?></span><h1><?= e($page['title']) ?></h1><p><?= e($page['intro']) ?></p><div><a class="button button--primary" href="<?= current_user() ? '/en/trade/' : '/en/sign-up/' ?>"><?= current_user() ? 'Open trade room' : 'Create account' ?></a><a class="button button--ghost" href="<?= current_user() ? '/en/support/' : '/en/sign-in/' ?>"><?= current_user() ? 'Open support' : 'Sign in' ?></a></div></section><section class="public-info-grid"><?php foreach ($page['sections'] as $section): ?><article><h2><?= e($section[0]) ?></h2><p><?= e($section[1]) ?></p></article><?php endforeach; ?></section></main>
    <?php
    render_public_footer();
    render_document_end();
}
