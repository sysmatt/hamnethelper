<?php
require __DIR__ . '/_bootstrap.php';

$id = hnh_valid_net_id($_GET['id'] ?? '');

$net = hnh_read_net($id);
if ($net === null) {
    hnh_error('Net not found', 404);
}

hnh_json($net);
