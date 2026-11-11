#!/usr/bin/env bash
# Install the official age binary, pinned and checksummed, from GitHub.
#
# Apt against azure.archive.ubuntu.com has been failing the deploy and the
# nightly backup on a DNS flake (Temporary failure resolving the Ubuntu
# mirror). The encrypted backup cannot be skipped, and GitHub itself is
# reachable when that mirror is not. ubuntu-latest is linux-amd64.
set -euo pipefail

AGE_VERSION=1.3.2
AGE_SHA256=cbe24006683f8eb669266162894b9a522a1af52f2665fbc63a4bb032ed26ac10
URL="https://github.com/FiloSottile/age/releases/download/v${AGE_VERSION}/age-v${AGE_VERSION}-linux-amd64.tar.gz"

tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT
curl -sSL --fail --retry 3 --retry-delay 2 -o "$tmp" "$URL"
echo "${AGE_SHA256}  ${tmp}" | sha256sum -c -
sudo tar -C /usr/local/bin --strip-components=1 -xzf "$tmp" age/age age/age-keygen
age --version
