# Deploys and backups from GitHub Actions

Until now every deploy and every database backup ran from Thor. If Thor was
off, nothing shipped and nothing was backed up. These workflows do the same
jobs from GitHub's runners, over SSH to Hermes, with the same scripts:

| Workflow | When | What it does |
|---|---|---|
| `.github/workflows/ci.yml` (**CI**) | every pull request; every push to main (as the first job of Deploy); manual | Throwaway PHP 8.4 + MySQL 8.0 stack, builds `rpg_5e` the way `docker/initdb/00-load.sh` does, seeds one player, runs `src/tools/verify.sh` (all 76 checks, including `test_state_machines`). No secrets. |
| `.github/workflows/deploy.yml` (**Deploy**) | push to main (only once `DEPLOY_ENABLED` is `true`); manual "Run workflow" | CI → SSH check → **encrypted backup of the live DB** → migration check (stops if Hermes is missing one) → `./deploy.sh` dry run → `echo deploy \| ./deploy.sh --apply` → smoke check of https://rpg.five-star.com |
| `.github/workflows/backup.yml` (**Nightly DB backup**) | 2:17 AM America/Indianapolis (once `BACKUP_ENABLED` is `true`); manual | `mysqldump` of `rpg_5e` over SSH, checked, age-encrypted, kept as an artifact for 14 days |

Nothing here changes how Thor deploys. **Keep Thor as the manual fallback**
(`sudo -u richard ./deploy.sh`) at least until the first successful Actions
deploy, and after that for whenever GitHub is down.

## What it ships with, and what it doesn't

- **Code:** `deploy.sh`, unchanged in what it sends: same `rsync-excludes`,
  same "no `--delete`", same `chown -R www-data:www-data` afterwards, same
  dry-run-then-type-`deploy` gate (the workflow pipes the word in). The one
  addition is `DEPLOY_COMPARE=checksum`, which the workflow sets: a fresh
  checkout gives every file a new mtime, so plain `rsync -a` would list and
  touch all ~12,000 files on every run, and `asset()`'s `?v=<filemtime>`
  would bust every player's cache on every deploy. In checksum mode only files
  whose bytes changed are sent, listed, and given a new mtime. Without the
  variable (Thor), `deploy.sh` behaves exactly as before.
- **Database:** never changed by a workflow. Backups are read-only
  (`mysqldump --single-transaction`). The migration step only runs
  `migrate.sh --status`; if anything is pending it **fails the deploy** and
  you apply the migration by hand, as CLAUDE.md says, then re-run.
- **nginx:** not touched. See "nginx snippets" below.
- **Credentials:** there is no database password anywhere in GitHub. Like
  `~/rivermark/bin/hermes-mysql` on Thor, everything runs `mysql`/`mysqldump`
  on Hermes as root, which authenticates over the socket.

## One-time setup (Rich)

Do this from your own computer (or Thor) — anywhere you already have
`ssh root@hermes.five-star.com` working.

### 1. Make a deploy key just for Actions

```bash
ssh-keygen -t ed25519 -N "" -C "github-actions hardcopi/dndrpg" -f ~/.ssh/dndrpg_actions
```

That writes two files: `~/.ssh/dndrpg_actions` (private — goes into GitHub
only) and `~/.ssh/dndrpg_actions.pub` (public — goes onto Hermes). No
passphrase, because a runner can't type one; the key is protected by living
only in GitHub's encrypted secrets.

### 2. Put the public half on Hermes

```bash
ssh root@hermes.five-star.com \
  "umask 077; cat >> /root/.ssh/authorized_keys" \
  <<< "restrict $(cat ~/.ssh/dndrpg_actions.pub)"
```

The `restrict` prefix turns off port/agent/X11 forwarding and terminals for
this key. rsync, `mysqldump`, `mysql` and `chown` don't need any of those.
It is still a root key on Hermes — that is what `deploy.sh` uses today — so
treat the GitHub secret accordingly.

Check it works (from the same machine):

```bash
ssh -o IdentitiesOnly=yes -i ~/.ssh/dndrpg_actions root@hermes.five-star.com true && echo ok
```

### 3. Pin Hermes's host key

```bash
ssh-keyscan -t ed25519 hermes.five-star.com 2>/dev/null > /tmp/hermes_known_hosts
ssh-keygen -lf /tmp/hermes_known_hosts
# compare with the real one, on Hermes:
ssh root@hermes.five-star.com ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub
```

The two fingerprints must match. The workflows run ssh in `BatchMode`, so if
Hermes's host key ever changes they stop instead of trusting a stranger.

### 4. Make a backup encryption key (age)

The repository is **public**, and artifacts of a public repo can be
downloaded by anyone signed in to GitHub. So every backup is encrypted before
upload, to a key only you hold. Install `age` (`brew install age` /
`apt install age`), then:

```bash
age-keygen -o ~/rpg-backup-age.key
# prints:  Public key: age1....
```

- `~/rpg-backup-age.key` is the **private** key. Keep it out of GitHub, and
  keep a second copy somewhere safe (password manager). Without it, no backup
  can be restored.
- The `age1...` line is the **public** key; it goes into GitHub below.

### 5. Add the secrets and variables in GitHub

github.com/hardcopi/dndrpg → Settings → Secrets and variables → Actions.

**Secrets** (tab "Secrets"):

| Name | Value |
|---|---|
| `HERMES_SSH_KEY` | the whole contents of `~/.ssh/dndrpg_actions` (the private key file, including its BEGIN/END lines) |
| `HERMES_KNOWN_HOSTS` | the contents of `/tmp/hermes_known_hosts` from step 3 (one line starting `hermes.five-star.com ssh-ed25519`) |

**Variables** (tab "Variables"; these are not secret):

| Name | Value | Effect |
|---|---|---|
| `BACKUP_AGE_RECIPIENT` | the `age1...` public key from step 4 | required by both Deploy and the nightly backup; without it they refuse to back up (and so refuse to deploy) |
| `BACKUP_ENABLED` | `true` | turns on the 2:17 AM schedule (set after step 6 works) |
| `DEPLOY_ENABLED` | `true` | makes every push to main deploy (set after step 7 works) |

Or with the GitHub CLI, from the repo:

```bash
gh secret set HERMES_SSH_KEY     < ~/.ssh/dndrpg_actions
gh secret set HERMES_KNOWN_HOSTS < /tmp/hermes_known_hosts
gh variable set BACKUP_AGE_RECIPIENT --body "age1...your public key..."
```

Then delete the local private key copy if you like (`rm ~/.ssh/dndrpg_actions`);
GitHub has it, and you can always make a new one.

Host, user and paths are **not** secrets and are not configurable: the
workflows use exactly what `deploy.sh` uses (`root@hermes.five-star.com`,
`/home/www/rpg/`, database `rpg_5e`).

### 6. Test the backup

Actions → **Nightly DB backup** → Run workflow. When it's green, download
the artifact and prove you can open it (see "Restoring a backup" — stop
before the production step). Then set `BACKUP_ENABLED=true`.

### 7. Test the deploy, dry run first

Actions → **Deploy** → Run workflow, leave **apply** unchecked. That runs CI,
takes a backup, checks migrations, and prints what `deploy.sh` *would*
change. Read the dry-run list.

Expect the first list to contain any file whose bytes differ between git
main and what is on Hermes now (for example a file someone edited on Thor
but never committed). If something there surprises you, stop and look before
applying.

Then Run workflow again with **apply** checked (or deploy once from Thor, as
today, and let the next push do it). When an apply run is green — including
the smoke check — set `DEPLOY_ENABLED=true`. From then on, merging to main
deploys.

"Run workflow" only appears once these files are on main.

## Every deploy, in order

1. **CI** — the whole of `verify.sh` on a fresh stack. Red = nothing below runs.
2. **SSH check** — key + pinned host key, one `true` over ssh.
3. **Backup** — `mysqldump` of `rpg_5e`, checked for a sane size, gzip
   integrity and mysqldump's own `-- Dump completed` trailer, then
   age-encrypted. Kept 30 days as `db-pre-deploy-<run id>`.
4. **Migrations** — `migrate.sh --status` against Hermes. Any pending = stop.
5. **Dry run** — `./deploy.sh` (lists only content changes).
6. **Apply** — `echo deploy | ./deploy.sh --apply` (push, or manual with apply checked).
7. **Smoke** — `/`, `style.css`, `api.js` (checked by content type, since a
   missing file is a 200 with the homepage), `/api/?r=session/status` as JSON;
   `/sql/content.sql` must not be served; a body from
   `/app/config/database.php` fails the run. Warnings (not failures) say whether
   the security headers and the nginx snippets are live yet.

Only one deploy runs at a time, and a running one is never cancelled.

## Rolling back

- **Bad code:** `git revert <sha>` in a PR, merge; the deploy ships the old
  bytes back. Or tag the last good commit and run **Deploy** from that tag
  ("Use workflow from" → the tag, apply checked). `deploy.sh` never deletes,
  so files a bad commit *added* stay on Hermes, unreferenced; remove them by
  hand if they matter.
- **Bad data:** restore the `db-pre-deploy-*` artifact from that run (below).
- **Stop automatic deploys:** delete the `DEPLOY_ENABLED` variable (or set it
  to anything but `true`). Manual runs still work; Thor still works.
- **Revoke Actions' access entirely:** remove the `github-actions
  hardcopi/dndrpg` line from `/root/.ssh/authorized_keys` on Hermes, and
  delete the `HERMES_SSH_KEY` secret.

## Restoring a backup

```bash
# 1. download (or use the artifact's download button on the run page)
gh run download <run-id> -R hardcopi/dndrpg -n db-nightly-<run-id>
# 2. decrypt + unzip
age -d -i ~/rpg-backup-age.key rpg_5e-*.sql.gz.age | gunzip > rpg_5e.sql
tail -n 1 rpg_5e.sql        # "-- Dump completed on ..."
```

The dump has no `CREATE DATABASE`/`USE` line (a single database was
dumped), so it loads into whichever database you name. **Always load it
into a scratch database first**, never into a working one:

```bash
mysql -e 'CREATE DATABASE rpg_restore_check CHARACTER SET utf8mb4'
mysql --default-character-set=utf8mb4 rpg_restore_check < rpg_5e.sql
mysql rpg_restore_check -e 'SELECT COUNT(*) FROM users; SELECT COUNT(*) FROM characters;'
```

**Production restore** (replaces every player's progress since the dump — a
last resort): take a fresh backup first (Run **Nightly DB backup**), then

```bash
ssh root@hermes.five-star.com mysql --default-character-set=utf8mb4 rpg_5e < rpg_5e.sql
```

## Retention and where copies live

- Nightly: 14 days of artifacts. Pre-deploy: 30 days.
- They live **only in GitHub Actions artifacts**. The repo has no other
  storage set up, so nothing else was invented. A second, off-GitHub copy
  (and where it should live) is still Rich's decision.
- Artifacts are encrypted with age; GitHub never sees the private key.

## nginx snippets (#53, #54)

`deploy.sh` only syncs `src/`, so the nginx changes written in #53 and #54
are **applied by hand, once**, on Hermes:

- gzip + long caching: `docs/ops/HERMES_NGINX.md`
- deny `/app/` (security): `docs/ops/HERMES_SECURITY.md` ("To apply on hermes by hand")

Both include their own test (`nginx -t`) and rollback. They are not
automated on purpose: rewriting the live vhost from CI on day one is a bigger
risk than one careful manual edit. After you apply them, every deploy's smoke
step reports them as `ok` instead of `warn`, so drift (someone reverting the
vhost) shows up in the run log.

## Security notes

- Third-party actions are pinned to commit SHAs (version in a comment).
- `GITHUB_TOKEN` is read-only (`permissions: contents: read`) and checkout
  doesn't keep it (`persist-credentials: false`).
- Deploy and backup jobs only run in `hardcopi/dndrpg`, never a fork. CI uses
  no secrets, and GitHub never hands secrets to pull requests from forks.
- Secrets are only given to the one step that writes the key file; nothing
  echoes them, and GitHub masks them in logs anyway. The key is deleted at the
  end of each job and the runner is thrown away.
- Backups are refused rather than uploaded unencrypted.
- **Optional:** Settings → Environments → `production` (created by the first
  Deploy run) → **Required reviewers** → you. Every deploy then waits for
  your click on the run page, including pushes to main. You can also move the
  two secrets into that environment so only the deploy job can read them —
  but the nightly backup needs them too, so if you do, leave a copy at repo
  level or give the backup job the same environment.
