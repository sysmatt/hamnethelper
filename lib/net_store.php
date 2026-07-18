<?php
/**
 * File I/O for net JSON files. Every api/*.php endpoint that reads/writes a net goes through
 * here rather than touching the filesystem directly, so the on-disk layout (SPEC.md §3.1) only
 * has one implementation.
 */

function hnh_uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function hnh_net_file_path(string $id): string
{
    global $hnh_config;
    return rtrim($hnh_config['nets_dir'], '/') . '/' . $id . '.json';
}

function hnh_read_net(string $id): ?array
{
    $path = hnh_net_file_path($id);
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return null;
    }

    // Nets created before the Official Start / clock ribbon feature won't have these keys on
    // disk -- default opened_at to created_at (the best available approximation of "when the
    // net was opened") and leave official_start unset, rather than requiring a one-time
    // migration of existing net files.
    if (empty($data['opened_at'])) {
        $data['opened_at'] = $data['created_at'] ?? null;
    }
    if (!array_key_exists('official_start', $data)) {
        $data['official_start'] = null;
    }

    return $data;
}

/**
 * Validates official_start as a parseable ISO 8601 datetime (a full instant, not just "HH:MM" --
 * see SPEC.md §5.6 for why: a bare wall-clock time with no date requires guessing which calendar
 * day it refers to, which was a real source of Duration bugs). Anything unparseable -- including
 * null/empty -- normalizes to null (no official start set).
 */
function hnh_valid_official_start(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    try {
        new DateTime($value);
    } catch (Exception $e) {
        return null;
    }
    return $value;
}

/**
 * Atomic write: write to a temp file in the same directory, then rename over the target, so a
 * request that dies mid-write never leaves a truncated net file behind.
 */
function hnh_write_net(array $net): void
{
    global $hnh_config;
    $dir = rtrim($hnh_config['nets_dir'], '/');

    if (!is_dir($dir)) {
        throw new RuntimeException("Nets directory does not exist or is not readable: $dir");
    }

    $path = $dir . '/' . $net['id'] . '.json';
    $tmpPath = $path . '.tmp-' . bin2hex(random_bytes(4));

    $json = json_encode($net, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($tmpPath, $json) === false) {
        throw new RuntimeException('Failed to write net file.');
    }

    if (!rename($tmpPath, $path)) {
        @unlink($tmpPath);
        throw new RuntimeException('Failed to finalize net file write.');
    }
}

function hnh_delete_net(string $id): bool
{
    $path = hnh_net_file_path($id);
    if (!is_file($path)) {
        return false;
    }
    return unlink($path);
}

/**
 * Summary metadata for the net list page (SPEC.md §4) — scans the data directory fresh on
 * every call rather than maintaining a separate index (see SPEC.md §8 resolution).
 */
function hnh_list_nets(): array
{
    global $hnh_config;
    $dir = rtrim($hnh_config['nets_dir'], '/');
    $out = [];

    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            continue;
        }
        $out[] = [
            'id' => $data['id'] ?? basename($file, '.json'),
            'name' => $data['name'] ?? '',
            'net_type' => $data['net_type'] ?? '',
            'net_control' => $data['net_control'] ?? '',
            'created_at' => $data['created_at'] ?? null,
            'ended_at' => $data['ended_at'] ?? null,
            'status' => $data['status'] ?? 'open',
            'checkin_count' => is_array($data['checkins'] ?? null) ? count($data['checkins']) : 0,
        ];
    }

    usort($out, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

    return $out;
}

/**
 * Builds a fresh net structure. $fields carries whatever the creation form submitted — for a
 * blank "Begin New Net" that's just the form fields; for "Start new net like this one" the
 * client pre-fills the same form (including roster/script_notes) from an existing net and the
 * operator can edit before submitting, so this function itself doesn't need to know about
 * source nets (SPEC.md §4).
 */
function hnh_new_net(array $fields): array
{
    global $hnh_config;
    $now = date('c');

    $hamdatLookup = $fields['hamdat_lookup'] ?? [];

    return [
        'id' => hnh_uuid_v4(),
        'schema_version' => 1,
        'name' => $fields['name'] ?? '',
        'net_type' => $fields['net_type'] ?? '',
        'created_at' => $now,
        'updated_at' => $now,
        'opened_at' => $now,
        'official_start' => hnh_valid_official_start($fields['official_start'] ?? null),
        'ended_at' => null,
        'net_control' => $fields['net_control'] ?? '',
        'frequency' => $fields['frequency'] ?? '',
        'description' => $fields['description'] ?? '',
        'status' => 'open',
        'script_notes' => $fields['script_notes'] ?? '',
        'hamdat_lookup' => [
            'zip' => $hamdatLookup['zip'] ?? '',
            'radius_miles' => $hamdatLookup['radius_miles'] ?? $hnh_config['default_hamdat_radius_miles'],
            'cached_results' => [],
            'last_refreshed_at' => null,
        ],
        'roster' => array_values($fields['roster'] ?? []),
        'checkins' => [],
    ];
}

/**
 * Restores a net from a previously-downloaded JSON backup (api/net_download.php's format=json --
 * see SPEC.md §4). Unlike hnh_new_net(), this preserves everything -- checkins, roster,
 * script_notes, hamdat_lookup including its cached_results, status, ended_at -- since an import
 * is meant to be a faithful restore, not a fresh start.
 *
 * The one thing it never preserves is identity: `id`/`created_at`/`updated_at` are always
 * reassigned, regardless of whatever the uploaded file's own values were. An import is always a
 * new net as far as this server is concerned -- restoring the same backup twice, or a backup
 * whose id happens to collide with an existing net (e.g. re-uploading to the same server it came
 * from), must never silently overwrite anything.
 *
 * $uploaded is merged over a full-shape skeleton (not just whitelisted fields) so a
 * hand-edited or partially-missing backup can't produce a structurally invalid net file.
 */
function hnh_import_net(array $uploaded): array
{
    $skeleton = [
        'schema_version' => 1,
        'name' => '',
        'net_type' => '',
        'net_control' => '',
        'frequency' => '',
        'description' => '',
        'status' => 'open',
        'ended_at' => null,
        'opened_at' => null,
        'official_start' => null,
        'script_notes' => '',
        'hamdat_lookup' => [
            'zip' => '',
            'radius_miles' => 0,
            'cached_results' => [],
            'last_refreshed_at' => null,
        ],
        'roster' => [],
        'checkins' => [],
    ];

    $net = array_replace($skeleton, $uploaded);

    $net['roster'] = array_values(is_array($net['roster'] ?? null) ? $net['roster'] : []);
    $net['checkins'] = array_values(is_array($net['checkins'] ?? null) ? $net['checkins'] : []);
    if (!is_array($net['hamdat_lookup'] ?? null)) {
        $net['hamdat_lookup'] = $skeleton['hamdat_lookup'];
    } else {
        $net['hamdat_lookup'] = array_replace($skeleton['hamdat_lookup'], $net['hamdat_lookup']);
    }
    $net['status'] = in_array($net['status'] ?? null, ['open', 'closed'], true) ? $net['status'] : 'open';

    $now = date('c');
    $net['id'] = hnh_uuid_v4();
    $net['created_at'] = $now;
    $net['updated_at'] = $now;

    // opened_at/official_start are operational data, not identity -- preserved from the backup
    // like checkins/status/script_notes are. A backup predating this feature (or missing the
    // key) falls back to the fresh created_at, same as hnh_read_net()'s migration path.
    if (empty($net['opened_at'])) {
        $net['opened_at'] = $now;
    }
    $net['official_start'] = hnh_valid_official_start($net['official_start'] ?? null);

    return $net;
}
