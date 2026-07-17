<?php
/**
 * Imports a previously-downloaded net JSON backup (api/net_download.php's format=json) as a
 * brand-new net (SPEC.md §4). See lib/net_store.php's hnh_import_net() for exactly what's
 * preserved vs reassigned -- in short: everything except identity (id/created_at/updated_at
 * are always fresh, regardless of the uploaded file's own values).
 */

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hnh_error('POST required', 405);
}

$uploaded = hnh_input();

if (!is_array($uploaded) || !array_key_exists('checkins', $uploaded)) {
    hnh_error('That does not look like a hamnethelper net backup file (missing "checkins").', 422);
}

$net = hnh_import_net($uploaded);

try {
    hnh_write_net($net);
} catch (RuntimeException $e) {
    hnh_error($e->getMessage(), 500);
}

hnh_json($net, 201);
