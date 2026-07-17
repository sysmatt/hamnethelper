<?php
/**
 * Public, browser-safe subset of the site config -- deliberately excludes server-internal
 * paths (hamdat_bin, hamdat_db, nets_dir). Everything the frontend needs to stay config-driven
 * (net type dropdown options, autosave timing, upload limits, default theme/radius) comes from
 * here rather than being hardcoded in JS.
 */

require __DIR__ . '/_bootstrap.php';

hnh_json([
    'app_name' => $hnh_config['app_name'],
    'net_types' => $hnh_config['net_types'],
    'autosave_debounce_ms' => $hnh_config['autosave_debounce_ms'],
    'roster_upload_max_bytes' => $hnh_config['roster_upload_max_bytes'],
    'default_theme' => $hnh_config['default_theme'],
    'default_hamdat_radius_miles' => $hnh_config['default_hamdat_radius_miles'],
    'lookup_suggestion_limit' => $hnh_config['lookup_suggestion_limit'],
]);
