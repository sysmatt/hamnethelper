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

/**
 * Every timestamp this app stores is a UTC instant -- correct for storage/duration math, but
 * meaningless to a human reader without converting to a real timezone first. The browser handles
 * that automatically for on-screen display; this download runs server-side with no browser to
 * ask, so it converts explicitly to the configured `timezone` (default 'UTC', README's config
 * reference) before any formatting below. Returns null if $iso is empty/unparseable.
 */
function hnh_report_datetime(?string $iso): ?DateTime
{
    if (!$iso) {
        return null;
    }
    global $hnh_config;
    try {
        $dt = new DateTime($iso);
    } catch (Exception $e) {
        return null;
    }
    $dt->setTimezone(new DateTimeZone($hnh_config['timezone'] ?? 'UTC'));
    return $dt;
}

function hnh_download_date(?string $iso): string
{
    $dt = hnh_report_datetime($iso);
    return $dt ? $dt->format('Y-m-d') : 'undated';
}

function hnh_checkin_display_name(array $checkin): string
{
    $name = (string) ($checkin['name'] ?? '');
    $preferred = trim((string) ($checkin['preferred_name'] ?? ''));
    return $preferred !== '' ? ($preferred . ' (' . $name . ')') : $name;
}

function hnh_report_date(?string $iso): string
{
    $dt = hnh_report_datetime($iso);
    return $dt ? $dt->format('l, F j, Y') : '';
}

function hnh_report_time(?string $iso): string
{
    $dt = hnh_report_datetime($iso);
    return $dt ? $dt->format('g:i A') : '';
}

/**
 * Converts script_notes' markdown source to clean plain text for the report download -- strips
 * the syntax the Script & Notes editor's toolbar actually produces (headings, bold, italic,
 * bullet lists; links/code/strikethrough handled too since they're cheap and someone may have
 * typed them by hand) and word-wraps at $wrapWidth, which reads much better pasted into an email
 * than a raw markdown blob full of heading/emphasis/list-marker characters and un-wrapped long
 * lines.
 *
 * Deliberately a small regex-based stripper, not a full CommonMark parser -- script_notes is
 * short-form net announcements/scripts, not arbitrary complex markdown, so this doesn't need to
 * handle tables, footnotes, nested blockquotes, etc. Order matters in a few places: list-marker
 * normalization runs before inline emphasis stripping so a `*`-bulleted list item's leading `*`
 * isn't mistaken for an italic marker; longer emphasis markers (`***`/`___`) are stripped before
 * shorter ones (`**`/`__`, then `*`/`_`) so nested/combined bold+italic doesn't leave stray
 * asterisks behind.
 */
function hnh_markdown_to_plain(string $markdown, int $wrapWidth = 80): string
{
    $lines = preg_split('/\r\n|\r|\n/', $markdown);
    $out = [];

    foreach ($lines as $line) {
        // Horizontal rule (---, ***, ___ alone on a line) -- collapse to a blank line.
        if (preg_match('/^\s*([-*_])\1{2,}\s*$/', $line)) {
            $out[] = '';
            continue;
        }

        $line = preg_replace('/^#{1,6}\s+/', '', $line);           // # Heading -> Heading
        $line = preg_replace('/^>\s?/', '', $line);                 // > quote -> quote
        $line = preg_replace('/^(\s*)[-*+]\s+/', '$1- ', $line);    // -/*/+  list -> - list (normalized)

        $line = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$1 ($2)', $line); // [text](url) -> text (url)

        $line = preg_replace('/(\*\*\*|___)(.+?)\1/', '$2', $line); // ***bold italic***
        $line = preg_replace('/(\*\*|__)(.+?)\1/', '$2', $line);    // **bold**
        $line = preg_replace('/\*(.+?)\*/', '$1', $line);           // *italic*
        $line = preg_replace('/_(.+?)_/', '$1', $line);             // _italic_
        $line = preg_replace('/~~(.+?)~~/', '$1', $line);           // ~~strikethrough~~
        $line = preg_replace('/`([^`]+)`/', '$1', $line);           // `code`

        $out[] = $line;
    }

    // A horizontal rule collapsing to a blank line, next to a blank line already on either side
    // of it in the source (a common way to visually separate sections in markdown), would
    // otherwise leave a stray double-blank gap -- collapse any run of 2+ blank lines to one.
    $collapsed = [];
    foreach ($out as $line) {
        if ($line === '' && end($collapsed) === '') {
            continue;
        }
        $collapsed[] = $line;
    }

    // Word-wrap each line independently (not the whole blob) so intentional blank lines --
    // paragraph/list-item breaks -- are preserved rather than merged into one reflowed mass.
    $wrapped = array_map(
        static fn($line) => $line === '' ? '' : wordwrap($line, $wrapWidth, "\n", false),
        $collapsed
    );

    return implode("\n", $wrapped);
}

/**
 * Resolves net.official_start (a full ISO datetime -- SPEC.md §5.6) to a DateTime, mirroring
 * assets/js/net.js's computeAnchorMs(). A self-contained absolute instant needs no calendar-day
 * inference -- an earlier date-less "HH:MM" design needed to guess which day it meant from
 * opened_at, which was a real, repeated source of Duration bugs (see SPEC.md §5.6's history).
 * Returns null if unset/unparseable (Duration then falls back to elapsed-since-opened).
 */
function hnh_official_start_anchor(?string $officialStart): ?DateTime
{
    if (!$officialStart) {
        return null;
    }
    try {
        return new DateTime($officialStart);
    } catch (Exception $e) {
        return null;
    }
}

/** Signed "+/-HH:MM:SS", unbounded hours (a multi-day net can exceed 24h) -- see SPEC.md §5.6. */
function hnh_format_duration(int $signedSeconds): string
{
    $sign = $signedSeconds < 0 ? '-' : '+';
    $abs = abs($signedSeconds);
    $h = intdiv($abs, 3600);
    $m = intdiv($abs % 3600, 60);
    $s = $abs % 60;
    return sprintf('%s%02d:%02d:%02d', $sign, $h, $m, $s);
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
        if (!empty($net['official_start'])) {
            $lines[] = 'Official Start: ' . hnh_report_date($net['official_start']) . ', ' . hnh_report_time($net['official_start']);
        }
        $lines[] = 'Status: ' . ($net['status'] ?? 'open');

        // Same formula as the live Duration clock (assets/js/net.js): closed nets freeze at
        // ended_at, open nets use "now" (report generation time).
        $anchor = hnh_official_start_anchor($net['official_start'] ?? null);
        $anchor = $anchor ?? (($net['opened_at'] ?? null) ? new DateTime($net['opened_at']) : null);
        if ($anchor !== null) {
            $end = (($net['status'] ?? 'open') === 'closed' && !empty($net['ended_at']))
                ? new DateTime($net['ended_at'])
                : new DateTime();
            $lines[] = 'Duration: ' . hnh_format_duration($end->getTimestamp() - $anchor->getTimestamp());
        }

        $lines[] = '';

        if (trim($net['script_notes'] ?? '') !== '') {
            $lines[] = 'SCRIPT & NOTES';
            $lines[] = str_repeat('-', 14);
            $lines[] = hnh_markdown_to_plain($net['script_notes'], 80);
            $lines[] = '';
        }

        $checkins = $net['checkins'] ?? [];
        $lines[] = 'CHECK-INS (' . count($checkins) . ')';
        $lines[] = str_repeat('-', 11);

        if (!$checkins) {
            $lines[] = '(no check-ins)';
        } else {
            // Column widths are computed from the actual data (like hamdat's own --table output)
            // rather than fixed guesses, so "Name"/"City/State" line up cleanly across rows
            // regardless of how long any particular callsign/name/city happens to be -- a fixed
            // width would either truncate long values or leave everything after them misaligned.
            $columns = ['num' => '#', 'callsign' => 'Callsign', 'name' => 'Name', 'where' => 'City/State', 'in' => 'Check-in', 'out' => 'Check-out'];

            $rows = [];
            foreach ($checkins as $c) {
                $rows[] = [
                    'num' => (string) ($c['order'] ?? ''),
                    'callsign' => (string) ($c['callsign'] ?? ''),
                    'name' => hnh_checkin_display_name($c),
                    'where' => trim(
                        ($c['city'] ?? '') . ((!empty($c['city']) && !empty($c['state'])) ? ', ' : '') . ($c['state'] ?? '')
                    ),
                    'in' => hnh_report_time($c['checked_in_at'] ?? null),
                    'out' => hnh_report_time($c['checked_out_at'] ?? null),
                    'notes' => (string) ($c['notes'] ?? ''),
                ];
            }

            $widths = [];
            foreach ($columns as $key => $label) {
                $widths[$key] = strlen($label);
                foreach ($rows as $r) {
                    $widths[$key] = max($widths[$key], strlen($r[$key]));
                }
            }

            $formatRow = function (array $cells) use ($widths, $columns): string {
                $parts = [];
                foreach ($columns as $key => $label) {
                    $pad = $key === 'num' ? STR_PAD_LEFT : STR_PAD_RIGHT;
                    $parts[] = str_pad((string) $cells[$key], $widths[$key], ' ', $pad);
                }
                return rtrim(implode('  ', $parts));
            };

            $lines[] = $formatRow($columns);
            $lines[] = implode('  ', array_map(static fn($w) => str_repeat('-', $w), $widths));

            foreach ($rows as $r) {
                $lines[] = $formatRow($r);
                if ($includeNotes && trim($r['notes']) !== '') {
                    $lines[] = '    Notes: ' . $r['notes'];
                }
            }
        }

        echo implode("\n", $lines) . "\n";
        exit;

    default:
        hnh_error('Unknown format. Use csv, json, or report.', 400);
}
