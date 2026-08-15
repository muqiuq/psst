# PSST - Philipp's Simple Share Tool

PSST is a small vanilla PHP sharing tool for Apache. It lets an admin create short-lived or persistent shares for text, links, and files, with optional browser-side encryption, secret-code protection, copyable links, and QR codes.

## ⭐ Features

- Vanilla PHP, JavaScript, CSS, and HTML
- Responsive management UI with vendored Bootstrap 5
- SQLite database with automatic migrations
- Apache `.htaccess` protection for stored files and runtime data
- Text, link, and file shares with random UUID URLs
- Upload drop boxes with an optional token, multi-file selection, and upload progress
- Mobile-friendly uploads with explicit size limits and readable error messages
- Optional browser-side AES-GCM encryption with PBKDF2-SHA-256
- Secret-code protected public shares
- Manager login with 3 second delay, 24 hour IP block after 10 failed attempts, and HTP challenge field
- 90 day manager session cookie
- Standard QR codes for normal share URLs
- ITI QR codes with automatically generated per-share validation codes
- Brazilian ITI validator JSON response support
- Version number, repository link, and author credit in the page footer
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
- HTP: `htp_secret_number * current minute * 2 + day of month`

Example with the default `htp_secret_number` of `2`, at 19:34 on May 11:

```text
2 * 34 * 2 + 11 = 147
```

The HTP value for the previous and next minute is also accepted.

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
'htp_secret_number' => 2,
'server_secret_code' => '',
'max_requests_per_window' => 50,
'max_failed_secret_attempts_per_window' => 10,
'max_failed_login_attempts' => 10,
'login_block_seconds' => 86400,
'session_lifetime_seconds' => 7776000,
```

`app/config.php` is ignored by git. Keep production credentials and secrets only in that file.

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
- Change the default admin credentials and `htp_secret_number` before production use.
- Bump `PSST_VERSION` in `app/functions.php` on release. It is shown in the footer and returned by the `status` API.

## ⚠️ Disclaimer

- Portions of this project were developed using Vibe Coding practices.
- An AI assistant (GitHub Copilot) was used during development; review and validate outputs before production use.

## 📄 License

MIT