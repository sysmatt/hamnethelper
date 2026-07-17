<?php
/**
 * Downloads for a net (SPEC.md §4):
 *   ?id=<id>&format=json           -- raw net data, as-is (the "JSON backup" download)
 *   ?id=<id>&format=csv            -- check-in table only, composed preferred_name(name) column
 *   ?id=<id>&format=report         -- plain-text summary meant to be pasted into a follow-up
 *                                     email; omits per-check-in Notes (those are often just
 *                                     internal to the net controller)
 *   ?id=<id>&format=report&notes=1 -- same report, with per-check-in Notes included
 *
 * Every filename includes the net's creation date (see hnh_download_date()) so nets that share a
 * name (e.g. a weekly net run under the same title every week) produce distinguishable downloads.
 */

require __DIR__ . '/_bootstrap.php';

$id = hnh_valid_net_id($_GET['id'] ?? '');
$format = $_GET['format'] ?? '';

$net = hnh_read_net($id);
if ($net === null) {
    hnh_error('Net not found', 404);
}

function hnh_download_slug(string $s): string
{
    $slug = trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $s), '-');
    return $slug !== '' ? strtolower($slug) : 'net';
}

function hnh_download_date(?string $iso): string
{
    if (!$iso) {
        return 'undated';
    }
    try {
        return (new DateTime($iso))->format('Y-m-d');
    } catch (Exception $e) {
        return 'undated';
    }
}

function hnh_checkin_display_name(array $checkin): string
{
    $name = (string) ($checkin['name'] ?? '');
    $preferred = trim((string) ($checkin['preferred_name'] ?? ''));
    return $preferred !== '' ? ($preferred . ' (' . $name . ')') : $name;
}

function hnh_report_date(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    try {
        return (new DateTime($iso))->format('l, F j, Y');
    } catch (Exception $e) {
        return $iso;
    }
}

function hnh_report_time(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    try {
        return (new DateTime($iso))->format('g:i A');
    } catch (Exception $e) {
        return $iso;
    }
}

$baseName = hnh_download_slug($net['name'] ?? 'net') . '-' . hnh_download_date($net['created_at'] ?? null);

switch ($format) {
    case 'json':
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $baseName . '.json"');
        echo json_encode($net, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;

    case 'csv':
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $baseName . '-checkins.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['#', 'Callsign', 'Name', 'City', 'State', 'Check-in', 'Check-out', 'Notes']);
        foreach (($net['checkins'] ?? []) as $c) {
            fputcsv($out, [
                $c['order'] ?? '',
                $c['callsign'] ?? '',
                hnh_checkin_display_name($c),
                $c['city'] ?? '',
                $c['state'] ?? '',
                $c['checked_in_at'] ?? '',
                $c['checked_out_at'] ?? '',
                $c['notes'] ?? '',
            ]);
        }
        fclose($out);
        exit;

    case 'report':
        $includeNotes = !empty($_GET['notes']);
        $reportFilenameSuffix = $includeNotes ? '-report-with-notes.txt' : '-report.txt';
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $baseName . $reportFilenameSuffix . '"');

        $title = $net['name'] ?: '(untitled net)';
        $lines = [$title, str_repeat('=', strlen($title))];
        $lines[] = 'Date: ' . hnh_report_date($net['created_at'] ?? null);
        $lines[] = 'Net Control: ' . ($net['net_control'] ?? '');
        if (!empty($net['frequency'])) {
            $lines[] = 'Frequency: ' . $net['frequency'];
        }
        $lines[] = 'Status: ' . ($net['status'] ?? 'open');
        $lines[] = '';

        if (trim($net['script_notes'] ?? '') !== '') {
            $lines[] = 'SCRIPT & NOTES';
            $lines[] = str_repeat('-', 14);
            $lines[] = $net['script_notes'];
            $lines[] = '';
        }

        $checkins = $net['checkins'] ?? [];
        $lines[] = 'CHECK-INS (' . count($checkins) . ')';
        $lines[] = str_repeat('-', 11);

        foreach ($checkins as $c) {
            $name = hnh_checkin_display_name($c);
            $where = trim(
                ($c['city'] ?? '') . ((!empty($c['city']) && !empty($c['state'])) ? ', ' : '') . ($c['state'] ?? '')
            );
            $inTime = hnh_report_time($c['checked_in_at'] ?? null);
            $outTime = hnh_report_time($c['checked_out_at'] ?? null);

            $row = sprintf('%2d. %-10s', $c['order'] ?? 0, $c['callsign'] ?? '');
            if ($name !== '') {
                $row .= '  ' . $name;
            }
            if ($where !== '') {
                $row .= '  (' . $where . ')';
            }
            $row .= '  [' . $inTime . ($outTime !== '' ? ' - ' . $outTime : '') . ']';
            $lines[] = $row;

            if ($includeNotes && trim($c['notes'] ?? '') !== '') {
                $lines[] = '      Notes: ' . $c['notes'];
            }
        }

        echo implode("\n", $lines) . "\n";
        exit;

    default:
        hnh_error('Unknown format. Use csv, json, or report.', 400);
}
