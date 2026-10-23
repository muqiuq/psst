# PSST API

PSST {{VERSION}} · Endpoint: `{{BASE_URL}}/api.php`

The API lets another application, for example PLM, share content through PSST: it
creates a share and gets back the link to send to the recipient. Shares created through
the API appear in the manager with an **API** badge naming the key. You can edit or
delete them there like any other share.

Each API key can only read and delete the shares it created itself.

## Authentication

1. In the manager, open **API** and create a key with a name, for example `PLM`.
   The key is shown once. Store it in the other application's configuration.
2. Send the key with every request in the `Authorization` header:

```http
Authorization: Bearer psst_...
```

If a proxy in between strips the `Authorization` header, send `X-API-Key: psst_...`
instead.

- Never put the key in the URL: URLs end up in access logs.
- Use HTTPS. Over plain HTTP, anyone on the network path can read the key.
- After {{MAX_FAILED}} failed attempts within an hour, requests from that IP address are
  refused with `429` until the hour is over.
- To revoke a key, open **API** in the manager and click **Revoke**. Requests with it fail
  with `401` right away; the shares it created stay online.

PSST stores only a SHA-256 hash of each key, so a lost key cannot be shown again.
Create a new one and revoke the old one.

## Requests and responses

- Choose the action with the `action` query parameter: `api.php?action=create`.
- Send request bodies as JSON (`Content-Type: application/json`) or as
  `multipart/form-data`. Files need `multipart/form-data`.
- Every response is JSON. Errors look like `{"error": "Share not found."}`, with an HTTP
  status code as listed under [Errors](#errors).

## Check the connection

`GET api.php?action=status`

```sh
curl -H "Authorization: Bearer $PSST_KEY" "{{BASE_URL}}/api.php?action=status"
```

```json
{
  "ok": true,
  "key": "PLM",
  "app_version": "{{VERSION}}",
  "max_upload_bytes": 104857600,
  "share_types": ["markdown", "text", "link", "file", "site"]
}
```

## Create a share

`POST api.php?action=create`

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `type` | string | yes | `markdown`, `text`, `link`, `file` or `site` (see [Sites](#sites)) |
| `content` | string | for `markdown`, `text`, `link`; or `site` | The Markdown source, the text, the URL, or the site's HTML |
| `file` | file | for `file`; or `site` | The file, as a `multipart/form-data` field |
| `title` | string | no | Shown on the share page, up to 160 characters. For Markdown it is also the name of the downloaded `.md` file |
| `expires_at` | string or number | no | When the share is deleted: ISO 8601 (`2026-12-31T23:00:00Z`) or a Unix timestamp. Must be in the future |
| `max_views` | integer | no | Delete the share after this many views. `1` means burn after reading |
| `secret_code` | string | no | Visitors must enter this code before the share opens |

A Markdown document:

```sh
curl -X POST "{{BASE_URL}}/api.php?action=create" \
  -H "Authorization: Bearer $PSST_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "type": "markdown",
    "title": "Release notes 2.4",
    "content": "# Release notes\n\n- Faster sync\n- New export",
    "expires_at": "2026-12-31T23:00:00Z",
    "max_views": 5
  }'
```

A file:

```sh
curl -X POST "{{BASE_URL}}/api.php?action=create" \
  -H "Authorization: Bearer $PSST_KEY" \
  -F type=file \
  -F title="Wiring diagram" \
  -F "file=@diagram.pdf"
```

The response has status `201`:

```json
{
  "share": {
    "uuid": "6f1d0b8e-3c1a-4f7e-9a59-2f5b8c0d4e21",
    "type": "markdown",
    "title": "Release notes 2.4",
    "url": "{{BASE_URL}}/index.php?id=6f1d0b8e-3c1a-4f7e-9a59-2f5b8c0d4e21",
    "download_url": "{{BASE_URL}}/index.php?id=6f1d0b8e-3c1a-4f7e-9a59-2f5b8c0d4e21&download=1",
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

Send `url` to the recipient. For Markdown, that page shows the rendered document with
buttons to copy or download the source. `download_url` downloads the raw file and also
counts as a view. For a share with a view limit it first shows the same click-to-open
page as `url`.

A share with a secret code asks for it on the share page. To skip the prompt, append it
to the link: `&_secretCode=CODE`. Anyone with that link can then open the share.

Shares with `max_views` open only after the visitor clicks a button, so link previews
in chat apps do not use up views.

## Get a share

`GET api.php?action=share&uuid=UUID`

Returns `{"share": {...}}` with the same fields as above, for example to check
`view_count` or `views_remaining`. Shares that expired, used up their views, were
deleted, or belong to another key answer `404`.

```sh
curl -H "Authorization: Bearer $PSST_KEY" "{{BASE_URL}}/api.php?action=share&uuid=UUID"
```

## Delete a share

`POST` or `DELETE api.php?action=delete&uuid=UUID`

Deletes the share, its file and, for sites, all stored data right away. Returns
`{"deleted": true}`.

```sh
curl -X DELETE -H "Authorization: Bearer $PSST_KEY" "{{BASE_URL}}/api.php?action=delete&uuid=UUID"
```

## Update a share

`POST api.php?action=update&uuid=UUID`

Changes a share of this key. Send only the fields to change, with the same names as for
`create`; everything else stays, including the link. A new `file` (or, for sites, new
`content`) replaces the stored one.

```sh
curl -X POST "{{BASE_URL}}/api.php?action=update&uuid=UUID" \
  -H "Authorization: Bearer $PSST_KEY" \
  -H "Content-Type: application/json" \
  -d '{"title": "Release notes 2.4.1", "max_views": 10}'
```

## Share fields

| Field | Description |
| --- | --- |
| `uuid` | The share's ID, used by `share` and `delete` |
| `type` | `markdown`, `text`, `link`, `file` or `site` |
| `title` | The title, or an empty string |
| `url` | The link for the recipient |
| `download_url` | Direct download for `file` and `markdown`, otherwise `null` |
| `file_name` | Original name of an uploaded file, otherwise `null` |
| `size` | Size in bytes of the file or content |
| `mime_type` | Content type PSST serves |
| `has_secret_code` | Whether this share has its own secret code |
| `requires_secret_code` | Whether visitors must enter a code (its own or the server-wide one) |
| `expires_at` | Deletion time in UTC (ISO 8601), or `null` |
| `max_views` | View limit, or `null` for unlimited |
| `view_count` | How often the share was opened |
| `views_remaining` | Views left, or `null` for unlimited |
| `created_at` | Creation time in UTC (ISO 8601) |
| `site` | For sites, the site details (see [Sites](#sites)), otherwise `null` |

## Sites

A site is a single HTML file that PSST serves as a web page, optionally with storage on
the server. The page itself uses `window.psst.storage`, described in *Building sites* (link at the top
of this page).

Create a site with `"type": "site"` and the HTML as `content`, or upload it as `file`.
Sites take these additional fields in `create` and `update`:

| Field | Type | Description |
| --- | --- | --- |
| `storage_mode` | string | `off` (default), `read` (visitors read, the editor link writes), `shared` (all visitors read and write) or `visitor` (each visitor has a store of their own) |
| `storage_quota_bytes` | integer or empty | Limit of the shared store, by default {{SITE_STORAGE}}. Empty for no limit |
| `visitor_quota_bytes` | integer or empty | Limit of each visitor store, by default {{VISITOR_STORAGE}} |
| `visitor_total_bytes` | integer or empty | Limit of all visitor stores together, by default {{VISITOR_TOTAL}} |
| `visitor_lifetime_days` | integer | How long a visitor is remembered after the last visit, by default {{VISITOR_DAYS}}. `0` means until the browser is closed |

Expiry and secret codes work as for other shares. `max_views` does not apply to sites
(every reload would count), and sites cannot be encrypted. Deleting a site, or its
expiry, deletes all of its stored data.

```sh
curl -X POST "{{BASE_URL}}/api.php?action=create" \
  -H "Authorization: Bearer $PSST_KEY" \
  -F type=site \
  -F title="Team checklist" \
  -F storage_mode=shared \
  -F "file=@checklist.html;type=text/html"
```

The share of a site has a `site` object:

```json
"site": {
  "file_name": "checklist.html",
  "storage_mode": "shared",
  "editor_url": "{{BASE_URL}}/index.php?id=UUID&edit=KEY",
  "storage_quota_bytes": 1048576,
  "visitor_quota_bytes": 262144,
  "visitor_total_bytes": 20971520,
  "visitor_lifetime_days": 90,
  "storage_used_bytes": 512,
  "visitor_used_bytes": 0,
  "visitor_count": 0
}
```

`editor_url` opens the site with write access in read-only mode; keep it private.
Replacing the HTML with `update` keeps the link, the editor link and the stored data.

### Site storage

The owner, here the API key that created the site, can read and change every store of
the site, whatever its mode. Without `visitor`, an action uses the shared store; with
`visitor=ID` (IDs come from `visitors`) it uses that visitor's store. The storage limits
apply. Request bodies are JSON (`Content-Type: application/json`).

| Action | Method | Parameters and body | Result |
| --- | --- | --- | --- |
| `storage_list` | GET | `uuid`, optional `visitor` | `{"items": [{"key", "size", "version", "updated_at"}]}` |
| `storage_get` | GET | `uuid`, `key`, optional `visitor` | `{"key", "value", "version", "exists"}`; version `0` if the key does not exist |
| `storage_set` | POST | `uuid`, optional `visitor`; body `{"key", "value", "version"}` | `{"key", "version"}`. With `version`, only if the key still has it; otherwise `409` |
| `storage_remove` | POST | `uuid`, optional `visitor`; body `{"key", "version"}` | `{"key", "removed"}` |
| `storage_append` | POST | `uuid`, optional `visitor`; body `{"key", "item"}` | `{"key", "version", "length"}`; adds to a list, created if missing |
| `storage_increment` | POST | `uuid`, optional `visitor`; body `{"key", "by"}` | `{"key", "value", "version"}`; `by` defaults to 1 |
| `visitors` | GET | `uuid` | `{"visitors": [{"id", "first_seen", "last_seen", "size", "keys"}]}` |
| `storage_export` | GET | `uuid` | All data: `{"site", "exported_at", "shared": {...}, "visitors": {...}}` |
| `storage_import` | POST | `uuid`, optional `replace=1`; body: an export document | `{"imported"}`. Merges (same keys are overwritten); `replace=1` deletes everything first |
| `storage_clear` | POST | `uuid`, optional `visitor` | `{"cleared": true}`. Deletes that visitor's store, or without `visitor` all data |

Values are any JSON up to 256 KB, keys up to 200 characters, at most 1,000 keys per
store. A failing import changes nothing.

A dashboard in read-only mode gets new numbers:

```sh
curl -X POST "{{BASE_URL}}/api.php?action=storage_set&uuid=UUID" \
  -H "Authorization: Bearer $PSST_KEY" \
  -H "Content-Type: application/json" \
  -d '{"key": "stats", "value": {"open": 12, "done": 30}}'
```

All answers of a quiz in per-visitor mode:

```sh
curl -H "Authorization: Bearer $PSST_KEY" "{{BASE_URL}}/api.php?action=storage_export&uuid=UUID"
```

## Markdown support

PSST renders a common subset of Markdown on the server:

- Headings (`#` to `######`, or underlined with `===` / `---`)
- Paragraphs, line breaks (two trailing spaces or `\` at the end of a line)
- **Bold**, *italic*, ~~strikethrough~~ and `inline code`
- Bulleted, numbered and nested lists, task lists (`- [ ]`, `- [x]`)
- Block quotes, horizontal rules
- Fenced code blocks (```` ``` ```` or `~~~`) and indented code blocks
- Tables with column alignment
- Links (`[text](https://...)`, reference links, bare URLs), links to headings
  (`[see setup](#setup)`) and images

For security, raw HTML is shown as text, links only work for `http`, `https`, `mailto`
and headings on the same page, and images must be `https` URLs or embedded PNG, JPEG,
GIF or WebP `data:` URLs. Markdown shares cannot be encrypted.

## Errors

| Status | Meaning |
| --- | --- |
| `400` | Invalid input; the message says which field |
| `401` | Missing, invalid or revoked API key |
| `404` | Unknown action, or the share does not exist (any more) for this key |
| `405` | Wrong HTTP method for the action |
| `409` | Site storage: the version did not match, or `append`/`increment` hit a value that is not a list or number |
| `413` | The upload is larger than {{MAX_UPLOAD}}, or a site's storage is full |
| `429` | Too many failed authentication attempts from this IP address |
| `500` | Server error; the details are in the PSST server log |
