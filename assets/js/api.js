window.HNH = window.HNH || {};

/**
 * Thin fetch() wrapper for every api/*.php call: adds JSON headers, surfaces server-provided
 * error messages, and treats a 401 (session expired -- see api/_bootstrap.php) as a hard stop
 * rather than something callers need to check for individually.
 */
HNH.api = async function (url, options) {
  options = options || {};
  options.headers = Object.assign({ 'Content-Type': 'application/json' }, options.headers || {});

  const res = await fetch(url, options);

  if (res.status === 401) {
    alert('Your session has expired. Reloading to log in again.');
    window.location.reload();
    throw new Error('unauthenticated');
  }

  const data = await res.json().catch(() => ({}));

  if (!res.ok) {
    throw new Error(data.error || ('Request failed: ' + res.status));
  }

  return data;
};

/**
 * Runs an async fn() but guarantees at least minMs elapses before this resolves/rejects, so a
 * loading indicator tied to it (button disabled/relabeled, etc.) doesn't flash imperceptibly on
 * a fast request -- e.g. net creation with no ZIP filled in, or a hamdat call that fails fast
 * against a missing/misconfigured database. Only ever adds delay, never removes it; a genuinely
 * slow request is unaffected.
 */
HNH.withMinDuration = async function (fn, minMs) {
  const start = Date.now();
  try {
    const result = await fn();
    await HNH._delayRemaining(start, minMs);
    return result;
  } catch (err) {
    await HNH._delayRemaining(start, minMs);
    throw err;
  }
};

HNH._delayRemaining = function (start, minMs) {
  const elapsed = Date.now() - start;
  return elapsed < minMs ? new Promise((resolve) => setTimeout(resolve, minMs - elapsed)) : null;
};

HNH.getConfig = function () {
  if (!HNH._configPromise) {
    HNH._configPromise = HNH.api(window.HNH_CONFIG_URL);
  }
  return HNH._configPromise;
};

HNH.escapeHtml = function (s) {
  return String(s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
};

// 24-hour, no seconds, everywhere -- shared so every time/date display in the app is consistent.
HNH.formatTime = function (iso) {
  if (!iso) {
    return '';
  }
  var d = new Date(iso);
  if (isNaN(d)) {
    return iso;
  }
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
};

// "YYYY-MM-DD" in LOCAL time (not UTC -- toISOString().slice(0,10) would be off by one near
// midnight in some timezones) -- the value format <input type="date"> expects/produces.
HNH.formatDateForInput = function (input) {
  var d = input instanceof Date ? input : new Date(input);
  if (isNaN(d)) {
    return '';
  }
  var mm = String(d.getMonth() + 1).padStart(2, '0');
  var dd = String(d.getDate()).padStart(2, '0');
  return d.getFullYear() + '-' + mm + '-' + dd;
};

HNH.todayDateInputValue = function () {
  return HNH.formatDateForInput(new Date());
};

// Combines a <input type="date"> value ("YYYY-MM-DD") and a plain-text "HH:MM" time value into
// a single ISO datetime string (official_start's storage format, SPEC.md §5.6) -- interpreted as
// LOCAL time (the operator's own browser-local sense of "7pm"), same as everything else this app
// treats as wall-clock time. Returns null if either half is missing/invalid, so an incomplete
// edit never silently stores a garbage or partial value.
HNH.combineDateAndTime = function (dateStr, timeStr) {
  if (!dateStr || !/^([01][0-9]|2[0-3]):[0-5][0-9]$/.test(timeStr || '')) {
    return null;
  }
  var dateParts = dateStr.split('-').map(Number);
  var timeParts = timeStr.split(':').map(Number);
  if (dateParts.length !== 3 || timeParts.length !== 2) {
    return null;
  }
  var d = new Date(dateParts[0], dateParts[1] - 1, dateParts[2], timeParts[0], timeParts[1], 0, 0);
  return isNaN(d.getTime()) ? null : d.toISOString();
};

// Date only, no time -- used under the net-clocks ribbon's Opened/Closed values (small text).
HNH.formatDateOnly = function (iso) {
  if (!iso) {
    return '';
  }
  var d = new Date(iso);
  if (isNaN(d)) {
    return '';
  }
  return d.toLocaleDateString([], { year: 'numeric', month: 'numeric', day: 'numeric' });
};

HNH.formatDateTime = function (iso) {
  if (!iso) {
    return '';
  }
  var d = new Date(iso);
  if (isNaN(d)) {
    return iso;
  }
  return d.toLocaleString([], {
    year: 'numeric',
    month: 'numeric',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  });
};
