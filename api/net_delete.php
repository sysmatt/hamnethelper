<?php
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hnh_error('POST required', 405);
}

$input = hnh_input();
$id = hnh_valid_net_id($input['id'] ?? '');

if (!hnh_delete_net($id)) {
    hnh_error('Net not found', 404);
}

hnh_json(['deleted' => $id]);
