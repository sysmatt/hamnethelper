<?php
/**
 * TODO (SPEC.md §2, §3.3): exec() the hamdat binary with --zip/--radius-miles/--json, every
 * argument through escapeshellarg(), same pattern as hamdatweb/index.php. Returns
 * [{callsign, name, city, state}], which the caller writes into the calling net's
 * hamdat_lookup.cached_results + last_refreshed_at via net_save.php.
 *
 * Not implemented yet -- scaffolding only.
 */

require __DIR__ . '/_bootstrap.php';

hnh_error('hamdat lookup not yet implemented', 501);
