<?php
/**
 * Account management.
 *
 * Administrators only — the routes check the role again on every request, so
 * this guard is about not rendering the page rather than about being the
 * security boundary.
 */

require_once __DIR__ . '/app/page_guard.php';
$me = require_admin_page();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Accounts — Rivermark Chronicles</title>
  <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body class="admin-page">
  <?php require APP_PATH . '/inc/site_bar.php'; ?>
  <header class="admin-head">
    <div>
      <p class="auth-eyebrow">Rivermark Chronicles</p>
      <h1>Accounts</h1>
    </div>
  </header>

  <main class="admin-main">
    <section class="admin-panel">
      <div class="admin-panel-head">
        <h2>Registration</h2>
      </div>
      <p class="admin-note" id="reg-note">…</p>
      <label class="admin-toggle">
        <input type="checkbox" id="reg-open">
        <span>Anyone may create an account</span>
      </label>
    </section>

    <section class="admin-panel">
      <div class="admin-panel-head">
        <h2>Authored content</h2>
        <button type="button" class="btn btn-small" id="btn-export">Export to files</button>
      </div>
      <p class="admin-note">
        The database is where the world lives, and where every edit you make in the
        map editor or the content editors is kept. Exporting writes it back out to
        <code>content/</code> and <code>maps/</code> so it can be committed and
        deployed — and so dropping the database volume is not the same as losing
        the work.
      </p>
      <p class="admin-note" id="export-result"></p>
    </section>

    <section class="admin-panel">
      <div class="admin-panel-head">
        <h2>Users</h2>
        <button type="button" class="btn btn-small" id="btn-new">New account</button>
      </div>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>User</th><th>Role</th><th>Characters</th>
              <th>Last seen</th><th>Status</th><th></th>
            </tr>
          </thead>
          <tbody id="user-rows"><tr><td colspan="6">Loading…</td></tr></tbody>
        </table>
      </div>
    </section>

    <p class="admin-error" id="admin-error" role="alert" aria-live="polite"></p>
  </main>

  <div id="modal-root" class="hidden"></div>

  <script src="assets/js/api.js"></script>
  <script>
    (function () {
      'use strict';
      const ME = <?= json_encode(['id' => (int) $me['id'], 'username' => $me['username']]) ?>;
      const $ = (s, r = document) => r.querySelector(s);
      const err = $('#admin-error');
      const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
      }[c]));

      const fail = (e) => { err.textContent = e.message || String(e); };
      const clearError = () => { err.textContent = ''; };

      function whenSeen(iso) {
        if (!iso) return 'never';
        const then = new Date(iso.replace(' ', 'T') + 'Z');
        const days = Math.floor((Date.now() - then.getTime()) / 86400000);
        if (days === 0) return 'today';
        if (days === 1) return 'yesterday';
        if (days < 30) return days + ' days ago';
        return then.toISOString().slice(0, 10);
      }

      async function load() {
        clearError();
        let data;
        try { data = await API.get('admin/users'); } catch (e) { return fail(e); }

        const toggle = $('#reg-open');
        toggle.checked = !!data.registration_open;
        toggle.disabled = !!data.registration_locked_by_env;
        $('#reg-note').textContent = data.registration_locked_by_env
          ? 'RPG_REGISTRATION is set on this server, so it decides — this switch cannot override it.'
          : (data.registration_open
              ? 'Open. Anyone who can reach the site can make an account.'
              : 'Closed. Only you can create accounts.');

        $('#user-rows').innerHTML = data.users.map((u) => {
          const isMe = Number(u.id) === ME.id;
          return `
            <tr class="${Number(u.is_active) ? '' : 'is-off'}">
              <td>
                <strong>${esc(u.username)}</strong>${isMe ? ' <em>(you)</em>' : ''}
                ${u.email ? `<div class="admin-sub">${esc(u.email)}</div>` : ''}
                ${Number(u.has_password) ? '' : '<div class="admin-warn">no password — cannot sign in</div>'}
              </td>
              <td>
                <select data-role="${u.id}" ${isMe ? 'disabled' : ''}>
                  <option value="user"${u.role === 'user' ? ' selected' : ''}>user</option>
                  <option value="admin"${u.role === 'admin' ? ' selected' : ''}>admin</option>
                </select>
              </td>
              <td>${u.characters}</td>
              <td class="admin-sub">${esc(whenSeen(u.last_login_at))}</td>
              <td>${Number(u.is_active) ? 'active' : 'deactivated'}</td>
              <td class="admin-actions">
                <button type="button" class="btn btn-small" data-pw="${u.id}" data-name="${esc(u.username)}">Password</button>
                <button type="button" class="btn btn-small" data-active="${u.id}"
                        data-to="${Number(u.is_active) ? 0 : 1}" ${isMe ? 'disabled' : ''}>
                  ${Number(u.is_active) ? 'Deactivate' : 'Reactivate'}
                </button>
              </td>
            </tr>`;
        }).join('');
      }

      // Delegated, because the rows are replaced on every reload and per-row
      // listeners would be rebound each time.
      $('#user-rows').addEventListener('change', async (e) => {
        const sel = e.target.closest('[data-role]');
        if (!sel) return;
        clearError();
        try {
          await API.post('admin/set_role', { user_id: Number(sel.dataset.role), role: sel.value });
        } catch (ex) { fail(ex); }
        load();
      });

      $('#user-rows').addEventListener('click', async (e) => {
        const pw = e.target.closest('[data-pw]');
        const act = e.target.closest('[data-active]');
        clearError();
        try {
          if (pw) {
            const next = prompt(`New password for ${pw.dataset.name} (at least 8 characters):`);
            if (!next) return;
            await API.post('admin/set_password', { user_id: Number(pw.dataset.pw), password: next });
          } else if (act) {
            await API.post('admin/set_active', {
              user_id: Number(act.dataset.active),
              is_active: act.dataset.to === '1',
            });
          } else { return; }
        } catch (ex) { fail(ex); }
        load();
      });

      $('#reg-open').addEventListener('change', async (e) => {
        clearError();
        try {
          await API.post('admin/set_registration', { open: e.target.checked });
        } catch (ex) { fail(ex); }
        load();
      });

      $('#btn-new').addEventListener('click', async () => {
        clearError();
        const username = prompt('Username:');
        if (!username) return;
        const password = prompt('Password (at least 8 characters):');
        if (!password) return;
        const role = confirm('Make this an administrator?\n\nOK = admin, Cancel = ordinary player.')
          ? 'admin' : 'user';
        try {
          await API.post('admin/create_user', { username, password, role });
        } catch (ex) { fail(ex); }
        load();
      });

      $('#btn-export').addEventListener('click', async (e) => {
        clearError();
        const btn = e.target;
        btn.disabled = true;
        const was = btn.textContent;
        btn.textContent = 'Exporting…';
        const out = $('#export-result');
        try {
          const r = await API.post('admin/export', {});
          const n = r.written.length;
          out.textContent = n === 0
            ? 'Nothing changed — the files already match the database.'
            : `${n} file${n === 1 ? '' : 's'} written: ` + r.written.slice(0, 6).join(', ')
              + (n > 6 ? `, and ${n - 6} more.` : '');
        } catch (ex) { fail(ex); out.textContent = ''; }
        btn.disabled = false;
        btn.textContent = was;
      });

      load();
    })();
  </script>
</body>
</html>
