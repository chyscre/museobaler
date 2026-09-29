// The service worker's rules, run under node's own test runner:
//
//     npm test
//
// sw.js is loaded into a sandbox with a stand-in `self` so its top level
// runs as it would in a browser; routeFor() is then called directly. What
// is pinned: which URLs are held offline and which never are, because the
// second list is the admission gate's guarantee.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here   = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(here, '../../public/visitor/sw.js'), 'utf8');

const ctx = createContext({
  self: {
    location: { origin: 'https://museo.example', href: 'https://museo.example/visitor/sw.js' },
    addEventListener() {},
    skipWaiting() {},
    clients: { claim() {} },
  },
  caches: {},
  URL,
  Headers,
  Response,
  fetch() {},
  // The worker waits NETWORK_PATIENCE_MS on a stalled request before it
  // reaches for the cached copy. That is four seconds by design and four
  // seconds is not something to spend in a test suite, so the clock the
  // worker is given here is compressed: anything it asks to wait a second or
  // more for happens in twenty milliseconds. Nothing else about the race
  // changes - it is still setTimeout against a pending fetch.
  setTimeout: (fn, ms) => setTimeout(fn, ms >= 1000 ? 20 : ms),
  clearTimeout,
});
runInContext(source, ctx);

const route = (method, path) => ctx.routeFor(method, 'https://museo.example' + path);

test('the app shell and its libraries are served cache-first-then-refresh', () => {
  for (const p of ['/visitor/', '/visitor/index.html', '/visitor/js/app.js?v=15', '/visitor/css/app.css',
                   '/visitor/model/weights.bin', '/js/vendor/tf.min.js', '/visitor/manifest.json']) {
    assert.equal(route('GET', p), 'shell', p);
  }
});

test('third-party assets - fonts - are treated as shell too', () => {
  assert.equal(ctx.routeFor('GET', 'https://fonts.googleapis.com/css2?family=Young+Serif'), 'shell');
  assert.equal(ctx.routeFor('GET', 'https://fonts.gstatic.com/s/youngserif/v1/x.woff2'), 'shell');
});

test('pictures and audio guides are cache-first', () => {
  assert.equal(route('GET', '/images/exhibits/Siege_1776872245.jpeg'), 'media');
  assert.equal(route('GET', '/audio/exhibit_1_en_1776872045.mp3'), 'media');
  assert.equal(route('GET', '/images/qr/EXH-001.svg'), 'media');
  assert.equal(route('GET', '/api/v1/media/audio/exhibit_1_en.mp3?expires=123&signature=x'), 'media');
});

test('only the museum content behind the gate may be answered from the last good copy', () => {
  assert.equal(route('GET', '/api/v1/exhibits?lang=en'), 'api');
  assert.equal(route('GET', '/api/v1/exhibits/EXH-001?lang=fil'), 'api');
  assert.equal(route('GET', '/api/v1/museum'), 'api');
  assert.equal(route('GET', '/api/v1/visitors/me'), 'api');
});

test('everything that changes the museum, or proves who you are, is network only', () => {
  for (const [m, p] of [
    ['POST',  '/api/v1/visitors'],
    ['POST',  '/api/v1/visitors/login'],
    ['POST',  '/api/v1/visitors/logout'],
    ['POST',  '/api/v1/visitors/me/group'],
    ['POST',  '/api/v1/scans'],
    ['POST',  '/api/v1/feedback'],
    ['POST',  '/api/v1/recognition'],
    ['POST',  '/api/v1/attendance'],
    ['PATCH', '/api/v1/attendance/12'],
    ['GET',   '/api/v1/survey'],
    ['GET',   '/api/v1/notifications'],
    ['POST',  '/api/v1/exhibits'],
  ]) {
    assert.equal(route(m, p), 'network', `${m} ${p}`);
  }
});

test('a scanned label still opens in a dead zone', () => {
  assert.equal(route('GET', '/visitor/index.php?scan=EXH-001'), 'scan-redirect');
});

test('the admin panel is none of the worker\'s business', () => {
  assert.equal(route('GET', '/'), 'network');
  assert.equal(route('GET', '/exhibits'), 'network');
  assert.equal(route('GET', '/login'), 'network');
  assert.equal(route('GET', '/exhibit-image/x.jpg'), 'network');
});

// -- Cache keys -------------------------------------------------------------
//
// Media URLs are signed and expire after half an hour, so the same audio
// file arrives under a different `signature` every time the exhibit is
// opened. If that reached the cache key, a phone would hold one copy per
// issue and find none of them the moment the signature rotated - and the
// moment it needs them is airplane mode, when there is no network to fall
// back to. These pin the key to the path.

const key = (path) => ctx.keyFor({ url: 'https://museo.example' + path });

test('a signed media url is cached under its path, not its signature', () => {
  const a = key('/api/v1/media/audio/exhibit_1_en.mp3?expires=1000&signature=aaa');
  const b = key('/api/v1/media/audio/exhibit_1_en.mp3?expires=9999&signature=zzz');

  assert.equal(a, b, 'two issues of the same file must be one cache entry');
  assert.equal(a, 'https://museo.example/api/v1/media/audio/exhibit_1_en.mp3');
});

test('a media url keeps any parameter that is not the signature', () => {
  assert.equal(
    key('/api/v1/media/images/exhibits/a.jpg?v=thumb&expires=1&signature=b'),
    'https://museo.example/api/v1/media/images/exhibits/a.jpg?v=thumb',
  );
});

test('everything else keys the way it always did', () => {
  // Our own files drop the query entirely: index.html asks for app.js?v=15
  // and the precache holds app.js.
  assert.equal(key('/visitor/js/app.js?v=15'), 'https://museo.example/visitor/js/app.js');

  // A third-party URL keeps its query - for Google Fonts it is the request.
  const font = 'https://fonts.googleapis.com/css2?family=Young+Serif';
  assert.equal(ctx.keyFor({ url: font }), font);
});

// ── networkFirst: which answer the page is actually handed ──────────────────
//
// The routing tests above say the exhibit list MAY be answered from the last
// good copy. These say when it IS - and the case that matters is the one that
// used to be unreachable: a connection that has not died, it has just stopped
// answering. fetch() rejects for a dead network and the old catch handled that
// correctly; for one bar inside a stone building the promise simply stays
// pending, which is precisely where a phone holding a cached exhibit list was
// left waiting on the network instead.

const URL_EXHIBITS = 'https://museo.example/api/v1/exhibits?lang=en';

/** A cache stub, seeded with whatever the test wants already held. */
function withCache(seed = {}) {
  const store = new Map(Object.entries(seed));
  const calls = { deleted: 0 };

  ctx.caches = {
    open: async () => ({
      match: async (k) => store.get(k),
      put:   async (k, v) => { store.set(k, v); },
    }),
    delete: async () => { calls.deleted++; store.clear(); return true; },
  };

  return { store, calls };
}

/** The FetchEvent, reduced to what networkFirst touches. */
function fetchEvent(url) {
  const pending = [];
  return {
    request: { url, method: 'GET', headers: new Headers() },
    waitUntil: (p) => pending.push(p),
    pending,
  };
}

test('a stalled network gives way to the last good answer', async () => {
  withCache({ [URL_EXHIBITS]: new Response('the last good list', { status: 200 }) });
  ctx.fetch = () => new Promise(() => {});      // accepted, then silence

  const res = await ctx.networkFirst(fetchEvent(URL_EXHIBITS));

  assert.equal(await res.text(), 'the last good list');
  assert.equal(res.headers.get('X-Museobaler-Cache'), 'slow-network');
});

test('a dead network gives way to it too, and says which it was', async () => {
  withCache({ [URL_EXHIBITS]: new Response('the last good list', { status: 200 }) });
  ctx.fetch = () => Promise.reject(new TypeError('Failed to fetch'));

  const res = await ctx.networkFirst(fetchEvent(URL_EXHIBITS));

  assert.equal(await res.text(), 'the last good list');
  assert.equal(res.headers.get('X-Museobaler-Cache'), 'offline-fallback');
});

test('a live answer still beats the cached one, and replaces it', async () => {
  const { store } = withCache({ [URL_EXHIBITS]: new Response('yesterday', { status: 200 }) });
  ctx.fetch = () => Promise.resolve(new Response('today', { status: 200 }));

  const event = fetchEvent(URL_EXHIBITS);
  const res   = await ctx.networkFirst(event);

  assert.equal(await res.text(), 'today');
  assert.equal(res.headers.get('X-Museobaler-Cache'), null, 'a fresh answer is not labelled stale');

  await Promise.all(event.pending);
  assert.equal(await store.get(URL_EXHIBITS).text(), 'today', 'the cache followed the network');
});

test('a phone that is no longer welcome loses the cache it was keeping', async () => {
  const { calls } = withCache({ [URL_EXHIBITS]: new Response('paid-for content', { status: 200 }) });
  ctx.fetch = () => Promise.resolve(new Response('{"error":"not_cleared"}', { status: 403 }));

  const event = fetchEvent(URL_EXHIBITS);
  const res   = await ctx.networkFirst(event);

  assert.equal(res.status, 403, 'the refusal reaches the app, not a cached copy of the museum');
  await Promise.all(event.pending);
  assert.equal(calls.deleted, 1);
});

test('with nothing cached, a failure is still a failure', async () => {
  withCache();
  ctx.fetch = () => Promise.reject(new TypeError('Failed to fetch'));

  await assert.rejects(() => ctx.networkFirst(fetchEvent(URL_EXHIBITS)), TypeError);
});
