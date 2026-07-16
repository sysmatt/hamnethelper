document.addEventListener('DOMContentLoaded', async function () {
  var netId = document.body.dataset.netId;

  var nameEl = document.getElementById('net-name');
  var saveIndicator = document.getElementById('save-indicator');
  var closeReopenBtn = document.getElementById('close-reopen-btn');
  var scriptNotesEl = document.getElementById('script-notes');
  var checkinTbody = document.querySelector('#checkin-table tbody');
  var lookupBox = document.getElementById('lookup-box');
  var uploadBtn = document.getElementById('upload-roster-btn');
  var uploadInput = document.getElementById('roster-file-input');

  var hamdatBtn = document.getElementById('hamdat-settings-btn');
  var hamdatDialog = document.getElementById('hamdat-dialog');
  var hamdatZip = document.getElementById('hamdat-zip');
  var hamdatRadius = document.getElementById('hamdat-radius');
  var hamdatLastRefreshed = document.getElementById('hamdat-last-refreshed');
  var hamdatLoadBtn = document.getElementById('hamdat-load');
  var hamdatCloseBtn = document.getElementById('hamdat-close');

  var net = null;
  var mde = null;
  var sortable = null;
  var saveTimer = null;
  var saveDebounceMs = 800;
  var saveInFlight = false;
  var dirtyWhileSaving = false;

  // --- Autosave (SPEC.md §2) -----------------------------------------------------------------

  function setIndicator(state) {
    var labels = { saved: 'Saved', saving: 'Saving…', failed: 'Save failed — click to retry' };
    saveIndicator.textContent = labels[state];
    saveIndicator.className = 'save-indicator ' + state;
  }

  function scheduleSave() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(doSave, saveDebounceMs);
  }

  async function doSave() {
    if (saveInFlight) {
      dirtyWhileSaving = true;
      return;
    }
    saveInFlight = true;
    setIndicator('saving');
    try {
      net = await HNH.api('api/net_save.php', { method: 'POST', body: JSON.stringify(net) });
      setIndicator('saved');
    } catch (err) {
      setIndicator('failed');
    } finally {
      saveInFlight = false;
      if (dirtyWhileSaving) {
        dirtyWhileSaving = false;
        scheduleSave();
      }
    }
  }

  saveIndicator.addEventListener('click', function () {
    if (saveIndicator.classList.contains('failed')) {
      doSave();
    }
  });

  window.addEventListener('beforeunload', function (e) {
    if (saveIndicator.classList.contains('saving') || saveIndicator.classList.contains('failed')) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  // --- Rendering -------------------------------------------------------------------------

  function render() {
    nameEl.textContent = net.name || '(untitled net)';
    closeReopenBtn.textContent = net.status === 'closed' ? 'Re-open Net' : 'Close Net';
    document.body.classList.toggle('net-closed', net.status === 'closed');

    if (mde) {
      mde.value(net.script_notes || '');
    } else {
      scriptNotesEl.value = net.script_notes || '';
    }

    hamdatZip.value = (net.hamdat_lookup && net.hamdat_lookup.zip) || '';
    hamdatRadius.value = (net.hamdat_lookup && net.hamdat_lookup.radius_miles) || '';
    renderHamdatRefreshedLabel();

    renderCheckins();
  }

  function renderHamdatRefreshedLabel() {
    var ts = net.hamdat_lookup && net.hamdat_lookup.last_refreshed_at;
    hamdatLastRefreshed.textContent = ts
      ? 'Last refreshed: ' + new Date(ts).toLocaleString()
      : 'Not loaded yet.';
  }

  function renderCheckins() {
    checkinTbody.innerHTML = '';
    (net.checkins || []).forEach(function (c, idx) {
      var tr = document.createElement('tr');
      tr.dataset.index = String(idx);
      if (c.checked_out_at) {
        tr.classList.add('checked-out');
      }

      tr.innerHTML =
        '<td class="drag-handle">' + (idx + 1) + '</td>' +
        '<td>' + HNH.escapeHtml(c.callsign) + '</td>' +
        '<td class="name-cell"></td>' +
        '<td>' + HNH.escapeHtml(c.city || '') + '</td>' +
        '<td>' + HNH.escapeHtml(c.state || '') + '</td>' +
        '<td>' + formatTime(c.checked_in_at) + '</td>' +
        '<td>' + formatTime(c.checked_out_at) + '</td>' +
        '<td class="notes-cell"></td>' +
        '<td class="actions"></td>';

      renderNameCell(tr.querySelector('.name-cell'), c);

      var notesInput = document.createElement('input');
      notesInput.type = 'text';
      notesInput.value = c.notes || '';
      notesInput.addEventListener('input', function () {
        c.notes = notesInput.value;
        scheduleSave();
      });
      tr.querySelector('.notes-cell').appendChild(notesInput);

      var actions = tr.querySelector('.actions');

      var toggleBtn = document.createElement('button');
      toggleBtn.type = 'button';
      toggleBtn.textContent = c.checked_out_at ? 'Un-73' : '73';
      toggleBtn.addEventListener('click', function () {
        c.checked_out_at = c.checked_out_at ? null : new Date().toISOString();
        renderCheckins();
        scheduleSave();
      });
      actions.appendChild(toggleBtn);

      var delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'danger';
      delBtn.textContent = 'Delete';
      delBtn.addEventListener('click', function () {
        if (!confirm('Remove ' + c.callsign + ' from the check-in list?')) {
          return;
        }
        net.checkins.splice(idx, 1);
        renumber();
        renderCheckins();
        scheduleSave();
      });
      actions.appendChild(delBtn);

      checkinTbody.appendChild(tr);
    });

    initSortable();
  }

  // preferred_name / name display + pencil edit (SPEC.md §5.2). callsign itself is never
  // inline-editable in v1 -- delete and re-add through the lookup box instead.
  function renderNameCell(cell, checkin) {
    cell.innerHTML = '';

    var display = document.createElement('span');
    display.textContent = checkin.preferred_name
      ? checkin.preferred_name + ' (' + (checkin.name || '') + ')'
      : (checkin.name || '');
    cell.appendChild(display);

    var editBtn = document.createElement('button');
    editBtn.type = 'button';
    editBtn.className = 'icon-btn';
    editBtn.textContent = '✏️';
    editBtn.title = 'Edit preferred name';
    editBtn.addEventListener('click', function () {
      cell.innerHTML = '';
      var input = document.createElement('input');
      input.type = 'text';
      input.value = checkin.preferred_name || '';
      input.placeholder = 'Preferred name';
      cell.appendChild(input);
      input.focus();

      function commit() {
        checkin.preferred_name = input.value.trim() || null;
        renderNameCell(cell, checkin);
        scheduleSave();
      }
      input.addEventListener('blur', commit);
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          input.blur();
        }
      });
    });
    cell.appendChild(editBtn);
  }

  function renumber() {
    (net.checkins || []).forEach(function (c, i) {
      c.order = i + 1;
    });
  }

  function initSortable() {
    if (sortable) {
      sortable.destroy();
    }
    if (typeof Sortable === 'undefined') {
      return; // vendor asset missing -- see assets/vendor/VERSIONS.md
    }
    sortable = Sortable.create(checkinTbody, {
      handle: '.drag-handle',
      animation: 150,
      onEnd: function () {
        var newOrder = Array.prototype.map.call(checkinTbody.children, function (tr) {
          return net.checkins[Number(tr.dataset.index)];
        });
        net.checkins = newOrder;
        renumber();
        renderCheckins();
        scheduleSave();
      },
    });
  }

  // --- Script & Notes (SPEC.md §5.3) ------------------------------------------------------

  function initScriptNotesEditor() {
    if (typeof EasyMDE === 'undefined') {
      // Vendor asset missing -- fall back to the plain textarea already in the page.
      scriptNotesEl.addEventListener('input', function () {
        net.script_notes = scriptNotesEl.value;
        scheduleSave();
      });
      return;
    }
    mde = new EasyMDE({ element: scriptNotesEl, spellChecker: false, status: false });
    mde.codemirror.on('change', function () {
      net.script_notes = mde.value();
      scheduleSave();
    });
  }

  // --- Close / Reopen (SPEC.md §5.5) ------------------------------------------------------

  closeReopenBtn.addEventListener('click', function () {
    if (net.status === 'closed') {
      net.status = 'open';
      net.ended_at = null;
    } else {
      net.status = 'closed';
      net.ended_at = new Date().toISOString();
    }
    render();
    scheduleSave();
  });

  // --- Callsign/name lookup box (SPEC.md §5.1) --------------------------------------------
  // TODO: live-filtering dropdown against roster (level-1) + hamdat cache (level-2) as the
  // operator types. For now, Enter adds a row immediately, matching only against the hamdat
  // cache (roster carries no name data -- see SPEC.md §3.1).

  lookupBox.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') {
      return;
    }
    var callsign = lookupBox.value.trim().toUpperCase();
    if (!callsign) {
      return;
    }

    var match = ((net.hamdat_lookup && net.hamdat_lookup.cached_results) || [])
      .find(function (r) { return (r.callsign || '').toUpperCase() === callsign; });

    net.checkins = net.checkins || [];
    net.checkins.push({
      order: net.checkins.length + 1,
      callsign: callsign,
      name: match ? match.name : '',
      preferred_name: null,
      city: match ? match.city : '',
      state: match ? match.state : '',
      checked_in_at: new Date().toISOString(),
      checked_out_at: null,
      notes: '',
    });

    lookupBox.value = '';
    renderCheckins();
    scheduleSave();
  });

  // --- Upload participant list (SPEC.md §5.1) ----------------------------------------------
  // Replaces net.roster outright. Roster is callsigns only (no name data) -- a first-pass
  // shortlist, not a restriction; see SPEC.md §3.1.

  uploadBtn.addEventListener('click', function () {
    uploadInput.click();
  });

  uploadInput.addEventListener('change', function () {
    var file = uploadInput.files[0];
    if (!file) {
      return;
    }
    var reader = new FileReader();
    reader.onload = function () {
      net.roster = String(reader.result)
        .split(/\r?\n/)
        .map(function (line) { return line.trim().toUpperCase(); })
        .filter(function (line) { return line.length > 0; });
      scheduleSave();
      alert('Loaded ' + net.roster.length + ' callsigns into the roster.');
    };
    reader.readAsText(file);
    uploadInput.value = '';
  });

  // --- HAMDAT Lookup Settings dialog (SPEC.md §3.3, §5.1) ----------------------------------

  hamdatBtn.addEventListener('click', function () {
    hamdatDialog.showModal();
  });
  hamdatCloseBtn.addEventListener('click', function () {
    hamdatDialog.close();
  });

  hamdatLoadBtn.addEventListener('click', async function () {
    net.hamdat_lookup = net.hamdat_lookup || {};
    net.hamdat_lookup.zip = hamdatZip.value.trim();
    net.hamdat_lookup.radius_miles = Number(hamdatRadius.value) || 0;

    try {
      var result = await HNH.api('api/hamdat_lookup.php', {
        method: 'POST',
        body: JSON.stringify({ zip: net.hamdat_lookup.zip, radius_miles: net.hamdat_lookup.radius_miles }),
      });
      net.hamdat_lookup.cached_results = result.results || [];
      net.hamdat_lookup.last_refreshed_at = new Date().toISOString();
      renderHamdatRefreshedLabel();
    } catch (err) {
      // Expected for now -- api/hamdat_lookup.php is a 501 stub pending the hamdat CLI
      // integration (SPEC.md §2, §3.3).
      alert('HAMDAT lookup: ' + err.message);
    }

    scheduleSave();
  });

  // --- Boot ------------------------------------------------------------------------------

  function formatTime(iso) {
    if (!iso) {
      return '';
    }
    var d = new Date(iso);
    return isNaN(d) ? iso : d.toLocaleTimeString();
  }

  try {
    var config = await HNH.getConfig();
    saveDebounceMs = config.autosave_debounce_ms || 800;
    net = await HNH.api('api/net_get.php?id=' + encodeURIComponent(netId));
    initScriptNotesEditor();
    setIndicator('saved');
    render();
  } catch (err) {
    nameEl.textContent = 'Failed to load net';
    console.error(err);
  }
});
