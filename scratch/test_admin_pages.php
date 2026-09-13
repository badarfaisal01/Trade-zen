<?php
require_once __DIR__ . '/../app/bootstrap.php';

$admin = db()->query("SELECT * FROM users WHERE role IN ('super_admin','admin') LIMIT 1")->fetch();
if (!$admin) {
    echo "No admin found\n";
    exit(1);
}

$_SESSION['user_id'] = $admin['id'];

$pages = [
    'dashboard', 'users', 'trades', 'requests', 'finance', 
    'payments', 'coupons', 'support', 'assets', 'agents', 
    'notifications', 'branding', 'admins', 'settings', 
    'audit', 'trading', 'market_api'
];

$errors = [];
foreach ($pages as $p) {
    $_GET = ['page' => $p];
    ob_start();
    try {
        include __DIR__ . '/../admin/index.php';
        $out = ob_get_clean();
        if (strlen($out) < 100) {
            $errors[$p] = "Output suspiciously short (" . strlen($out) . " bytes)";
            echo "Page [$p]: WARNING (" . strlen($out) . " bytes)\n";
        } else {
            echo "Page [$p]: OK (" . strlen($out) . " bytes)\n";
        }
    } catch (Throwable $e) {
        ob_end_clean();
        $errors[$p] = $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile();
        echo "Page [$p]: ERROR -> " . $errors[$p] . "\n";
    }
}

if (!empty($errors)) {
    echo "\n=== ERRORS / WARNINGS ===\n";
    print_r($errors);
    exit(1);
} else {
    echo "\n>>> ALL " . count($pages) . " ADMIN PAGES RENDERED CLEANLY (0 ERRORS)! <<<\n";
    exit(0);
}
