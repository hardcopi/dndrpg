#!/usr/bin/env bash
# Put the deploy key and Hermes's pinned host key where deploy.sh looks.
#
# deploy.sh (and the scripts beside this one) ssh with
#   -o BatchMode=yes -i ~/.ssh/id_ed25519 root@hermes.five-star.com
# BatchMode never prompts, so an unknown or changed host key is a hard failure:
# HERMES_KNOWN_HOSTS is what makes that a check rather than a guess. Nothing
# here is ever echoed; the runner is thrown away after the job, and the
# workflow's last step removes the key anyway.
set -euo pipefail
: "${HERMES_SSH_KEY:?HERMES_SSH_KEY secret is not set (see docs/ops/GITHUB_ACTIONS.md)}"
: "${HERMES_KNOWN_HOSTS:?HERMES_KNOWN_HOSTS secret is not set (see docs/ops/GITHUB_ACTIONS.md)}"

umask 077
mkdir -p ~/.ssh
printf '%s\n' "$HERMES_SSH_KEY" > ~/.ssh/id_ed25519
printf '%s\n' "$HERMES_KNOWN_HOSTS" > ~/.ssh/known_hosts
chmod 600 ~/.ssh/id_ed25519 ~/.ssh/known_hosts

grep -q 'hermes.five-star.com' ~/.ssh/known_hosts \
    || { echo "HERMES_KNOWN_HOSTS has no line for hermes.five-star.com" >&2; exit 1; }

# One harmless command proves key + host key before anything real runs.
ssh -o BatchMode=yes -o ConnectTimeout=20 -i ~/.ssh/id_ed25519 \
    root@hermes.five-star.com true
echo "ssh to hermes.five-star.com: ok"
