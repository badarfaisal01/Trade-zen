<?php
declare(strict_types=1);

function check_bom($dir) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $content = file_get_contents($file->getPathname());
            if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
                echo "BOM FOUND in: " . $file->getPathname() . "\n";
            }
        }
    }
}

check_bom(__DIR__ . '/../app');
check_bom(__DIR__ . '/../en');
check_bom(__DIR__ . '/../config');
echo "BOM scan complete.\n";
