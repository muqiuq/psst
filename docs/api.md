# PSST API integration guide

This guide is for developers who connect another application, for example PLM, to
PSST. PSST shares text, Markdown documents, links and files through links that can
expire, open a limited number of times, or ask for a secret code. Through the API, your
application creates such a share and gets back the link to give to the recipient.

The examples use `https://psst.example.com` as the PSST address. Replace it with the
address of your PSST installation, the folder that contains `manage.php`, for example
`https://example.com/psst`. The API endpoint is `api.php` in that folder.

The PSST manager has the same reference under **API** → *Open the API documentation*,
with your real address filled in.

## Quick start

1. Ask the PSST administrator for an API key. They create it in the manager under
   **API**; it starts with `psst_`.
2. Check the connection:

   ```sh
   curl -H "Authorization: Bearer $PSST_KEY" "https://psst.example.com/api.php?action=status"
   ```

3. Share a Markdown document:

   ```sh
   curl -X POST "https://psst.example.com/api.php?action=create" \
     -H "Authorization: Bearer $PSST_KEY" \
     -H "Content-Type: application/json" \
     -d '{"type": "markdown", "title": "Release notes", "content": "# Release notes\n\n- Faster sync"}'
   ```

4. Give the `url` from the response to the recipient.

## Authentication

Send the API key with every request in the `Authorization` header:

```http
Authorization: Bearer psst_...
```

If a proxy between your application and PSST strips that header, send
`X-API-Key: psst_...` instead. Keys in the URL are ignored.

- **Server to server only.** The key can create and delete shares. Keep it in your
  application's server-side configuration or secret store, never in browser code or in
  the repository.
- **Use HTTPS.** Over plain HTTP, anyone on the network path can read the key.
- **One key per application.** Each key can only read and delete the shares it created
  itself; shares of other keys, or created in the PSST manager, answer `404`.
- **Lost or leaked keys** cannot be shown again: PSST stores only a hash. The
  administrator creates a new key and revokes the old one. Requests with a revoked key
  fail with `401` immediately; the shares it created stay online.
- **Failed attempts are limited.** After 10 requests with a missing or wrong key within an
  hour (configurable on the server), PSST refuses every API request from that IP address
  with `429` until the hour is over, even ones with a valid key. A misconfigured key
  can therefore lock out your application for up to an hour; stop retrying on `401`.

## Conventions

- Endpoint: `api.php?action=ACTION`.
- Request bodies are JSON (`Content-Type: application/json`, UTF-8) or
  `multipart/form-data`. File uploads need `multipart/form-data`.
- Every response is JSON. Errors have the form `{"error": "Human-readable message"}`
  and an HTTP status code from the [error table](#errors).
- Times in responses are UTC in ISO 8601, for example `2026-12-31T23:00:00Z`.

## Endpoints

### `GET api.php?action=status`

Checks the key and returns the server's limits.

```json
{
  "ok": true,
  "key": "PLM",
  "app_version": "1.3.0",
  "max_upload_bytes": 104857600,
  "share_types": ["markdown", "text", "link", "file"]
}
```

`key` is the name the administrator gave the key. `max_upload_bytes` is the largest
request body PSST accepts.

### `POST api.php?action=create`

Creates a share. Returns `201` and `{"share": {...}}` with the
[share fields](#share-fields).

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `type` | string | yes | `markdown`, `text`, `link` or `file` |
| `content` | string | for `markdown`, `text`, `link` | The Markdown source, the text, or the URL to redirect to |
| `file` | file | for `file` | The file, as a `multipart/form-data` field |
| `title` | string | no | Shown on the share page; longer than 160 characters is cut. For Markdown it is also the name of the downloaded `.md` file |
| `expires_at` | string or number | no | When PSST deletes the share: ISO 8601 (`2026-12-31T23:00:00Z`) or a Unix timestamp in seconds. Must be in the future |
| `max_views` | integer | no | Delete the share after this many views, 1 to 1,000,000. `1` means burn after reading |
| `secret_code` | string | no | The recipient must enter this code before the share opens |

Without `expires_at` and `max_views`, the share stays until someone deletes it. The API
cannot create encrypted shares or upload drop boxes.

A Markdown document that opens five times at most and expires at the end of the year:

```json
{
  "type": "markdown",
  "title": "Release notes 2.4",
  "content": "# Release notes\n\n- Faster sync\n- New export",
  "expires_at": "2026-12-31T23:00:00Z",
  "max_views": 5
}
```

A file:

```sh
curl -X POST "https://psst.example.com/api.php?action=create" \
  -H "Authorization: Bearer $PSST_KEY" \
  -F type=file \
  -F title="Wiring diagram" \
  -F max_views=3 \
  -F "file=@diagram.pdf;type=application/pdf"
```

Response:

```json
{
  "share": {
    "uuid": "6f1d0b8e-3c1a-4f7e-9a59-2f5b8c0d4e21",
    "type": "markdown",
    "title": "Release notes 2.4",
    "url": "https://psst.example.com/index.php?id=6f1d0b8e-3c1a-4f7e-9a59-2f5b8c0d4e21",
    "download_url": "https://psst.example.com/index.php?id=6f1d0b8e-3c1a-4f7e-9a59-2f5b8c0d4e21&download=1",
    "file_name": null,
    "size": 47,
    "mime_type": "text/markdown; charset=utf-8",
    "has_secret_code": false,
    "requires_secret_code": false,
    "expires_at": "2026-12-31T23:00:00Z",
    "max_views": 5,
    "view_count": 0,
    "views_remaining": 5,
    "created_at": "2026-10-22T09:15:02Z"
  }
}
```

### `GET api.php?action=share&uuid=UUID`

Returns `{"share": {...}}` for one of your shares, for example to show how often it was
opened. A share that expired, used up its views or was deleted answers `404`.

### `POST api.php?action=delete&uuid=UUID`

Also accepts `DELETE`. Deletes the share and its file immediately and returns
`{"deleted": true}`. The link stops working right away.

## Share fields

| Field | Description |
| --- | --- |
| `uuid` | The share's ID, used by `share` and `delete` |
| `type` | `markdown`, `text`, `link` or `file` |
| `title` | The title, or an empty string |
| `url` | The link for the recipient |
| `download_url` | Direct download for `file` and `markdown`, otherwise `null`; see [What the recipient sees](#what-the-recipient-sees) |
| `file_name` | Original name of an uploaded file, otherwise `null` |
| `size` | Size of the file or content in bytes |
| `mime_type` | Content type PSST serves |
| `has_secret_code` | Whether the share has its own secret code |
| `requires_secret_code` | Whether the recipient must enter a code: the share's own, or one the server sets for all shares |
| `expires_at` | When PSST deletes the share, or `null` |
| `max_views` | The view limit, or `null` for unlimited |
| `view_count` | How often the share was opened |
| `views_remaining` | Views left, or `null` for unlimited |
| `created_at` | When the share was created |

## What the recipient sees

- **Markdown:** a formatted page with buttons to copy the source or download it as
  `.md`; those buttons do not count as extra views. `download_url` returns the raw file
  instead and counts as a view.
- **Text:** the plain text. **Link:** a redirect to the URL. **File:** the file; images,
  PDFs, plain text, audio and video open in the browser, everything else downloads.
  Files with a view limit always download, so a reload cannot use up a view.
- **View limit:** the recipient first sees a page with a button, and only the click
  counts as a view. This keeps link previews in chat apps from using up views. It also
  applies to `download_url`.
- **Secret code:** the recipient is asked for the code. To skip the prompt, append it to
  the link: `&_secretCode=CODE`. Anyone with that full link can then open the share, so
  send code and link separately when the code matters.
- **Gone:** after the expiry or the last view, PSST deletes the share and the link
  answers `404` with an empty page.

Anyone who has the link can open the share. Use `max_views`, `expires_at` or
`secret_code` to limit that.

## Markdown support

PSST renders the Markdown on the server. Supported:

- Headings (`#` to `######`, or underlined with `===` / `---`); each gets an anchor, so
  `[see setup](#setup)` links to the heading "Setup"
- Paragraphs and line breaks (two trailing spaces or a `\` at the end of the line)
- `**bold**`, `*italic*`, `~~strikethrough~~` and `` `inline code` ``
- Bulleted, numbered and nested lists, task lists (`- [ ]`, `- [x]`)
- Block quotes and horizontal rules
- Fenced (```` ``` ````, `~~~`) and indented code blocks
- Tables, with column alignment
- Links: inline, reference-style, `<https://...>` and bare URLs
- Images from `https` URLs, or embedded as `data:` URLs (PNG, JPEG, GIF, WebP)

Not supported, which matters when you export documents:

- **Raw HTML is shown as text**, including `<br>`, `<details>` and HTML comments
  (`<!-- -->`). Remove or convert them before sending.
- **Relative links and images do not work** (`./other.md`, `images/a.png`): PSST only has
  this one document. Use absolute `https` URLs, or embed images as `data:` URLs. Links to
  other schemes than `http`, `https` and `mailto` are shown as plain text.
- Footnotes, math, Mermaid diagrams and emoji shortcodes appear as their source text
  (Mermaid inside a code block stays a code block).

## Errors

| Status | Meaning | What to do |
| --- | --- | --- |
| `400` | Invalid input; the message names the problem | Fix the request; do not retry unchanged |
| `401` | Missing, invalid or revoked API key | Stop and check the configuration; retrying counts toward the lockout |
| `404` | Unknown action, or the share does not exist (any more) for this key | Treat the share as gone |
| `405` | Wrong HTTP method; the `Allow` header lists the right ones | Fix the request |
| `413` | The request is larger than `max_upload_bytes` | Send a smaller file |
| `429` | Too many failed authentication attempts from this IP address | Wait up to an hour |
| `500` | Server error; the details are in the PSST server log | Retry later; tell the PSST administrator if it persists |

## Integrating it into your application

A typical flow:

1. **Share:** call `create` and store `uuid`, `url` and `expires_at` with the document in
   your application.
2. **Show the link:** display `url` to the user, or send it to the recipient.
3. **Status:** call `share` when you need current numbers. A `404` means the share
   expired, was opened as often as allowed, or was deleted; mark it as no longer
   available in your application.
4. **Unshare:** call `delete` when the user withdraws the share.

`create` is not idempotent. If a request times out, PSST may still have created the
share; a retry then creates a second one with a different link. Both work; delete the
one you do not keep if that matters.

Large documents: the whole request must fit into `max_upload_bytes` from `status`.
Embedded `data:` images count toward it.

### PHP

```php
<?php
function psst_share_markdown(string $title, string $markdown, ?int $maxViews = null): array
{
    $payload = ['type' => 'markdown', 'title' => $title, 'content' => $markdown];
    if ($maxViews !== null) {
        $payload['max_views'] = $maxViews;
    }

    $curl = curl_init('https://psst.example.com/api.php?action=create');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . getenv('PSST_API_KEY'),
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    $data = json_decode((string) $body, true);
    if ($status !== 201) {
        throw new RuntimeException('PSST: ' . ($data['error'] ?? "HTTP $status"));
    }

    return $data['share']; // uuid, url, expires_at, ...
}
```

### JavaScript (Node.js 18 or later)

```js
async function shareMarkdown(title, markdown, maxViews) {
  const response = await fetch('https://psst.example.com/api.php?action=create', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${process.env.PSST_API_KEY}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ type: 'markdown', title, content: markdown, max_views: maxViews }),
  });

  const data = await response.json();
  if (response.status !== 201) {
    throw new Error(`PSST: ${data.error ?? `HTTP ${response.status}`}`);
  }

  return data.share; // uuid, url, expires_at, ...
}
```

Uploading a file from Node.js:

```js
import { readFile } from 'node:fs/promises';

const form = new FormData();
form.append('type', 'file');
form.append('title', 'Wiring diagram');
form.append('file', new Blob([await readFile('diagram.pdf')], { type: 'application/pdf' }), 'diagram.pdf');

const response = await fetch('https://psst.example.com/api.php?action=create', {
  method: 'POST',
  headers: { Authorization: `Bearer ${process.env.PSST_API_KEY}` },
  body: form,
});
```
