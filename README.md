# PSST - Philipp's Simple Share Tool

PSST is a small vanilla PHP sharing tool for Apache. It lets an admin create short-lived or persistent shares for text, links, and files, with optional browser-side encryption, secret-code protection, copyable links, and QR codes.

## ⭐ Features

- Vanilla PHP, JavaScript, CSS, and HTML
- Responsive management UI with vendored Bootstrap 5
- SQLite database with automatic migrations
- Apache `.htaccess` protection for stored files and runtime data
- Text, Markdown, link, and file shares with random UUID URLs; Markdown is rendered as a formatted page (dependency-free, raw HTML is shown as text)
- Expiry dates and view limits per share (a limit of 1 is burn after reading); used-up shares are deleted
- Upload drop boxes with an optional token, a per-drop-box storage limit, an optional closing date, multi-file selection, and upload progress
- Mobile-friendly uploads with explicit size limits and readable error messages
- Optional browser-side AES-GCM encryption with PBKDF2-SHA-256 (600,000 iterations)
- Secret-code protected public shares
- Manager login with 3 second delay, 24 hour IP block after 10 failed attempts, and a one-time HTP number
- 90 day manager session cookie
- Standard QR codes for normal share URLs
- ITI QR codes with automatically generated per-share validation codes
- Brazilian ITI validator JSON response support
- Version number, repository link, and author credit in the page footer
- `health.php` JSON health check (PHP extensions, database, writable data folders)
- `api.php` for other applications (for example PLM) to create and manage shares with revocable API keys; the documentation is in the manager under **API**
- Podman-based local testing with Apache and PHP

## 🔧 Quick start

### Prerequisites

- Podman

### Run locally

```sh
git clone <repo-url>
cd psst
chmod +x start.sh
./start.sh
```

Access the manager at `http://localhost:8081/manage.php`.

Default manager login:
- Username: `admin`
- Password: `psst`
- HTP: a 6-digit number (above 100000, below 999999) that has never been used before and contains
  the current month (two digits) and the current minute (two digits) at positions that do not overlap.
  The other digits are free; the previous and next minute are also accepted.

Examples:

```text
19:34 in May (05, 34):  053412  340599  105034  valid
                        053434  340505          valid (values may repeat)
                        034512                  invalid: no "05"
19:51 in May (05, 51):  055199                  valid
                        051999                  invalid: "05" and "51" share the 5
```

Every successful login uses up its HTP number for good, so pick a new one each time. The month and
minute follow the `timezone` setting.

The SQLite database and uploaded files are created automatically in `app/data/`. That directory is protected by Apache and ignored by git, except for marker/protection files.

### Configure

Copy the example config and adjust it for your environment:

```sh
cp app/config.php.example app/config.php
```

Important settings live in `app/config.php`:

```php
'base_url' => 'http://localhost:8081',
'admin_username' => 'admin',
'admin_password' => 'psst',
'timezone' => 'Europe/Zurich',
'server_secret_code' => '',
'default_upload_quota_bytes' => 1073741824,
'trusted_proxies' => [],
'max_requests_per_window' => 50,
'max_failed_secret_attempts_per_window' => 10,
'max_failed_login_attempts' => 10,
'login_block_seconds' => 86400,
'session_lifetime_seconds' => 7776000,
```

`app/config.php` is ignored by git. Keep production credentials and secrets only in that file.
Settings missing from an older `config.php` fall back to the defaults in `app/db.php`.

If PSST runs behind a reverse proxy, list the proxy in `trusted_proxies` (IPs or CIDR ranges, for
example `['10.0.0.5', '192.168.0.0/16']`). Otherwise every visitor shares the proxy's IP, so rate
limits and the login block apply to everyone at once.

### API for other applications

Other applications can create shares through `app/api.php`. Create an API key in the manager
under **API**; the key is shown once and stored only as a hash. Each key can only read and
delete the shares it created. The full documentation opens from the same dialog
(`api-docs.php`, manager login required); developers of the other application can use the
standalone integration guide in [`docs/api.md`](docs/api.md). Send the key in the `Authorization: Bearer` header and
use HTTPS. After `max_failed_api_auth_per_window` failed attempts in an hour, the client IP is
refused until the hour is over.

### Expiry and view limits

A share can expire at a given time and/or allow a limited number of views. When either runs out,
the share and its file are deleted. Shares with a view limit only open after the visitor clicks a
button, so link previews in chat apps do not use up views. For encrypted shares, fetching the
encrypted content counts as the view; retrying a mistyped password on the same page does not.

For drop boxes, the expiry closes the box: it stops accepting files, and received files are kept.
Each drop box has its own storage limit (`default_upload_quota_bytes` for new ones); the manager
shows how much is used.

### Share URLs

Normal share links look like this:

```text
https://example.com/psst/index.php?id=UUID
```

If a share has a secret code, it can also be supplied in the URL:

```text
https://example.com/psst/index.php?id=UUID&_secretCode=SECRET
```

ITI validation URLs are generated automatically per share and include a generated per-share ITI code:

```text
https://example.com/psst/index.php?id=UUID&_format=application/validador-iti%2Bjson&_secretCode=ITI_CODE
```

### Upload limits

The effective limit is the smallest of `max_upload_bytes` in `app/config.php` and PHP's
`upload_max_filesize` / `post_max_size`. PHP's stock defaults (2 MB / 8 MB) are far below
the app default and silently discard larger request bodies, which is why phone photos and
videos used to fail. The limits are raised in three places, depending on how PHP runs:

| Deployment | File |
| --- | --- |
| Container image | `php/psst.ini` |
| PHP-FPM / CGI, shared hosting | `app/.user.ini` |
| Apache `mod_php` | `app/.htaccess` |

Raise all three together, and keep `post_max_size` above `upload_max_filesize` to leave
room for multipart overhead. Reverse proxies need their own limit raised as well, for
example `client_max_body_size` in nginx. The manager form and the drop-box page show the
current limit and reject oversized files before uploading.

## 📦 Deployment notes

- Deploy the contents of `app/` to an Apache2 + PHP host.
- Enable `.htaccess` support with `AllowOverride` for the application directory.
- Make sure PHP can write to `app/data/` and `app/data/files/`.
- Set `base_url` to the public URL, including any subfolder, for example `https://example.com/psst`.
- Deploy `app/.user.ini` together with the app, or apply the equivalent upload limits in your PHP config.
- Change the default admin credentials before production use.
- Check `https://your-host/psst/health.php` after deploying; it answers `503` when, for example, the `pdo_sqlite` extension is missing.
- Bump `PSST_VERSION` in `app/functions.php` on release. It is shown in the footer and returned by the `status` API.

## ⚠️ Disclaimer

- Portions of this project were developed using Vibe Coding practices.
- An AI assistant (GitHub Copilot) was used during development; review and validate outputs before production use.

## 📄 License

MIT