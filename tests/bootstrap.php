<?php

declare(strict_types=1);

/**
 * Test bootstrap for the NagaCommerce SDK.
 *
 * Canonical setup:
 *   composer install   (installs PHPUnit + sets up the autoloader)
 *   vendor/bin/phpunit (or: composer test)
 *
 * Fallback for the dev case where this repo sits next to the nagaCommerce
 * repo and PHPUnit is already installed there — we use that vendor so the
 * SDK repo can run its tests without a separate composer install. This
 * fallback only triggers when there's no local vendor/.
 */

$root = dirname(__DIR__);

$candidates = [
    $root . '/vendor/autoload.php',                              // canonical: local composer install
    dirname($root) . '/nagaCommerce/packages/vendor/autoload.php', // dev convenience: sibling repo's vendor
];
foreach ($candidates as $autoload) {
    if (file_exists($autoload)) {
        require $autoload;
        break;
    }
}

// PSR-4 autoload for the SDK itself, matching composer.json. Kept even
// when composer's autoloader is loaded above, so this file works in both
// modes without ordering assumptions.
spl_autoload_register(function ($class) use ($root) {
    $prefix = 'NagaCommerce\\SDK\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));

    // First try src/ — production code.
    $file = $root . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require $file;
        return;
    }

    // Fall back to tests/ for test-namespace classes (Integration\,
    // Support\, etc.) that mirror composer.json's autoload-dev section.
    $testFile = $root . '/tests/' . str_replace('\\', '/', preg_replace('#^Tests\\\\#', '', $relative)) . '.php';
    if (file_exists($testFile)) {
        require $testFile;
    }
});

// Test-support classes.
require_once __DIR__ . '/Support/RecordingHttpClient.php';
