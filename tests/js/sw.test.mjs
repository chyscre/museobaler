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
