<?php
$sql = file_get_contents(__DIR__ . '/../database/hostinger_production.sql');
$statements = array_filter(array_map('trim', explode(';', $sql)));
echo "Total SQL statements: " . count($statements) . "\n";
foreach ($statements as $i => $stmt) {
    if (preg_match('/CREATE TABLE (?:IF NOT EXISTS )?`?([a-zA-Z0-9_]+)`?/i', $stmt, $m)) {
        echo "Table: " . $m[1] . "\n";
    }
}
