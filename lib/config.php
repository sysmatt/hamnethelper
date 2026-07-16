<?php
/**
 * Loads hamnethelper-config.php from the docroot parent (one level above this repo, same
 * pattern as hamdatweb-config.php) and merges it over defaults. Every literal that should be
 * changeable without a code change (net types, debounce timing, upload limits, hamdat paths,
 * branding) lives here as a default and can be overridden in the site config file.
 *
 * Throws RuntimeException on missing/invalid config rather than exiting directly, so page
 * scripts and API endpoints can each format the error their own way.
 */

function hnh_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $defaults = [
        'hamdat_bin' => '/usr/local/bin/hamdat',
        'hamdat_db' => null,
        'nets_dir' => '/var/lib/hamnethelper/nets',
        'app_name' => 'HamNetHelper',
        'net_types' => [
            ['value' => 'weekly', 'label' => 'Weekly'],
            ['value' => 'ares', 'label' => 'Emergency / ARES'],
            ['value' => 'drill', 'label' => 'Drill / Training'],
            ['value' => 'special', 'label' => 'Special Event'],
            ['value' => 'other', 'label' => 'Other'],
        ],
        'default_hamdat_radius_miles' => 25,
        'autosave_debounce_ms' => 800,
        'roster_upload_max_bytes' => 65536,
        'default_theme' => 'dark',
    ];

    $configFile = __DIR__ . '/../../hamnethelper-config.php';

    if (!is_file($configFile)) {
        throw new RuntimeException(
            "hamnethelper is not configured.\n\n" .
            "Copy hamnethelper-config.php.example to:\n  $configFile\n" .
            "and fill in the correct paths, then reload."
        );
    }

    $userConfig = require $configFile;

    if (!is_array($userConfig)) {
        throw new RuntimeException('hamnethelper-config.php must `return` an array.');
    }

    $config = array_replace($defaults, $userConfig);

    return $config;
}
