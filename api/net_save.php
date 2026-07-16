<?php
/**
 * Autosave target (SPEC.md §2). The client sends the full net object after every debounced
 * edit. `id` and `created_at` are server-authoritative and can't be changed by the client;
 * `updated_at` is always recomputed here. The payload is merged over the existing file (not a
 * blind replace) so a client-side bug that omits a field can't silently wipe it out.
 */

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hnh_error('POST required', 405);
}

$input = hnh_input();
$id = hnh_valid_net_id($input['id'] ?? '');

$existing = hnh_read_net($id);
if ($existing === null) {
    hnh_error('Net not found', 404);
}

$net = array_replace($existing, $input);
$net['id'] = $existing['id'];
$net['created_at'] = $existing['created_at'];
$net['updated_at'] = date('c');

try {
    hnh_write_net($net);
} catch (RuntimeException $e) {
    hnh_error($e->getMessage(), 500);
}

hnh_json($net);
