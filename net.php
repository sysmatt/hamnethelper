<?php
require __DIR__ . '/../simplewebauth/auth.php';
require __DIR__ . '/lib/config.php';

try {
    $config = hnh_config();
} catch (RuntimeException $e) {
    http_response_code(500);
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
}

$netId = $_GET['id'] ?? '';
if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $netId)) {
    http_response_code(400);
    echo 'Invalid net id.';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($config['default_theme']) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($config['app_name']) ?> — Net</title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/vendor/vditor/vditor.min.css">
</head>
<body data-net-id="<?= htmlspecialchars($netId) ?>">

<header class="app-header">
  <div class="header-left">
    <a class="back-link" href="index.php">&larr; Net List</a>
    <h1 id="net-name">Loading&hellip;</h1>
  </div>

  <div id="net-clocks" class="net-clocks">
    <div id="clock-start" class="clock">
      <span class="clock-label-row">
        <span class="clock-label">Start</span>
        <button type="button" id="edit-official-start-btn" class="clock-edit-btn icon-btn"></button>
      </span>
      <span class="clock-value-row">
        <span id="clock-start-value" class="clock-value"></span>
        <input type="text" id="clock-start-input" class="clock-edit-input" inputmode="numeric" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="HH:MM" maxlength="5" hidden>
      </span>
      <span class="clock-date"></span>
    </div>

    <div class="clock">
      <span class="clock-label-row">
        <span class="clock-label">Open</span>
        <button type="button" id="reset-opened-btn" class="clock-edit-btn icon-btn" title="Reset Opened time to now" aria-label="Reset Opened time to now">&#8635;</button>
      </span>
      <span class="clock-value-row">
        <span id="clock-open-value" class="clock-value"></span>
      </span>
      <span id="clock-open-date" class="clock-date"></span>
    </div>

    <div class="clock">
      <span class="clock-label-row">
        <span class="clock-label">Duration</span>
      </span>
      <span class="clock-value-row">
        <span id="clock-duration-value" class="clock-value clock-mono"></span>
      </span>
      <span class="clock-date"></span>
    </div>

    <div id="clock-closed" class="clock" hidden>
      <span class="clock-label-row">
        <span class="clock-label">Closed</span>
      </span>
      <span class="clock-value-row">
        <span id="clock-closed-value" class="clock-value"></span>
      </span>
      <span id="clock-closed-date" class="clock-date"></span>
    </div>
  </div>

  <div class="header-actions">
    <span id="save-indicator" class="save-indicator">&mdash;</span>
    <button id="close-reopen-btn" type="button">Close Net</button>
    <button id="theme-toggle" type="button">Toggle theme</button>
  </div>
</header>

<main>
  <div class="workspace">
    <section class="script-notes-panel">
      <h2>Script &amp; Notes</h2>
      <textarea id="script-notes" placeholder="Welcome script, announcements, running notes&hellip;"></textarea>
      <div id="vditor-script-notes"></div>
    </section>

    <section class="checkin-panel">
      <div class="lookup-bar">
        <div class="lookup-wrap">
          <input id="lookup-box" type="text" placeholder="Callsign or name&hellip; (press / to focus)" autocomplete="off">
          <ul id="lookup-suggestions" class="suggestions" hidden></ul>
        </div>
        <button id="upload-roster-btn" type="button">Upload participant list</button>
        <input id="roster-file-input" type="file" accept=".txt" hidden>
        <button id="hamdat-settings-btn" type="button">HAMDAT Lookup settings</button>
      </div>

      <div class="table-scroll">
        <table id="checkin-table" class="data-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Callsign</th>
              <th>Name</th>
              <th>City</th>
              <th>State</th>
              <th>Check-in</th>
              <th>Check-out</th>
              <th class="notes-col">Notes</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
      <p id="lookup-status" class="lookup-status"></p>
    </section>
  </div>
</main>

<dialog id="hamdat-dialog">
  <form id="hamdat-form">
    <h2>HAMDAT Lookup Settings</h2>
    <label>ZIP code
      <input id="hamdat-zip" type="text" maxlength="5" inputmode="numeric">
    </label>
    <label>Radius (miles)
      <input id="hamdat-radius" type="number" min="0">
    </label>
    <p id="hamdat-last-refreshed" class="muted"></p>
    <menu>
      <button type="button" id="hamdat-close">Close</button>
      <button type="button" id="hamdat-load">Load / Refresh</button>
    </menu>
  </form>
</dialog>

<script>window.HNH_CONFIG_URL = 'api/config.php';</script>
<script src="assets/vendor/sortable.min.js"></script>
<script src="assets/vendor/vditor/vditor.min.js"></script>
<script src="assets/js/api.js"></script>
<script src="assets/js/theme.js"></script>
<script src="assets/js/net.js"></script>
</body>
</html>
