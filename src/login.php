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
 *
 * The painted skin (river, wordmark, oak plank) lives in assets/css/login.css
 * and is this page only. The posts, the session and the redirect are unchanged.
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
  <?= csrf_meta() ?>
  <title><?= $mode === 'register' ? 'Create an account' : 'Sign in' ?> — Rivermark Chronicles</title>
  <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
  <link rel="stylesheet" href="<?= asset('assets/css/login.css') ?>">
</head>
<body class="auth-page login-screen" data-auth-mode="<?= htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') ?>">
  <?php require APP_PATH . '/inc/site_bar.php'; ?>
  <div class="login-stage">
    <div class="login-cluster">
      <img class="login-logo"
           src="<?= asset('assets/images/login/logo.webp') ?>"
           srcset="<?= asset('assets/images/login/logo-1x.webp') ?> 577w, <?= asset('assets/images/login/logo.webp') ?> 1154w"
           sizes="(max-width: 40rem) 92vw, 36rem"
           width="1154" height="456"
           alt="Rivermark Chronicles">

      <main class="auth-card login-panel">
        <span class="login-bracket login-bracket-nw" aria-hidden="true"></span>
        <span class="login-bracket login-bracket-ne" aria-hidden="true"></span>
        <span class="login-bracket login-bracket-sw" aria-hidden="true"></span>
        <span class="login-bracket login-bracket-se" aria-hidden="true"></span>

        <header class="auth-head">
          <h1 id="auth-title"><?= $mode === 'register' ? 'Create an account' : 'Sign in' ?></h1>
          <p class="auth-sub" id="auth-sub">
            <?= $mode === 'register'
                ? 'Your characters and their world are yours alone.'
                : 'Your characters are waiting.' ?>
          </p>
        </header>

        <form id="auth-form" autocomplete="on" novalidate>
          <label class="auth-field">
            <span>Account</span>
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
                   autocomplete="<?= $mode === 'register' ? 'new-password' : 'current-password' ?>">
          </label>

          <p class="auth-error" id="auth-error" role="alert" aria-live="polite"></p>

          <div class="login-actions">
            <button type="submit" class="btn login-btn auth-submit" id="auth-submit">
              <?= $mode === 'register' ? 'Create account' : 'Enter Realm' ?>
            </button>
            <?php if ($registrationOpen): ?>
              <a href="#" id="switch-link" class="btn login-btn"><?= $mode === 'register' ? 'Sign in' : 'New Account' ?></a>
            <?php endif; ?>
            <a class="btn login-btn" href="index.php">Quit</a>
          </div>
        </form>

        <?php if ($registrationOpen): ?>
          <p class="auth-switch login-sr">
            <span id="switch-prompt"><?= $mode === 'register' ? 'Already have an account?' : 'No account yet?' ?></span>
          </p>
        <?php else: ?>
          <p class="auth-switch auth-closed">
            Registration is closed on this server. Ask an administrator for an account.
          </p>
        <?php endif; ?>

        <p class="auth-foot"><a href="about.php">About &amp; licence</a></p>
      </main>
    </div>
  </div>

  <script src="<?= asset('assets/js/api.js') ?>"></script>
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
        document.body.dataset.authMode = registering ? 'register' : 'login';
        document.title = (registering ? 'Create an account' : 'Sign in') + ' — Rivermark Chronicles';
        document.getElementById('auth-title').textContent = registering ? 'Create an account' : 'Sign in';
        document.getElementById('auth-sub').textContent = registering
          ? 'Your characters and their world are yours alone.'
          : 'Your characters are waiting.';
        submit.textContent = registering ? 'Create account' : 'Enter Realm';
        emailField.hidden = !registering;
        // Tells a password manager whether to offer a stored password or a
        // generated one. Without it, registering prompts to autofill the
        // password of the account you are trying not to use.
        password.setAttribute('autocomplete', registering ? 'new-password' : 'current-password');
        const prompt = document.getElementById('switch-prompt');
        const link = document.getElementById('switch-link');
        if (prompt && link) {
          prompt.textContent = registering ? 'Already have an account?' : 'No account yet?';
          link.textContent = registering ? 'Sign in' : 'New Account';
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
