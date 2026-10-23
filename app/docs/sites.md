# Building sites for PSST

A **site** is a single HTML file that PSST publishes under a link. It can store data on
the server through `window.psst.storage`, shared by all visitors or separately for each
visitor. Publish a site in the manager (type **Site**) or through the API (see
the API documentation, section *Sites*).

## What runs and what does not

PSST serves the page in a browser sandbox, so its JavaScript cannot reach the manager or
other applications on the same server. Inside the sandbox:

- **Works:** HTML, CSS and JavaScript, `fetch` to other servers, forms, `alert`/`confirm`,
  opening links in new tabs, downloads, inline and `data:` images, scripts and styles
  from CDNs.
- **Does not work:** `localStorage`, `sessionStorage`, IndexedDB, cookies and service
  workers; using them throws an error. Use `psst.storage` instead.
- **One file:** there are no other files next to the page. Put CSS, JavaScript and images
  into the HTML (inline or as `data:` URLs) or load them from absolute `https://` URLs.
- Clipboard, camera or location access can be blocked by the browser in a sandbox.

## Storage modes

The owner chooses the mode per site:

| Mode | Visitors | Owner (manager, API, editor link) | `psst.storage.scope` |
| --- | --- | --- | --- |
| Off | no storage, `window.psst` is not defined | – | – |
| Read-only | read | read and write | `site` |
| Shared | read and write | read and write | `site` |
| Per visitor | read and write their own store only | read and write every store | `visitor` |

In read-only mode the owner opens the **editor link** (shown in the manager) to get
write access in the page itself.

Per-visitor stores are tied to the browser through a cookie that PSST sets; the page
cannot see it. A different browser or device, a private window or deleted cookies start
with an empty store. The owner sets how long a visitor is remembered after the last
visit (0 = until the browser is closed); unused stores are deleted after that time.

## The JavaScript interface

PSST adds `window.psst` before the page's own scripts, so it is available right away.
Every method returns a promise.

| Call | Result |
| --- | --- |
| `psst.storage.get(key)` | The value, or `null` if the key does not exist |
| `psst.storage.getEntry(key)` | `{ value, version }`; version `0` if the key does not exist |
| `psst.storage.set(key, value)` | Stores any JSON value; returns the new version |
| `psst.storage.set(key, value, { version })` | Stores only if the key still has that version |
| `psst.storage.remove(key)` | Deletes the key; returns `true` if it existed |
| `psst.storage.list()` | `[{ key, size, version, updated_at }]` |
| `psst.storage.append(key, item)` | Adds `item` to the list stored under `key` (created if missing); returns `{ length, version }` |
| `psst.storage.increment(key, by = 1)` | Adds `by` to the number under `key` (0 if missing); returns the new number |
| `psst.storage.scope` | `'site'` or `'visitor'` |
| `psst.storage.canWrite` | Whether this page may change data |

Keys are text of up to 200 characters. Values are anything JSON can hold: text, numbers,
`true`/`false`, `null`, lists and objects.

### Several visitors at once

`set` replaces the value; if two visitors change the same key at the same time, the
later change wins. For lists and counters use `append` and `increment`: the server does
them in one step, so no entry gets lost. To change a stored object safely, read its
version and pass it back:

```js
const { value: board, version } = await psst.storage.getEntry('board');
board.columns.push('Done');
try {
  await psst.storage.set('board', board, { version });
} catch (error) {
  if (error.status === 409) {
    // Someone else changed it meanwhile: load again and redo the change.
  }
}
```

### Errors

Failed calls reject with an `Error` whose `status` is the HTTP status:

| `status` | Meaning |
| --- | --- |
| `400` | Invalid key or value |
| `401` | The page's access token expired (after 12 hours); reload the page |
| `403` | The page may only read, or the owner turned storage off |
| `404` | The site was deleted or expired |
| `409` | Version conflict, or `append`/`increment` on a value that is not a list or number |
| `413` | A value is larger than 256 KB, or the storage is full |
| `429` | More than {{MAX_WRITES}} changes per minute from this visitor |

## Limits

| Limit | Value on this server |
| --- | --- |
| HTML file | {{MAX_SITE}} |
| One value | 256 KB |
| Keys per store | 1,000 |
| Changes per visitor and minute | {{MAX_WRITES}} |

The storage limits per site (shared store, each visitor, all visitors together) are set by
the owner; the defaults are shown in the manager.

## Developing locally

Opened as a local file, the page has no `window.psst`. A small wrapper keeps it working
with `localStorage` during development:

```js
const store = window.psst ? window.psst.storage : {
  async get(key) { const raw = localStorage.getItem(key); return raw === null ? null : JSON.parse(raw); },
  async set(key, value) { localStorage.setItem(key, JSON.stringify(value)); return 1; },
  async remove(key) { const had = localStorage.getItem(key) !== null; localStorage.removeItem(key); return had; },
};
```

## Example: a shared checklist

With storage mode **Shared**, every visitor sees and ticks the same items:

```html
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Team checklist</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 32rem; margin: 2rem auto; padding: 0 1rem; }
    li { list-style: none; margin: .4rem 0; }
  </style>
</head>
<body>
  <h1>Team checklist</h1>
  <ul id="items"></ul>
  <form id="add"><input id="text" placeholder="New item" required> <button>Add</button></form>
  <script>
    const list = document.getElementById('items');

    async function render() {
      const items = (await psst.storage.get('items')) || [];
      list.innerHTML = '';
      items.forEach((item, index) => {
        const row = document.createElement('li');
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.checked = item.done;
        box.disabled = !psst.storage.canWrite;
        box.addEventListener('change', () => toggle(index));
        row.append(box, ' ', item.text);
        list.append(row);
      });
    }

    async function toggle(index) {
      const { value, version } = await psst.storage.getEntry('items');
      value[index].done = !value[index].done;
      try {
        await psst.storage.set('items', value, { version });
      } catch (error) {
        if (error.status !== 409) throw error;
      }
      render();
    }

    document.getElementById('add').addEventListener('submit', async (event) => {
      event.preventDefault();
      const text = document.getElementById('text');
      await psst.storage.append('items', { text: text.value, done: false });
      text.value = '';
      render();
    });

    render();
    setInterval(render, 10000); // pick up changes of other visitors
  </script>
</body>
</html>
```

With storage mode **Per visitor**, the same file becomes a personal checklist for every
visitor, without any change.

## Instructions for AI assistants

When an AI assistant writes a site for PSST, add this to the request:

```text
The page will be published as a single HTML file on PSST and runs in a sandboxed
browser context. Do not use localStorage, sessionStorage, IndexedDB or cookies; they
throw errors there. Keep everything in one file. For data that must be saved, use
window.psst.storage, which PSST provides (all methods return promises):
get(key), getEntry(key) -> {value, version}, set(key, value, {version}?),
remove(key), list(), append(key, item), increment(key, by), and the properties
scope ('site' or 'visitor') and canWrite. Values are JSON. Use append/increment for
lists and counters that several visitors change. Failed calls throw an Error with a
status property (409 = changed meanwhile, 413 = storage full). If window.psst is
undefined (opened locally), fall back to an in-memory or localStorage store.
```
