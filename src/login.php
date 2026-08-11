<?php
/**
 * Signing in, and signing up.
 *
 * One page for both, because they are the same form with one extra field and a
 * different verb, and two pages would mean two copies of the same layout
 * drifting apart.
 *
 * Anyone already signed in is sent on rather than shown a login form they do
 * not need — reaching this page with a session is what happens when somebody
 * bookmarks it, not a request to sign out.
 */

require_once __DIR__ . '/app/bootstrap.php';

if (auth()->isSignedIn()) {
    header('Location: index.php');
    exit;
}

$registrationOpen = auth()->registrationOpen();

// `next` carries where the visitor was trying to get to, so an expired session
// does not also cost them their place. Only a local path is honoured: an
// absolute URL here would make this an open redirect, and a login page is
// exactly the thing worth pointing somewhere else in a phishing email.
$next = (string) ($_GET['next'] ?? 'index.php');
if ($next === '' || $next[0] === '/' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $next)) {
    $next = 'index.php';
}

// Which half of the form opens: ?mode=register from the "create one" link.
$mode = ($_GET['mode'] ?? '') === 'register' && $registrationOpen ? 'register' : 'login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in — Rivermark Chronicles</title>
  <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body class="auth-page">
  <main class="auth-card">
    <header class="auth-head">
      <p class="auth-eyebrow">Rivermark Chronicles</p>
      <h1 id="auth-title"><?= $mode === 'register' ? 'Create an account' : 'Sign in' ?></h1>
      <p class="auth-sub" id="auth-sub">
        <?= $mode === 'register'
            ? 'Your characters and their world are yours alone.'
            : 'Your characters are waiting.' ?>
      </p>
    </header>

    <form id="auth-form" autocomplete="on" novalidate>
      <label class="auth-field">
        <span>Username</span>
        <input type="text" name="username" id="username" required
               autocomplete="username" autocapitalize="none" spellcheck="false" autofocus>
      </label>

      <label class="auth-field" id="email-field" hidden>
        <span>Email <em>(optional)</em></span>
        <input type="email" name="email" id="email" autocomplete="email" spellcheck="false">
      </label>

      <label class="auth-field">
        <span>Password</span>
        <input type="password" name="password" id="password" required
               autocomplete="current-password">
      </label>

      <p class="auth-error" id="auth-error" role="alert" aria-live="polite"></p>

      <button type="submit" class="btn btn-primary auth-submit" id="auth-submit">
        <?= $mode === 'register' ? 'Create account' : 'Sign in' ?>
      </button>
    </form>

    <?php if ($registrationOpen): ?>
      <p class="auth-switch">
        <span id="switch-prompt"><?= $mode === 'register' ? 'Already have an account?' : 'No account yet?' ?></span>
        <a href="#" id="switch-link"><?= $mode === 'register' ? 'Sign in' : 'Create one' ?></a>
      </p>
    <?php else: ?>
      <p class="auth-switch auth-closed">
        Registration is closed on this server. Ask an administrator for an account.
      </p>
    <?php endif; ?>

    <p class="auth-foot"><a href="about.php">About &amp; licence</a></p>
  </main>

  <script src="assets/js/api.js"></script>
  <script>
    (function () {
      const form = document.getElementById('auth-form');
      const err = document.getElementById('auth-error');
      const submit = document.getElementById('auth-submit');
      const emailField = document.getElementById('email-field');
      const password = document.getElementById('password');
      const next = <?= json_encode($next) ?>;
      const canRegister = <?= $registrationOpen ? 'true' : 'false' ?>;

      let mode = <?= json_encode($mode) ?>;

      function paint() {
        const registering = mode === 'register';
        document.getElementById('auth-title').textContent = registering ? 'Create an account' : 'Sign in';
        document.getElementById('auth-sub').textContent = registering
          ? 'Your characters and their world are yours alone.'
          : 'Your characters are waiting.';
        submit.textContent = registering ? 'Create account' : 'Sign in';
        emailField.hidden = !registering;
        // Tells a password manager whether to offer a stored password or a
        // generated one. Without it, registering prompts to autofill the
        // password of the account you are trying not to use.
        password.setAttribute('autocomplete', registering ? 'new-password' : 'current-password');
        const prompt = document.getElementById('switch-prompt');
        const link = document.getElementById('switch-link');
        if (prompt && link) {
          prompt.textContent = registering ? 'Already have an account?' : 'No account yet?';
          link.textContent = registering ? 'Sign in' : 'Create one';
        }
        err.textContent = '';
        // So a reload, or a shared link, opens the same half of the form.
        const url = new URL(location.href);
        if (registering) { url.searchParams.set('mode', 'register'); } else { url.searchParams.delete('mode'); }
        history.replaceState(null, '', url);
      }

      const switchLink = document.getElementById('switch-link');
      if (switchLink) {
        switchLink.addEventListener('click', (e) => {
          e.preventDefault();
          mode = mode === 'register' ? 'login' : 'register';
          paint();
          document.getElementById('username').focus();
        });
      }

      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        err.textContent = '';
        submit.disabled = true;
        const was = submit.textContent;
        submit.textContent = mode === 'register' ? 'Creating…' : 'Signing in…';

        const body = {
          username: document.getElementById('username').value.trim(),
          password: password.value,
        };
        if (mode === 'register') body.email = document.getElementById('email').value.trim();

        try {
          await API.post(mode === 'register' && canRegister ? 'auth/register' : 'auth/login', body);
          location.href = next;
        } catch (ex) {
          err.textContent = ex.message || 'That did not work.';
          submit.disabled = false;
          submit.textContent = was;
          password.select();
        }
      });

      paint();
    })();
  </script>
</body>
</html>
