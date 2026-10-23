# Tests

The suites run inside podman containers; nothing is installed on the host.

```sh
tests/run.sh             # PHP suites
tests/run.sh --browser   # plus the browser tests
```

`run.sh` builds the image, starts a throwaway container `psst-tests` with an empty data
folder for each suite, and removes it afterwards (`KEEP=1 tests/run.sh` keeps the
containers for a look around). It publishes no port, so it does not interfere with a
running development container, and `app/data` is never touched. Results, the generated
test configuration and the browser screenshots land in `tests/output/` (git-ignored).
The script exits with status 1 if any check fails.

| Suite | What it covers |
| --- | --- |
| `smoke.sh` | Health check, HTP rules, login block, trusted proxies, expiry and view limits, encrypted shares, file headers, drop-box quotas, error masking |
| `markdown.php` | Markdown renderer: syntax and XSS attempts |
| `api.sh` | API keys, `api.php` authentication and isolation, Markdown shares, API documentation |
| `sites.sh` | Sites: sandbox, page tokens, storage operations, modes, per-visitor stores, quotas, cleanup, owner access in the manager and the API |
| `browser.mjs` | Manager UI in Chromium; sites, the sandbox and decryption in Chromium, Firefox and WebKit |

The browser suite uses the official Playwright image (about 2.3 GB on first use). Its site
test publishes the checklist example from `app/docs/sites.md`, so that example is tested
too.

`config.php` is the configuration of the test container: no login delay, a high request
limit, a 1 MB default drop-box quota and `127.0.0.1` as trusted proxy.
