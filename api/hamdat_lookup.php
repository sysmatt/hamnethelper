<?php
/**
 * Runs a hamdat zip/radius query and returns the trimmed {callsign, name, city, state} records
 * this app actually needs (SPEC.md §3.3). Direct exec() of the hamdat binary, same pattern as
 * hamdatweb/index.php: build an argv array, escapeshellarg() every value, write --json output to
 * a temp file, read it, delete it.
 *
 * hamdat's own JSON field names (see hamdat/README.md "Result fields"): call_sign, name, city,
 * state, distance_miles, ... -- only call_sign/name/city/state are kept here.
 */

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hnh_error('POST required', 405);
}

$input = hnh_input();

$zip = trim((string) ($input['zip'] ?? ''));
if (!preg_match('/^\d{5}$/', $zip)) {
    hnh_error('ZIP code must be exactly 5 digits', 422);
}

$radiusMiles = (int) ($input['radius_miles'] ?? 0);
if ($radiusMiles < 0) {
    hnh_error('Radius must be zero or a positive number of miles', 422);
}

if (empty($hnh_config['hamdat_db'])) {
    hnh_error('hamdat_db is not configured (see hamnethelper-config.php.example)', 500);
}

// Not tempnam() + appending an extension -- that creates a file at the pre-extension path via
// tempnam() itself, then hamdat writes to a *different* path (with .json appended), leaving the
// original tempnam() file orphaned on every request. Build the unique, extensioned path directly.
$tempDir = rtrim($hnh_config['hamdat_temp_dir'], '/');
$tmpFile = $tempDir . '/hnh_hamdat_' . bin2hex(random_bytes(8)) . '.json';

$cmd = sprintf(
    '%s --db %s --zip %s --radius-miles %d --json --file %s 2>&1',
    escapeshellarg($hnh_config['hamdat_bin']),
    escapeshellarg($hnh_config['hamdat_db']),
    escapeshellarg($zip),
    $radiusMiles,
    escapeshellarg($tmpFile)
);

exec($cmd, $cmdOutput, $exitCode);

if ($exitCode !== 0) {
    @unlink($tmpFile);
    hnh_error('hamdat error: ' . implode("\n", $cmdOutput), 502);
}

if (!is_readable($tmpFile)) {
    hnh_error('hamdat did not produce an output file', 502);
}

$raw = file_get_contents($tmpFile);
@unlink($tmpFile);

$records = json_decode((string) $raw, true);
if (!is_array($records)) {
    hnh_error('hamdat produced unreadable output', 502);
}

$results = array_map(static function (array $r): array {
    return [
        'callsign' => strtoupper((string) ($r['call_sign'] ?? '')),
        'name' => (string) ($r['name'] ?? ''),
        'city' => (string) ($r['city'] ?? ''),
        'state' => (string) ($r['state'] ?? ''),
    ];
}, $records);

hnh_json(['results' => $results]);
