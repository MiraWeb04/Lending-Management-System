<?php
/**
 * Local asset URLs and PWA helpers (works without internet / Wi‑Fi).
 */

function lendingAssetPath(string $relativePath): string
{
    return 'assets/' . ltrim($relativePath, '/');
}

function lendingRenderPwaMeta(): void
{
    $theme = '#0f3974';
    echo '<meta name="theme-color" content="' . htmlspecialchars($theme, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
    echo '<meta name="apple-mobile-web-app-title" content="RJ &amp; RR Finance">' . "\n";
    echo '<link rel="manifest" href="manifest.webmanifest">' . "\n";
    echo '<link rel="apple-touch-icon" href="images/logo.png">' . "\n";
}

function lendingRenderStylesheets(array $extraStylesheets = []): void
{
    lendingRenderPwaMeta();
    // lending_styles.css already @imports nav_styles, design-system, notifications, etc.
    // Do not link those again after lending_styles or sidebar/nav theme overrides break.
    $stylesheets = array_merge([
        lendingAssetPath('vendor/bootstrap/bootstrap.min.css'),
        lendingAssetPath('vendor/fontawesome/css/all.min.css'),
        'css/lending_styles.css',
        'css/pwa.css',
    ], $extraStylesheets);

    foreach ($stylesheets as $href) {
        echo '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }
}

function lendingRenderScripts(array $extraScripts = [], bool $includeCore = true): void
{
    if ($includeCore) {
        echo '<script src="' . htmlspecialchars(lendingAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'), ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
        echo '<script src="assets/lending_ui.js"></script>' . "\n";
        echo '<script src="assets/lending.js"></script>' . "\n";
        echo '<script src="assets/pwa.js" defer></script>' . "\n";
    }

    foreach ($extraScripts as $src) {
        echo '<script src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
    }
}

function lendingChartJsSrc(): string
{
    return lendingAssetPath('vendor/chartjs/chart.umd.min.js');
}
