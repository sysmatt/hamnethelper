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

try {
    hnh_write_net($net);
} catch (RuntimeException $e) {
    hnh_error($e->getMessage(), 500);
}

hnh_json($net, 201);
