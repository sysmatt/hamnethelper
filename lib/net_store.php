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
    return is_array($data) ? $data : null;
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
