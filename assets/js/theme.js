(function () {
  var KEY = 'hnh-theme';
  var root = document.documentElement;

  var saved = localStorage.getItem(KEY);
  if (saved) {
    root.setAttribute('data-theme', saved);
  }

  document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('theme-toggle');
    if (!btn) {
      return;
    }
    btn.addEventListener('click', function () {
      var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      root.setAttribute('data-theme', next);
      localStorage.setItem(KEY, next);
    });
  });
})();
