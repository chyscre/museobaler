/* Museo de Baler — Visitor App  |  sw.js
   ═══════════════════════════════════════════════════════════

   The service worker: what keeps the tour going where the museum's stone
   walls eat the Wi-Fi.

   Three caches, three rules, chosen so that nothing here can ever show a
   visitor something the server would not:

   SHELL   the app itself - index.html, the CSS and JS, the icons, the
           TensorFlow libraries and the recognition model. Served from the
           cache at once and refreshed in the background on every use
           (stale-while-revalidate), so a phone opens the app instantly and
           picks up a new release on its next visit. The cache name carries
           VERSION; a new release installs into a fresh cache and the old
           one is deleted on activation. deploy.sh stamps VERSION with the
           release hash.

   MEDIA   exhibit pictures and audio guides under /images and /audio.
           Cache-first: the file names are timestamped by the panel, so a
           cached copy is never stale, and a five-megabyte audio guide is
           fetched once per phone.

   API     the museum's content behind the admission gate - the exhibit
           list, single exhibits, the museum record, and the visitor's own
           clearance. Network-first: the live answer whenever there is one,
           the last good answer only when the network fails outright. That
           is the "signal dropped in Hall B" case, and nothing else: a 401
           or 403 from the server empties this cache, and so does signing
           out, so a phone that is no longer cleared has nothing to fall
           back on. Everything else on the API - sign-in, registration,
           scans, feedback, attendance - is never cached and never served
           from here; if the network is down the page hears about it and
           shows the offline notice.

   The exhibit list arrives with an ETag and no-cache. The fetch() below
   goes through the browser's HTTP cache, which sends If-None-Match and
   turns a 304 into the full response before this code sees it, so the
   304 path from Phase 2 keeps working unchanged underneath this. */

'use strict';

const VERSION = 'dev';   // deploy.sh replaces this with the release hash

const SHELL_CACHE = 'museobaler-shell-' + VERSION;
const MEDIA_CACHE = 'museobaler-media';
const API_CACHE   = 'museobaler-api';

// Resolved against this file's URL (/visitor/sw.js), so '../js/...' is /js/....
// Anything here that fails to fetch fails the install: the app cannot run
// without it, so a half-installed worker would be worse than none.
const SHELL_REQUIRED = [
  './',
  './index.html',
  './css/app.css',
  './js/app.js',
  './js/viewport.js',
  './manifest.json',
  './icons/icon-192.png',
  './icons/icon-512.png',
  './icons/icon-maskable-512.png',
  './icons/apple-touch-icon.png',
  '../js/vendor/tf.min.js',
  '../js/vendor/teachablemachine-image.min.js',
  '../js/vendor/html5-qrcode.min.js',
];

// The recognition model is written by the admin panel and may not exist
// on a fresh install; the app falls back to the server-side matcher
// without it. Cached when present, skipped when not.
const SHELL_OPTIONAL = [
  './model/model.json',
  './model/weights.bin',
  './model/metadata.json',
];

// API paths whose last good answer may stand in for a dead network.
const API_CACHEABLE = /^\/api\/v1\/(exhibits(\/[^/]+)?|museum|visitors\/me)$/;

const OWN = self.location.origin;

// -- Install: fill the shell cache -------------------------------------------

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(SHELL_CACHE);
    await cache.addAll(SHELL_REQUIRED);
    await Promise.all(SHELL_OPTIONAL.map((url) => cache.add(url).catch(() => {})));
    // Take over from the previous worker without waiting for every tab to
    // close. The shell is stale-while-revalidate, so a page that is already
    // open simply gets the new files on its next load.
    await self.skipWaiting();
  })());
});

// -- Activate: drop every shell cache but this version's -------------------

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys
      .filter((k) => k.startsWith('museobaler-shell-') && k !== SHELL_CACHE)
      .map((k) => caches.delete(k)));
    await self.clients.claim();
  })());
});

// -- Messages from the page ------------------------------------------------

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'clear-api-cache') {
    event.waitUntil(caches.delete(API_CACHE));
  }
});

// -- Routing ----------------------------------------------------------------

/**
 * Which rule a request falls under. Pure, so it can be tested on its own.
 *
 * @param {string} method
 * @param {string} urlString
 * @returns {'shell'|'media'|'api'|'scan-redirect'|'network'}
 */
function routeFor(method, urlString) {
  if (method !== 'GET') return 'network';

  const url = new URL(urlString);

  if (url.origin !== OWN) {
    // Google Fonts and the like: part of the shell in practice, and an
    // opaque cached copy is as good as a fresh one for a stylesheet or font.
    return 'shell';
  }

  const p = url.pathname;

  if (p.startsWith('/api/v1/media/')) return 'media';
  if (p.startsWith('/api/v1/')) {
    return API_CACHEABLE.test(p) ? 'api' : 'network';
  }
  if (/^\/visitor\/index\.php$/.test(p)) return 'scan-redirect';
  if (/^\/(images|audio)\//.test(p))     return 'media';
  if (p.startsWith('/visitor/') || p.startsWith('/js/vendor/')) return 'shell';

  return 'network';
}

self.addEventListener('fetch', (event) => {
  const route = routeFor(event.request.method, event.request.url);

  switch (route) {
    case 'shell':         return event.respondWith(staleWhileRevalidate(event));
    case 'media':         return event.respondWith(cacheFirst(event));
    case 'api':           return event.respondWith(networkFirst(event));
    case 'scan-redirect': return event.respondWith(scanRedirect(event.request));
    default:              return; // the network, untouched
  }
});

// -- Strategies -------------------------------------------------------------

/**
 * The cache key for a request. Our own files drop the query string:
 * index.html asks for app.js?v=15 and the precache holds app.js, and the
 * two must be one entry. A third-party URL keeps its query - for Google
 * Fonts it is the whole request.
 *
 * Media is the exception to that exception. Its URLs are signed and expire
 * after half an hour, so `expires` and `signature` differ on every issue of
 * the same file. Keeping them in the key would store another copy of the
 * same audio each time the exhibit was opened, and - worse - miss every
 * copy already held the moment the signature rotated, which is exactly when
 * a phone in airplane mode has nothing else to fall back on. So those two
 * are dropped and the file is keyed by its path. Only the key is stripped:
 * what goes to the network is still the full signed URL, because the
 * signature is what authorises the request.
 */
function keyFor(request) {
  const url = new URL(request.url);

  if (url.origin !== OWN) return url.href;

  if (url.pathname.startsWith('/api/v1/media/')) {
    url.searchParams.delete('expires');
    url.searchParams.delete('signature');
  } else {
    url.search = '';
  }

  return url.href;
}

/** The cached copy now, the network's copy next time. */
async function staleWhileRevalidate(event) {
  const request = event.request;
  const cache   = await caches.open(SHELL_CACHE);
  const key     = keyFor(request);
  const cached  = await cache.match(key);

  const refresh = fetch(request).then((response) => {
    if (response.ok || response.type === 'opaque') {
      // waitUntil: the page already has its answer; this write must still
      // finish even if the browser would otherwise stop the worker.
      event.waitUntil(cache.put(key, response.clone()).catch(() => {}));
    }
    return response;
  }).catch(() => null);

  if (cached) {
    event.waitUntil(refresh);
    return cached;
  }

  const fresh = await refresh;
  if (fresh) return fresh;

  // Nothing cached and no network: a page still gets the app shell.
  if (request.mode === 'navigate') {
    const shell = await cache.match(new URL('./index.html', self.location.href).href);
    if (shell) return shell;
  }
  return Response.error();
}

/**
 * Once fetched, kept. Pictures and audio are named by timestamp.
 *
 * Audio arrives as Range requests: the <audio> element asks for bytes, and
 * the Cache API will neither store a 206 nor slice a stored file. So the
 * whole file is cached once, in the background, and every byte range after
 * that is cut from the cached copy here. Seeking keeps working offline.
 */
async function cacheFirst(event) {
  const request = event.request;
  const cache   = await caches.open(MEDIA_CACHE);
  const key     = keyFor(request);
  const cached  = await cache.match(key);
  const range   = request.headers.get('range');

  if (cached) {
    return range ? byteRange(cached, range) : cached;
  }

  if (range) {
    // Answer this range from the network; fill the cache with the whole file.
    // request.url, not key: the key has had the signature stripped, and the
    // signed route would refuse it. Fetching the URL as a string rather than
    // the request is what drops the Range header and gets the whole file.
    event.waitUntil(fetch(request.url).then((r) => { if (r.ok) return cache.put(key, r); }).catch(() => {}));
    return fetch(request);
  }

  const response = await fetch(request);
  if (response.ok) {
    event.waitUntil(cache.put(key, response.clone()).catch(() => {}));
  }
  return response;
}

/** A 206 cut from a complete cached response. */
async function byteRange(response, rangeHeader) {
  const m = /^bytes=(\d*)-(\d*)$/.exec(rangeHeader.trim());
  if (!m) return response;

  const buffer = await response.arrayBuffer();
  const total  = buffer.byteLength;
  const start  = m[1] === '' ? Math.max(0, total - Number(m[2])) : Number(m[1]);
  const end    = m[1] !== '' && m[2] !== '' ? Math.min(Number(m[2]), total - 1) : total - 1;

  if (start > end || start >= total) {
    return new Response(null, { status: 416, headers: { 'Content-Range': 'bytes */' + total } });
  }

  const headers = new Headers(response.headers);
  headers.set('Content-Range',  'bytes ' + start + '-' + end + '/' + total);
  headers.set('Content-Length', String(end - start + 1));
  headers.set('Accept-Ranges',  'bytes');

  return new Response(buffer.slice(start, end + 1), { status: 206, statusText: 'Partial Content', headers });
}

/**
 * The live answer, or the last good one only when the network is gone.
 *
 * Stored under the URL alone, not the request - the Authorization header
 * is not part of the key, and a request that carries one cannot be used as
 * a key at all. A 401 or 403 means this phone is no longer welcome and the
 * cache goes with it.
 */
async function networkFirst(event) {
  const request = event.request;
  const cache   = await caches.open(API_CACHE);
  const key     = request.url;

  try {
    const response = await fetch(request);

    if (response.status === 401 || response.status === 403) {
      event.waitUntil(caches.delete(API_CACHE));
    } else if (response.ok) {
      event.waitUntil(cache.put(key, response.clone()).catch(() => {}));
    }
    return response;
  } catch (networkError) {
    const cached = await cache.match(key);
    if (cached) {
      // Say so, for anyone debugging why an edited exhibit looks old.
      const headers = new Headers(cached.headers);
      headers.set('X-Museobaler-Cache', 'offline-fallback');
      return new Response(cached.body, { status: cached.status, statusText: cached.statusText, headers });
    }
    throw networkError;
  }
}

/**
 * A scanned QR label opens /visitor/index.php?scan=CODE, a PHP page that
 * parks the code in sessionStorage and redirects to the app. PHP does not
 * run in a dead zone, so this answers the same way when the network is
 * gone: the label still opens the exhibit, from the cache.
 */
async function scanRedirect(request) {
  try {
    return await fetch(request);
  } catch (networkError) {
    const url   = new URL(request.url);
    const scan  = (url.searchParams.get('scan') || '').trim();
    const debug = url.searchParams.get('debug') === '1';
    const safe  = scan.replace(/[^A-Za-z0-9._-]/g, '');

    const html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
      + '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
      + '<meta name="theme-color" content="#2D5016"><title>Museo de Baler</title>'
      + '<style>html,body{height:100%;margin:0;background:#2D5016}</style></head><body><script>'
      + (safe ? "sessionStorage.setItem('mb_pending_scan','" + safe + "');" : '')
      + "window.location.replace('./index.html" + (debug ? '?debug=1' : '') + "');"
      + '</script></body></html>';

    return new Response(html, { status: 200, headers: { 'Content-Type': 'text/html; charset=UTF-8' } });
  }
}

