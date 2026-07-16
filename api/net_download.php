<?php
/**
 * TODO (SPEC.md §4): ?id=&format=csv|json|report
 *   - json: stream the raw net file as-is (the "JSON backup" download).
 *   - csv: check-in table only, Name column using the composed `preferred_name (name)` form.
 *   - report: plain-text summary for pasting into a follow-up email -- net header, script_notes,
 *     then the check-in list (composed name form, city/state, check-in/out times, notes).
 *
 * Not implemented yet -- scaffolding only.
 */

require __DIR__ . '/_bootstrap.php';

hnh_error('Downloads not yet implemented', 501);
