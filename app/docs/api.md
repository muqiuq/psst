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
  "share_types": ["markdown", "text", "link", "file"]
}
```

## Create a share

`POST api.php?action=create`

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `type` | string | yes | `markdown`, `text`, `link` or `file` |
| `content` | string | for `markdown`, `text`, `link` | The Markdown source, the text, or the URL |
| `file` | file | for `file` | The file, as a `multipart/form-data` field |
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

Deletes the share and its file right away. Returns `{"deleted": true}`.

```sh
curl -X DELETE -H "Authorization: Bearer $PSST_KEY" "{{BASE_URL}}/api.php?action=delete&uuid=UUID"
```

## Share fields

| Field | Description |
| --- | --- |
| `uuid` | The share's ID, used by `share` and `delete` |
| `type` | `markdown`, `text`, `link` or `file` |
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
| `413` | The upload is larger than {{MAX_UPLOAD}} |
| `429` | Too many failed authentication attempts from this IP address |
| `500` | Server error; the details are in the PSST server log |
