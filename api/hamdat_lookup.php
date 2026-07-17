<?php
/**
 * Explicit Load/Refresh action from the HAMDAT Lookup Settings dialog (SPEC.md §3.3, §5.1).
 * See lib/hamdat.php for the actual CLI invocation -- also used by net_create.php to run this
 * automatically when the creation form's zip/radius are filled in.
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

try {
    $results = hnh_hamdat_zip_lookup($hnh_config, $zip, $radiusMiles);
} catch (RuntimeException $e) {
    hnh_error($e->getMessage(), 502);
}

hnh_json(['results' => $results]);
