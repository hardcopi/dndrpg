# Hermes: security headers and the /app/ fence

## What ships with deploy.sh (nothing to do by hand)

`src/app/bootstrap.php` now sends these headers on every PHP response (pages,
API, editors):

| Header | Value |
|---|---|
| `X-Content-Type-Options` | `nosniff` |
| `Content-Security-Policy` | `frame-ancestors 'self' https://five-star.com https://*.five-star.com` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=()` |
| `Strict-Transport-Security` | `max-age=15552000`, HTTPS only, no `includeSubDomains` |

The only CSP directive is `frame-ancestors`. A script/style CSP is a separate
job, because the pages still use inline handlers.

## To apply on hermes by hand: deny /app/

`docker/nginx/deny-private.conf` now refuses `/app/...` with a 404, alongside
sql/tools/docs/content/tests. Before this, `/app/config/database.php` answered
200 with an empty body: PHP ran it, so nothing leaked, but it should never be
reachable by URL. hermes has its own copy of the snippet, so:

```bash
install -m 644 docker/nginx/deny-private.conf /etc/nginx/snippets/rpg-deny-private.conf
nginx -t && systemctl reload nginx
curl -s -o /dev/null -w '%{http_code}\n' https://rpg.five-star.com/app/config/database.php   # expect 404
curl -s -o /dev/null -w '%{http_code}\n' https://rpg.five-star.com/login.php                 # expect 200
```

To check both parts at once from anywhere:
`BASE=https://rpg.five-star.com bash src/tools/test_security_headers.sh`
(GETs only). The HSTS check is skipped over https.

Rollback: copy the previous `deny-private.conf` back from git and reload.
