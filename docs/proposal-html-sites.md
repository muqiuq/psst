# Proposal: publishing single-file HTML sites with server-side storage

Status: phases 1 and 2 implemented in PSST 1.4.0, with the recommended answers to the
decisions below. Phase 3 is open. · 2026-10-23

## Goal

Publish a single HTML file through PSST as a small website or web app, under a PSST
link, and give it a little server-side storage: either one store that all visitors
share, or a separate store for every visitor. Everything the manager can do with sites
is also available through the API, so other applications such as PLM can publish sites
and work with their data.

Examples:

- A team checklist or to-do list that several people tick off (shared)
- A poll, a sign-up list or a guestbook (shared or collect)
- A personal checklist, a quiz that remembers progress, a tool that keeps each user's
  settings (per visitor)
- A small dashboard whose numbers the owner or another application updates (read-only)
- Single-file tools generated with an AI assistant, published with one click

## Out of scope

- Server-side code. Sites are HTML, CSS and JavaScript running in the browser; PSST only
  stores and serves the file and the data.
- Sites made of several files. Images, styles and scripts must be inside the HTML file
  (inline, or as `data:` URLs) or loaded from other servers.
- User accounts and logins inside a site. Per-visitor storage recognizes a browser, not a
  person.
- Real-time updates. Pages that want fresh data ask again every few seconds.

## Overview

```text
 Manager / api.php ──publish──► PSST stores the HTML file (new share type "site")

 Visitor ──link──► index.php?id=UUID
                     │  PSST recognizes the visitor by its own cookie (per-visitor mode),
                     │  serves the HTML in a browser sandbox and adds a small script that
                     │  defines window.psst.storage with a signed access token
                     ▼
                  The site's JavaScript ──fetch──► storage.php ──► JSON store for this site,
                                                                   shared or per visitor

 Manager / api.php ──read, change, export──► the same stores
```

## Publishing

A new share type **Site**:

- Upload an `.html` file or paste HTML, in the manager or through the API. The same
  request sets storage mode, quotas and the other site options.
- Replacing the HTML keeps the link and the stored data, so a site can be updated without
  sending a new link.
- Expiry and secret codes work as for other shares. View limits do not fit: every reload
  would count as a view, so they are not offered for sites.
- Browser encryption is not available: PSST has to add its storage script to the page.

## Isolation: the main security question

Sites contain arbitrary JavaScript. If PSST served such a page as a normal page from its
own address, that script would run with the same rights as PSST itself:

- It could call the manager API with the admin's session if the admin opens the site in
  the same browser, and create, read or delete any share.
- It could read `localStorage` and cookies of other applications on the same host. When
  PSST runs in a subfolder next to other apps on one domain, they share one origin, and a
  published page could, for example, read another app's access token from
  `localStorage`.

### Proposal: serve sites in a browser sandbox

PSST serves the HTML with the header

```http
Content-Security-Policy: sandbox allow-scripts allow-forms allow-modals allow-popups allow-popups-to-escape-sandbox allow-downloads
```

`allow-same-origin` is deliberately missing. The browser then runs the page under an
anonymous origin of its own:

- The page cannot use PSST's cookies or session, so it cannot reach the manager.
- It cannot read `localStorage`, cookies or IndexedDB of PSST or of other apps on the host.
- Scripts, forms, `alert`/`confirm`, opening links in new tabs and downloads still work.

The price: the page itself has no `localStorage`, `sessionStorage`, IndexedDB, cookies or
service workers either; using them throws an error. That is exactly what the PSST
storage replaces. Existing single-file apps that keep their data in `localStorage`
need a small change, or the compatibility option below. Some browser features
(clipboard access, geolocation, camera) may also be limited in a sandbox; that needs a
test in the browsers you care about.

PSST already sends `Content-Security-Policy: sandbox` for shared files; sites would use a
less strict variant of the same mechanism.

### Alternative: a separate host for sites

For full browser features, sites could be served from a host name of their own, for
example `sites.example.com`, pointing to the same PSST installation. There, pages can
run with `allow-same-origin`, so `localStorage` works normally, while the manager and
other apps on the main host stay out of reach. This needs a DNS entry, an extra web
server configuration and HTTPS for that name, and PSST must refuse manager requests on
the sites host. Proposed as an optional setting (`sites_base_url`) for later, with the
sandbox as the default that works without any server setup.

## Storage

### Model

Each site gets JSON key/value storage:

- Keys are short strings (`todos`, `poll/answers`, `settings`).
- Values are JSON: text, numbers, lists, objects.
- Each key has a version number that increases with every change.
- Depending on the mode, there is one store for the site, or one store per visitor.
- Quotas limit the size; the manager and the API show how much is used, as for drop
  boxes.
- Deleting the site, or its expiry, deletes all of its storage.

### Storage modes

Per site, one of these modes:

| Mode | Visitors with the link | Owner (manager, API, editor link) | Typical use |
| --- | --- | --- | --- |
| Off (default) | no storage | no storage | Static pages and tools |
| Read-only | read | read and write | Dashboard the owner or PLM updates |
| Shared | read and write | read and write | Team checklist, poll, guestbook |
| Collect | add entries only | read and write | Sign-up or feedback form whose answers only the owner sees |
| Per visitor (session) | read and write their own store only; never see other visitors' data | read, change and delete every visitor's store | Personal checklist, quiz progress, settings per user, existing `localStorage` apps |

The **editor link** is the normal link plus a second random key
(`index.php?id=UUID&edit=KEY`). The owner opens it to get write access in read-only mode
and read access in collect mode. The manager and the API return it with the site. In
per-visitor mode it is not needed.

For the shared modes, anyone with a link has that link's rights. As with other shares,
the link is the secret; a secret code adds a second factor.

Later, a site could combine a per-visitor store with a shared one, for example a poll
that remembers "my vote" per visitor and keeps the tally shared. That would add a second
object (`psst.visitor` next to `psst.storage`); it is not part of the first version.

### Per-visitor storage (session)

A sandboxed page cannot keep cookies or `localStorage`, so PSST recognizes visitors
itself:

- When a visitor opens the site, PSST sets a cookie on its own address (`HttpOnly`,
  `Secure` on HTTPS, `SameSite=Lax`). The page's own JavaScript cannot read it.
- From that cookie, PSST derives a visitor ID for this site and puts it into the page's
  signed access token. Storage requests carry only the token, so the sandbox is no
  obstacle. The derived ID differs per site, so visitor stores of different sites cannot
  be linked to each other in the database.
- On the next visit with the same browser, the visitor gets the same store back.
- How long a visitor is remembered is set per site: until the browser is closed (a true
  session), or for a number of days (default 90).
- A different browser or device, a private window or deleted cookies mean a new, empty
  store. A later option could offer a "continue on another device" link that carries
  the visitor key.
- Visitor stores that were not used for longer than the remembered time are deleted
  automatically, so abandoned data does not pile up.

The owner sees every visitor store in the manager and through the API, as an anonymous ID
with first and last visit and size, and can read, change, export or delete it. That makes
results such as quiz answers available to the owner, and it means the stored data is
personal data of the visitors: a site that stores more than settings should say so.

### How the page gets access

When PSST serves the page, it adds a small script before the site's own code. It defines
`window.psst.storage` and carries a **signed access token**: site ID, rights (read,
write, add), the visitor ID in per-visitor mode, and an expiry of a few hours, signed
with a server key (HMAC). The storage endpoint checks the signature and does not need
cookies, which a sandboxed page cannot send anyway. This also makes secret codes work:
the visitor enters the code on the PSST page before the site loads, and only then gets a
token. A page left open longer than the token lifetime gets a fresh token on the next
load.

The storage endpoint answers with `Access-Control-Allow-Origin: *`, because requests from
a sandboxed page come from an anonymous origin. Since access depends only on the token,
not on cookies, that is safe.

### JavaScript interface for site authors

The same calls work in every mode; the mode decides which store they reach.

```js
await psst.storage.get('todos');                  // value, or null
await psst.storage.set('todos', todos);           // replace the value
await psst.storage.set('todos', todos, { version: 7 }); // only if nobody changed it since version 7
await psst.storage.remove('todos');
await psst.storage.list();                        // [{ key, size, version, updated_at }]
await psst.storage.append('guestbook', entry);    // add to a list, safe with many visitors
await psst.storage.increment('visits');           // counter, safe with many visitors
psst.storage.scope;                               // 'site' or 'visitor'
psst.storage.canWrite;                            // what this link may do
```

- Last write wins, unless `set` passes the version it read; then a concurrent change
  answers with a conflict and the page reloads the value. `append` and `increment` are
  done by the server in one step, so polls and guestbooks do not lose entries.
- Opened as a local file during development, `window.psst` does not exist; a site can then
  fall back to `localStorage`. The reference documentation would include that pattern.

### Compatibility option: localStorage backed by PSST

To run existing apps unchanged, the added script could provide its own `localStorage`
that loads all values from PSST storage when the page opens and writes changes back.
This fits the per-visitor mode best: each visitor keeps personal data as before, and it
now survives on the server even though the page runs in a sandbox. In the shared modes,
all visitors would share one "localStorage", which suits a team checklist but not an app
that expects personal data. Replacing `localStorage` in a sandboxed page needs testing
in all major browsers, so this is planned for phase 3, after that test.

### Limits and abuse protection

| Limit | Proposed default | Setting |
| --- | --- | --- |
| HTML file size | 5 MB | `max_site_bytes` |
| Shared storage per site | 1 MB | per site, default `default_site_storage_bytes` |
| Storage per visitor | 256 KB | per site, default `default_visitor_storage_bytes` |
| All visitor stores of a site together | 20 MB | per site, default `default_site_visitor_total_bytes` |
| Visitor remembered for | 90 days (or until the browser closes) | per site |
| Size of one value | 256 KB | fixed |
| Keys per store | 1,000 | fixed |
| Writes per IP and site | 60 per minute | `max_site_writes_per_minute` |
| Access token lifetime | 12 hours | fixed |

- Storage is off by default; the owner turns it on per site.
- When the total for visitor stores is reached, new visitors can still open the site but
  cannot save; existing visitors keep their data.
- Only the manager and API keys can publish sites. Visitors and drop boxes cannot, so
  nobody can use PSST to host arbitrary pages without access.
- Site pages get `noindex`, like all PSST pages.
- Storage is not encrypted. Sites should not store passwords or sensitive personal data;
  the manager form says so.

### Privacy: requests to other servers

By default a site can load scripts, fonts and images from anywhere and send data
anywhere, like any web page. A per-site switch **Block external requests** would add a
policy that only allows the PSST storage endpoint and inline or `data:` content. That
suits sites whose visitors should not be tracked, but breaks sites that load libraries
from a CDN.

## API

Everything the manager can do with sites is also available through `api.php`, with the
same API keys and the same rule as today: a key only sees the sites it created. API
access counts as the owner, so it may read and write in every mode, including all visitor
stores.

| Action | Method | What it does |
| --- | --- | --- |
| `create` with `type: "site"` | POST | Publish: HTML as `content` or uploaded `file`, plus `storage_mode`, quotas, `visitor_lifetime_days`, `block_external`, `expires_at`, `secret_code` |
| `update` | POST | Replace the HTML and/or change settings; link and data stay. New, and available for all share types |
| `share` | GET | Details: link, editor link, mode, storage usage, number of visitor stores |
| `delete` | POST, DELETE | Delete the site with all its storage |
| `storage_list` | GET | Keys of the shared store, or of one visitor store (`visitor=ID`) |
| `storage_get` | GET | One value with its version |
| `storage_set` | POST | Write one value, optionally only if the version matches |
| `storage_remove` | POST | Delete one key |
| `storage_append`, `storage_increment` | POST | The same atomic operations as in the page |
| `storage_export` | GET | Everything as one JSON document: shared store and all visitor stores |
| `storage_import` | POST | Restore or prefill from such a document |
| `storage_clear` | POST | Delete all data, or only one visitor's |
| `visitors` | GET | Visitor stores: anonymous ID, first and last visit, size |

Examples: PLM publishes a dashboard site in read-only mode and pushes new numbers with
`storage_set`; or it publishes a quiz in per-visitor mode and collects the answers later
with `storage_export`.

The in-app API documentation and `docs/api.md` are extended with each phase.

## Manager

- Type **Site** in the share form: upload or paste HTML, storage mode, quotas, how long
  visitors are remembered, block external requests.
- After publishing: the link, the editor link (if the mode has one), QR code.
- A **Storage** view per site: in the shared modes, the keys with size and last change,
  view and edit a value as JSON, delete a key; in per-visitor mode, the list of visitor
  stores, each with the same views. Export everything as JSON, import, clear. Usage bars
  against the quotas.
- The list shows a "Site" badge, storage mode and usage.

## Data model sketch

- `shares`: new type `site`; new columns for storage mode, quotas, visitor lifetime, hash
  of the editor key, and the block-external-requests switch. The HTML file is stored in
  `data/files` like file shares.
- New table `site_storage`: site, visitor ID (empty for the shared store), key, value
  (JSON text), version, size, last change, IP address of the last writer. Unique per
  site, visitor and key.
- New table `site_visitors`: site, visitor ID, first visit, last visit, size. Used for
  the visitor list, the quotas and the automatic cleanup.
- A server key for signing access tokens and deriving visitor IDs, generated once and
  kept in `data/`, so no configuration is needed.

## Phases

Every phase delivers the manager and the API together.

1. **Publish sites:** share type, sandboxed serving, manager form, replace HTML; in the
   API `create`, `share`, `delete` for sites and the new `update` for all share types.
   Useful on its own for static pages and tools without storage.
2. **Storage:** storage endpoint, JavaScript interface, the modes off, read-only, shared
   and per visitor, editor link, quotas, rate limits, cleanup of old visitor stores, the
   manager's storage view, all `storage_*` and `visitors` API actions, reference
   documentation for site authors.
3. **Extras, each optional:** collect mode, the `localStorage` compatibility option, a
   per-visitor plus shared combination, "continue on another device" links, the
   separate sites host, block external requests.

## Decisions needed

| # | Question | Recommendation |
| --- | --- | --- |
| 1 | Sandbox, separate host, or both? | Sandbox now; separate host as a later option |
| 2 | Which storage modes in phase 2? | Off, read-only, shared and per visitor; collect later |
| 3 | Per-visitor storage: until the browser closes, or remembered for some days? | Remembered, 90 days by default: phones end browser sessions often and unpredictably |
| 4 | May the owner and the API read visitor stores? | Yes, needed for quizzes and forms; the manager form reminds the owner to tell visitors |
| 5 | Is the editor link wanted, or are the manager and the API enough to change data? | Editor link: owners can update a dashboard from any device |
| 6 | Should API keys also see sites created in the manager? | No, keep today's rule (a key sees only its own shares) |
| 7 | Default quotas | 5 MB HTML, 1 MB shared storage, 256 KB per visitor, 20 MB for all visitors of a site |
| 8 | Block external requests: default on or off? | Off; on breaks many existing pages |

## Risks

- **Abuse of a leaked link:** in the shared modes, anyone with the link can change or
  delete the data. Mitigations: quotas, write limits, version numbers, export in the
  manager and API; possibly a simple undo (keeping the previous value of each key) later.
  Per-visitor stores are not affected: a link only reaches the visitor's own store.
- **Many visitors:** each visitor creates a store. The total quota per site, the write
  limits and the automatic cleanup keep that bounded; a site that runs full stops saving
  for new visitors instead of growing.
- **Personal data:** per-visitor stores may hold personal data that the owner and API
  keys can read. Sites should be honest about it, and data is deleted with the site, on
  expiry, or after the inactivity period.
- **Browser differences in the sandbox:** features that existing apps use may be blocked.
  Phase 1 includes testing a few typical single-file apps in Chrome, Firefox and Safari.
- **Phishing:** pages under your domain look trustworthy. Only the admin and API keys
  can publish, and every site shows its PSST address in the browser's address bar, but
  a compromised manager or API key could publish a fake login page. Revoking keys and
  deleting sites must stay quick.
- **Growth:** storage lives in the same SQLite file as everything else. With the proposed
  quotas that stays small, but many sites with many visitors would make backups bigger.
