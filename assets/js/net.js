document.addEventListener('DOMContentLoaded', async function () {
  var netId = document.body.dataset.netId;

  var nameEl = document.getElementById('net-name');
  var saveIndicator = document.getElementById('save-indicator');
  var closeReopenBtn = document.getElementById('close-reopen-btn');
  var scriptNotesEl = document.getElementById('script-notes');
  var checkinTbody = document.querySelector('#checkin-table tbody');
  var lookupBox = document.getElementById('lookup-box');
  var suggestionsEl = document.getElementById('lookup-suggestions');
  var lookupStatusEl = document.getElementById('lookup-status');
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
  var suggestionLimit = 15;
  var saveInFlight = false;
  var dirtyWhileSaving = false;
  var candidates = []; // merged roster (level-1) + hamdat cache (level-2), rebuilt on data change
  var suggestions = []; // current filtered/ranked matches for the lookup box
  var activeIndex = -1;
  // "Who's next" cursor -- pure client-side UI state, never saved to the net JSON. Tracked by
  // object reference rather than row index: index shifts under delete/reorder, a reference to
  // the actual checkin object doesn't, so the highlight survives those without extra bookkeeping.
  var currentCheckin = null;

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
      // Patch only the server-computed field back in -- never wholesale-replace `net` with the
      // response. The request body is a snapshot of `net` at the moment fetch() was called, but
      // `net` itself keeps getting mutated in place by further clicks/edits while this request is
      // in flight (that's the whole point of it being the same object reference). Reassigning
      // `net = <response>` here would silently discard any of those in-flight mutations the
      // instant this promise resolves -- which is exactly what caused check-ins to "un-73"
      // themselves when another row was touched while a save was still in progress.
      var response = await HNH.api('api/net_save.php', { method: 'POST', body: JSON.stringify(net) });
      net.updated_at = response.updated_at;
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
      ? 'Last refreshed: ' + HNH.formatDateTime(ts)
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
        '<td><span class="name-cell"></span></td>' +
        '<td>' + HNH.escapeHtml(c.city || '') + '</td>' +
        '<td>' + HNH.escapeHtml(c.state || '') + '</td>' +
        '<td>' + HNH.formatTime(c.checked_in_at) + '</td>' +
        '<td>' + HNH.formatTime(c.checked_out_at) + '</td>' +
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

      var qrzLink = document.createElement('a');
      qrzLink.className = 'icon-action-btn';
      qrzLink.href = 'https://www.qrz.com/db/' + encodeURIComponent(c.callsign);
      qrzLink.target = '_blank';
      qrzLink.rel = 'noopener noreferrer';
      qrzLink.textContent = '🌐';
      qrzLink.title = 'Open ' + c.callsign + ' on QRZ.com in a new tab';
      qrzLink.setAttribute('aria-label', 'Open ' + c.callsign + ' on QRZ.com');
      actions.appendChild(qrzLink);

      // "73" stays as text, not a picture-icon -- it's the actual ham-radio nomenclature for
      // "signing off," instantly recognizable to the audience in a way an invented glyph
      // wouldn't be. Styled to match the icon buttons' height/color/radius, just auto-width
      // instead of a fixed square so "Un-73" isn't cramped.
      var toggleBtn = document.createElement('button');
      toggleBtn.type = 'button';
      toggleBtn.className = 'toggle-73-btn';
      toggleBtn.textContent = c.checked_out_at ? 'Un-73' : '73';
      toggleBtn.addEventListener('click', function () {
        c.checked_out_at = c.checked_out_at ? null : new Date().toISOString();
        renderCheckins();
        scheduleSave();
      });
      actions.appendChild(toggleBtn);

      var delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'icon-action-btn danger';
      delBtn.textContent = '🗑';
      delBtn.title = 'Remove ' + c.callsign + ' from the check-in list';
      delBtn.setAttribute('aria-label', 'Delete ' + c.callsign);
      delBtn.addEventListener('click', function () {
        if (!confirm('Remove ' + c.callsign + ' from the check-in list?')) {
          return;
        }
        if (net.checkins[idx] === currentCheckin) {
          currentCheckin = null;
        }
        net.checkins.splice(idx, 1);
        renumber();
        renderCheckins();
        scheduleSave();
      });
      actions.appendChild(delBtn);

      checkinTbody.appendChild(tr);
    });

    applyCurrentRowHighlight();
    initSortable();
  }

  // --- "Who's next" row highlight (client-side only, never saved -- see `currentCheckin`) ------

  function applyCurrentRowHighlight() {
    Array.prototype.forEach.call(checkinTbody.children, function (tr) {
      var c = net.checkins[Number(tr.dataset.index)];
      tr.classList.toggle('current-row', c === currentCheckin && c !== undefined);
    });
  }

  // Click anywhere in a row that isn't a button/input/link toggles it as the "current" row --
  // single selection, click the highlighted row again to clear it. Attached once (delegation),
  // so it survives renderCheckins() rebuilding the table on every 73/delete/reorder/edit.
  checkinTbody.addEventListener('click', function (e) {
    if (e.target.closest('button, input, a')) {
      return;
    }
    var tr = e.target.closest('tr[data-index]');
    if (!tr) {
      return;
    }
    var c = net.checkins[Number(tr.dataset.index)];
    currentCheckin = currentCheckin === c ? null : c;
    applyCurrentRowHighlight();
  });

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
    mde = new EasyMDE({
      element: scriptNotesEl,
      spellChecker: false,
      status: false,
      minHeight: '150px', // narrow side panel (see .script-notes-panel) -- keep it compact
      toolbar: ['bold', 'italic', 'heading', '|', 'unordered-list', 'ordered-list', '|', 'preview'],
    });
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
  //
  // Live-filters against the roster (level-1, callsigns only -- no name data, see SPEC.md
  // §3.1) merged with the hamdat cache (level-2, callsign+name+city+state). `candidates` is
  // the merged set, rebuilt whenever the underlying roster or hamdat cache changes; `suggestions`
  // is the current filtered/ranked view of it for whatever's typed right now.

  // A callsign can only be checked in once. Silently no-ops on a duplicate -- no alert, no
  // second row -- rather than erroring, since the common way to hit this is an operator
  // re-entering someone by habit, not a real mistake that needs flagging.
  function isAlreadyCheckedIn(callsign) {
    var upper = callsign.toUpperCase();
    return (net.checkins || []).some(function (c) {
      return (c.callsign || '').toUpperCase() === upper;
    });
  }

  function addCheckin(callsign, name, city, state) {
    net.checkins = net.checkins || [];
    if (isAlreadyCheckedIn(callsign)) {
      return;
    }
    net.checkins.push({
      order: net.checkins.length + 1,
      callsign: callsign,
      name: name || '',
      preferred_name: null,
      city: city || '',
      state: state || '',
      checked_in_at: new Date().toISOString(),
      checked_out_at: null,
      notes: '',
    });
    renderCheckins();
    scheduleSave();
  }

  function rebuildCandidates() {
    var map = {};

    (net.roster || []).forEach(function (cs) {
      var key = String(cs || '').trim().toUpperCase();
      if (!key) {
        return;
      }
      map[key] = map[key] || { callsign: key, name: '', city: '', state: '', inRoster: false };
      map[key].inRoster = true;
    });

    ((net.hamdat_lookup && net.hamdat_lookup.cached_results) || []).forEach(function (r) {
      var key = String(r.callsign || '').trim().toUpperCase();
      if (!key) {
        return;
      }
      map[key] = map[key] || { callsign: key, name: '', city: '', state: '', inRoster: false };
      map[key].name = r.name || map[key].name;
      map[key].city = r.city || map[key].city;
      map[key].state = r.state || map[key].state;
    });

    candidates = Object.keys(map).map(function (k) { return map[k]; });
    renderLookupStatus();
  }

  // Visibility into how many entries are actually loaded in each lookup list -- without this,
  // there's no way to tell "roster upload silently produced 0 rows" from "there's just nobody
  // near this ZIP" apart from noticing the lookup box quietly not matching anything.
  function renderLookupStatus() {
    var rosterCount = (net.roster || []).length;
    var hamdatResults = (net.hamdat_lookup && net.hamdat_lookup.cached_results) || [];
    var hamdatCount = hamdatResults.length;
    var refreshedAt = net.hamdat_lookup && net.hamdat_lookup.last_refreshed_at;

    var text = 'Roster: ' + rosterCount + ' callsign' + (rosterCount === 1 ? '' : 's') +
      ' · HAMDAT cache: ' + hamdatCount + ' record' + (hamdatCount === 1 ? '' : 's');
    text += refreshedAt ? ' (refreshed ' + formatRelativeTime(refreshedAt) + ')' : ' (not loaded yet)';

    lookupStatusEl.textContent = text;
  }

  function formatRelativeTime(iso) {
    var d = new Date(iso);
    if (isNaN(d)) {
      return '';
    }
    var seconds = Math.floor((Date.now() - d.getTime()) / 1000);
    if (seconds < 5) {
      return 'just now';
    }
    if (seconds < 60) {
      return seconds + 's ago';
    }
    var minutes = Math.floor(seconds / 60);
    if (minutes < 60) {
      return minutes + 'm ago';
    }
    var hours = Math.floor(minutes / 60);
    if (hours < 24) {
      return hours + 'h ago';
    }
    return Math.floor(hours / 24) + 'd ago';
  }

  // Match priority: exact callsign > callsign prefix > callsign substring > name substring.
  // Within that, roster members sort ahead of hamdat-only matches at the same tier -- the
  // roster is the "expected to check into this net" list, so those calls should be the easiest
  // to spot, not just whichever text-matched marginally better. An exact callsign match still
  // wins outright regardless of roster status, since typing the full call is effectively
  // confirming identity either way.
  //
  // Callsigns already on the check-in list are excluded entirely -- once someone's checked in,
  // there's no reason to keep suggesting them (and it keeps the dropdown from being cluttered
  // with people already handled, on a busy net with a long roster).
  function filterCandidates(query) {
    var q = query.trim().toUpperCase();
    if (!q) {
      return [];
    }
    return candidates
      .filter(function (c) { return !isAlreadyCheckedIn(c.callsign); })
      .map(function (c) {
        var score;
        if (c.callsign === q) {
          score = 0;
        } else if (c.callsign.indexOf(q) === 0) {
          score = 1;
        } else if (c.callsign.indexOf(q) !== -1) {
          score = 2;
        } else if (c.name && c.name.toUpperCase().indexOf(q) !== -1) {
          score = 3;
        } else {
          return null;
        }
        var copy = Object.assign({}, c);
        copy.score = score;
        return copy;
      })
      .filter(Boolean)
      .sort(function (a, b) {
        if (a.score !== b.score) {
          return a.score - b.score;
        }
        if (a.inRoster !== b.inRoster) {
          return a.inRoster ? -1 : 1;
        }
        return a.callsign.localeCompare(b.callsign);
      })
      .slice(0, suggestionLimit);
  }

  function renderSuggestions(list) {
    suggestions = list;
    activeIndex = list.length ? 0 : -1;
    suggestionsEl.innerHTML = '';

    if (!list.length) {
      suggestionsEl.hidden = true;
      return;
    }

    list.forEach(function (c, idx) {
      var li = document.createElement('li');
      li.className = (idx === activeIndex ? 'active' : '') + (c.inRoster ? ' in-roster' : '');

      var leftGroup = document.createElement('span');
      leftGroup.className = 'suggestion-left';

      var callsignSpan = document.createElement('span');
      callsignSpan.className = 'callsign';
      callsignSpan.textContent = c.callsign;
      leftGroup.appendChild(callsignSpan);

      // Roster membership is shown regardless of whether hamdat also matched this callsign --
      // it's the "expected to check into this net" signal, always worth surfacing on its own.
      if (c.inRoster) {
        var badge = document.createElement('span');
        badge.className = 'roster-badge';
        badge.textContent = '★ Roster';
        leftGroup.appendChild(badge);
      }

      li.appendChild(leftGroup);

      var detailSpan = document.createElement('span');
      detailSpan.className = 'detail';
      if (c.name) {
        var where = [c.city, c.state].filter(Boolean).join(', ');
        detailSpan.textContent = c.name + (where ? ' — ' + where : '');
      }
      li.appendChild(detailSpan);

      // mousedown (not click) fires before the input's blur, and preventDefault on it keeps
      // focus on the input entirely -- so selection works without a blur/close race.
      li.addEventListener('mousedown', function (e) {
        e.preventDefault();
        selectCandidate(c);
      });

      suggestionsEl.appendChild(li);
    });

    suggestionsEl.hidden = false;
  }

  function highlightActive() {
    Array.prototype.forEach.call(suggestionsEl.children, function (li, idx) {
      li.classList.toggle('active', idx === activeIndex);
    });
  }

  function closeSuggestions() {
    suggestions = [];
    activeIndex = -1;
    suggestionsEl.hidden = true;
    suggestionsEl.innerHTML = '';
  }

  function selectCandidate(c) {
    addCheckin(c.callsign, c.name, c.city, c.state);
    lookupBox.value = '';
    closeSuggestions();
    lookupBox.focus();
  }

  lookupBox.addEventListener('input', function () {
    // A leading "/" means the "/"-to-focus shortcut fired while the box already had focus (so
    // the "/" typed itself in instead of being intercepted) -- strip it silently. Only ever the
    // leading slash: callsigns legitimately contain "/" mid-string (portable/mobile suffixes
    // like W1AW/4 or /QRP), so those must never be touched.
    while (lookupBox.value.charAt(0) === '/') {
      lookupBox.value = lookupBox.value.slice(1);
    }
    renderSuggestions(filterCandidates(lookupBox.value));
  });

  lookupBox.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowDown') {
      if (!suggestions.length) {
        return;
      }
      e.preventDefault();
      activeIndex = (activeIndex + 1) % suggestions.length;
      highlightActive();
      return;
    }

    if (e.key === 'ArrowUp') {
      if (!suggestions.length) {
        return;
      }
      e.preventDefault();
      activeIndex = (activeIndex - 1 + suggestions.length) % suggestions.length;
      highlightActive();
      return;
    }

    if (e.key === 'Escape') {
      if (suggestions.length) {
        e.preventDefault();
        closeSuggestions();
      }
      return;
    }

    if (e.key !== 'Enter') {
      return;
    }
    e.preventDefault();

    if (activeIndex >= 0 && suggestions[activeIndex]) {
      selectCandidate(suggestions[activeIndex]);
      return;
    }

    // No suggestion matched -- add whatever was typed as a raw, unmatched check-in (visitor /
    // unlisted station, see SPEC.md §3.1).
    var callsign = lookupBox.value.trim().toUpperCase();
    if (!callsign) {
      return;
    }
    addCheckin(callsign, '', '', '');
    lookupBox.value = '';
    closeSuggestions();
  });

  document.addEventListener('click', function (e) {
    if (!suggestionsEl.hidden && !lookupBox.contains(e.target) && !suggestionsEl.contains(e.target)) {
      closeSuggestions();
    }
  });

  // Press "/" anywhere on the page to jump back to the lookup box, unless the operator is
  // already typing in some other field (including the EasyMDE/CodeMirror editor, which reports
  // its focused element as a plain TEXTAREA).
  document.addEventListener('keydown', function (e) {
    if (e.key !== '/') {
      return;
    }
    var active = document.activeElement;
    var tag = active ? active.tagName : '';
    var isEditable = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'
      || (active && active.isContentEditable);
    if (isEditable) {
      return;
    }
    e.preventDefault();
    lookupBox.focus();
    lookupBox.select();
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
      rebuildCandidates();
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

    var originalLabel = hamdatLoadBtn.textContent;
    hamdatLoadBtn.disabled = true;
    hamdatLoadBtn.textContent = 'Loading…';
    hamdatLastRefreshed.textContent = 'Querying hamdat…';

    try {
      var result = await HNH.withMinDuration(function () {
        return HNH.api('api/hamdat_lookup.php', {
          method: 'POST',
          body: JSON.stringify({ zip: net.hamdat_lookup.zip, radius_miles: net.hamdat_lookup.radius_miles }),
        });
      }, 500);
      net.hamdat_lookup.cached_results = result.results || [];
      net.hamdat_lookup.last_refreshed_at = new Date().toISOString();
      rebuildCandidates();
    } catch (err) {
      alert('HAMDAT lookup: ' + err.message);
    } finally {
      renderHamdatRefreshedLabel(); // restores "Last refreshed" on success, or prior state on failure
      hamdatLoadBtn.disabled = false;
      hamdatLoadBtn.textContent = originalLabel;
    }

    scheduleSave();
  });

  // --- Boot ------------------------------------------------------------------------------

  try {
    var config = await HNH.getConfig();
    saveDebounceMs = config.autosave_debounce_ms || 800;
    suggestionLimit = config.lookup_suggestion_limit || 15;
    net = await HNH.api('api/net_get.php?id=' + encodeURIComponent(netId));
    rebuildCandidates();
    initScriptNotesEditor();
    setIndicator('saved');
    render();
  } catch (err) {
    nameEl.textContent = 'Failed to load net';
    console.error(err);
  }
});
