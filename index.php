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
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($config['default_theme']) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($config['app_name']) ?> — Nets</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<header class="app-header">
  <h1><?= htmlspecialchars($config['app_name']) ?></h1>
  <div class="header-actions">
    <span class="user-label">Logged in as <?= htmlspecialchars(auth_user()) ?></span>
    <button id="theme-toggle" type="button">Toggle theme</button>
    <a href="../simplewebauth/logout.php">Sign out</a>
  </div>
</header>

<main>
  <div class="toolbar">
    <button id="begin-new-net" type="button">Begin New Net</button>
  </div>

  <table id="net-list" class="data-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Date</th>
        <th>Net Control</th>
        <th>Check-ins</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <tr><td colspan="6" class="loading">Loading nets…</td></tr>
    </tbody>
  </table>
</main>

<dialog id="new-net-dialog">
  <form id="new-net-form">
    <h2>Begin New Net</h2>

    <label>Name
      <input name="name" type="text" required>
    </label>

    <label>Net type
      <select name="net_type"></select>
    </label>

    <label>Net control
      <input name="net_control" type="text">
    </label>

    <label>Frequency
      <input name="frequency" type="text" placeholder="e.g. 146.940 MHz -0.6 PL 100.0">
    </label>

    <label>Description
      <textarea name="description" rows="2"></textarea>
    </label>

    <label>HAMDAT ZIP code
      <input name="hamdat_zip" type="text" maxlength="5" inputmode="numeric">
    </label>

    <label>HAMDAT radius (miles)
      <input name="hamdat_radius" type="number" min="0">
    </label>

    <menu>
      <button type="button" id="new-net-cancel">Cancel</button>
      <button type="submit" id="new-net-submit">Create</button>
    </menu>
  </form>
</dialog>

<script>window.HNH_CONFIG_URL = 'api/config.php';</script>
<script src="assets/js/api.js"></script>
<script src="assets/js/theme.js"></script>
<script src="assets/js/net-list.js"></script>
</body>
</html>
