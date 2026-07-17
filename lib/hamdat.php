<?php
/**
 * Shared hamdat CLI invocation. Used by api/hamdat_lookup.php (the explicit Load/Refresh action)
 * and api/net_create.php (running the lookup immediately if the creation form's zip/radius were
 * filled in, rather than requiring a second trip into HAMDAT Lookup Settings afterward).
 *
 * Direct exec() of the hamdat binary, same pattern as hamdatweb/index.php: build an argv string,
 * escapeshellarg() every value, write --json output to a temp file, read it, delete it.
 *
 * hamdat's own JSON field names (see hamdat/README.md "Result fields"): call_sign, name, city,
 * state, distance_miles, ... -- only call_sign/name/city/state are kept here.
 *
 * hamdat is a `#!/usr/bin/env python3` script -- run directly, that shebang resolves to whatever
 * python3 is first on PATH for the web server process, which is not necessarily the venv hamdat's
 * own dependencies (requests, pgeocode) are actually installed into. If `hamdat_python_bin` is
 * configured, we invoke that interpreter explicitly with the hamdat script as its argument
 * instead of relying on the shebang, so the correct venv is used regardless of PATH.
 *
 * Throws RuntimeException on any failure; callers decide whether that's fatal.
 */
function hnh_hamdat_zip_lookup(array $config, string $zip, int $radiusMiles): array
{
    if (empty($config['hamdat_db'])) {
        throw new RuntimeException('hamdat_db is not configured (see hamnethelper-config.php.example)');
    }

    // Not tempnam() + appending an extension -- that creates a file at the pre-extension path via
    // tempnam() itself, then hamdat writes to a *different* path (with .json appended), leaving
    // the original tempnam() file orphaned on every request. Build the unique path directly.
    $tempDir = rtrim($config['hamdat_temp_dir'], '/');
    $tmpFile = $tempDir . '/hnh_hamdat_' . bin2hex(random_bytes(8)) . '.json';

    $interpreterPrefix = !empty($config['hamdat_python_bin'])
        ? escapeshellarg($config['hamdat_python_bin']) . ' '
        : '';

    $cmd = sprintf(
        '%s%s --db %s --zip %s --radius-miles %d --json --file %s 2>&1',
        $interpreterPrefix,
        escapeshellarg($config['hamdat_bin']),
        escapeshellarg($config['hamdat_db']),
        escapeshellarg($zip),
        $radiusMiles,
        escapeshellarg($tmpFile)
    );

    exec($cmd, $cmdOutput, $exitCode);

    if ($exitCode !== 0) {
        @unlink($tmpFile);
        throw new RuntimeException('hamdat error: ' . implode("\n", $cmdOutput));
    }

    if (!is_readable($tmpFile)) {
        throw new RuntimeException('hamdat did not produce an output file');
    }

    $raw = file_get_contents($tmpFile);
    @unlink($tmpFile);

    $records = json_decode((string) $raw, true);
    if (!is_array($records)) {
        throw new RuntimeException('hamdat produced unreadable output');
    }

    return array_map(static function (array $r): array {
        return [
            'callsign' => strtoupper((string) ($r['call_sign'] ?? '')),
            'name' => (string) ($r['name'] ?? ''),
            'city' => (string) ($r['city'] ?? ''),
            'state' => (string) ($r['state'] ?? ''),
        ];
    }, $records);
}
