document.addEventListener('DOMContentLoaded', async function () {
  var tbody = document.querySelector('#net-list tbody');
  var beginBtn = document.getElementById('begin-new-net');
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

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    var fd = new FormData(form);

    var payload = {
      name: fd.get('name'),
      net_type: fd.get('net_type'),
      net_control: fd.get('net_control'),
      frequency: fd.get('frequency'),
      description: fd.get('description'),
      hamdat_lookup: {
        zip: fd.get('hamdat_zip'),
        radius_miles: Number(fd.get('hamdat_radius')) || 0,
      },
    };

    if (form.dataset.carryRoster) {
      payload.roster = JSON.parse(form.dataset.carryRoster);
    }
    if (form.dataset.carryScriptNotes) {
      payload.script_notes = form.dataset.carryScriptNotes;
    }

    let net;
    try {
      net = await HNH.api('api/net_create.php', {
        method: 'POST',
        body: JSON.stringify(payload),
      });
    } catch (err) {
      alert('Could not create net: ' + err.message);
      return;
    }

    window.location.href = 'net.php?id=' + encodeURIComponent(net.id);
  });

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
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + HNH.escapeHtml(net.name || '(untitled)') + '</td>' +
        '<td>' + HNH.escapeHtml(formatDate(net.created_at)) + '</td>' +
        '<td>' + HNH.escapeHtml(net.net_control || '') + '</td>' +
        '<td>' + net.checkin_count + '</td>' +
        '<td>' + HNH.escapeHtml(net.status) + '</td>' +
        '<td class="actions"></td>';

      var actions = tr.querySelector('.actions');

      var openLink = document.createElement('a');
      openLink.href = 'net.php?id=' + encodeURIComponent(net.id);
      openLink.textContent = net.status === 'closed' ? 'Resume' : 'Open';
      actions.appendChild(openLink);

      ['csv', 'report', 'json'].forEach(function (format) {
        var link = document.createElement('a');
        link.href = 'api/net_download.php?id=' + encodeURIComponent(net.id) + '&format=' + format;
        link.textContent = format.toUpperCase();
        actions.appendChild(link);
      });

      var startLikeBtn = document.createElement('button');
      startLikeBtn.type = 'button';
      startLikeBtn.textContent = 'Start new net like this one';
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
      actions.appendChild(startLikeBtn);

      var delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'danger';
      delBtn.textContent = 'Delete';
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
      actions.appendChild(delBtn);

      tbody.appendChild(tr);
    });
  }

  function formatDate(iso) {
    if (!iso) {
      return '';
    }
    var d = new Date(iso);
    return isNaN(d) ? iso : d.toLocaleString();
  }

  loadNets();
});
