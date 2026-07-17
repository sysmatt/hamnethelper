<?php
/**
 * Creates a new net. Used both by "Begin New Net" (blank form) and "Start new net like this
 * one" (form pre-filled client-side from an existing net, editable before submit) -- the
 * request body shape is identical either way; this endpoint doesn't need to know about a
 * source net (SPEC.md §4).
 */

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hnh_error('POST required', 405);
}

$input = hnh_input();

if (trim($input['name'] ?? '') === '') {
    hnh_error('Name is required', 422);
}

$net = hnh_new_net($input);

// If the creation form's ZIP was filled in, run the hamdat lookup immediately rather than
// making the operator open the net and separately visit HAMDAT Lookup Settings just to do what
// they already told us to do. Best-effort: a failed lookup here (bad zip data, hamdat down)
// must never block net creation -- it's surfaced back to the client as `hamdat_lookup_error`
// so the UI can tell the operator, and they can retry from the dialog once the net is open.
$zip = $net['hamdat_lookup']['zip'];
$hamdatLookupError = null;
if (preg_match('/^\d{5}$/', $zip)) {
    try {
        $net['hamdat_lookup']['cached_results'] = hnh_hamdat_zip_lookup(
            $hnh_config,
            $zip,
            (int) $net['hamdat_lookup']['radius_miles']
        );
        $net['hamdat_lookup']['last_refreshed_at'] = date('c');
    } catch (RuntimeException $e) {
        $hamdatLookupError = $e->getMessage();
    }
}

try {
    hnh_write_net($net);
} catch (RuntimeException $e) {
    hnh_error($e->getMessage(), 500);
}

if ($hamdatLookupError !== null) {
    $net['hamdat_lookup_error'] = $hamdatLookupError;
}

hnh_json($net, 201);
