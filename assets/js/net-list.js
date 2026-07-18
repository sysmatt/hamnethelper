document.addEventListener('DOMContentLoaded', async function () {
  var tbody = document.querySelector('#net-list tbody');
  var beginBtn = document.getElementById('begin-new-net');
  var importBtn = document.getElementById('import-net-btn');
  var importInput = document.getElementById('import-net-input');
  var dialog = document.getElementById('new-net-dialog');
  var form = document.getElementById('new-net-form');
  var cancelBtn = document.getElementById('new-net-cancel');
  var netTypeSelect = form.elements.net_type;

  var config = await HNH.getConfig().catch(function () {
    return { net_types: [] };
  });

  (config.net_types || []).forEach(function (t) {
    var opt = document.createElement('option');
    opt.value = t.value;
    opt.textContent = t.label;
    netTypeSelect.appendChild(opt);
  });

  function openBlankForm() {
    form.reset();
    delete form.dataset.carryRoster;
    delete form.dataset.carryScriptNotes;
    dialog.showModal();
  }

  function openPrefilledForm(net) {
    form.reset();
    form.elements.name.value = net.name || '';
    form.elements.net_type.value = net.net_type || '';
    form.elements.net_control.value = net.net_control || '';
    form.elements.official_start.value = net.official_start || '';
    form.elements.frequency.value = net.frequency || '';
    form.elements.description.value = net.description || '';
    form.elements.hamdat_zip.value = (net.hamdat_lookup && net.hamdat_lookup.zip) || '';
    form.elements.hamdat_radius.value = (net.hamdat_lookup && net.hamdat_lookup.radius_miles) || '';
    form.dataset.carryRoster = JSON.stringify(net.roster || []);
    form.dataset.carryScriptNotes = net.script_notes || '';
    dialog.showModal();
  }

  beginBtn.addEventListener('click', openBlankForm);
  cancelBtn.addEventListener('click', function () {
    dialog.close();
  });

  // --- Import a previously-downloaded net JSON backup (SPEC.md §4) -----------------------
  // Always becomes a brand-new net (fresh id/created_at) regardless of the uploaded file's own
  // values -- see api/net_import.php / lib/net_store.php's hnh_import_net().

  importBtn.addEventListener('click', function () {
    importInput.click();
  });

  importInput.addEventListener('change', function () {
    var file = importInput.files[0];
    if (!file) {
      return;
    }

    var originalLabel = importBtn.textContent;
    importBtn.disabled = true;
    importBtn.textContent = 'Importing…';

    function reset() {
      importBtn.disabled = false;
      importBtn.textContent = originalLabel;
      importInput.value = '';
    }

    var reader = new FileReader();
    reader.onload = async function () {
      var parsed;
      try {
        parsed = JSON.parse(String(reader.result));
      } catch (e) {
        alert('That file is not valid JSON.');
        reset();
        return;
      }

      let net;
      try {
        net = await HNH.withMinDuration(function () {
          return HNH.api('api/net_import.php', {
            method: 'POST',
            body: JSON.stringify(parsed),
          });
        }, 500);
      } catch (err) {
        alert('Could not import net: ' + err.message);
        reset();
        return;
      }

      window.location.href = 'net.php?id=' + encodeURIComponent(net.id);
    };
    reader.readAsText(file);
  });

  var submitBtn = document.getElementById('new-net-submit');

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    var fd = new FormData(form);
    var zip = fd.get('hamdat_zip');

    var payload = {
      name: fd.get('name'),
      net_type: fd.get('net_type'),
      net_control: fd.get('net_control'),
      official_start: fd.get('official_start') || null,
      frequency: fd.get('frequency'),
      description: fd.get('description'),
      hamdat_lookup: {
        zip: zip,
        radius_miles: Number(fd.get('hamdat_radius')) || 0,
      },
    };

    if (form.dataset.carryRoster) {
      payload.roster = JSON.parse(form.dataset.carryRoster);
    }
    if (form.dataset.carryScriptNotes) {
      payload.script_notes = form.dataset.carryScriptNotes;
    }

    // A filled-in ZIP means net_create.php runs the hamdat lookup synchronously as part of
    // creation (see api/net_create.php) -- that can take a few seconds, so say so rather than
    // leaving the operator staring at an unresponsive dialog.
    var originalLabel = submitBtn.textContent;
    submitBtn.disabled = true;
    cancelBtn.disabled = true;
    submitBtn.textContent = /^\d{5}$/.test(zip) ? 'Creating & looking up HAMDAT…' : 'Creating…';

    let net;
    try {
      // Minimum visible duration so the "Creating…" state never flashes by unnoticed on a fast
      // request (e.g. no ZIP filled in, or hamdat failing fast against a missing database) --
      // without this, "no indication anything happened" is exactly what a quick create looks like.
      net = await HNH.withMinDuration(function () {
        return HNH.api('api/net_create.php', {
          method: 'POST',
          body: JSON.stringify(payload),
        });
      }, 500);
    } catch (err) {
      alert('Could not create net: ' + err.message);
      submitBtn.disabled = false;
      cancelBtn.disabled = false;
      submitBtn.textContent = originalLabel;
      return;
    }

    if (net.hamdat_lookup_error) {
      alert(
        'Net created, but the HAMDAT lookup failed: ' + net.hamdat_lookup_error +
        '\n\nYou can retry it from HAMDAT Lookup Settings once the net is open.'
      );
    }

    window.location.href = 'net.php?id=' + encodeURIComponent(net.id);
  });

  // Click anywhere in a row that isn't itself an interactive element (link/button/the download
  // menu's summary) opens that net -- attached once, via delegation, so it survives tbody being
  // rewritten on every loadNets() call. The Name cell is a real <a> (see below), so normal
  // left-clicks there navigate on their own and this handler just no-ops for that case (checked
  // via closest('a, button, summary')) -- native ctrl/middle-click-to-new-tab still works on the
  // Name link specifically, since the browser handles that before/instead of our click handler.
  tbody.addEventListener('click', function (e) {
    if (e.target.closest('a, button, summary')) {
      return;
    }
    var tr = e.target.closest('tr[data-net-id]');
    if (!tr) {
      return;
    }
    window.location.href = 'net.php?id=' + encodeURIComponent(tr.dataset.netId);
  });

  // Only one download menu open at a time, and closed by clicking anywhere else.
  document.addEventListener('click', function (e) {
    document.querySelectorAll('.download-menu[open]').forEach(function (d) {
      if (!d.contains(e.target)) {
        d.removeAttribute('open');
      }
    });
  });

  function makeIconButton(icon, label, extraClass) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'icon-action-btn' + (extraClass ? ' ' + extraClass : '');
    btn.textContent = icon;
    btn.title = label;
    btn.setAttribute('aria-label', label);
    return btn;
  }

  async function loadNets() {
    tbody.innerHTML = '<tr><td colspan="6" class="loading">Loading nets…</td></tr>';

    let nets;
    try {
      var data = await HNH.api('api/nets_list.php');
      nets = data.nets;
    } catch (err) {
      tbody.innerHTML = '<tr><td colspan="6" class="error">Failed to load nets: ' + HNH.escapeHtml(err.message) + '</td></tr>';
      return;
    }

    if (!nets.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="empty">No nets yet — click "Begin New Net" to start one.</td></tr>';
      return;
    }

    tbody.innerHTML = '';
    nets.forEach(function (net) {
      var netUrl = 'net.php?id=' + encodeURIComponent(net.id);

      var tr = document.createElement('tr');
      tr.dataset.netId = net.id;

      var nameTd = document.createElement('td');
      var nameLink = document.createElement('a');
      nameLink.className = 'row-link';
      nameLink.href = netUrl;
      nameLink.textContent = net.name || '(untitled)';
      nameTd.appendChild(nameLink);
      tr.appendChild(nameTd);

      var dateTd = document.createElement('td');
      dateTd.textContent = HNH.formatDateTime(net.created_at);
      tr.appendChild(dateTd);

      var ncTd = document.createElement('td');
      ncTd.textContent = net.net_control || '';
      tr.appendChild(ncTd);

      var ciTd = document.createElement('td');
      ciTd.textContent = net.checkin_count;
      tr.appendChild(ciTd);

      var statusTd = document.createElement('td');
      statusTd.textContent = net.status;
      tr.appendChild(statusTd);

      var actionsTd = document.createElement('td');
      actionsTd.className = 'actions';

      var dlMenu = document.createElement('details');
      dlMenu.className = 'download-menu';
      var dlSummary = document.createElement('summary');
      dlSummary.className = 'icon-action-btn';
      dlSummary.textContent = '⬇';
      dlSummary.title = 'Download…';
      dlMenu.appendChild(dlSummary);

      var dlList = document.createElement('ul');
      [
        { format: 'csv', label: 'CSV' },
        { format: 'report', label: 'Report' },
        { format: 'report', label: 'Report w/ Notes', notes: true },
        { format: 'json', label: 'JSON backup' },
      ].forEach(function (dl) {
        var li = document.createElement('li');
        var link = document.createElement('a');
        link.href = 'api/net_download.php?id=' + encodeURIComponent(net.id) + '&format=' + dl.format +
          (dl.notes ? '&notes=1' : '');
        link.textContent = dl.label;
        li.appendChild(link);
        dlList.appendChild(li);
      });
      dlMenu.appendChild(dlList);
      actionsTd.appendChild(dlMenu);

      var startLikeBtn = makeIconButton('🔁', 'Start new net like this one');
      startLikeBtn.addEventListener('click', async function () {
        let full;
        try {
          full = await HNH.api('api/net_get.php?id=' + encodeURIComponent(net.id));
        } catch (err) {
          alert('Could not load net: ' + err.message);
          return;
        }
        openPrefilledForm(full);
      });
      actionsTd.appendChild(startLikeBtn);

      var delBtn = makeIconButton('🗑', 'Delete "' + (net.name || 'untitled') + '"', 'danger');
      delBtn.addEventListener('click', async function () {
        if (!confirm('Delete "' + (net.name || '(untitled)') + '"? This cannot be undone.')) {
          return;
        }
        try {
          await HNH.api('api/net_delete.php', {
            method: 'POST',
            body: JSON.stringify({ id: net.id }),
          });
        } catch (err) {
          alert('Could not delete net: ' + err.message);
          return;
        }
        loadNets();
      });
      actionsTd.appendChild(delBtn);

      tr.appendChild(actionsTd);
      tbody.appendChild(tr);
    });
  }

  loadNets();
});
