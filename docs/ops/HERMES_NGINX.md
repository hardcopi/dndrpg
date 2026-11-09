# Hermes nginx: gzip and asset caching (to apply by hand)

`deploy.sh` only syncs `src/`, so nothing under `docker/nginx/` reaches hermes
on a deploy. These are the server-side steps that match the docker stack. They
are safe to apply before or after the code deploy, because they only change
headers.

## What changes

| | Before | After |
|---|---|---|
| CSS / JS / JSON / SVG | sent raw (only HTML was gzipped) | gzip, level 5, `Vary: Accept-Encoding` |
| CSS/JS with `?v=` (every `asset()` URL) | `Cache-Control: no-cache` (revalidated on every load) | `public, max-age=31536000, immutable` |
| CSS/JS without `?v=` (`tokens.css` via `@import`, `/embed/`) | `no-cache` | `no-cache` (unchanged) |
| Images, audio | heuristic caching, no gzip | unchanged |

Measured on the docker-equivalent stack: all CSS + JS is 1,593,677 B raw and
471,310 B gzipped (−70%). `style.css` goes from 358,192 B to 97,160 B on the wire.

## Steps on hermes (as root)

1. Copy two files from this repo:

   ```bash
   install -m 644 docker/nginx/rpg-perf.conf /etc/nginx/snippets/rpg-perf.conf
   install -m 644 docker/nginx/rpg-maps.conf /etc/nginx/conf.d/rpg-maps.conf
   ```

   `rpg-maps.conf` must be read at **http** level. On Ubuntu's stock
   `/etc/nginx/nginx.conf`, `include /etc/nginx/conf.d/*.conf;` is inside
   `http {}`; check with `grep -n conf.d /etc/nginx/nginx.conf`.

2. Edit `/etc/nginx/sites-available/rpg`, in the rpg `server {}` block:

   - Next to the existing `include /etc/nginx/snippets/rpg-deny-private.conf;`, add:

     ```nginx
     include /etc/nginx/snippets/rpg-perf.conf;
     ```

   - In the `location ~* \.(?:css|js)$` block, replace
     `add_header Cache-Control "no-cache" always;` with:

     ```nginx
     add_header Cache-Control $rpg_asset_cache always;
     ```

   - Leave `location ^~ /embed/` (and its `gzip off;`) exactly as it is.

3. Test and reload:

   ```bash
   nginx -t && systemctl reload nginx
   ```

4. Check from anywhere:

   ```bash
   V=$(curl -s https://rpg.five-star.com/login.php | grep -o 'assets/css/style.css?v=[0-9]*' | head -1)
   curl -s -o /dev/null -H 'Accept-Encoding: gzip' \
     -w '%{http_code} %{size_download}B enc=%header{content-encoding} cc=%header{cache-control}\n' \
     "https://rpg.five-star.com/$V"
   # expect: 200 ~97000B enc=gzip cc=public, max-age=31536000, immutable
   curl -sI https://rpg.five-star.com/assets/css/tokens.css | grep -i cache-control
   # expect: no-cache
   ```

## Rollback

Remove the `include .../rpg-perf.conf;` line, put `"no-cache"` back in the
css/js block, then `nginx -t && systemctl reload nginx`. The two copied files
can stay; they do nothing unless they're referenced.
