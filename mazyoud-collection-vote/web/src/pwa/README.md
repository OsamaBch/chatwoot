# PWA assets

Vite serves files from `web/public/` at the site root, which is where the
service worker and manifest must live to control the whole scope. So the actual
PWA files are:

- `web/public/manifest.webmanifest` — app manifest (linked from `index.html`)
- `web/public/sw.js` — service worker (registered in `src/main.tsx`)
- `web/public/icon.svg` — app/maskable icon

The registration call lives in [`../main.tsx`](../main.tsx). The service worker
precaches the app shell, serves proxied images cache-first, and never caches the
API. PWA support is a progressive enhancement — the app works fully without it.
