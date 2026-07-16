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
