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

/**
 * Best-effort read of the *host's* configured timezone, as a sensible default for `timezone`
 * below -- most deployments already have their system clock set correctly for wherever net
 * control operates from, so this saves that being a second, easy-to-forget place to configure it
 * (see SPEC.md §5.6's history: forgetting to set it once already produced a wrong report). Only
 * ever used as a fallback -- an explicit `timezone` in hamnethelper-config.php always wins.
 * Falls back to 'UTC' if detection fails for any reason (non-Linux host, unreadable files,
 * unrecognized value) rather than risking an invalid DateTimeZone identifier downstream.
 */
function hnh_detect_system_timezone(): string
{
    $identifiers = DateTimeZone::listIdentifiers();

    // /etc/timezone: a plain-text identifier, the simplest source (Debian/Ubuntu and derivatives).
    if (is_readable('/etc/timezone')) {
        $tz = trim((string) file_get_contents('/etc/timezone'));
        if (in_array($tz, $identifiers, true)) {
            return $tz;
        }
    }

    // /etc/localtime: most other distros symlink this to .../zoneinfo/<Region>/<City> instead.
    if (is_link('/etc/localtime')) {
        $target = readlink('/etc/localtime');
        $pos = $target !== false ? strpos($target, 'zoneinfo/') : false;
        if ($pos !== false) {
            $tz = substr($target, $pos + strlen('zoneinfo/'));
            if (in_array($tz, $identifiers, true)) {
                return $tz;
            }
        }
    }

    return 'UTC';
}

function hnh_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $defaults = [
        // Every timestamp stored by this app (created_at, opened_at, checked_in_at,
        // official_start, ...) is a UTC instant -- correct and unambiguous for storage/duration
        // math, but meaningless to a human reader without converting to a real timezone first.
        // The browser handles that automatically for on-screen display (HNH.formatTime(), §5.1),
        // but report generation (api/net_download.php) runs server-side with no browser to ask,
        // so it needs to be told what timezone to render times in. Defaults to the host's own
        // system timezone (hnh_detect_system_timezone()) -- override here only if net control
        // operates somewhere other than wherever this server's clock is set for.
        'timezone' => hnh_detect_system_timezone(),
        'hamdat_bin' => '/usr/local/bin/hamdat',
        'hamdat_db' => null,
        'hamdat_temp_dir' => sys_get_temp_dir(),
        // If hamdat needs to run from a specific Python venv (its own dependencies -- requests,
        // pgeocode -- installed there rather than in whatever python3 the web server's PATH
        // resolves to), set this to that venv's python binary, e.g.
        // '/home/hamdat/venv/bin/python3'. Left unset (null), hamdat runs directly, relying on
        // its own `#!/usr/bin/env python3` shebang. See lib/hamdat.php.
        'hamdat_python_bin' => null,
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
        'lookup_suggestion_limit' => 15,
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
