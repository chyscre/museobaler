/* ═══════════════════════════════════════════════════════════
   Museo de Baler — Visitor App  |  app.js
   ═══════════════════════════════════════════════════════════ */

'use strict';

// ── CONFIG ───────────────────────────────────────────────────────
/* API_BASE is worked out from where this page is actually being served.

   It used to be chosen by sniffing the hostname: a LAN IP or a tunnel got
   '/api', and everything else -- localhost and yeppie.test included -- got a
   hardcoded '/yeppie/museobaler/public/api'. That literal only resolves when
   Apache's document root is C:/laragon/www, and on this machine it is not:

     localhost     docroot museobaler/public      -> app at /visitor/
     yeppie.test   docroot C:/laragon/www/yeppie  -> app at /museobaler/public/visitor/

   so on both of them every API call went to /yeppie/museobaler/public/api/...,
   which 404s. A 404 there is Laravel's HTML error page, r.json() rejects on the
   leading '<', and the .catch() reports it as "Cannot reach the museum right
   now" -- blaming the network for what was really a wrong path.

   The app is always served out of a 'visitor/' directory and the API is always
   its sibling 'api/', whatever depth the pair sits at. Deriving one from the
   other holds at every entry point -- localhost, yeppie.test, the LAN IP, a
   cloudflared or ngrok tunnel -- and needs no list of hostnames to maintain. */
const API_BASE = (function () {
  const path = window.location.pathname;
  const i = path.lastIndexOf('/visitor/');
  const base = i >= 0
    ? path.slice(0, i)                        // .../visitor/index.html -> ...
    : path.replace(/\/[^/]*$/, '') + '/..';   // opened from somewhere unexpected
  return window.location.origin + base + '/api';
})();

// Pictures and audio guides sit beside api/ in public/, so the same base
// serves them. The API hands back root-relative paths built the same way;
// this only exists for the odd cached record that still carries a bare filename.
const PUBLIC_BASE = API_BASE.replace(/\/api$/, '');

// Helper: fetch with ngrok bypass header always included
function apiFetch(url, options = {}) {
  const headers = {
    'ngrok-skip-browser-warning': 'true'
  };
  // The session token proves who we are to every gated endpoint. Sending it
  // here means no individual call site can forget it.
  if (STATE && STATE.token) headers['Authorization'] = 'Bearer ' + STATE.token;

  options.headers = Object.assign(headers, options.headers || {});

  return fetch(url, options).then(res => {
    // 401 = the session is gone (expired, or signed out elsewhere).
    // 403 = still registered, but the front desk has not cleared admission.
    // Either way the app must not keep showing museum content.
    if (res.status === 401) { handleSessionLost(); }
    else if (res.status === 403) { handleClearanceLost(res.clone()); }

    /* Every call site ends in .then(r => r.json()), so when the server answers
       with something that is not JSON — a 404 page, a PHP fatal, a tunnel
       interstitial — the parse throws and lands in a .catch() that reports it
       as a connection problem. The request in fact arrived and was answered.
       Name the real cause in the console; the visitor still gets the friendly
       message, but whoever is debugging gets the URL and the status. */
    const type = res.headers.get('content-type') || '';
    if (!res.ok && !type.includes('json')) {
      console.error(
        '[api] ' + res.status + ' ' + res.statusText + ' from ' + res.url + '\n' +
        '      Response is ' + (type || 'of unknown type') + ', not JSON, so parsing it will\n' +
        '      fail and surface to the visitor as a "could not reach the museum" message.\n' +
        '      API_BASE is ' + API_BASE + ' — check it matches where api/ is served from.'
      );
    }
    return res;
  });
}
const STORAGE_KEY = 'mb_visitor';

// ── DEMO EXHIBITS ────────────────────────────────────────────
// Normalize any exhibit object (API or demo) to a consistent shape
function normalizeExhibit(ex) {
  return {
    id:          ex.exhibit_code || ex.id || ('EXH-' + ex.exhibit_id),
    exhibit_id:  ex.exhibit_id   || null,
    code:        ex.exhibit_code || ex.code || ex.id,
    title:       ex.name         || ex.title || 'Untitled Exhibit',
    name:        ex.name         || ex.title || 'Untitled Exhibit',
    category:    ex.category     || 'Exhibit',
    hall:        ex.hall         || '—',
    floor:       ex.floor        || '—',
    year:        ex.year         || (ex.date_published ? ex.date_published.substring(0,4) : ''),
    storyline:   ex.storyline_order || ex.storyline || null,
    // Pin position on the museum map, % of the floor plan; null = not placed yet
    map_x:       (ex.map_x ?? null) === null ? null : +ex.map_x,
    map_y:       (ex.map_y ?? null) === null ? null : +ex.map_y,
    icon:        ex.icon         || categoryIcon(ex.category || ex.category_name || ''),
    gradient:    ex.gradient     || categoryGradient(ex.category || ex.category_name || ''),
    description: ex.description  || '',
    fun_facts:   Array.isArray(ex.fun_facts) ? ex.fun_facts : [],
    author:      (ex.authors && ex.authors !== '0') ? ex.authors : (ex.author && ex.author !== '0' ? ex.author : 'Museum Curator'),
    date:        ex.date_published ? ex.date_published.substring(0,4) : (ex.date || ex.year || ''),
    views:       ex.scan_count   || ex.views || 0,
    image:       ex.image ? (ex.image.startsWith('http') ? ex.image : window.location.origin + ex.image) : null,
    gallery:     (ex.gallery || []).map(g => ({
                   url: g.url ? (g.url.startsWith('http') ? g.url : window.location.origin + g.url)
                      : (g.filename ? PUBLIC_BASE + '/images/exhibits/' + encodeURIComponent(g.filename) : ''),
                   caption: g.caption || ''
                 })),
    languages:   ex.languages    || ['en','fil'],
    audio_file:  ex.audio_file   || null,
    audio_url:   ex.audio_url ? (ex.audio_url.startsWith('http') ? ex.audio_url : window.location.origin + ex.audio_url)
                  : (ex.audio_file ? PUBLIC_BASE + '/audio/' + encodeURIComponent(ex.audio_file) : null),
  };
}

function categoryIcon(cat) {
  const m = { History:'history_edu', Culture:'palette', Nature:'park', Science:'science', Religion:'church', Artifacts:'category' };
  return m[cat] || 'museum';
}

function categoryGradient(cat) {
  const m = {
    History:  'linear-gradient(135deg,#5C3D1E,#8B6340)',
    Culture:  'linear-gradient(135deg,#5C3D1E,#8B6340)',
    Nature:   'linear-gradient(135deg,#2D5016,#4A7C2F)',
    Science:  'linear-gradient(135deg,#0891b2,#0e7490)',
    Religion: 'linear-gradient(135deg,#2D5016,#4A7C2F)',
    Artifacts:'linear-gradient(135deg,#5C3D1E,#8B6340)',
  };
  return m[cat] || 'linear-gradient(135deg,#2D5016,#4A7C2F)';
}

const DEMO_EXHIBITS = [
  { id:'EXH-001', code:'MB001', title:'Siege of Baler Diorama', category:'History', hall:'Hall A', year:'1898', storyline:1, icon:'history_edu', gradient:'linear-gradient(135deg,#5C3D1E,#8B6340)', description:'The Siege of Baler (1898–1899) was a remarkable episode during the Philippine Revolution. A small Spanish garrison held out in the church of Baler for 337 days, unaware that the war had ended. This diorama depicts the final moments of the siege with detailed figurines and period-accurate scenery.', author:'Museum Curator', date:'2024', views:1240 },
  { id:'EXH-002', code:'MB002', title:'Baler Church Model', category:'History', hall:'Hall A', year:'1735', storyline:2, icon:'church', gradient:'linear-gradient(135deg,#2D5016,#4A7C2F)', description:'A scale model of the historic Baler Church, also known as the Parish of Saint Louis of Toulouse. The church served as a fortress during the Siege of Baler and remains a symbol of resilience and faith in the community.', author:'Museum Curator', date:'2024', views:980 },
  { id:'EXH-003', code:'MB003', title:'Aurora Flora Collection', category:'Nature', hall:'Hall B', year:'2020', storyline:3, icon:'forest', gradient:'linear-gradient(135deg,#2a6b5a,#3d9e7a)', description:'A curated collection of pressed specimens and illustrations showcasing the rich biodiversity of Aurora Province. Features endemic plant species found only in the Sierra Madre mountain range.', author:'Dr. Maria Santos', date:'2023', views:756 },
  { id:'EXH-004', code:'MB004', title:'Casiguran Agta Artifacts', category:'Culture', hall:'Hall C', year:'1980', storyline:4, icon:'groups', gradient:'linear-gradient(135deg,#3a5a8a,#5a7aaa)', description:'A collection of traditional tools, ornaments, and everyday objects from the Casiguran Agta, an indigenous Negrito group of Aurora Province. These artifacts reflect their hunter-gatherer lifestyle and deep connection with the forest.', author:'Dr. Jose Reyes', date:'2022', views:634 },
  { id:'EXH-005', code:'MB005', title:'Quezon Legacy Gallery', category:'History', hall:'Hall A', year:'1935', storyline:5, icon:'account_balance', gradient:'linear-gradient(135deg,#5C3D1E,#8B6340)', description:'Manuel L. Quezon, the first President of the Philippine Commonwealth, was born in Baler. This gallery celebrates his life, political career, and enduring legacy through photographs, documents, and personal memorabilia.', author:'Museum Curator', date:'2024', views:890 },
  { id:'EXH-006', code:'MB006', title:'Baler Bay Surfing Heritage', category:'Culture', hall:'Hall C', year:'2000', storyline:6, icon:'surfing', gradient:'linear-gradient(135deg,#1a5a8a,#2a8aaa)', description:'Baler is known as the surfing capital of the Philippines. This exhibit traces the history of surfing in Baler from its introduction during the filming of Apocalypse Now in 1979 to its current status as a world-class surf destination.', author:'Tourism Office', date:'2023', views:712 },
  { id:'EXH-007', code:'MB007', title:'Aurora Wildlife Diorama', category:'Nature', hall:'Hall B', year:'2019', storyline:7, icon:'pets', gradient:'linear-gradient(135deg,#2a6b5a,#3d9e7a)', description:'Life-size dioramas depicting the diverse wildlife of Aurora Province, including the Philippine Eagle, cloud rats, and various endemic species found in the Sierra Madre biodiversity corridor.', author:'DENR Aurora', date:'2023', views:543 },
  { id:'EXH-008', code:'MB008', title:'Traditional Fishing Tools', category:'Culture', hall:'Hall C', year:'1960', storyline:null, icon:'set_meal', gradient:'linear-gradient(135deg,#5C3D1E,#8B6340)', description:'A collection of traditional fishing implements used by the coastal communities of Baler and surrounding municipalities. Includes hand-woven fish traps, bamboo fish corrals, and traditional boats.', author:'Museum Curator', date:'2022', views:421 },
];

// ── STATE ────────────────────────────────────────────────────
let STATE = {
  visitorId: null,
  name: '',
  email: '',
  provider: 'manual',
  firstName: '',
  lastName: '',
  age: '',
  sex: '',
  middleName: '',
  country: 'Philippines',
  city: '',
  barangay: '',
  province: '',
  visitType: 'Walk-in',
  visitorType: 'Local',
  // Admission is settled at the entrance desk: locals show an ID for free
  // entry, everyone else pays the flat fee at the counter.
  admissionFee: 0,
  paymentStatus: 'Free',
  idVerified: false,
  // Bearer token for the visitor API. Not a secret the app derives anything
  // from — the server decides what it unlocks.
  token: null,
  // Last known admission state. Cached so the waiting screen can render
  // instantly, but never trusted: the server re-checks on every request.
  clearance: 'pending_payment',
  cleared: false,
  // The party the desk signed them in with today, or null. Display only -
  // the server decides whether the group covers them.
  group: null,
  modeSelected: false,
  mode: 'storyline',
  lang: 'en',
  scanned: [],
  bookmarks: [],
  recentlyViewed: [],
  storylineProgress: 0,
  settings: {
    narration: true,
    // Start the narration by itself when an exhibit is scanned. Browsing to
    // an exhibit from a list never autoplays - only a scan, which means the
    // visitor is standing in front of the piece.
    autoplay: true,
    music: false,
    speed: 1,
    textSize: 'medium',
    highContrast: false,
    volume: 1,
  }
};

// ── AUDIO STATE ──────────────────────────────────────────────
let audioState = {
  playing: false,
  utterance: null,
  text: '',
  speed: 1,
  startTime: 0,
  duration: 0,
  timer: null,
  elapsed: 0,
};

// ── QR SCANNER ───────────────────────────────────────────────
let qrScanner = null;
let currentExhibit = null;
let tcChecked = false;
let feedbackRating = 0;
// Stays 0 for the great majority of visits, which have no guide at all.
let guideRating = 0;
let currentFloor = 'ground';

// ═══════════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
  loadState();
  // Clear old exhibit cache so normalized data is fetched fresh
  Object.keys(sessionStorage).filter(k => k.startsWith('mb_exhibits')).forEach(k => sessionStorage.removeItem(k));
  // Apply saved preferences immediately
  applyAllPreferences();
  startClock();
  retireServiceWorker();
  setupInstallPrompt();
  // Preferences that reach past the DOM: the narration gate and the ambient track.
  applyNarrationUI();
  applyVolume();
  if (STATE.settings.music) applyMusic();

  // Handle QR scan redirect from index.php. The scan is always parked rather
  // than run immediately — whether it can be opened depends on the clearance
  // check below, which has not happened yet.
  const pendingScan = sessionStorage.getItem('mb_pending_scan');
  if (pendingScan) {
    sessionStorage.removeItem('mb_pending_scan');
    STATE._pendingScan = pendingScan;
  }

  /**
   * Decide the opening screen from the SERVER's answer, not from what is in
   * localStorage. A visitor could otherwise flip `cleared` to true by hand and
   * walk past the admission gate — the gated endpoints would still refuse, but
   * the app would flash museum chrome it should never have shown.
   */
  if (STATE.token) {
    setAuthMode('signin');
    showLoading(true);
    apiFetch(`${API_BASE}/visitor.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'status' })
    })
      .then(r => r.ok ? r.json() : null)
      .then(data => {
        if (!data || data.error) { handleSessionLost(); return; }
        applySession(data);
        routeAfterAuth(data);
      })
      .catch(() => {
        // The museum could not be reached. There is no cached copy of the app
        // to fall back on, so say so and let a reload retry. The token is kept.
        setAuthMode('signin');
        showScreen('s-register');
        showRegError('Could not reach the museum. Check your connection and reload.');
      })
      .finally(() => showLoading(false));
  } else {
    setAuthMode('signin');
    showScreen('s-register');
  }

  // Geofence runs for everyone — registered or not. Config is fetched
  // immediately so it has the full 3s head start to resolve before
  // initGeofence() reads MUSEUM_LAT/MUSEUM_LNG/GEOFENCE_RADIUS; if it hasn't
  // resolved by then, the hardcoded defaults above are used instead.
  _loadMuseumConfig();
  setTimeout(initGeofence, 3000);
});

// ── PERSIST ──────────────────────────────────────────────────
function saveState() {
  try { localStorage.setItem(STORAGE_KEY, JSON.stringify(STATE)); } catch(e) {}
}
function loadState() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return;
    const saved = JSON.parse(raw);
    // settings is merged key by key, not replaced. Object.assign is shallow, so
    // a state written before a setting existed used to overwrite the whole
    // settings object and take every default with it -- narration silently
    // became undefined (i.e. off) for anyone with an older save.
    const settings = Object.assign({}, STATE.settings, saved.settings || {});
    STATE = Object.assign(STATE, saved);
    STATE.settings = settings;
  } catch(e) {}
}

// ═══════════════════════════════════════════════════════════
// NAVIGATION
// ═══════════════════════════════════════════════════════════
function showScreen(id) {
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  const el = document.getElementById(id);
  if (el) {
    el.classList.add('active');
    el.scrollTop = 0;
    const body = el.querySelector('.sbody');
    if (body) body.scrollTop = 0;
  } else {
    console.warn('showScreen: element not found:', id);
    return;
  }
  // The clearance poll only belongs to the waiting screen — leaving it for any
  // reason (cleared, signed out, sent back to the welcome screen) stops it.
  if (id !== 's-pending') { try { stopClearancePolling(); } catch(e){} }

  // The camera belongs to the scan screens the same way. Now that it starts
  // itself, the one guarantee that matters is that it never keeps running —
  // LED on, battery draining — behind a screen that is not the scanner.
  if (id !== 's-scan-storyline' && id !== 's-scan-free') { try { stopScanner(); } catch(e){} }

  // Screen-specific enter hooks
  if (id === 's-home') { try { populateHome(); } catch(e){} }
  if (id === 's-profile') { try { onProfileEnter(); } catch(e){ console.error('profile enter error:', e); } }
  if (id === 's-profile-scanned') { try { populateScanned(); } catch(e){} }
  if (id === 's-profile-bookmarked') { try { populateBookmarked(); } catch(e){} }
  if (id === 's-profile-halls') { try { populateHalls(); } catch(e){} }
  if (id === 's-home-exhibits') { try { populateAllExhibits(); } catch(e){} }
  if (id === 's-map') { try { updateMap(); } catch(e){} }
  // The scanner hooks call onScanScreenShown, NOT onScanEnter. onScanEnter is
  // the router that calls showScreen — wiring the hook back to it made the two
  // call each other until the stack overflowed (2,649 frames deep, measured),
  // with the RangeError swallowed by the catch below. Every open of the scan
  // screen was silently doing that.
  if (id === 's-scan-storyline') { try { onScanScreenShown('storyline'); } catch(e){ console.error(e); } }
  if (id === 's-scan-free') { try { onScanScreenShown('free'); } catch(e){ console.error(e); } }
  if (id === 's-settings') { try { syncSettings(); } catch(e){} }
}

// ═══════════════════════════════════════════════════════════
// CLOCK
// ═══════════════════════════════════════════════════════════
function startClock() {
  function tick() {
    const now = new Date();
    const h = now.getHours(), m = now.getMinutes();
    const t = `${h}:${m < 10 ? '0' + m : m}`;
    document.querySelectorAll('.sb-time').forEach(el => el.textContent = t);
  }
  tick();
  setInterval(tick, 30000);
}

// ═══════════════════════════════════════════════════════════
// AUTH + REGISTER FLOW
// ═══════════════════════════════════════════════════════════
// Accounts are email + password. Signing in returns a session token which
// apiFetch() attaches to every request; the server resolves the visitor from
// that token, so nothing here can claim to be someone else by editing
// localStorage.
//
// Museum content stays locked until the front desk records the admission —
// fee collected, or residency ID sighted for locals. That gate is enforced on
// the server; the waiting screen below is only how it is presented.

// Admission fee in pesos, as set on the admin's Museum Info page. Fetched
// with the rest of the museum settings at start-up (_loadMuseumConfig); the
// number here is only what shows until that answer arrives. The server
// recomputes the fee from the visitor type on every registration — nothing
// on this side is trusted for money.
let ADMISSION_FEE = 50;

function feeLabel(amount) {
  const n = Number(amount === undefined ? ADMISSION_FEE : amount);
  return n > 0 ? '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : 'FREE';
}

// Baler locals enter free but must show proof of residency at the desk.
// Everyone else pays the flat fee at the entrance counter. Fee and label are
// read live so a fee that arrives after this table is built still shows.
const PAYING_RULE = {
  get fee()   { return ADMISSION_FEE; },
  get label() { return feeLabel(); },
  note: 'Please pay at the entrance counter before starting your tour.',
  bg: '#FFFBEB', border: '#FDE68A', fg: '#B45309',
};
const ADMISSION_RULES = {
  Local: {
    fee: 0,
    label: 'FREE',
    note: 'Baler residents enter free. Show your ID at the entrance desk.',
    bg: '#F0FDF4', border: '#BBF7D0', fg: '#15803D',
  },
  Tourist: PAYING_RULE,
  Foreign: PAYING_RULE,
};

// Which half of the welcome screen is showing.
let authMode = 'signin';

/**
 * Details typed on the welcome screen while creating an account, held until
 * the visitor-information form is submitted.
 *
 * SECURITY: deliberately a plain variable and never part of STATE — STATE is
 * written to localStorage, and a password has no business being stored on the
 * device. It lives only in memory and is wiped as soon as it has been sent.
 */
let pendingSignup = null;

function isValidEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value);
}

function showRegError(msg) {
  const box = document.getElementById('reg-error');
  if (!box) return;
  box.textContent = msg;
  box.style.display = msg ? 'block' : 'none';
}

// ── Password rules ─────────────────────────────────────────────────────────
// A mirror of public/api/_password_policy.php, for instant feedback while
// typing. The server runs the same checks and is the one that decides — this
// copy only exists so the visitor is not made to guess what is wrong.

const COMMON_PASSWORD_ROOTS = [
  'password', 'passwd', 'pass', 'welcome', 'admin', 'administrator',
  'letmein', 'qwerty', 'qwertyuiop', 'asdfgh', 'zxcvbn', 'abc', 'abcd',
  'iloveyou', 'monkey', 'dragon', 'sunshine', 'princess', 'football',
  'baseball', 'master', 'shadow', 'superman', 'batman', 'trustno',
  'login', 'guest', 'test', 'testing', 'demo', 'sample', 'default',
  'changeme', 'secret', 'access', 'freedom', 'whatever', 'starwars',
  'computer', 'internet', 'samsung', 'google', 'facebook', 'gmail',
  'birthday', 'january', 'february', 'december', 'summer', 'winter',
  'museo', 'museum', 'baler', 'aurora', 'philippines', 'pilipinas',
  'museobaler', 'quezon', 'sabang', 'ditumabo', 'visitor', 'tourist',
];

function hasRunOfFour(value) {
  for (let i = 3; i < value.length; i++) {
    const a = value.charCodeAt(i - 3), b = value.charCodeAt(i - 2),
          c = value.charCodeAt(i - 1), d = value.charCodeAt(i);
    if (b - a === 1 && c - b === 1 && d - c === 1) return true;
    if (a - b === 1 && b - c === 1 && c - d === 1) return true;
  }
  return false;
}

function looksCommon(password) {
  const lower = password.toLowerCase();
  const stripped = lower
    .replace(/[@4]/g, 'a').replace(/3/g, 'e').replace(/[1!]/g, 'i')
    .replace(/0/g, 'o').replace(/[$5]/g, 's').replace(/[7+]/g, 't')
    .replace(/[^a-z]/g, '');

  return COMMON_PASSWORD_ROOTS.some(root =>
    stripped === root ||
    (root.length >= 4 && stripped.includes(root) && root.length >= stripped.length * 0.6)
  );
}

function containsPersonalInfo(password, personal) {
  const lower = password.toLowerCase();
  return personal.some(value => {
    value = String(value || '').trim().toLowerCase();
    if (value.length < 4) return false;
    const reversed = value.split('').reverse().join('');
    return lower.includes(value) || lower.includes(reversed);
  });
}

/** The rule checklist, in the order it is shown under the password field. */
function checkPasswordRules(password, personal = []) {
  return [
    { label: 'At least 8 characters',            ok: password.length >= 8 },
    { label: 'Upper and lower case letters',     ok: /[a-z]/.test(password) && /[A-Z]/.test(password) },
    { label: 'At least one number',              ok: /[0-9]/.test(password) },
    { label: 'At least one symbol',              ok: /[^a-zA-Z0-9]/.test(password) },
    { label: 'Not a common or guessable password', ok: password.length > 0 && !looksCommon(password) && !hasRunOfFour(password.toLowerCase()) && !/^(.)\1+$/.test(password) },
    { label: 'Does not contain your name or email', ok: password.length > 0 && !containsPersonalInfo(password, personal) },
  ];
}

function personalValuesForPassword() {
  const email = (document.getElementById('reg-email').value || '').trim();
  return [
    (document.getElementById('reg-first').value || '').trim(),
    (document.getElementById('reg-last').value || '').trim(),
    email.split('@')[0] || '',
  ];
}

function onPasswordInput() {
  if (authMode !== 'signup') return;

  const password = document.getElementById('reg-password').value;
  const rules    = checkPasswordRules(password, personalValuesForPassword());
  const passed   = rules.filter(r => r.ok).length;

  const list = document.getElementById('pw-rules');
  list.innerHTML = rules.map(r =>
    `<div class="pw-rule ${r.ok ? 'ok' : 'bad'}">
       <span class="material-icons-round">${r.ok ? 'check_circle' : 'radio_button_unchecked'}</span>${r.label}
     </div>`
  ).join('');

  // Strength is simply how many of the rules are satisfied — the rules are the
  // policy, so there is nothing to be gained from a second, vaguer score.
  const pct   = password ? Math.round((passed / rules.length) * 100) : 0;
  const bar   = document.getElementById('pw-strength-bar');
  const label = document.getElementById('pw-strength-label');
  const tier  = passed === rules.length ? ['Strong', '#16a34a']
              : passed >= 4             ? ['Getting there', '#d97706']
              : password                ? ['Weak', '#ef4444']
              : ['—', '#9ca3af'];
  bar.style.width      = pct + '%';
  bar.style.background = tier[1];
  label.textContent    = tier[0];
  label.style.color    = tier[1];
}

function togglePw(inputId, btn) {
  const input = document.getElementById(inputId);
  const icon  = btn.querySelector('.material-icons-round');
  const show  = input.type === 'password';
  input.type        = show ? 'text' : 'password';
  icon.textContent  = show ? 'visibility_off' : 'visibility';
}

// ── Welcome screen: sign in vs create account ──────────────────────────────
function setAuthMode(mode) {
  authMode = mode;
  const signup = mode === 'signup';

  document.getElementById('auth-tab-signin').classList.toggle('active', !signup);
  document.getElementById('auth-tab-signup').classList.toggle('active', signup);
  document.getElementById('signup-names').style.display = signup ? 'grid' : 'none';
  document.getElementById('signup-password-extras').style.display = signup ? 'block' : 'none';

  document.getElementById('auth-heading').textContent =
    signup ? 'Create your visitor account' : 'Welcome back';
  document.getElementById('auth-subheading').textContent =
    signup ? 'Register once, then use the same email on every visit'
           : 'Sign in to continue your museum journey';
  document.getElementById('auth-submit-label').textContent =
    signup ? 'Continue' : 'Sign In';
  document.querySelector('#auth-submit .material-icons-round').textContent =
    signup ? 'arrow_forward' : 'login';
  document.getElementById('auth-hint').innerHTML =
    signup ? 'Already registered? Tap <strong>Sign In</strong> and use your email and password.'
           : 'First time here? Tap <strong>Create Account</strong> to register for your visit.';

  // Tell the password manager which kind of field this is.
  document.getElementById('reg-password').setAttribute(
    'autocomplete', signup ? 'new-password' : 'current-password'
  );
  document.getElementById('reg-password').placeholder =
    signup ? 'Choose a strong password' : 'Enter your password';

  showRegError('');
  if (signup) onPasswordInput();
}

function submitAuth() {
  return authMode === 'signup' ? startRegistration() : doSignIn();
}

// ── Sign in ────────────────────────────────────────────────────────────────
function doSignIn() {
  const email    = document.getElementById('reg-email').value.trim();
  const password = document.getElementById('reg-password').value;

  if (!isValidEmail(email)) { showRegError('Please enter a valid email address.'); return; }
  if (!password)            { showRegError('Please enter your password.'); return; }
  showRegError('');

  showLoading(true);
  apiFetch(`${API_BASE}/visitor.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'login', email, password })
  })
    .then(r => r.status === 429
      ? { error: 'rate_limited' }
      : r.json())
    .then(data => {
      if (!data || data.error) {
        showRegError(authErrorText(data && data.error));
        return;
      }
      applySession(data);
      document.getElementById('reg-password').value = '';
      routeAfterAuth(data, `Welcome back, ${STATE.firstName}!`);
    })
    .catch(() => showRegError('Could not reach the museum. Check your connection, or try again in a moment.'))
    .finally(() => showLoading(false));
}

// ── Create account: step 1 of the sign-up ──────────────────────────────────
function startRegistration() {
  const email     = document.getElementById('reg-email').value.trim();
  const first     = document.getElementById('reg-first').value.trim();
  const last      = document.getElementById('reg-last').value.trim();
  const password  = document.getElementById('reg-password').value;
  const password2 = document.getElementById('reg-password2').value;

  if (!first || !last)      { showRegError('Please enter your first and last name.'); return; }
  if (!isValidEmail(email)) { showRegError('Please enter a valid email address.'); return; }

  const failed = checkPasswordRules(password, personalValuesForPassword()).filter(r => !r.ok);
  if (failed.length) { showRegError(failed[0].label + '.'); return; }
  if (password !== password2) { showRegError('The two passwords do not match.'); return; }
  showRegError('');

  // Held in memory only — see the note on pendingSignup.
  pendingSignup = { email, first, last, password };

  STATE.email     = email;
  STATE.firstName = first;
  STATE.lastName  = last;
  STATE.name      = `${first} ${last}`;
  STATE.provider  = 'manual';

  goToDetailsForm();
}

function goToDetailsForm() {
  document.getElementById('confirm-name').textContent  = STATE.name;
  document.getElementById('confirm-email').textContent = STATE.email;
  tcChecked = false;
  updateTCCheck();
  showScreen('s-login-confirm');
}

function authErrorText(code) {
  return {
    invalid_credentials: 'Incorrect email or password.',
    email_taken:         'That email is already registered. Tap Sign In instead.',
    rate_limited:        'Too many attempts. Please wait a minute and try again.',
    unauthenticated:     'Your session has expired. Please sign in again.',
  }[code] || registrationErrorText(code);
}

// ── Session handling ───────────────────────────────────────────────────────
function applySession(v) {
  STATE.token       = v.token || STATE.token;
  STATE.visitorId   = v.visitor_id;
  STATE.firstName   = v.first_name || STATE.firstName;
  STATE.lastName    = v.last_name  || STATE.lastName;
  STATE.name        = `${STATE.firstName} ${STATE.lastName}`;
  STATE.email       = v.email || STATE.email;
  if (v.age)          STATE.age         = v.age;
  if (v.sex)          STATE.sex         = v.sex;
  if (v.visit_type)   STATE.visitType   = v.visit_type;
  if (v.visitor_type) STATE.visitorType = v.visitor_type;
  if (v.city    !== undefined && v.city    !== null) STATE.city     = v.city;
  if (v.barangay !== undefined && v.barangay !== null) STATE.barangay = v.barangay;
  if (v.province!== undefined && v.province!== null) STATE.province = v.province;
  if (v.country)      STATE.country     = v.country;
  STATE.admissionFee  = Number(v.admission_fee || 0);
  STATE.paymentStatus = v.payment_status || STATE.paymentStatus;
  STATE.idVerified    = !!v.id_verified;
  STATE.clearance     = v.clearance || (v.cleared ? 'cleared' : STATE.clearance);
  STATE.cleared       = !!v.cleared;
  // Who they came with, if the desk signed the party in as one. Explicitly
  // cleared when the reply says null: a stale group from a previous visit
  // must not linger in local state and claim to cover today's admission.
  if (v.group !== undefined) STATE.group = v.group || null;
  STATE.provider      = 'manual';
  if (v.explore_mode) STATE.mode = v.explore_mode === 'Free Roam' ? 'free' : 'storyline';
  // Only a returning visitor has actually chosen a mode. A new registration
  // carries the column's default, which must not be mistaken for a choice —
  // otherwise first-time visitors skip the explore-mode screen entirely.
  if (v.returning) STATE.modeSelected = true;
  saveState();
}

/**
 * Where to go once we know who the visitor is: into the museum if the front
 * desk has cleared them, otherwise to the waiting screen.
 */
function routeAfterAuth(v, welcomeMessage) {
  if (!v.cleared) {
    showPending(v);
    return;
  }

  claimAttendance(STATE.visitorId, STATE.name);
  applyModeTheme();
  if (welcomeMessage) showToast(welcomeMessage);

  const pendingScan = STATE._pendingScan;
  delete STATE._pendingScan;
  saveState();

  if (!STATE.modeSelected) { showScreen('s-mode-choice'); return; }
  if (pendingScan) { handleScan(pendingScan); return; }
  populateHome();
  showScreen('s-home');
}

/** The session token is gone — drop back to the welcome screen. */
function handleSessionLost() {
  if (!STATE.token) return;
  stopClearancePolling();
  STATE.token   = null;
  STATE.cleared = false;
  saveState();
  showToast('Your session has expired. Please sign in again.');
  showScreen('s-register');
}

/** Still signed in, but staff has not cleared admission (or it lapsed). */
function handleClearanceLost(response) {
  response.json()
    .then(body => showPending((body && body.visitor) || { clearance: STATE.clearance }))
    .catch(() => showPending({ clearance: STATE.clearance }));
}

// ── Waiting for staff clearance ────────────────────────────────────────────
let clearanceTimer = null;

function showPending(v) {
  if (v) {
    STATE.clearance     = v.clearance || STATE.clearance;
    STATE.cleared       = false;
    if (v.payment_status) STATE.paymentStatus = v.payment_status;
    if (v.admission_fee !== undefined) STATE.admissionFee = Number(v.admission_fee);
    if (v.visitor_type) STATE.visitorType = v.visitor_type;
    saveState();
  }

  if (v && v.group !== undefined) STATE.group = v.group || null;

  const needsPayment = STATE.clearance === 'pending_payment';
  const withGroup    = STATE.clearance === 'pending_group';
  const box   = document.getElementById('pending-action');
  const icon  = document.getElementById('pending-action-icon');
  const title = document.getElementById('pending-action-title');
  const text  = document.getElementById('pending-action-text');
  const feeRow = document.getElementById('pending-fee-row');

  // The join box is for someone NOT yet in a group. Once they are, the box
  // above explains that the group's payment is what they are waiting on.
  const joinBox = document.getElementById('pending-join-group');
  if (joinBox) joinBox.style.display = withGroup ? 'none' : 'block';

  if (withGroup) {
    const label = STATE.group ? STATE.group.label : 'your group';
    box.style.background = '#F0FDF4';
    box.style.border     = '1px solid #BBF7D0';
    box.style.color      = '#166534';
    icon.textContent     = 'groups';
    title.textContent    = `You're with ${label}`;
    text.textContent     = 'The group pays as one at the entrance counter. As soon as the person who signed you in settles it, this screen unlocks by itself - nothing to pay on your own.';
    feeRow.style.display = 'none';
  } else if (needsPayment) {
    box.style.background = '#FFFBEB';
    box.style.border     = '1px solid #FDE68A';
    box.style.color      = '#B45309';
    icon.textContent     = 'payments';
    title.textContent    = 'Pay at the entrance counter';
    text.textContent     = 'Hand your admission fee to the staff at the desk. Once they record it, this screen unlocks by itself.';
    feeRow.style.display = 'flex';
    document.getElementById('pending-fee').textContent =
      feeLabel(STATE.admissionFee || ADMISSION_FEE);
  } else {
    box.style.background = '#EFF6FF';
    box.style.border     = '1px solid #BFDBFE';
    box.style.color      = '#1D4ED8';
    icon.textContent     = 'badge';
    title.textContent    = 'Show your ID at the entrance desk';
    text.textContent     = 'Present proof of Baler residency — barangay certificate, PhilSys ID, driver’s licence, school or company ID. Staff will verify it and this screen unlocks by itself.';
    feeRow.style.display = 'none';
  }

  document.getElementById('pending-name').textContent = STATE.name || 'Visitor';
  document.getElementById('pending-meta').textContent =
    [STATE.visitorType, STATE.email].filter(Boolean).join(' · ');

  showScreen('s-pending');
  startClearancePolling();
}

function startClearancePolling() {
  stopClearancePolling();
  // Every 6s: often enough that the visitor sees it flip while still at the
  // desk, rare enough not to drain the battery or the rate-limit budget.
  clearanceTimer = setInterval(() => checkClearance(false), 6000);
}

function stopClearancePolling() {
  if (clearanceTimer) { clearInterval(clearanceTimer); clearanceTimer = null; }
}

function checkClearance(manual) {
  if (!STATE.token) { handleSessionLost(); return; }

  const status  = document.getElementById('pending-status');
  const spinner = document.getElementById('pending-spinner');
  if (manual && status) status.textContent = 'Checking…';

  return apiFetch(`${API_BASE}/visitor.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'status' })
  })
    .then(r => r.ok ? r.json() : null)
    .then(data => {
      if (!data || data.error) return;

      if (data.cleared) {
        stopClearancePolling();
        applySession(data);
        STATE.cleared = true;
        saveState();
        if (spinner) spinner.classList.add('cleared');
        if (status)  status.textContent = 'Cleared — enjoy your visit!';
        showToast('You’re all set. Welcome to Museo de Baler!');
        setTimeout(() => routeAfterAuth({ ...data, cleared: true }), 900);
        return;
      }

      // Still waiting — refresh the wording in case staff changed the type.
      STATE.clearance = data.clearance;
      if (status) {
        status.textContent = manual
          ? 'Not confirmed yet — please check with the staff.'
          : 'Waiting for staff confirmation…';
      }
    })
    .catch(() => {
      if (status) status.textContent = 'Can\'t reach the museum — retrying…';
    });
}

function toggleTC() {
  tcChecked = !tcChecked;
  updateTCCheck();
}
function updateTCCheck() {
  const el = document.getElementById('tc-check');
  if (!el) return;
  if (tcChecked) {
    el.style.background = 'var(--gm)';
    el.innerHTML = '<span class="material-icons-round" style="font-size:14px;color:white;">check</span>';
  } else {
    el.style.background = '#E5E7EB';
    el.innerHTML = '';
  }
}

function confirmSignIn() {
  if (!tcChecked) { showToast('Please agree to the Terms & Conditions'); return; }
  document.getElementById('vi-name-label').textContent  = STATE.name;
  document.getElementById('vi-email-label').textContent = STATE.email;
  showScreen('s-visitor-info');
  updateFeeBox();
}

function selectRadioPill(input) {
  const group = input.closest('[id$="-group"]') || input.closest('div[style*="flex-wrap"]');
  if (group) {
    group.querySelectorAll('.radio-pill').forEach(p => p.classList.remove('active'));
    input.closest('.radio-pill').classList.add('active');
  }

  // The group code only makes sense for someone arriving with a party the
  // desk has signed in. Show it for Group and School, tuck it away for a
  // walk-in so the form stays as short as the paper book it replaced.
  if (input.name === 'vtype') {
    const wrap = document.getElementById('group-code-wrap');
    if (wrap) {
      const withParty = input.value === 'Group' || input.value === 'School';
      wrap.style.display = withParty ? 'block' : 'none';
      if (!withParty) document.getElementById('vi-group-code').value = '';
    }
  }
}

/** Upper-case, no spaces: what the desk read out, however it was typed. */
function normaliseGroupCode(raw) {
  return String(raw || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
}

// ── Visitor type: swap the location fields and the fee summary ─────────────
function selectVisitorType(input) {
  selectRadioPill(input);
  const type = input.value;
  document.getElementById('panel-local').style.display   = type === 'Local'   ? 'block' : 'none';
  document.getElementById('panel-tourist').style.display = type === 'Tourist' ? 'block' : 'none';
  document.getElementById('panel-foreign').style.display = type === 'Foreign' ? 'block' : 'none';
  updateFeeBox();
}

function toggleCountryOther() {
  const sel = document.getElementById('vi-country');
  document.getElementById('country-other-wrap').style.display = sel.value === 'Other' ? 'block' : 'none';
}

function currentVisitorType() {
  return document.querySelector('input[name="vistype"]:checked')?.value || 'Local';
}

function updateFeeBox() {
  const rule = ADMISSION_RULES[currentVisitorType()] || ADMISSION_RULES.Local;
  const box  = document.getElementById('fee-box');
  if (!box) return;
  box.style.background  = rule.bg;
  box.style.border      = '1px solid ' + rule.border;
  document.getElementById('fee-label').style.color  = rule.fg;
  document.getElementById('fee-amount').style.color = rule.fg;
  document.getElementById('fee-amount').textContent = rule.label;
  const note = document.getElementById('fee-note');
  note.style.color   = rule.fg;
  note.textContent   = rule.note;
}

// ── Submit the details form ────────────────────────────────────────────────
function submitRegistration() {
  if (!pendingSignup) {
    showToast('Please start again from the welcome screen');
    showScreen('s-register');
    return;
  }

  const age = document.getElementById('vi-age').value;
  const sex = document.getElementById('vi-sex').value;
  const visitorType = currentVisitorType();

  if (!age || Number(age) < 1) { showToast('Please enter your age'); return; }
  if (!sex) { showToast('Please select your sex'); return; }

  // Location depends on the visitor type — locals must name their barangay
  // so the free-admission claim (Baler residents only) can be checked
  // against what their ID says at the desk.
  let city = '', province = '', country = 'Philippines', barangay = '';
  if (visitorType === 'Local') {
    barangay = document.getElementById('vi-barangay').value;
    if (!barangay) { showToast('Please select your barangay'); return; }
    city = 'Baler';
    province = 'Aurora';
  } else if (visitorType === 'Tourist') {
    city     = document.getElementById('vi-city').value.trim();
    province = document.getElementById('vi-province').value.trim();
    if (!city || !province) { showToast('Please enter your city and province'); return; }
  } else {
    const picked = document.getElementById('vi-country').value;
    country = picked === 'Other'
      ? document.getElementById('vi-country-other').value.trim()
      : picked;
    if (!country) { showToast('Please tell us which country you are from'); return; }
    city = document.getElementById('vi-city-foreign').value.trim();
  }

  STATE.age         = age;
  STATE.sex         = sex;
  STATE.country     = country;
  STATE.city        = city;
  STATE.barangay    = barangay;
  STATE.province    = province;
  STATE.middleName  = document.getElementById('vi-middle').value.trim();
  STATE.visitType   = document.querySelector('input[name="vtype"]:checked')?.value || 'Walk-in';
  STATE.visitorType = visitorType;

  showLoading(true);
  apiFetch(`${API_BASE}/visitor.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action:           'register',
      first_name:       pendingSignup.first,
      last_name:        pendingSignup.last,
      middle_name:      STATE.middleName,
      age:              STATE.age,
      sex:              STATE.sex,
      visit_type:       STATE.visitType,
      visitor_type:     STATE.visitorType,
      country:          STATE.country,
      city:             STATE.city,
      barangay:         STATE.barangay,
      province:         STATE.province,
      email:            pendingSignup.email,
      password:         pendingSignup.password,
      password_confirm: pendingSignup.password,
      explore_mode:     STATE.mode === 'free' ? 'Free Roam' : 'Storyline',
      // Blank unless they are joining a party the desk signed in. The server
      // decides what the code is worth; the fee is never sent from here.
      group_code:       normaliseGroupCode(document.getElementById('vi-group-code')?.value),
    })
  })
  .then(r => r.json())
  .then(data => {
    if (!data || data.error) {
      const message = (data && data.message) || registrationErrorText(data && data.error);
      showToast(message, 3800);
      // A rejected password, a taken email or a bogus email domain has to be
      // fixed on the welcome screen, so send the visitor back there rather
      // than leaving them stuck on the details form.
      if (data && (data.error === 'email_taken' || data.error === 'email_domain_invalid' || String(data.error).startsWith('password'))) {
        showRegError(message);
        setAuthMode(data.error === 'email_taken' ? 'signin' : 'signup');
        showScreen('s-register');
      }
      return;
    }

    applySession(data);
    // SECURITY: the password has been sent; wipe it from memory immediately.
    pendingSignup = null;
    document.getElementById('reg-password').value  = '';
    document.getElementById('reg-password2').value = '';

    // Attendance is arrival, not admission — recorded even while pending.
    claimAttendance(STATE.visitorId, STATE.name);
    finishRegistration();
  })
  .catch(() => showToast('Could not reach the museum. Check your connection, or try again in a moment.', 3500))
  .finally(() => showLoading(false));
}

function registrationErrorText(code) {
  const messages = {
    missing_name:         'Please enter your first and last name.',
    name_too_long:        'That name is too long.',
    invalid_email:        'Please enter a valid email address.',
    email_too_long:       'That email address is too long.',
    email_taken:          'That email is already registered. Tap Sign In instead.',
    email_domain_invalid: 'That email address does not look real — please check the part after the @.',
    missing_municipality: 'Please select your barangay.',
    missing_barangay: 'Please select your barangay.',
    missing_country:      'Please tell us which country you are from.',
    location_too_long:    'That location is too long.',
    registration_failed:  'Registration failed. Please ask a staff member for help.',
    group_not_found:      'That group code was not found for today. Check it with the person who signed you in.',
    group_full:           'Everyone in that group has already joined. Ask the desk to check the headcount.',
  };
  return messages[code] || 'Registration failed. Please try again.';
}

function finishRegistration() {
  saveState();
  const loc = [STATE.city, STATE.province].filter(Boolean).join(', ') || STATE.country;
  document.getElementById('success-name').textContent = STATE.name;
  document.getElementById('success-email').textContent = STATE.email;
  document.getElementById('success-vtype').textContent = STATE.visitType;
  document.getElementById('success-vistype').textContent = STATE.visitorType;
  document.getElementById('success-loc').textContent = loc;
  document.getElementById('success-agesex').textContent = `${STATE.age} · ${STATE.sex}`;

  const free = STATE.paymentStatus === 'Free';
  document.getElementById('success-fee').textContent = free ? 'FREE' : feeLabel(STATE.admissionFee || ADMISSION_FEE);
  document.getElementById('success-fee-note').textContent = STATE.group
    ? `You're with ${STATE.group.label}. The group's payment covers your admission.`
    : free
      ? 'Show your ID at the entrance desk to claim free entry.'
      : 'Payable at the entrance counter before your tour.';

  showScreen('s-reg-success');
}

/**
 * "Continue" on the success screen. Into the waiting room, unless they are
 * with a group that has already paid (or a Local group the desk signed in
 * face to face) - then there is nothing to wait for.
 */
function afterRegistrationContinue() {
  if (STATE.cleared) {
    routeAfterAuth({ ...STATE, cleared: true });
    return;
  }
  showPending(null);
}

/**
 * Join a group from the waiting screen.
 *
 * For the member who registered before the desk had signed the party in, or
 * a returning visitor who is with a group today. Once joined, the group's
 * payment is what unlocks them - so if it has already paid, this ends the
 * wait on the spot.
 */
function joinGroupFromPending() {
  const input = document.getElementById('pending-group-code');
  const code  = normaliseGroupCode(input && input.value);

  if (code.length < 4) { showToast('Enter the group code the desk gave you.'); return; }
  if (!STATE.token)    { handleSessionLost(); return; }

  showLoading(true);
  apiFetch(`${API_BASE}/visitor.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'join_group', group_code: code })
  })
    .then(r => r.json())
    .then(data => {
      if (!data || data.error) {
        showToast((data && data.message) || registrationErrorText(data && data.error), 3800);
        return;
      }

      applySession(data);
      saveState();
      input.value = '';

      if (data.cleared) {
        stopClearancePolling();
        showToast(`You're in with ${STATE.group ? STATE.group.label : 'your group'}. Welcome!`);
        setTimeout(() => routeAfterAuth({ ...data, cleared: true }), 700);
        return;
      }

      showToast(`Joined ${STATE.group ? STATE.group.label : 'the group'}. Waiting on the group's payment.`, 3000);
      showPending(data);
    })
    .catch(() => showToast('Could not reach the museum. Check your connection and try again.', 3500))
    .finally(() => showLoading(false));
}

// ═══════════════════════════════════════════════════════════
// MODE CHOICE
// ═══════════════════════════════════════════════════════════
function selectMode(mode) {
  STATE.mode = mode;
  const slCard = document.getElementById('mode-storyline');
  const frCard = document.getElementById('mode-free');
  const slIcon = document.getElementById('mode-sl-icon');
  const frIcon = document.getElementById('mode-fr-icon');
  const slRadio = document.getElementById('mode-sl-radio');
  const frRadio = document.getElementById('mode-fr-radio');
  const tip = document.getElementById('mode-tip');
  const tipText = document.getElementById('mode-tip-text');
  const btn = document.getElementById('mode-start-btn');

  if (mode === 'storyline') {
    slCard.style.background = 'linear-gradient(135deg,#C8DFB0,#F5EDE0)';
    slCard.style.borderColor = 'var(--gm)';
    slIcon.style.background = 'var(--gm)';
    slIcon.querySelector('.material-icons-round').style.color = 'white';
    slRadio.style.background = 'var(--gm)';
    slRadio.style.borderColor = 'var(--gm)';
    slRadio.innerHTML = '<span class="material-icons-round" style="font-size:14px;color:white;">check</span>';
    frCard.style.background = 'var(--ew)';
    frCard.style.borderColor = 'var(--es)';
    frIcon.style.background = 'var(--es)';
    frIcon.querySelector('.material-icons-round').style.color = 'var(--tl)';
    frRadio.style.background = '';
    frRadio.style.borderColor = 'var(--es)';
    frRadio.innerHTML = '';
    tip.style.background = 'var(--gp)';
    tipText.style.color = 'var(--gd)';
    tipText.innerHTML = 'In <strong>Storyline mode</strong>, after each scan the map highlights exactly where to go next — just follow the path through the museum.';
    btn.style.background = 'linear-gradient(135deg,var(--mode-secondary),var(--mode-primary))';
  } else {
    frCard.style.background = 'linear-gradient(135deg,#EDD9C0,#F5EDE0)';
    frCard.style.borderColor = 'var(--bm)';
    frIcon.style.background = 'var(--bm)';
    frIcon.querySelector('.material-icons-round').style.color = 'white';
    frRadio.style.background = 'var(--bm)';
    frRadio.style.borderColor = 'var(--bm)';
    frRadio.innerHTML = '<span class="material-icons-round" style="font-size:14px;color:white;">check</span>';
    slCard.style.background = 'var(--ew)';
    slCard.style.borderColor = 'var(--es)';
    slIcon.style.background = 'var(--es)';
    slIcon.querySelector('.material-icons-round').style.color = 'var(--tl)';
    slRadio.style.background = '';
    slRadio.style.borderColor = 'var(--es)';
    slRadio.innerHTML = '';
    tip.style.background = 'var(--bp)';
    tipText.style.color = 'var(--bd)';
    tipText.innerHTML = 'In <strong>Free Explore mode</strong>, scan any exhibit in any order. No guided path — just explore at your own pace.';
    btn.style.background = 'linear-gradient(135deg,var(--bm),var(--bd))';
  }
  btn.style.opacity = '1';
  btn.style.cursor = 'pointer';
  btn.disabled = false;
}

function setMode() {
  if (!STATE.mode) { showToast('Please select an explore mode'); return; }
  STATE.modeSelected = true;
  saveState();
  applyModeTheme();
  populateHome();
  // If a QR scan was pending from a direct QR link, handle it now
  if (STATE._pendingScan) {
    const code = STATE._pendingScan;
    delete STATE._pendingScan;
    handleScan(code);
    return;
  }
  showScreen('s-home');
}

// Apply green (storyline) or brown (free) theme to the whole app
function applyModeTheme() {
  const root = document.documentElement;
  if (STATE.mode === 'free') {
    root.style.setProperty('--mode-primary',   'var(--bd)');
    root.style.setProperty('--mode-secondary', 'var(--bm)');
    root.style.setProperty('--mode-light',     'var(--bl)');
    root.style.setProperty('--mode-pale',      'var(--bp)');
    document.body.classList.add('mode-free');
    document.body.classList.remove('mode-storyline');
  } else {
    root.style.setProperty('--mode-primary',   'var(--gd)');
    root.style.setProperty('--mode-secondary', 'var(--gm)');
    root.style.setProperty('--mode-light',     'var(--gl)');
    root.style.setProperty('--mode-pale',      'var(--gp)');
    document.body.classList.add('mode-storyline');
    document.body.classList.remove('mode-free');
  }
  // Keep map and home banner in sync whenever mode changes
  try { updateMap(); } catch(e) {}
  const banner = document.getElementById('home-scan-banner');
  if (banner) {
    banner.style.background = STATE.mode === 'free'
      ? 'linear-gradient(135deg,var(--bd),var(--bm))'
      : 'linear-gradient(135deg,var(--gd),var(--gm))';
  }
}

// ═══════════════════════════════════════════════════════════
// HOME
// ═══════════════════════════════════════════════════════════
function populateHome() {
  const hour = new Date().getHours();
  const greeting = hour < 12 ? 'Good morning,' : hour < 17 ? 'Good afternoon,' : 'Good evening,';
  const nameEl = document.getElementById('home-name');
  const greetEl = document.getElementById('home-greeting');
  const locEl = document.getElementById('home-location');
  if (nameEl) nameEl.textContent = STATE.name || 'Visitor';
  if (greetEl) greetEl.textContent = greeting;
  if (locEl) locEl.textContent = [STATE.city, STATE.province].filter(Boolean).join(', ') || STATE.country || 'Baler, Aurora';

  // Sync scan banner color to current mode
  const banner = document.getElementById('home-scan-banner');
  if (banner) {
    banner.style.background = STATE.mode === 'free'
      ? 'linear-gradient(135deg,var(--bd),var(--bm))'
      : 'linear-gradient(135deg,var(--gd),var(--gm))';
  }

  loadExhibits().then(exhibits => {
    renderMostViewed(exhibits);
    renderHomeList(exhibits.slice(0, 3));
  });
}

// In-memory exhibit cache — populated once by loadExhibits
let _exhibitCache = [];

function loadExhibits(lang) {
  return apiFetch(`${API_BASE}/exhibits.php?lang=${lang || STATE.lang}&_=${Date.now()}`, {
    headers: { 'ngrok-skip-browser-warning': 'true' }
  })
    .then(r => r.json())
    .then(data => {
      const raw = Array.isArray(data) ? data : (data.exhibits || DEMO_EXHIBITS);
      _exhibitCache = raw.map(ex => normalizeExhibit(ex));
      return _exhibitCache;
    })
    .catch(() => {
      if (_exhibitCache.length) return _exhibitCache;
      return DEMO_EXHIBITS.map(ex => normalizeExhibit(ex));
    });
}

/* ── Unlocked or not ──
   Every exhibit card used to draw a padlock and open the "scan to unlock"
   sheet no matter what — scanning bumped the profile counter and changed
   nothing else. A card is unlocked once its code is in STATE.scanned. */
function isUnlocked(ex) {
  if (!ex || !Array.isArray(STATE.scanned)) return false;
  const keys = [ex.id, ex.code, ex.exhibit_code].filter(Boolean).map(String);
  return STATE.scanned.some(s => keys.includes(String(s.id)) || (s.code && keys.includes(String(s.code))));
}

// The one tap handler every card goes through. Unlocked: open the real thing,
// re-fetched so translations and audio are current, and logged as a repeat
// view rather than a fresh scan. Locked: the preview sheet.
function openExhibitCard(ex) {
  if (typeof ex === 'string') { try { ex = JSON.parse(ex); } catch(e) { return; } }
  if (isUnlocked(ex)) {
    handleScan(String(ex.code || ex.id), 'qr', true);
  } else {
    showExhibitPreview(ex);
  }
}

// A padlock for a locked card; an open lock in the accent colour once scanned.
function lockGlyph(ex, styleExtra, openColor, lockedColor) {
  const open = isUnlocked(ex);
  return '<span class="material-icons-round" title="' + (open ? 'Unlocked' : 'Scan to unlock') + '" style="' +
    (styleExtra || '') + 'color:' + (open ? (openColor || 'var(--accent-text)') : (lockedColor || 'var(--tl)')) + ';">' +
    (open ? 'lock_open' : 'lock') + '</span>';
}

/* Background declaration for an exhibit tile. ex.gradient is stored as a bare
   'linear-gradient(...)' value, but every template used to drop it straight
   into a style attribute as if it were a full declaration — an invalid rule
   the browser silently threw away, leaving a white glyph on a white card. */
function tileBg(ex) {
  const g = ex && ex.gradient;
  if (!g) return 'background:var(--mode-secondary)';
  return /^s*background/.test(g) ? g : 'background:' + g;
}

function renderMostViewed(exhibits) {
  const el = document.getElementById('most-viewed-list');
  if (!el) return;
  const top = [...exhibits].sort((a, b) => (b.views || 0) - (a.views || 0)).slice(0, 4);
  el.innerHTML = top.map(ex => `
    <div onclick="openExhibitCard(${JSON.stringify(ex).replace(/"/g,'&quot;')})" style="flex-shrink:0;width:130px;background:var(--w);border-radius:14px;overflow:hidden;box-shadow:var(--sh);cursor:pointer;">
      <div style="height:72px;position:relative;overflow:hidden;${ex.image ? 'background:#000' : (tileBg(ex))};display:flex;align-items:center;justify-content:center;">
        ${ex.image
          ? `<img src="${ex.image}" alt="${ex.title}" style="width:100%;height:100%;object-fit:cover;position:absolute;inset:0;" onerror="this.style.display='none'">`
          : `<span class="material-icons-round" style="font-size:32px;color:rgba(255,255,255,0.7);">${ex.icon || 'museum'}</span>`
        }
        ${lockGlyph(ex, 'position:absolute;bottom:4px;right:6px;font-size:14px;z-index:1;text-shadow:0 1px 3px rgba(0,0,0,0.5);', '#a7e08a', 'rgba(255,255,255,0.8)')}
      </div>
      <div style="padding:8px;">
        <div style="font-size:calc(11px * var(--fs));font-weight:700;color:var(--td);line-height:1.3;">${ex.title}</div>
        <div style="font-size:calc(10px * var(--fs));color:var(--tl);margin-top:3px;">${ex.hall} · ${ex.category}</div>
      </div>
    </div>`).join('');
}

function renderHomeList(exhibits) {
  const el = document.getElementById('home-exhibit-list');
  if (!el) return;
  el.innerHTML = exhibits.map(ex => exhibitListItem(ex)).join('');
}

function populateAllExhibits() {
  loadExhibits().then(exhibits => {
    const el = document.getElementById('all-exhibit-list');
    if (el) el.innerHTML = exhibits.map(ex => exhibitListItem(ex)).join('');
  });
}

function exhibitListItem(ex) {
  const thumb = ex.image
    ? `<div style="width:44px;height:44px;border-radius:12px;overflow:hidden;flex-shrink:0;background:#000;">
         <img src="${ex.image}" alt="${ex.title}" style="width:100%;height:100%;object-fit:cover;" onerror="this.parentElement.style.background='${ex.gradient||'var(--ew)'}';this.style.display='none'">
       </div>`
    : `<div style="width:44px;height:44px;border-radius:12px;${tileBg(ex)};display:flex;align-items:center;justify-content:center;flex-shrink:0;">
         <span class="material-icons-round" style="font-size:22px;color:white;">${ex.icon || 'museum'}</span>
       </div>`;
  return `<div onclick="openExhibitCard(${JSON.stringify(ex).replace(/"/g,'&quot;')})" style="background:var(--w);border-radius:14px;padding:12px;display:flex;align-items:center;gap:12px;box-shadow:var(--sh);cursor:pointer;">
    ${thumb}
    <div style="flex:1;min-width:0;">
      <div style="font-size:calc(13px * var(--fs));font-weight:700;color:var(--td);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${ex.title}</div>
      <div style="font-size:calc(11px * var(--fs));color:var(--tl);margin-top:2px;">${ex.hall} · ${ex.category} · ${ex.id}</div>
    </div>
    ${lockGlyph(ex, 'font-size:18px;')}
  </div>`;
}

function filterCategory(cat) {
  loadExhibits().then(exhibits => {
    const filtered = exhibits.filter(e => e.category === cat);
    const el = document.getElementById('all-exhibit-list');
    if (el) el.innerHTML = filtered.map(ex => exhibitListItem(ex)).join('');
    showScreen('s-home-exhibits');
  });
}

// ═══════════════════════════════════════════════════════════
// QR SCANNER
// ═══════════════════════════════════════════════════════════
// Router only. Everything that has to happen once the screen is up lives in
// onScanScreenShown, which showScreen calls for us — so this stays safe to
// call from anywhere without re-entering itself.
function onScanEnter(forceMode) {
  const mode = forceMode || STATE.mode;
  showScreen(mode === 'free' ? 's-scan-free' : 's-scan-storyline');
}

// Called by showScreen once a scan screen is the active one.
function onScanScreenShown(mode) {
  stopScanner();
  // Update home scan banner color to match mode
  const banner = document.getElementById('home-scan-banner');
  if (banner) {
    banner.style.background = mode === 'free'
      ? 'linear-gradient(135deg,var(--bd),var(--bm))'
      : 'linear-gradient(135deg,var(--gd),var(--gm))';
  }
  // In storyline mode, show which exhibit to scan next
  if (mode !== 'free') updateStorylineProgress();
  // The live scanner is the default: it starts on its own, and the first time
  // that is what raises the browser's camera-permission prompt.
  startLiveScanner(mode);
}

function updateStorylineProgress() {
  const run = (allExhibits) => {
    const storylineExhibits = allExhibits
      .filter(e => e.storyline && e.storyline > 0)
      .sort((a, b) => a.storyline - b.storyline);

    const scannedOrders = getScannedStorylineOrders(allExhibits);
    const nextExhibit = storylineExhibits.find(e => !scannedOrders.includes(e.storyline));
    const currentStep = scannedOrders.length;
    const totalSteps = storylineExhibits.length || 8;

    const progressText = document.getElementById('sl-progress-text');
    const progressFill = document.getElementById('sl-progress-fill');
    if (progressText) progressText.textContent = `${currentStep} of ${totalSteps}`;
    if (progressFill) progressFill.style.width = Math.round(currentStep / totalSteps * 100) + '%';

    const nextBanner = document.getElementById('sl-next-banner');
    if (nextBanner && nextExhibit) {
      nextBanner.style.display = 'flex';
      const nameEl = document.getElementById('sl-next-name');
      const hallEl = document.getElementById('sl-next-hall');
      if (nameEl) nameEl.textContent = nextExhibit.title;
      if (hallEl) hallEl.textContent = nextExhibit.hall + ' · Step ' + nextExhibit.storyline;
    } else if (nextBanner && !nextExhibit) {
      nextBanner.innerHTML = '<span class="material-icons-round" style="font-size:18px;color:var(--accent-text)">emoji_events</span><span style="font-size:calc(13px * var(--fs));font-weight:600;color:var(--mode-primary)">You\'ve completed the storyline!</span>';
    }
  };

  if (_exhibitCache.length) { run(_exhibitCache); }
  else { loadExhibits().then(run); }
}

function getCachedExhibits(lang) {
  return _exhibitCache;
}

// ── Live scanner ─────────────────────────────────────────────────────────
// Each scan screen has a reader container, an idle panel that sits on top of
// it, and inside that panel a message and a retry button. Which trio a mode
// uses is the only thing that differs between the two screens.
const SCAN_UI = {
  storyline: { reader: 'qr-reader',      panel: 'scan-ui-sl', msg: 'scan-msg-sl', retry: 'scan-retry-sl' },
  free:      { reader: 'qr-reader-free', panel: 'scan-ui-fr', msg: 'scan-msg-fr', retry: 'scan-retry-fr' },
};

// Paint the idle panel: what to say, and whether the "Turn on camera" button
// is worth showing (it is not while a start is already in flight).
function setScanIdle(mode, message, showRetry) {
  const ui = SCAN_UI[mode] || SCAN_UI.storyline;
  const panel = document.getElementById(ui.panel);
  const msg   = document.getElementById(ui.msg);
  const retry = document.getElementById(ui.retry);
  if (panel) panel.style.display = 'flex';
  if (msg)   msg.textContent = message;
  if (retry) retry.style.display = showRetry ? 'inline-flex' : 'none';
}

/* Start the live scanner for a mode. Safe to call repeatedly — a running
   scanner is stopped first — and this is what both the automatic start and the
   "Turn on camera" retry go through.

   It runs after a short delay so the screen has finished laying out: the QR
   box is sized from the viewfinder's real width, and immediately after
   showScreen that width is still 0.

   There is deliberately no other way in: the camera opens by itself, and if it
   cannot, the panel offers a retry and the code box underneath still works. */
let _scanStartTimer = null;
function startLiveScanner(mode) {
  mode = mode || (STATE.mode === 'free' ? 'free' : 'storyline');
  const ui = SCAN_UI[mode];
  clearTimeout(_scanStartTimer);
  stopScanner();

  // A camera needs a secure context. Over plain http on a LAN address there
  // is simply no mediaDevices object — say so, instead of a generic failure.
  if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    setScanIdle(mode, 'The camera needs a secure (https) connection. Open the app through the https link, or type the exhibit code below.', false);
    return;
  }
  if (typeof Html5Qrcode === 'undefined') {
    setScanIdle(mode, 'The scanner could not load. Check the connection and tap below to try again, or type the exhibit code below.', true);
    return;
  }

  setScanIdle(mode, 'Starting camera…', false);
  _scanStartTimer = setTimeout(() => activateCamera(ui.reader, mode), 250);
}

function activateCamera(containerId, mode) {
  const el = document.getElementById(containerId);
  if (!el) return;
  mode = mode || (containerId === 'qr-reader-free' ? 'free' : 'storyline');
  const ui = SCAN_UI[mode];
  el.innerHTML = '';

  try {
    qrScanner = new Html5Qrcode(containerId);
    // Use the parent square's width for qrbox
    const size = el.parentElement ? Math.round(el.parentElement.offsetWidth * 0.7) : 220;
    qrScanner.start(
      { facingMode: 'environment' },
      { fps: 15, qrbox: { width: size, height: size } },
      (code) => { stopScanner(); handleScan(code.trim()); },
      () => {}
    ).then(() => {
      // Camera is live: drop the idle panel, and start image search alongside.
      const panel = document.getElementById(ui.panel);
      if (panel) panel.style.display = 'none';
      ImageSearchEngine.start(containerId);
    }).catch((err) => {
      qrScanner = null;
      setScanIdle(mode, cameraErrorText(err), true);
    });
  } catch(e) {
    qrScanner = null;
    setScanIdle(mode, cameraErrorText(e), true);
  }
}

// html5-qrcode rejects with the underlying DOMException, or sometimes just its
// message as a string. Read the name out of either and say something useful.
function cameraErrorText(err) {
  // A DOMException carries name+message; a string IS the message. Reading
  // .name off a string gives "undefined undefined", which is truthy and would
  // hide the real text from the checks below.
  const text = typeof err === 'string' ? err : ((err && (err.name + ' ' + err.message)) || String(err || ''));
  if (/NotAllowed|Permission|denied/i.test(text)) {
    return 'Camera access was blocked. Tap below to try again, or allow the camera for this site in your browser settings. You can also type the exhibit code below.';
  }
  if (/NotFound|no camera|not found|OverconstrainedError/i.test(text)) {
    return 'No back camera was found on this device. You can still type the exhibit code below.';
  }
  if (/NotReadable|in use|TrackStartError|AbortError/i.test(text)) {
    return 'The camera is being used by another app. Close it and tap below to try again.';
  }
  return 'The camera could not start. Tap below to try again, or type the exhibit code below.';
}

function handleManualScan(screen) {
  const inputId = screen === 'fr' ? 'manual-code-fr' : 'manual-code-sl';
  const input = document.getElementById(inputId);
  const code = input ? input.value.trim().toUpperCase() : '';
  if (!code) { showToast('Please enter an exhibit code'); return; }
  stopScanner();
  handleScan(code);
}

function stopScanner() {
  clearTimeout(_scanStartTimer);
  if (qrScanner) {
    qrScanner.stop().catch(() => {});
    qrScanner = null;
  }
  ImageSearchEngine.stop();
}

// ── Image Search Engine (TensorFlow.js / Teachable Machine) ──────────────────
// Loads a Teachable Machine image classification model from /visitor/model/
// and runs inference on live camera frames every 1.5s.
// Works 100% in the browser — no API calls, no internet after first load.
//
// Model files expected at:
//   public/visitor/model/model.json
//   public/visitor/model/weights.bin
//   public/visitor/model/metadata.json  ← contains class names
//
// Class names in the model MUST match exhibit codes exactly, e.g.:
//   "EXH-2026-001", "EXH-2026-002", …
// OR if you used plain names during training, the mapping is handled via
// the _labelMap object which you can populate below.
const ImageSearchEngine = (() => {
  let _timer       = null;
  let _busy        = false;
  let _videoEl     = null;
  let _containerId = null;
  let _model       = null;   // tmImage.CustomMobileNet instance
  let _ready       = false;  // true once a trained Teachable Machine model is loaded
  let _usingFallback = false; // true when using the server-side pHash matcher instead

  // ── Debug readout ──────────────────────────────────────────────────────────
  // Load the app once with ?ai=debug to get a live panel on the scan screen
  // showing what the recogniser thinks of every frame — the same numbers as
  // Teachable Machine's Output panel, but on the phone in the real room. It is
  // how you tell "the model is weak" from "the plumbing is broken", and which
  // exhibit a wall is being mistaken for. ?ai=off removes it. Sticks in
  // localStorage so it survives navigation.
  let _debug = false;
  try {
    const p = new URLSearchParams(window.location.search).get('ai');
    if (p === 'debug') localStorage.setItem('mb_ai_debug', '1');
    if (p === 'off')   localStorage.removeItem('mb_ai_debug');
    _debug = localStorage.getItem('mb_ai_debug') === '1';
  } catch (e) {}

  // rows: [{ label, confidence, ignored }] — rendered highest first.
  function _renderDebug(engine, rows) {
    if (!_debug) return;
    let el = document.getElementById('ai-debug-panel');
    if (!el) {
      el = document.createElement('div');
      el.id = 'ai-debug-panel';
      el.style.cssText = [
        'position:fixed;top:12px;left:12px;right:12px;z-index:9998',
        'background:rgba(0,0,0,0.78);color:#fff;border-radius:12px',
        'padding:10px 12px;font:12px/1.5 ui-monospace,Menlo,Consolas,monospace',
        'pointer-events:none;white-space:pre',
      ].join(';');
      document.body.appendChild(el);
    }
    const lines = [...rows]
      .sort((a, b) => b.confidence - a.confidence)
      .map(r => {
        const pct  = Math.round(r.confidence * 100);
        const bar  = '█'.repeat(Math.round(pct / 10)).padEnd(10, '·');
        const flag = r.ignored ? ' (ignored)' : pct >= HIGH_CONF * 100 ? ' ◀ navigate' : pct >= LOW_CONF * 100 ? ' ◀ candidate' : '';
        return `${bar} ${String(pct).padStart(3)}%  ${r.label}${flag}`;
      });
    el.textContent = `[${engine}]\n` + (lines.join('\n') || '(no predictions)');
  }

  function _removeDebug() {
    const el = document.getElementById('ai-debug-panel');
    if (el) el.remove();
  }

  const SAMPLE_MS          = 1500; // TF.js — runs fully on-device, can sample fast
  const FALLBACK_SAMPLE_MS = 2500; // pHash — network round trip + server work, sample slower
  const HIGH_CONF  = 0.75;   // ≥ this → auto-navigate
  const LOW_CONF   = 0.45;   // between LOW and HIGH → show candidate list
                              // below LOW → ignore entirely

  // ── Label map ──────────────────────────────────────────────────────────────
  // If your Teachable Machine class names ARE your exhibit codes, leave this
  // empty. If you used human-readable names during training, map them here:
  //   'My Laptop': 'EXH-2026-001',
  //   'Blue Mug':  'EXH-2026-002',
  // Keys are matched case-insensitively — Teachable Machine class names are
  // usually typed in lowercase, and the exhibit codes in the database are not.
  const _labelMap = {
    // 'Class Name From Training': 'EXHIBIT-CODE',
    // The first training run used a year-prefixed naming scheme the database
    // never had (codes are EXH-005, not EXH-2026-005). Mapped here so that
    // model works as exported; rename the classes to the real codes on the
    // next export and these entries become harmless no-ops.
    'exh-2026-005': 'EXH-005',
    'exh-2026-009': 'EXH-009',
  };

  const _labelMapLower = Object.fromEntries(
    Object.entries(_labelMap).map(([k, v]) => [k.trim().toLowerCase(), v])
  );

  // A class name becomes an exhibit code via the map if it is listed, or is
  // used as-is otherwise (handleScan upper-cases it before the lookup).
  function _resolveLabel(className) {
    const n = String(className || '').trim();
    return _labelMap[n] || _labelMapLower[n.toLowerCase()] || n;
  }

  // Classes that mean "this is not an exhibit". The model needs a class like
  // this to have somewhere to put walls, floors, and people — otherwise it is
  // forced to pick *some* exhibit for every frame — but a confident prediction
  // of it must never be looked up as an exhibit code. Matched case-insensitively;
  // any class whose name starts with "_" is treated the same way.
  const IGNORED_LABELS = new Set(['background', 'nothing', 'none', 'empty', 'other', 'unknown']);
  function _isIgnored(className) {
    const n = String(className || '').trim();
    return n.startsWith('_') || IGNORED_LABELS.has(n.toLowerCase());
  }

  // ── Load model ─────────────────────────────────────────────────────────────
  // Called once at page load. If no trained Teachable Machine model is
  // present, fall back to the server-side pHash matcher (/api/image_search.php)
  // instead of leaving image search dark — it needs no training data since it
  // matches against exhibit photos already in the admin panel, just with a
  // lower accuracy ceiling than a properly trained model.
  async function _loadModel() {
    if (typeof tmImage === 'undefined') {
      // TF.js library not loaded (CDN blocked, script failed, etc.)
      _usingFallback = true;
      _updateAllStatusPills('fallback');
      return;
    }
    try {
      _updateAllStatusPills('loading');
      const modelURL    = './model/model.json';
      const metadataURL = './model/metadata.json';
      _model = await tmImage.load(modelURL, metadataURL);
      _ready = true;
      _updateAllStatusPills('ready');
    } catch (e) {
      // Model files don't exist yet — use the pHash fallback instead
      _ready = false;
      _usingFallback = true;
      _updateAllStatusPills('fallback');
    }
  }

  // ── Start sampling ─────────────────────────────────────────────────────────
  function start(containerId) {
    stop();
    _containerId = containerId;
    if (!_ready && !_usingFallback) return; // neither engine available

    // Wait for html5-qrcode to inject its <video> element
    const wait = setInterval(() => {
      const container = document.getElementById(containerId);
      if (!container) return;
      const vid = container.querySelector('video');
      if (!vid || !vid.srcObject) return;
      clearInterval(wait);
      _videoEl = vid;
      _timer   = _ready
        ? setInterval(_tick, SAMPLE_MS)
        : setInterval(_tickFallback, FALLBACK_SAMPLE_MS);
    }, 300);
  }

  function stop() {
    if (_timer) { clearInterval(_timer); _timer = null; }
    _busy    = false;
    _videoEl = null;
    _setViewfinderState('idle');
    _removeDebug();
    // Remove any lingering candidate banner
    const old = document.getElementById('img-candidates-banner');
    if (old) old.remove();
  }

  // ── Main inference tick ────────────────────────────────────────────────────
  async function _tick() {
    if (_busy || !_videoEl || !_ready || _videoEl.readyState < 2) return;

    // Only run while a scan screen is active
    const active = document.querySelector('.screen.active');
    if (!active || !active.id.startsWith('s-scan')) return;

    _busy = true;
    _setViewfinderState('scanning');

    try {
      // Run Teachable Machine inference directly on the video element
      // tmImage handles the canvas capture internally — no manual frame grab needed
      const predictions = await _model.predict(_videoEl);

      _renderDebug('on-device model', predictions.map(p => ({
        label:      p.className,
        confidence: p.probability,
        ignored:    _isIgnored(p.className),
      })));

      // predictions = [{ className, probability }, …] in model order.
      // Drop the not-an-exhibit classes before ranking: if "Background" took
      // most of the probability, whatever is left will fall under LOW_CONF and
      // read correctly as "nothing recognised" rather than as a wrong exhibit.
      const sorted = [...predictions]
        .filter(p => !_isIgnored(p.className))
        .sort((a, b) => b.probability - a.probability);
      const top    = sorted[0];

      _busy = false;

      if (!top || top.probability < LOW_CONF) {
        _setViewfinderState('idle');
        _maybeShowHint();
        return;
      }

      // Resolve exhibit code: check label map first, then use class name directly
      const exhibitCode = _resolveLabel(top.className);

      if (top.probability >= HIGH_CONF) {
        _setViewfinderState('matched');
        showToast('Match found!', 800);
        stop();
        // On-device match — nothing has been recorded yet, so log it here.
        setTimeout(() => handleScan(exhibitCode, 'image'), 800);
      } else {
        // Between LOW_CONF and HIGH_CONF → show top 3 as candidates
        _setViewfinderState('idle');
        const candidates = sorted
          .filter(p => p.probability >= LOW_CONF)
          .slice(0, 3)
          .map(p => ({
            exhibit_code: _resolveLabel(p.className),
            name:         p.className,
            confidence:   p.probability,
          }));
        _showCandidates(candidates);
      }
    } catch (err) {
      _busy = false;
      _setViewfinderState('idle');
    }
  }

  // ── Fallback tick: server-side pHash match via /api/image_search.php ──────
  // Used when no trained Teachable Machine model is present. Slower cadence
  // than _tick() (network round trip + server-side work per frame) and a
  // lower accuracy ceiling than a trained model, but works immediately with
  // zero training data using exhibit photos already in the admin panel.
  async function _tickFallback() {
    if (_busy || !_videoEl || _videoEl.readyState < 2) return;

    const active = document.querySelector('.screen.active');
    if (!active || !active.id.startsWith('s-scan')) return;

    _busy = true;
    _setViewfinderState('scanning');

    try {
      const blob = await _captureFrame(_videoEl);
      if (!blob) { _busy = false; _setViewfinderState('idle'); return; }

      const form = new FormData();
      form.append('frame', blob, 'frame.jpg');
      if (STATE.visitorId) form.append('visitor_id', STATE.visitorId);

      const res = await apiFetch(`${API_BASE}/image_search.php`, { method: 'POST', body: form });

      // Everyone on the museum's Wi-Fi shares one rate-limit budget, so a busy
      // floor can hit the ceiling through ordinary use. Backing off for the
      // window the server names lets it recover; continuing to sample would
      // just keep the bucket full and hold the limit open indefinitely.
      if (res.status === 429) {
        _busy = false;
        _setViewfinderState('idle');
        const retryAfter = parseInt(res.headers.get('Retry-After'), 10);
        _pauseSampling(Number.isFinite(retryAfter) ? retryAfter * 1000 : 30000);
        return;
      }

      const data = res.ok ? await res.json() : null;
      _busy = false;

      _renderDebug('photo match (server)', ((data && data.candidates) || []).map(c => ({
        label:      `${c.exhibit_code}  ${c.name}`,
        confidence: c.confidence,
      })));

      if (!data || !data.exhibit_code) {
        _setViewfinderState('idle');
        _maybeShowHint();
        return;
      }

      if (data.confidence >= HIGH_CONF) {
        _setViewfinderState('matched');
        showToast('Match found!', 800);
        stop();
        // The matcher already wrote the scan row before responding.
        setTimeout(() => handleScan(data.exhibit_code, 'image', true), 800);
      } else {
        _setViewfinderState('idle');
        // The server rescales its scores onto the same 0–1 meaning the model's
        // probabilities carry, and already drops anything below this floor, so
        // both engines can share one threshold. Re-filtering here keeps that
        // true if the two ever drift apart.
        const candidates = (data.candidates || []).filter(c => c.confidence >= LOW_CONF);
        if (candidates.length) _showCandidates(candidates);
        else _maybeShowHint();
      }
    } catch (err) {
      _busy = false;
      _setViewfinderState('idle');
    }
  }

  // Stop sampling for a while, then pick the loop back up on whichever engine
  // is running. Used when the server asks us to slow down.
  function _pauseSampling(ms) {
    if (_timer) { clearInterval(_timer); _timer = null; }
    setTimeout(() => {
      // The visitor may have left the scan screen while we were paused; start()
      // is only safe to resume from if the camera is still up.
      if (!_videoEl || _timer) return;
      _timer = _ready
        ? setInterval(_tick, SAMPLE_MS)
        : setInterval(_tickFallback, FALLBACK_SAMPLE_MS);
    }, ms);
  }

  // Capture the current video frame as a downscaled JPEG Blob for upload.
  // Downscaled because the server only needs enough resolution for a 32x32
  // perceptual hash — sending full camera resolution would just slow the
  // upload down for no accuracy benefit.
  function _captureFrame(videoEl) {
    return new Promise((resolve) => {
      try {
        const maxW  = 480;
        const scale = Math.min(1, maxW / videoEl.videoWidth);
        const w     = Math.round(videoEl.videoWidth * scale);
        const h     = Math.round(videoEl.videoHeight * scale);
        const canvas = document.createElement('canvas');
        canvas.width  = w;
        canvas.height = h;
        canvas.getContext('2d').drawImage(videoEl, 0, 0, w, h);
        canvas.toBlob((blob) => resolve(blob), 'image/jpeg', 0.75);
      } catch (e) {
        resolve(null);
      }
    });
  }

  // ── Status pill updates ────────────────────────────────────────────────────
  // Updates both scan screens (storyline + free)
  function _updateAllStatusPills(state) {
    [['ai-dot-sl', 'ai-label-sl'], ['ai-dot-fr', 'ai-label-fr']].forEach(([dotId, labelId]) => {
      const dot   = document.getElementById(dotId);
      const label = document.getElementById(labelId);
      if (!dot || !label) return;

      if (state === 'loading') {
        dot.style.background   = '#fbbf24';
        label.textContent      = 'Loading AI…';
      } else if (state === 'ready') {
        dot.style.background   = '#4ade80';
        label.textContent      = 'AI Ready';
      } else if (state === 'fallback') {
        // Server-side photo match is active (no trained model yet) — still
        // functional, so show an honest label instead of hiding the pill.
        dot.style.background   = '#60a5fa';
        label.textContent      = 'Photo Match';
      } else {
        // Neither engine available — hide the pill entirely
        const pill = dot.closest('[id^="ai-status"]');
        if (pill) pill.style.display = 'none';
      }
    });
  }

  // ── Viewfinder border pulse ────────────────────────────────────────────────
  function _setViewfinderState(state) {
    const container = _containerId ? document.getElementById(_containerId) : null;
    const parent    = container ? container.parentElement : null;
    if (!parent) return;
    parent.classList.remove('img-search-scanning', 'img-search-matched');
    if (state === 'scanning') parent.classList.add('img-search-scanning');
    if (state === 'matched')  parent.classList.add('img-search-matched');
  }

  // ── Hint toast (throttled) ─────────────────────────────────────────────────
  let _hintCount = 0;
  function _maybeShowHint() {
    _hintCount++;
    if (_hintCount % 5 === 0) {
      showToast('No exhibit recognized — try moving closer or improving lighting', 3000);
    }
  }

  // ── Candidate list ─────────────────────────────────────────────────────────
  function _showCandidates(candidates) {
    if (!candidates || !candidates.length) return;

    const old = document.getElementById('img-candidates-banner');
    if (old) old.remove();

    if (_timer) { clearInterval(_timer); _timer = null; }

    const banner = document.createElement('div');
    banner.id = 'img-candidates-banner';
    banner.style.cssText = [
      'position:fixed;bottom:80px;left:50%;transform:translateX(-50%)',
      'background:#1a1a2e;border:1.5px solid rgba(255,255,255,0.15)',
      'border-radius:16px;padding:14px 16px;max-width:340px;width:90%',
      'box-shadow:0 8px 32px rgba(0,0,0,.45);z-index:9999',
    ].join(';');

    const title = document.createElement('div');
    title.style.cssText = 'font-size:11px;font-weight:700;color:rgba(255,255,255,0.5);margin-bottom:10px;text-transform:uppercase;letter-spacing:.06em';
    title.textContent   = 'Did you mean?';
    banner.appendChild(title);

    candidates.forEach(c => {
      const btn = document.createElement('button');
      btn.style.cssText = [
        'display:flex;width:100%;align-items:center;justify-content:space-between',
        'padding:10px 12px;margin-bottom:7px',
        'background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.1)',
        'border-radius:10px;cursor:pointer;font-size:13px;font-weight:600;color:white',
      ].join(';');

      const pct = Math.round(c.confidence * 100);
      btn.innerHTML = `<span>${c.name}</span><span style="font-size:calc(11px * var(--fs));color:${pct >= 65 ? '#4ade80' : '#fbbf24'};font-weight:700">${pct}%</span>`;
      // A candidate the visitor picked was never confident enough for the
      // server to record on its own, so this one is logged from here.
      btn.onclick = () => { banner.remove(); stop(); handleScan(c.exhibit_code, 'image'); };
      banner.appendChild(btn);
    });

    const dismiss = document.createElement('button');
    dismiss.style.cssText = 'display:block;width:100%;text-align:center;padding:8px;background:transparent;border:none;cursor:pointer;font-size:12px;color:rgba(255,255,255,0.4);margin-top:2px';
    dismiss.textContent   = 'None of these — keep scanning';
    dismiss.onclick = () => {
      banner.remove();
      _hintCount = 0;
      // Resume on whichever engine is actually running — restarting _tick
      // unconditionally left the photo matcher dead after a dismissal, since
      // _tick returns immediately when no on-device model is loaded.
      if (_videoEl) {
        _timer = _ready
          ? setInterval(_tick, SAMPLE_MS)
          : setInterval(_tickFallback, FALLBACK_SAMPLE_MS);
      }
    };
    banner.appendChild(dismiss);

    document.body.appendChild(banner);
  }

  // ── Init ───────────────────────────────────────────────────────────────────
  // Load model as soon as this module is evaluated (non-blocking)
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _loadModel);
  } else {
    _loadModel();
  }

  return { start, stop };
})();

// scanType records how the exhibit was identified ('qr' or 'image') so the
// admin analytics can tell the two apart.
//
// alreadyLogged is set only by the server-side photo matcher, which writes its
// own scan row before responding — logging again here would double-count the
// visit and, worse, file the second copy under 'qr'. The on-device model has
// no server round trip, so it logs through the normal path like a QR scan.
function handleScan(code, scanType = 'qr', alreadyLogged = false) {
  stopScanner();

  // If the scanned value is a full URL (from QR code), extract the scan parameter
  if (code.startsWith('http')) {
    try {
      const url = new URL(code);
      const scanParam = url.searchParams.get('scan');
      if (scanParam) code = decodeURIComponent(scanParam);
    } catch(e) {}
  }

  code = code.trim().toUpperCase();

  // Show a small inline toast instead of full-screen loading overlay
  showToast('Looking up exhibit…', 1500);

  // Always fetch from API so admin changes (translations, audio) are reflected
  apiFetch(`${API_BASE}/exhibits.php?code=${encodeURIComponent(code)}&lang=${STATE.lang}&_=${Date.now()}`, {
    headers: { 'ngrok-skip-browser-warning': 'true' }
  })
    .then(r => r.json())
    .then(data => {
      if (data.error) {
        const demo = DEMO_EXHIBITS.find(e =>
          e.code === code || e.id === code ||
          e.code === code.replace('EXH-2026-', 'MB0') ||
          ('EXH-2026-00' + e.code.replace('MB00','')) === code
        );
        if (demo) { logScan(demo, scanType, !alreadyLogged); renderExhibit(demo, { autoplay: true }); }
        else showToast('Exhibit not found: ' + code);
        return;
      }
      const ex = normalizeExhibit(data);
      logScan(ex, scanType, !alreadyLogged);
      renderExhibit(ex, { autoplay: true });
    })
    .catch(() => {
      const demo = DEMO_EXHIBITS.find(e => e.code === code || e.id === code);
      if (demo) { logScan(demo, scanType, !alreadyLogged); renderExhibit(demo, { autoplay: true }); }
      else showToast('Could not load exhibit. Check your connection.');
    });
}

// postToServer is false when the record already exists server-side; the local
// trail (scanned list, recently viewed, storyline progress, map position) still
// has to be updated either way, since that lives only in this device's state.
function logScan(exhibit, scanType = 'qr', postToServer = true) {
  if (!STATE.scanned.find(e => e.id === exhibit.id)) {
    STATE.scanned.unshift({ id: exhibit.id, code: exhibit.code || exhibit.id, title: exhibit.title, hall: exhibit.hall, category: exhibit.category, icon: exhibit.icon, gradient: exhibit.gradient, scannedAt: Date.now() });
  }
  if (!STATE.recentlyViewed.find(e => e.id === exhibit.id)) {
    STATE.recentlyViewed.unshift({ id: exhibit.id, code: exhibit.code || exhibit.id, title: exhibit.title, hall: exhibit.hall, icon: exhibit.icon, gradient: exhibit.gradient, viewedAt: Date.now() });
    if (STATE.recentlyViewed.length > 10) STATE.recentlyViewed.pop();
  }
  // Update storyline progress if in storyline mode
  if (STATE.mode === 'storyline') {
    STATE.storylineProgress = STATE.scanned.length;
  }
  saveState();
  // Update map legend to show current position
  updateMapAfterScan(exhibit);

  if (!postToServer) return;

  apiFetch(`${API_BASE}/visitor.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      // The server attributes the scan to whoever the token belongs to, so no
      // visitor_id is sent — it would be ignored anyway.
      action:      'scan',
      exhibit_id:  exhibit.exhibit_id || parseInt(String(exhibit.id).replace(/\D/g,'')) || null,
      scan_type:   scanType,
    })
  }).catch(() => {});
}

// ── Map pins ─────────────────────────────────────────────────
// Each exhibit's pin comes from map_x/map_y saved on the admin Museum Map
// (percent of the floor plan). Exhibits nobody has placed yet get a spot
// along the walking route of their floor so they still show up.
const MAP_FLOORS = {
  ground: { svgId: 'map-svg-ground', label: '1st Floor' },
  second: { svgId: 'map-svg-second', label: '2nd Floor' },
};
const MAP_DEFAULT_SPOTS = {
  ground: [[24,54],[12,72],[30,85],[48,72],[18,24],[28,40],[70,30],[74,50],[86,20],[80,65],[60,70],[8,45]],
  second: [[12,35],[30,20],[50,20],[68,20],[85,35],[90,60],[80,88],[60,90],[40,90],[20,88],[12,60],[50,55]],
};
const MAP_PIN_R = 52; // in floor-plan units (the plans are ~1700 wide, so ~13px on a phone)

function mapFloorOf(ex) {
  return /2nd|second/i.test(String(ex.floor || '')) ? 'second' : 'ground';
}

// Every active exhibit with a resolved {floor, svgId, x, y} in SVG units.
function mapNodes() {
  const src = _exhibitCache.length ? _exhibitCache : DEMO_EXHIBITS.map(normalizeExhibit);
  const counters = { ground: 0, second: 0 };
  return src.map(ex => {
    const floor = mapFloorOf(ex);
    const svg = document.getElementById(MAP_FLOORS[floor].svgId);
    if (!svg) return null;
    let px = ex.map_x, py = ex.map_y;
    if (px === null || px === undefined || py === null || py === undefined) {
      const spots = MAP_DEFAULT_SPOTS[floor];
      const n = counters[floor]++;
      const s = spots[n % spots.length], lap = Math.floor(n / spots.length);
      px = Math.min(98, s[0] + lap * 3); py = Math.min(98, s[1] + lap * 3);
    }
    const vb = svg.viewBox.baseVal;
    return { ex, floor, svgId: MAP_FLOORS[floor].svgId, x: px / 100 * vb.width, y: py / 100 * vb.height };
  }).filter(Boolean);
}

function mapNodeFor(ex) {
  if (!ex) return null;
  return mapNodes().find(n => n.ex.id === ex.id || (ex.exhibit_id && n.ex.exhibit_id === ex.exhibit_id)) || null;
}

function svgEl(tag, attrs, text) {
  const el = document.createElementNS('http://www.w3.org/2000/svg', tag);
  Object.entries(attrs).forEach(([k, v]) => el.setAttribute(k, v));
  if (text !== undefined) el.textContent = text;
  return el;
}

// Draws the numbered storyline pins + path and the free-explore pins for
// both floors. Scan-done / scan-next markers are layered on afterwards.
function renderMapPins() {
  const nodes = mapNodes();
  ['ground', 'second'].forEach(floor => {
    const svg = document.getElementById(MAP_FLOORS[floor].svgId);
    if (!svg) return;
    const storyLayer = svg.querySelector(floor === 'ground' ? '#map-path-overlay' : '#map-path-overlay-2nd');
    const freeLayer  = svg.querySelector(floor === 'ground' ? '#map-free-nodes'   : '#map-free-nodes-2nd');
    const path       = svg.querySelector('[data-path]');
    if (!storyLayer || !freeLayer) return;
    storyLayer.innerHTML = ''; freeLayer.innerHTML = '';

    const here = nodes.filter(n => n.floor === floor);
    const story = here.filter(n => n.ex.storyline > 0).sort((a, b) => a.ex.storyline - b.ex.storyline);
    if (path) {
      path.setAttribute('points', story.map(n => n.x + ',' + n.y).join(' '));
      path.style.display = (STATE.mode === 'storyline' && story.length > 1) ? 'block' : 'none';
    }

    story.forEach(n => {
      const g = svgEl('g', { class: 'story-node', transform: 'translate(' + n.x + ',' + n.y + ')', style: 'cursor:pointer' });
      g.appendChild(svgEl('circle', { r: MAP_PIN_R, fill: '#E8A020', stroke: 'white', 'stroke-width': 8 }));
      g.appendChild(svgEl('text', { y: 16, 'text-anchor': 'middle', 'font-size': 44, 'font-weight': 700, fill: 'white' }, n.ex.storyline));
      g.addEventListener('click', () => openExhibitFromMap(n.ex));
      storyLayer.appendChild(g);
    });

    here.forEach(n => {
      const code = String(parseInt(String(n.ex.code || '').replace(/\D/g, ''), 10) || '') || '•';
      const g = svgEl('g', { class: 'free-node', transform: 'translate(' + n.x + ',' + n.y + ')', style: 'cursor:pointer' });
      g.appendChild(svgEl('circle', { r: MAP_PIN_R, fill: '#4A7C2F', stroke: 'white', 'stroke-width': 8 }));
      g.appendChild(svgEl('text', { y: 14, 'text-anchor': 'middle', 'font-size': code.length > 2 ? 32 : 40, 'font-weight': 700, fill: 'white' }, code));
      g.addEventListener('click', () => openExhibitFromMap(n.ex));
      freeLayer.appendChild(g);
    });
  });
}

function openExhibitFromMap(ex) {
  const code = String(ex.code || ex.id || '').replace(/[^A-Za-z0-9_-]/g, '');
  if (code) handleScan(code, 'qr', true);
}

function updateMapAfterScan(exhibit) {
  if (STATE.mode !== 'storyline') return;
  refreshStorylineMapOverlay();

  const infoText = document.getElementById('map-info-text');
  if (!infoText) return;
  const src = _exhibitCache.length ? _exhibitCache : DEMO_EXHIBITS.map(normalizeExhibit);
  const scannedOrders = getScannedStorylineOrders(src);
  const storylineExhibits = src.filter(e => e.storyline > 0).sort((a,b) => a.storyline - b.storyline);
  const nextEx = storylineExhibits.find(e => !scannedOrders.includes(e.storyline));
  if (nextEx) {
    infoText.textContent = 'Next: ' + nextEx.title + ' · ' + MAP_FLOORS[mapFloorOf(nextEx)].label;
  } else {
    infoText.textContent = "Storyline complete! You've visited all exhibits.";
  }
}

// Match scanned exhibits by ID against the live cache
function getScannedStorylineOrders(src) {
  const scannedIds = STATE.scanned.map(s => s.id);
  return src
    .filter(e => e.storyline > 0 && scannedIds.includes(e.id))
    .map(e => e.storyline);
}

function refreshStorylineMapOverlay() {
  renderMapPins();
  const nodes = mapNodes();
  const src = nodes.map(n => n.ex);
  const scannedOrders = getScannedStorylineOrders(src);
  const storylineNodes = nodes.filter(n => n.ex.storyline > 0).sort((a, b) => a.ex.storyline - b.ex.storyline);
  const nextNode = storylineNodes.find(n => !scannedOrders.includes(n.ex.storyline));

  ['map-svg-ground', 'map-svg-second'].forEach(svgId => {
    const svg = document.getElementById(svgId);
    if (!svg) return;
    svg.querySelectorAll('.scan-done, .scan-next').forEach(el => el.remove());

    storylineNodes.forEach(node => {
      if (node.svgId !== svgId) return;
      const isDone = scannedOrders.includes(node.ex.storyline);
      const isNext = nextNode && node.ex.storyline === nextNode.ex.storyline;
      const at = 'translate(' + node.x + ',' + node.y + ')';

      if (isDone) {
        const g = svgEl('g', { class: 'scan-done', transform: at });
        g.appendChild(svgEl('circle', { r: MAP_PIN_R, fill: '#16a34a', stroke: 'white', 'stroke-width': 8 }));
        g.appendChild(svgEl('text', { 'text-anchor': 'middle', 'dominant-baseline': 'middle', 'font-size': 52, fill: 'white' }, '✓'));
        svg.appendChild(g);
      } else if (isNext) {
        const g = svgEl('g', { class: 'scan-next', transform: at });
        const pulse = svgEl('circle', { r: 78, fill: 'none', stroke: '#ef4444', 'stroke-width': 8, opacity: 0.6 });
        pulse.style.animation = 'scanPulse 1.2s ease-out infinite';
        g.appendChild(pulse);
        g.appendChild(svgEl('circle', { r: MAP_PIN_R, fill: '#ef4444', stroke: 'white', 'stroke-width': 8 }));
        g.appendChild(svgEl('text', { 'text-anchor': 'middle', 'dominant-baseline': 'middle', 'font-size': 26, 'font-weight': 700, fill: 'white' }, 'SCAN'));
        g.appendChild(svgEl('text', { y: -90, 'text-anchor': 'middle', 'font-size': 32, 'font-weight': 700, fill: '#ef4444' }, '▼ NEXT'));
        svg.appendChild(g);
      }
    });
  });
}

function toggleFlash() {
  showToast('Flashlight toggled');
}

// ═══════════════════════════════════════════════════════════
// EXHIBIT PREVIEW (locked — requires QR scan)
// ═══════════════════════════════════════════════════════════
function showExhibitPreview(ex) {
  if (typeof ex === 'string') { try { ex = JSON.parse(ex); } catch(e) { return; } }

  // Remove any existing preview
  const existing = document.getElementById('exhibit-preview-sheet');
  if (existing) existing.remove();

  const sheet = document.createElement('div');
  sheet.id = 'exhibit-preview-sheet';
  sheet.style.cssText = 'position:fixed;inset:0;z-index:9998;display:flex;flex-direction:column;justify-content:flex-end;';

  const modeAccent = STATE.mode === 'free' ? 'var(--bm)' : 'var(--gm)';

  sheet.innerHTML = `
    <div onclick="document.getElementById('exhibit-preview-sheet').remove()" style="flex:1;background:rgba(0,0,0,0.5);"></div>
    <div style="background:var(--ec);border-radius:24px 24px 0 0;padding:20px 20px 36px;max-height:70vh;overflow-y:auto;">
      <div style="width:36px;height:4px;background:var(--es);border-radius:2px;margin:0 auto 18px;"></div>
      <div style="display:flex;gap:14px;align-items:flex-start;margin-bottom:16px;">
        <div style="width:64px;height:64px;border-radius:16px;overflow:hidden;flex-shrink:0;${ex.image ? 'background:#000' : (tileBg(ex))};">
          ${ex.image
            ? `<img src="${ex.image}" alt="${ex.title}" style="width:100%;height:100%;object-fit:cover;" onerror="this.style.display='none'">`
            : `<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;"><span class="material-icons-round" style="font-size:32px;color:rgba(255,255,255,0.8);">${ex.icon || 'museum'}</span></div>`
          }
        </div>
        <div style="flex:1;min-width:0;">
          <div style="font-family:'Young Serif',serif;font-size:calc(17px * var(--fs));color:var(--td);line-height:1.3;">${ex.title}</div>
          <div style="font-size:calc(12px * var(--fs));color:var(--tl);margin-top:4px;">${ex.hall} · ${ex.category}${ex.year ? ' · ' + ex.year : ''}</div>
        </div>
      </div>
      <div style="background:var(--w);border-radius:14px;padding:14px 16px;margin-bottom:14px;border:1.5px dashed var(--es);">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
          <span class="material-icons-round" style="font-size:20px;color:${modeAccent};">lock</span>
          <div style="font-size:calc(13px * var(--fs));font-weight:700;color:var(--td);">Scan QR Code to Unlock</div>
        </div>
        <p style="font-size:calc(12px * var(--fs));color:var(--tm);line-height:1.6;">Find the QR code on the exhibit label in the museum to access the full description, audio guide, fun facts, and gallery.</p>
      </div>
      <div style="display:flex;align-items:center;gap:8px;background:var(--ew);border-radius:10px;padding:10px 14px;margin-bottom:16px;">
        <span class="material-icons-round" style="font-size:16px;color:var(--tl);">location_on</span>
        <span style="font-size:calc(12px * var(--fs));color:var(--tm);">${[ex.floor, ex.hall].filter(Boolean).join(' · ') || '—'}</span>
      </div>
      <button onclick="document.getElementById('exhibit-preview-sheet').remove();onScanEnter();"
              style="width:100%;padding:14px;background:${modeAccent};color:white;border:none;border-radius:14px;font-size:calc(14px * var(--fs));font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
        <span class="material-icons-round" style="font-size:18px;">qr_code_scanner</span>Scan QR Code
      </button>
    </div>`;

  document.getElementById('app').appendChild(sheet);
}

// ═══════════════════════════════════════════════════════════
// EXHIBIT DETAIL
// ═══════════════════════════════════════════════════════════
function renderExhibit(ex, opts) {
  if (typeof ex === 'string') {
    try { ex = JSON.parse(ex); } catch(e) { return; }
  }
  opts = opts || {};
  currentExhibit = ex;
  stopAudio();

  // Hero — the whole picture, never a crop: the hero grows to the image's
  // own shape (capped so the text is still reachable) and a tap opens it
  // full-screen. No picture: the old fixed gradient with an icon.
  const hero = document.getElementById('ex-hero');
  const heroIcon = document.getElementById('ex-hero-icon');
  const heroImg = document.getElementById('ex-hero-img');
  if (ex.image) {
    if (hero) { hero.style.background = '#000'; hero.style.height = 'auto'; hero.style.minHeight = '220px'; }
    if (heroImg) { heroImg.src = ex.image; heroImg.alt = ex.title || ''; heroImg.style.display = 'block'; }
    if (heroIcon) heroIcon.parentElement.style.display = 'none';
  } else {
    if (hero) { hero.style.background = ex.gradient || 'linear-gradient(160deg,var(--bd),var(--bm))'; hero.style.height = '220px'; hero.style.minHeight = ''; }
    if (heroImg) { heroImg.style.display = 'none'; heroImg.removeAttribute('src'); }
    if (heroIcon) { heroIcon.parentElement.style.display = ''; heroIcon.textContent = ex.icon || 'museum'; }
  }
  const badge = document.getElementById('ex-badge');
  if (badge) badge.textContent = `${ex.id} · ${ex.hall}`;

  // Title & meta
  const titleEl = document.getElementById('ex-title');
  if (titleEl) titleEl.textContent = ex.title;
  const authorEl = document.getElementById('ex-author');
  if (authorEl) authorEl.textContent = ex.author || 'Museum Curator';
  const dateEl = document.getElementById('ex-date');
  if (dateEl) dateEl.textContent = ex.date || ex.year || '2024';

  // Tags
  const tagsEl = document.getElementById('ex-tags');
  if (tagsEl) {
    const tags = [
      { label: ex.category, style: 'background:var(--bp);color:var(--bd);' },
      { label: ex.hall, style: 'background:var(--ew);color:var(--tm);' },
      { label: ex.year, style: 'background:var(--ew);color:var(--tm);' },
      ex.storyline ? { label: `Storyline #${ex.storyline}`, style: 'background:var(--mode-pale);color:var(--mode-primary);' } : null,
    ].filter(Boolean);
    tagsEl.innerHTML = tags.map(t => `<div style="border-radius:8px;padding:4px 10px;font-size:calc(11px * var(--fs));font-weight:600;${t.style}">${t.label}</div>`).join('');
  }

  // Description
  const descEl = document.getElementById('ex-desc');
  if (descEl) descEl.textContent = ex.description || 'No description available.';

  // Location mini
  const locText = document.getElementById('ex-location-text');
  if (locText) locText.textContent = [ex.floor, ex.hall].filter(Boolean).join(' · ') || '—';

  // Fun facts (generated from exhibit data)
  const factsList = document.getElementById('ex-facts-list');
  if (factsList) {
    const facts = generateFunFacts(ex);
    factsList.innerHTML = facts.map((f, i) => `
      <div style="background:var(--ew);border-radius:12px;padding:14px;display:flex;gap:12px;align-items:flex-start">
        <div style="width:28px;height:28px;border-radius:50%;background:${STATE.mode==='free'?'var(--bm)':'var(--gm)'};color:#fff;font-size:calc(12px * var(--fs));font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0">${i+1}</div>
        <p style="font-size:calc(13px * var(--fs));color:var(--tm);line-height:1.6">${f}</p>
      </div>`).join('');
  }

  // Next in storyline — only show in storyline mode
  const nextCard = document.getElementById('next-in-storyline');
  if (nextCard && STATE.mode === 'storyline' && ex.storyline) {
    const nextEx = DEMO_EXHIBITS.find(e => e.storyline === ex.storyline + 1);
    if (nextEx) {
      document.getElementById('next-title').textContent = nextEx.title;
      nextCard.style.display = 'flex';
      nextCard.onclick = () => goToNext();
    } else {
      nextCard.style.display = 'none';
    }
  } else if (nextCard) {
    nextCard.style.display = 'none';
  }

  // "Scan Another Exhibit" button — only in free explore mode
  const scanAnotherBtn = document.getElementById('scan-another-btn');
  if (scanAnotherBtn) {
    scanAnotherBtn.style.display = STATE.mode === 'free' ? '' : 'none';
  }

  // Gallery tab — real images from admin
  const galleryGrid = document.getElementById('ex-gallery-grid');
  if (galleryGrid) {
    const imgs = ex.gallery && ex.gallery.length ? ex.gallery : (ex.image ? [{ url: ex.image, caption: ex.title }] : []);
    if (imgs.length) {
      galleryGrid.innerHTML = imgs.map(g => `
        <div style="border-radius:10px;overflow:hidden;background:#000;cursor:pointer;" onclick="openLightbox('${g.url}','${(g.caption||'').replace(/'/g,"\\'")}')">
          <img src="${g.url}" alt="${g.caption||''}" style="width:100%;height:110px;object-fit:cover;display:block;">
          ${g.caption ? `<div style="padding:4px 8px;font-size:calc(10px * var(--fs));color:var(--tl);background:var(--ew);">${g.caption}</div>` : ''}
        </div>`).join('');
      const note = galleryGrid.nextElementSibling;
      if (note) note.style.display = 'none';
    } else {
      galleryGrid.innerHTML = `
        <div style="height:100px;background:var(--ew);border-radius:10px;display:flex;align-items:center;justify-content:center;"><span class="material-icons-round" style="font-size:32px;color:var(--tl);">image</span></div>
        <div style="height:100px;background:var(--ew);border-radius:10px;display:flex;align-items:center;justify-content:center;"><span class="material-icons-round" style="font-size:32px;color:var(--tl);">image</span></div>`;
      const note = galleryGrid.nextElementSibling;
      if (note) note.style.display = '';
    }
  }
  updateBookmarkIcon();

  // Related
  const relatedList = document.getElementById('related-list');
  if (relatedList) {
    const related = DEMO_EXHIBITS.filter(e => e.id !== ex.id && e.category === ex.category).slice(0, 3);
    relatedList.innerHTML = related.map(r => exhibitListItem(r)).join('');
  }

  // Audio — use uploaded file if available, else fall back to TTS
  audioState.text = ex.description || '';
  audioState.elapsed = 0;
  audioState.audioFile = null; // reset

  // Check if exhibit has an audio file for current language
  if (ex.audio_url) {
    audioState.audioFile = ex.audio_url;
  }

  const totalWords = audioState.text.split(' ').length;
  const estSecs = Math.round(totalWords / 2.5);
  audioState.duration = estSecs;
  updateAudioUI();

  // Language label — the exhibit was fetched in STATE.lang, so say so
  // (this used to reset to "English" whatever had actually been loaded).
  const langNames = { en: 'English', fil: 'Filipino', es: 'Español' };
  const langLabel = document.getElementById('ex-lang-label');
  if (langLabel) langLabel.textContent = langNames[STATE.lang] || 'English';
  document.querySelectorAll('.lang-chip').forEach(c => {
    c.classList.toggle('active', c.getAttribute('onclick')?.includes("'" + (STATE.lang || 'en') + "'"));
  });

  switchTab('overview');
  showScreen('s-exhibit');

  // Scanned, not browsed: start reading. The visitor tapped to scan, so the
  // browser has the user activation it wants; where it still refuses (older
  // iOS), playAudio() leaves a "Tap play to listen" toast and the button.
  if (opts.autoplay && STATE.settings.narration && STATE.settings.autoplay
      && (audioState.audioFile || audioState.text)) {
    setTimeout(() => { try { playAudio(); } catch (e) {} }, 350);
  }
}

/* ── Full-screen picture ─────────────────────────────────────────────────
   The hero shows the whole image, but small. A tap opens it over everything
   at the largest size the screen allows; pinch-zoom is the browser's own. */
function openHeroImage() {
  const src = document.getElementById('ex-hero-img')?.getAttribute('src');
  if (!src) return;
  let box = document.getElementById('img-lightbox');
  if (!box) {
    box = document.createElement('div');
    box.id = 'img-lightbox';
    box.setAttribute('role', 'dialog');
    box.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.96);display:flex;align-items:center;justify-content:center;touch-action:pinch-zoom;';
    box.innerHTML = '<img alt="" style="max-width:100%;max-height:100%;object-fit:contain;">'
      + '<div onclick="closeHeroImage()" style="position:absolute;top:calc(14px + env(safe-area-inset-top));right:14px;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;cursor:pointer;"><span class="material-icons-round" style="color:#fff;font-size:24px;">close</span></div>';
    box.addEventListener('click', e => { if (e.target === box) closeHeroImage(); });
    document.body.appendChild(box);
  }
  box.querySelector('img').src = src;
  box.style.display = 'flex';
}

function closeHeroImage() {
  const box = document.getElementById('img-lightbox');
  if (box) box.style.display = 'none';
}

function updateBookmarkIcon() {
  if (!currentExhibit) return;
  const isBookmarked = STATE.bookmarks.some(b => b.id === currentExhibit.id);
  const btn = document.getElementById('bookmark-btn');
  const icon = document.getElementById('bookmark-icon');
  if (btn && icon) {
    if (isBookmarked) {
      btn.style.background = 'var(--gm)';
      btn.style.border = 'none';
      icon.textContent = 'bookmark';
      icon.style.color = 'white';
    } else {
      btn.style.background = 'var(--ew)';
      btn.style.border = '1.5px solid var(--es)';
      icon.textContent = 'bookmark_border';
      icon.style.color = 'var(--tl)';
    }
  }
}

function toggleBookmark() {
  if (!currentExhibit) return;
  const idx = STATE.bookmarks.findIndex(b => b.id === currentExhibit.id);
  if (idx >= 0) {
    STATE.bookmarks.splice(idx, 1);
    showToast('Removed from bookmarks');
  } else {
    STATE.bookmarks.unshift({ id: currentExhibit.id, code: currentExhibit.code || currentExhibit.id, title: currentExhibit.title, hall: currentExhibit.hall, category: currentExhibit.category, icon: currentExhibit.icon, gradient: currentExhibit.gradient });
    showToast('Added to bookmarks!');
  }
  saveState();
  updateBookmarkIcon();
}

function shareExhibit() {
  if (navigator.share && currentExhibit) {
    navigator.share({ title: currentExhibit.title, text: currentExhibit.description, url: window.location.href }).catch(() => {});
  } else {
    showToast('Share link copied!');
  }
}

function switchTab(tab) {
  ['overview', 'gallery', 'facts'].forEach(t => {
    const panel = document.getElementById(`tab-${t}`);
    if (panel) panel.style.display = t === tab ? 'block' : 'none';
  });
  const accent = STATE.mode === 'free' ? 'var(--bm)' : 'var(--gm)';
  document.querySelectorAll('.ex-tab').forEach(el => {
    const isActive = el.getAttribute('onclick')?.includes(`'${tab}'`);
    el.style.color       = isActive ? accent : 'var(--tl)';
    el.style.fontWeight  = isActive ? '700' : '400';
    el.style.borderBottom= isActive ? `2px solid ${accent}` : 'none';
    el.style.marginBottom= isActive ? '-2px' : '0';
  });
}

function goToNext() {
  if (!currentExhibit || !currentExhibit.storyline) return;
  const src = _exhibitCache.length ? _exhibitCache : DEMO_EXHIBITS.map(normalizeExhibit);
  const next = src.find(e => e.storyline === currentExhibit.storyline + 1);
  if (!next) { showToast('You\'ve reached the end of the storyline!'); return; }
  stopAudio();
  showScreen('s-scan-storyline');
  updateStorylineProgress();
  showNextExhibitMapHint(next);
}

function showNextExhibitMapHint(exhibit) {
  // Remove any existing hint
  const existing = document.getElementById('next-exhibit-map-hint');
  if (existing) existing.remove();

  const hint = document.createElement('div');
  hint.id = 'next-exhibit-map-hint';
  hint.style.cssText = [
    'position:fixed', 'bottom:90px', 'left:50%', 'transform:translateX(-50%)',
    'background:rgba(20,20,20,0.92)', 'color:white', 'border-radius:16px',
    'padding:14px 18px', 'display:flex', 'align-items:center', 'gap:12px',
    'z-index:9999', 'max-width:340px', 'width:calc(100% - 40px)',
    'box-shadow:0 4px 24px rgba(0,0,0,0.4)', 'border:1px solid rgba(232,160,32,0.4)'
  ].join(';');

  hint.innerHTML = `
    <div style="width:36px;height:36px;border-radius:50%;background:#E8A020;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
      <span class="material-icons-round" style="font-size:20px;color:white;">location_on</span>
    </div>
    <div style="flex:1;min-width:0;">
      <div style="font-size:calc(10px * var(--fs));color:rgba(255,255,255,0.55);text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">Next Stop · Step ${exhibit.storyline}</div>
      <div style="font-size:calc(13px * var(--fs));font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${exhibit.title}</div>
      <div style="font-size:calc(11px * var(--fs));color:rgba(255,255,255,0.6);margin-top:1px;">${exhibit.hall}${exhibit.floor ? ' · ' + exhibit.floor : ''}</div>
    </div>
    <button onclick="stopAudio();showScreen('s-map');document.getElementById('next-exhibit-map-hint')?.remove();"
            style="background:rgba(232,160,32,0.2);border:1px solid rgba(232,160,32,0.5);color:#E8A020;border-radius:10px;padding:6px 10px;font-size:calc(11px * var(--fs));font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0;">
      View Map
    </button>
  `;

  // Dismiss on tap
  hint.addEventListener('click', function(e) {
    if (!e.target.closest('button')) hint.remove();
  });

  document.getElementById('app').appendChild(hint);

  // Auto-dismiss after 8 seconds
  setTimeout(() => hint.remove(), 8000);
}

// Generate fun facts from exhibit data — uses admin-entered facts if available
function generateFunFacts(ex) {
  // Use facts from the API/admin if they exist
  if (ex.fun_facts && ex.fun_facts.length > 0) {
    return ex.fun_facts;
  }
  // Fallback: hardcoded facts keyed by exhibit_code
  const factMap = {
    'EXH-001': [
      'The 57 Spanish soldiers held out for exactly 337 days — the longest siege of the Philippine Revolution.',
      'Lieutenant Martín Cerezo refused to surrender even after receiving news that Spain had lost the war, believing it was enemy propaganda.',
      'When they finally surrendered on June 2, 1899, the soldiers were given full military honors by the American forces.',
      'The event inspired a Spanish film called "Los últimos de Filipinas" (1945), later remade in 2016.',
    ],
    'EXH-002': [
      'The Baler Church was built in 1735 and is dedicated to Saint Louis of Toulouse.',
      'The church walls are over a meter thick — which is why it could withstand months of siege.',
      'It is one of the few Spanish-era churches in the Philippines that still holds regular masses today.',
      'The church bell tower served as a lookout post during the siege.',
    ],
    'EXH-003': [
      'Aurora Province was only officially created in 1951, named after First Lady Aurora Aragon Quezon.',
      'The province has 8 municipalities, all facing the Pacific Ocean.',
      'Aurora is one of the least densely populated provinces in Luzon.',
      'The province is known for producing some of the finest rattan furniture in the Philippines.',
    ],
    'EXH-004': [
      'The Casiguran Agta are one of the few remaining hunter-gatherer groups in Southeast Asia.',
      'They have their own distinct language, Casiguran Agta, which belongs to the Austronesian family.',
      'Agta women are among the rare examples of female hunters in any human society.',
      'They have lived in the Sierra Madre forests for at least 30,000 years.',
    ],
    'EXH-005': [
      'Baler Bay was put on the international surfing map when the crew of Apocalypse Now left their surfboards behind in 1979.',
      'The bay faces the Pacific Ocean directly, receiving swells from as far as Japan.',
      'Baler is home to the "Cemetery Break" — one of the most famous surf spots in the Philippines.',
      'Sea turtles nest on the beaches of Baler Bay every year.',
    ],
    'EXH-006': [
      'Manuel L. Quezon was born in Baler on August 19, 1878.',
      'He became the first President of the Philippine Commonwealth in 1935.',
      'Quezon City, the most populous city in the Philippines, is named after him.',
      'He signed the Women\'s Suffrage Act in 1937, giving Filipino women the right to vote.',
    ],
    'EXH-007': [
      'The Sierra Madre is 540 km long — the longest mountain range in the Philippines.',
      'It is home to over 700 species of birds, including the critically endangered Philippine Eagle.',
      'The range acts as a natural shield, protecting Central Luzon from Pacific typhoons.',
      'It contains one of the last remaining old-growth forests in the Philippines.',
    ],
    'EXH-008': [
      'The bubo (fish trap) used in Baler has remained virtually unchanged for over 500 years.',
      'Traditional fishermen in Baler can read the tides and currents without any instruments.',
      'The baklad (fish corral) can span hundreds of meters and catch thousands of fish in a single tide.',
      'Baler Bay supports over 200 species of fish, many of which are endemic to the Pacific coast.',
    ],
  };
  return factMap[ex.id] || factMap[ex.code] || [
    `${ex.title} is located in ${ex.hall}, ${ex.floor}.`,
    `This exhibit belongs to the ${ex.category} collection.`,
    `Curated by ${ex.author || 'the museum team'}.`,
  ];
}

function toggleLangPicker() {
  const picker = document.getElementById('lang-picker');
  if (picker) picker.style.display = picker.style.display === 'none' ? 'block' : 'none';
}

function setLang(lang) {
  // Per-exhibit language — does NOT change the global scan language
  const labels = { en: 'English', fil: 'Filipino', es: 'Español' };
  const langLabel = document.getElementById('ex-lang-label');
  if (langLabel) langLabel.textContent = labels[lang] || 'English';
  document.querySelectorAll('.lang-chip').forEach(c => {
    c.classList.toggle('active', c.getAttribute('onclick')?.includes(`'${lang}'`));
  });
  const picker = document.getElementById('lang-picker');
  if (picker) picker.style.display = 'none';
  if (currentExhibit) {
    showToast('Language: ' + (labels[lang] || lang));
    var code = currentExhibit.code || currentExhibit.exhibit_code;
    if (code) {
      apiFetch(API_BASE + '/exhibits.php?code=' + encodeURIComponent(code) + '&lang=' + lang + '&_=' + Date.now())
        .then(function(r){ return r.json(); })
        .then(function(data){
          if (!data.error) {
            var ex = normalizeExhibit(data);
            currentExhibit = ex;
            var titleEl = document.getElementById('ex-title');
            var descEl = document.getElementById('ex-desc');
            if (titleEl) titleEl.textContent = ex.title;
            if (descEl) descEl.textContent = ex.description;
            audioState.text = ex.description || '';
            audioState.audioFile = null;
            if (ex.audio_url) {
              audioState.audioFile = ex.audio_url;
            }
            stopAudio();
            resetAudio();
            updateAudioUI();
          }
        }).catch(function(){});
    }
  }
}

function setTextSize(size) {
  STATE.settings.textSize = size;
  saveState();
  applyTextSize(size);
  document.querySelectorAll('.txt-size-btn').forEach(btn => {
    const isActive = btn.dataset.size === size;
    btn.classList.toggle('active', isActive);
    btn.style.background = isActive ? 'var(--mode-secondary)' : 'var(--ew)';
    btn.style.color = isActive ? 'white' : 'var(--tl)';
  });
  setPickerValue('settings-textsize', size);
}

// ═══════════════════════════════════════════════════════════
// AUDIO (Web Speech API)
// ═══════════════════════════════════════════════════════════
function toggleAudio() {
  if (audioState.playing) {
    stopAudio();  // pause not reliable cross-browser — stop and remember position
  } else {
    playAudio();
  }
}

/* ── Audio ───────────────────────────────────────────────────────────────
   Two sources: an uploaded narration file when the exhibit has one, or the
   browser's speech synthesiser reading the description.

   The file path was built on a wall clock. Every play() reset the element's
   src and reloaded it, so pause-then-play restarted from 0 while the bar still
   showed the old position; the progress timer counted real seconds, so at 1.5×
   it lagged the audio; seeking moved the bar and not the audio; and any
   rejected play() — the autoplay policy, say — nulled the file for good and
   quietly swapped in the synthesiser. The element is now the source of truth
   for its own position, and it is only reloaded when the source changes. */

// One <audio> element, created on first use, events bound once.
function getAudioEl() {
  let el = document.getElementById('exhibit-audio-player');
  if (el) return el;
  el = document.createElement('audio');
  el.id = 'exhibit-audio-player';
  el.preload = 'metadata';
  el.setAttribute('playsinline', '');
  el.style.display = 'none';
  document.body.appendChild(el);

  el.addEventListener('loadedmetadata', () => {
    if (isFinite(el.duration) && el.duration > 0) audioState.duration = el.duration;
    updateAudioUI();
  });
  el.addEventListener('timeupdate', () => {
    audioState.elapsed = el.currentTime;
    updateAudioUI();
  });
  el.addEventListener('play', () => {
    el.playbackRate = STATE.settings.speed || 1;
    el.volume = typeof STATE.settings.volume === 'number' ? STATE.settings.volume : 1;
    audioState.playing = true;
    updateAudioUI();
  });
  el.addEventListener('pause', () => {
    audioState.playing = false;
    audioState.elapsed = el.currentTime;
    updateAudioUI();
  });
  el.addEventListener('ended', () => {
    audioState.playing = false;
    audioState.elapsed = audioState.duration;
    updateAudioUI();
    const icon = document.getElementById('audio-play-icon');
    if (icon) icon.textContent = 'replay';
  });
  el.addEventListener('error', () => {
    // A genuine media error (404, bad codec): fall back to speech for this
    // exhibit. This is the only place the file is given up on.
    audioState.playing = false;
    audioState.audioFile = null;
    audioState.elapsed = 0;
    updateAudioUI();
    showToast('Narration file unavailable — reading the description instead');
    playAudio();
  });
  return el;
}

function playAudio() {
  // Audio Narration is the master switch for this whole path.
  if (!STATE.settings.narration) {
    showToast('Audio narration is off - turn it on in Settings');
    return;
  }
  if (!audioState.text && !audioState.audioFile) { showToast('No audio available'); return; }

  // ── Uploaded narration ──
  if (audioState.audioFile) {
    const el = getAudioEl();
    const srcChanged = el.getAttribute('data-src') !== audioState.audioFile;
    if (srcChanged) {
      el.setAttribute('data-src', audioState.audioFile);
      el.src = audioState.audioFile;
      el.load();
    }
    // Resume where it was paused (or where the bar was dragged to).
    const resumeAt = audioState.elapsed || 0;
    const seekThenPlay = () => {
      if (resumeAt > 0 && isFinite(el.duration) && resumeAt < el.duration) el.currentTime = resumeAt;
      else if (resumeAt > 0 && !isFinite(el.duration)) el.currentTime = resumeAt;
      el.play().catch(err => {
        // Usually the autoplay policy: the file is fine, it just needs a tap.
        audioState.playing = false;
        updateAudioUI();
        if (err && err.name === 'NotAllowedError') showToast('Tap play to listen');
        else showToast('Could not play narration');
      });
    };
    if (el.readyState >= 1 || !srcChanged) seekThenPlay();
    else el.addEventListener('loadedmetadata', seekThenPlay, { once: true });
    return;
  }

  // ── Speech synthesis ──
  if (!window.speechSynthesis) { showToast('Audio not supported on this browser'); return; }

  window.speechSynthesis.cancel(); // clear any pending

  const utter = new SpeechSynthesisUtterance(audioState.text);
  utter.rate = STATE.settings.speed || 1;
  utter.volume = typeof STATE.settings.volume === 'number' ? STATE.settings.volume : 1;
  utter.lang = { en:'en-US', fil:'fil-PH', es:'es-ES', ja:'ja-JP', zh:'zh-CN' }[STATE.lang] || 'en-US';

  utter.onstart = () => {
    audioState.playing = true;
    audioState.startTime = Date.now() - (audioState.elapsed * 1000);
    clearInterval(audioState.timer);
    audioState.timer = setInterval(tickAudio, 500);
    updateAudioUI();
  };
  utter.onend = () => {
    audioState.playing = false;
    audioState.elapsed = audioState.duration;
    clearInterval(audioState.timer);
    updateAudioUI();
    const icon = document.getElementById('audio-play-icon');
    if (icon) icon.textContent = 'replay';
  };
  utter.onerror = () => {
    audioState.playing = false;
    clearInterval(audioState.timer);
    updateAudioUI();
  };
  audioState.utterance = utter;
  window.speechSynthesis.speak(utter);
}

function stopAudio() {
  const el = document.getElementById('exhibit-audio-player');
  if (el && !el.paused) {
    el.pause();                      // the 'pause' listener records elapsed
  } else if (audioState.playing && !audioState.audioFile) {
    // Speech: there is no element to ask, so the wall clock is the position.
    audioState.elapsed = (Date.now() - audioState.startTime) / 1000;
  }
  window.speechSynthesis?.cancel();
  audioState.playing = false;
  clearInterval(audioState.timer);
  updateAudioUI();
}

function resetAudio() {
  window.speechSynthesis?.cancel();
  const el = document.getElementById('exhibit-audio-player');
  if (el) { el.pause(); try { el.currentTime = 0; } catch(e) {} }
  audioState.playing  = false;
  audioState.elapsed  = 0;
  audioState.utterance= null;
  clearInterval(audioState.timer);
  const fill = document.getElementById('audio-fill');
  const cur  = document.getElementById('audio-current');
  const icon = document.getElementById('audio-play-icon');
  if (fill) fill.style.width = '0%';
  if (cur)  cur.textContent = '0:00';
  if (icon) icon.textContent = 'play_arrow';
}

// Speech only: the element reports its own time through 'timeupdate'.
function tickAudio() {
  audioState.elapsed = (Date.now() - audioState.startTime) / 1000;
  if (audioState.elapsed >= audioState.duration) {
    audioState.elapsed = audioState.duration;
    audioState.playing = false;
    clearInterval(audioState.timer);
  }
  updateAudioUI();
}

function updateAudioUI() {
  const playIcon = document.getElementById('audio-play-icon');
  const fill = document.getElementById('audio-fill');
  const current = document.getElementById('audio-current');
  const total = document.getElementById('audio-total');
  if (playIcon) playIcon.textContent = audioState.playing ? 'pause' : 'play_arrow';
  const pct = audioState.duration > 0 ? Math.min(100, (audioState.elapsed / audioState.duration) * 100) : 0;
  if (fill) fill.style.width = pct + '%';
  if (current) current.textContent = formatTime(audioState.elapsed);
  if (total) total.textContent = formatTime(audioState.duration);
}

function seekAudio(e) {
  const bar = e.currentTarget;
  const rect = bar.getBoundingClientRect();
  const pct = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
  audioState.elapsed = pct * audioState.duration;
  const el = document.getElementById('exhibit-audio-player');
  if (audioState.audioFile && el && isFinite(el.duration)) {
    // The element seeks in place, playing or paused — no reload.
    el.currentTime = pct * el.duration;
  } else if (audioState.playing) {
    // Speech cannot seek: restart from the new offset.
    stopAudio();
    audioState.elapsed = pct * audioState.duration;
    playAudio();
  }
  updateAudioUI();
}

function cycleSpeed() {
  const speeds = [0.75, 1, 1.25, 1.5, 2];
  const cur = speeds.indexOf(STATE.settings.speed);
  STATE.settings.speed = speeds[(cur + 1) % speeds.length];
  saveState();
  const label = document.getElementById('speed-label');
  if (label) label.textContent = STATE.settings.speed + '×';
  setPickerValue('settings-speed', STATE.settings.speed);
  applyPlaybackSpeed();
}

// A file changes rate in place; speech has to be restarted to pick it up.
function applyPlaybackSpeed() {
  const el = document.getElementById('exhibit-audio-player');
  if (el) el.playbackRate = STATE.settings.speed || 1;
  if (audioState.playing && !audioState.audioFile && window.speechSynthesis) {
    stopAudio();
    playAudio();
  }
}

function formatTime(secs) {
  const s = Math.floor(secs || 0);
  return `${Math.floor(s / 60)}:${(s % 60).toString().padStart(2, '0')}`;
}

/* Volume.
   This button used to call adjustVolume(), which showed the toast "Volume
   adjusted" and changed nothing. Four steps is plenty for a handset held at
   arm's length, and it covers mute, which is the one people actually want. */
const VOLUME_STEPS = [1, 0.66, 0.33, 0];

function cycleVolume() {
  const cur = STATE.settings.volume;
  const i = VOLUME_STEPS.indexOf(typeof cur === 'number' ? cur : 1);
  STATE.settings.volume = VOLUME_STEPS[(i + 1) % VOLUME_STEPS.length];
  saveState();
  applyVolume();
  const pct = Math.round(STATE.settings.volume * 100);
  showToast(pct === 0 ? 'Muted' : 'Volume ' + pct + '%');
}

function applyVolume() {
  const v = typeof STATE.settings.volume === 'number' ? STATE.settings.volume : 1;
  const el = document.getElementById('exhibit-audio-player');
  if (el) el.volume = v;
  const icon = document.getElementById('volume-icon');
  if (icon) icon.textContent = v === 0 ? 'volume_off' : (v <= 0.4 ? 'volume_down' : 'volume_up');
  // Speech synthesis takes its volume per-utterance, so a change mid-sentence
  // only lands on the next one; restart so it lands now.
  if (audioState.playing && !audioState.audioFile && window.speechSynthesis) {
    stopAudio();
    playAudio();
  }
}

// ═══════════════════════════════════════════════════════════
// PROFILE
// ═══════════════════════════════════════════════════════════
function onProfileEnter() {
  // Safety: ensure arrays exist
  if (!Array.isArray(STATE.scanned)) STATE.scanned = [];
  if (!Array.isArray(STATE.bookmarks)) STATE.bookmarks = [];
  if (!Array.isArray(STATE.recentlyViewed)) STATE.recentlyViewed = [];

  const nameEl = document.getElementById('profile-name');
  if (nameEl) nameEl.textContent = STATE.name || 'Visitor';

  const provEl = document.getElementById('profile-provider');
  if (provEl) {
    // Sign-in is by email only — no social accounts.
    provEl.textContent = STATE.email || 'Visitor Account';
  }

  const modeLabel = document.getElementById('profile-mode-label');
  const modeBadge = document.getElementById('profile-mode-badge');
  if (modeLabel) modeLabel.textContent = STATE.mode === 'free' ? 'Free Explore' : 'Storyline';
  if (modeBadge) {
    const icon = modeBadge.querySelector('.material-icons-round');
    if (icon) icon.textContent = STATE.mode === 'free' ? 'explore' : 'route';
  }

  // The header follows --mode-primary/--mode-secondary from the stylesheet,
  // which applyModeTheme() already keeps in step with the mode.

  const scannedEl = document.getElementById('stat-scanned');
  const bookmarkedEl = document.getElementById('stat-bookmarked');
  const hallsEl = document.getElementById('stat-halls');
  if (scannedEl) scannedEl.textContent = STATE.scanned.length;
  if (bookmarkedEl) bookmarkedEl.textContent = STATE.bookmarks.length;
  // "Halls done" and "Museum Progress" both need the full exhibit list. It is
  // cached in memory after the first load, so this is normally synchronous.
  const paintStats = (all) => {
    if (hallsEl) hallsEl.textContent = hallProgress(all).filter(h => h.done).length;
    paintMuseumProgress(all.length || DEMO_EXHIBITS.length);
  };
  if (_exhibitCache.length) paintStats(_exhibitCache);
  else loadExhibits().then(paintStats).catch(() => paintStats(DEMO_EXHIBITS.map(normalizeExhibit)));

  const recentEl = document.getElementById('profile-recent-list');
  if (recentEl) {
    const recent = STATE.recentlyViewed.slice(0, 3);
    if (recent.length === 0) {
      recentEl.innerHTML = '<div style="text-align:center;padding:20px;color:var(--tl);font-size:calc(13px * var(--fs));">No exhibits viewed yet</div>';
    } else {
      recentEl.innerHTML = recent.map(ex => `
        <div onclick="handleScan('${String(ex.code || ex.id).replace(/[^A-Za-z0-9_-]/g, '')}', 'qr', true)" style="background:var(--w);border-radius:14px;padding:12px;display:flex;align-items:center;gap:12px;box-shadow:var(--sh);cursor:pointer;">
          <div style="width:44px;height:44px;border-radius:12px;${tileBg(ex)};display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <span class="material-icons-round" style="font-size:22px;color:white;">${ex.icon || 'museum'}</span>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:calc(13px * var(--fs));font-weight:700;color:var(--td);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${ex.title}</div>
            <div style="font-size:calc(11px * var(--fs));color:var(--tl);margin-top:2px;">${ex.hall} · ${timeAgo(ex.viewedAt)}</div>
          </div>
          <span class="material-icons-round" style="font-size:18px;color:var(--tl);">chevron_right</span>
        </div>`).join('');
    }
  }
}

function populateScanned() {
  const countEl = document.getElementById('scanned-count');
  const listEl = document.getElementById('scanned-list');
  if (countEl) countEl.textContent = `${STATE.scanned.length} total`;
  if (listEl) {
    if (STATE.scanned.length === 0) {
      listEl.innerHTML = '<div style="text-align:center;padding:40px;color:var(--tl);font-size:calc(13px * var(--fs));">No exhibits scanned yet</div>';
    } else {
      listEl.innerHTML = STATE.scanned.map(ex => `
        <div onclick="handleScan('${String(ex.code || ex.id).replace(/[^A-Za-z0-9_-]/g, '')}', 'qr', true)" style="background:var(--w);border-radius:14px;padding:12px;display:flex;align-items:center;gap:12px;box-shadow:var(--sh);cursor:pointer;">
          <div style="width:44px;height:44px;border-radius:12px;${tileBg(ex)};display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <span class="material-icons-round" style="font-size:22px;color:white;">${ex.icon || 'museum'}</span>
          </div>
          <div style="flex:1;"><div style="font-size:calc(13px * var(--fs));font-weight:700;color:var(--td);">${ex.title}</div><div style="font-size:calc(11px * var(--fs));color:var(--tl);margin-top:2px;">${ex.hall} · ${ex.category}</div></div>
          <span class="material-icons-round" style="font-size:18px;color:var(--tl);">chevron_right</span>
        </div>`).join('');
    }
  }
}

function paintMuseumProgress(total) {
  const pct = total ? Math.round((STATE.scanned.length / total) * 100) : 0;
  const pctEl = document.getElementById('profile-pct');
  const fillEl = document.getElementById('profile-prog-fill');
  const labelEl = document.getElementById('profile-prog-label');
  if (pctEl) pctEl.textContent = pct + '%';
  if (fillEl) fillEl.style.width = pct + '%';
  if (labelEl) labelEl.textContent = STATE.scanned.length + ' of ' + total + ' exhibits explored';
}

/* Per-hall completion from the real exhibit list: how many of each hall's
   exhibits have been scanned. Used by the profile stat and the Halls screen,
   which used to be three hard-coded cards reading 100%. */
function hallProgress(all) {
  const byHall = {};
  all.forEach(e => {
    const name = e.hall && e.hall !== '—' ? e.hall : 'Other';
    const h = byHall[name] || (byHall[name] = { name, total: 0, scanned: 0, category: e.category });
    h.total++;
    if (isUnlocked(e)) h.scanned++;
  });
  return Object.values(byHall)
    .map(h => Object.assign(h, { done: h.total > 0 && h.scanned === h.total }))
    .sort((a, b) => a.name.localeCompare(b.name));
}

function populateHalls() {
  const run = (all) => {
    const halls = hallProgress(all);
    const doneCount = halls.filter(h => h.done).length;
    const title = document.getElementById('halls-header-title');
    if (title) title.textContent = 'Halls Completed (' + doneCount + ')';
    const list = document.getElementById('halls-list');
    if (!list) return;
    if (!halls.length) {
      list.innerHTML = '<div style="text-align:center;padding:40px;color:var(--tl);font-size:calc(13px * var(--fs));">No halls to show yet</div>';
      return;
    }
    list.innerHTML = halls.map(h => {
      const pct = h.total ? Math.round(h.scanned / h.total * 100) : 0;
      return '<div class="hall' + (h.done ? ' done' : '') + '">' +
        '<div class="hall-row">' +
          '<div class="hall-ico"><span class="material-icons-round">' + (h.done ? 'emoji_events' : 'meeting_room') + '</span></div>' +
          '<div style="flex:1;min-width:0;">' +
            '<div class="hall-name">' + h.name + (h.category ? ' — ' + h.category : '') + '</div>' +
            '<div class="hall-sub">' + h.scanned + '/' + h.total + ' exhibits · ' + pct + '%</div>' +
          '</div>' +
          '<span class="material-icons-round hall-mark">' + (h.done ? 'check_circle' : 'radio_button_unchecked') + '</span>' +
        '</div>' +
        '<div class="prog-track"><div class="prog-fill" style="width:' + pct + '%;"></div></div>' +
      '</div>';
    }).join('');
  };
  if (_exhibitCache.length) run(_exhibitCache); else loadExhibits().then(run);
}

function populateBookmarked() {
  const titleEl = document.getElementById('bookmarked-header-title');
  const listEl = document.getElementById('bookmarked-list');
  if (titleEl) titleEl.textContent = `Bookmarked (${STATE.bookmarks.length})`;
  if (listEl) {
    if (STATE.bookmarks.length === 0) {
      listEl.innerHTML = '<div style="text-align:center;padding:40px;color:var(--tl);font-size:calc(13px * var(--fs));">No bookmarks yet</div>';
    } else {
      listEl.innerHTML = STATE.bookmarks.map(ex => `
        <div onclick="handleScan('${String(ex.code || ex.id).replace(/[^A-Za-z0-9_-]/g, '')}', 'qr', true)" style="background:var(--w);border-radius:14px;padding:12px;display:flex;align-items:center;gap:12px;box-shadow:var(--sh);cursor:pointer;">
          <div style="width:44px;height:44px;border-radius:12px;${tileBg(ex)};display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <span class="material-icons-round" style="font-size:22px;color:white;">${ex.icon || 'museum'}</span>
          </div>
          <div style="flex:1;"><div style="font-size:calc(13px * var(--fs));font-weight:700;color:var(--td);">${ex.title}</div><div style="font-size:calc(11px * var(--fs));color:var(--tl);margin-top:2px;">${ex.hall} · ${ex.category}</div></div>
          <span class="material-icons-round" style="font-size:20px;color:var(--accent-text);">bookmark</span>
        </div>`).join('');
    }
  }
}

function timeAgo(ts) {
  if (!ts) return '';
  const diff = Date.now() - ts;
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return 'Just now';
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  return `${Math.floor(hrs / 24)}d ago`;
}

// ═══════════════════════════════════════════════════════════
// MAP
// ═══════════════════════════════════════════════════════════
function updateMap() {
  const isStoryline = STATE.mode === 'storyline';
  const header = document.getElementById('map-header');
  const modeLabel = document.getElementById('map-mode-label');
  const modeBadge = document.getElementById('map-mode-badge');
  const slBar = document.getElementById('map-storyline-bar');
  const infoText = document.getElementById('map-info-text');
  const legendSl = document.getElementById('map-legend-storyline');
  const pathOverlay = document.getElementById('map-path-overlay');
  const freeNodes = document.getElementById('map-free-nodes');
  const pathOverlay2nd = document.getElementById('map-path-overlay-2nd');
  const freeNodes2nd = document.getElementById('map-free-nodes-2nd');

  if (header) header.style.background = isStoryline ? 'var(--gd)' : 'var(--bd)';
  if (modeLabel) modeLabel.textContent = isStoryline ? 'Storyline' : 'Free Explore';
  if (modeBadge) {
    const icon = modeBadge.querySelector('.material-icons-round');
    if (icon) icon.textContent = isStoryline ? 'route' : 'explore';
  }
  if (slBar) slBar.style.display = isStoryline ? 'block' : 'none';
  if (legendSl) legendSl.style.display = isStoryline ? 'flex' : 'none';
  if (pathOverlay) pathOverlay.style.display = isStoryline ? 'block' : 'none';
  if (freeNodes) freeNodes.style.display = isStoryline ? 'none' : 'block';
  if (pathOverlay2nd) pathOverlay2nd.style.display = isStoryline ? 'block' : 'none';
  if (freeNodes2nd) freeNodes2nd.style.display = isStoryline ? 'none' : 'block';

  // Always clear scan markers — re-draw only in storyline mode
  ['map-svg-ground', 'map-svg-second'].forEach(svgId => {
    const svg = document.getElementById(svgId);
    if (svg) svg.querySelectorAll('.scan-done, .scan-next').forEach(el => el.remove());
  });

  if (isStoryline) {
    refreshStorylineMapOverlay();

    const src = _exhibitCache.length ? _exhibitCache : DEMO_EXHIBITS.map(normalizeExhibit);
    const storylineExhibits = src.filter(e => e.storyline > 0).sort((a,b) => a.storyline - b.storyline);
    const scannedOrders = getScannedStorylineOrders(src);
    const nextEx = storylineExhibits.find(e => !scannedOrders.includes(e.storyline));
    const total = storylineExhibits.length || 8;
    const done = scannedOrders.length;
    const pct = Math.round(done / total * 100);

    const slLabel = document.getElementById('map-sl-label');
    const slPct = document.getElementById('map-sl-pct');
    const slFill = document.getElementById('map-sl-fill');
    if (slLabel) slLabel.textContent = 'Exhibit ' + done + ' of ' + total;
    if (slPct) slPct.textContent = pct + '%';
    if (slFill) slFill.style.width = pct + '%';

    if (nextEx) {
      const floor = mapFloorOf(nextEx);
      if (infoText) infoText.textContent = 'Next: ' + nextEx.title + ' · ' + MAP_FLOORS[floor].label;
      switchVisFloor(floor === 'ground' ? 1 : 2);
    } else {
      if (infoText) infoText.textContent = 'Storyline complete! You\'ve visited all exhibits.';
    }
  } else {
    renderMapPins();
    if (infoText) infoText.textContent = 'Tap any exhibit pin to view details';
  }

  // Floor tab colors
  const gndTab = document.getElementById('floor-gnd');
  const color = isStoryline ? 'var(--gm)' : 'var(--bd)';
  if (gndTab && currentFloor === 'ground') {
    gndTab.style.color = color;
    gndTab.style.borderBottom = `2px solid ${color}`;
    gndTab.style.marginBottom = '-2px';
  }
}

function switchMapFloor(floor) {
  currentFloor = floor;
  const gndTab = document.getElementById('floor-gnd');
  const sndTab = document.getElementById('floor-2nd');
  const isStoryline = STATE.mode === 'storyline';
  const color = isStoryline ? 'var(--gm)' : 'var(--bd)';
  if (gndTab) {
    gndTab.style.color = floor === 'ground' ? color : 'var(--tl)';
    gndTab.style.borderBottom = floor === 'ground' ? `2px solid ${color}` : 'none';
    gndTab.style.marginBottom = floor === 'ground' ? '-2px' : '0';
    gndTab.style.fontWeight = floor === 'ground' ? '700' : '400';
  }
  if (sndTab) {
    sndTab.style.color = floor === 'second' ? color : 'var(--tl)';
    sndTab.style.borderBottom = floor === 'second' ? `2px solid ${color}` : 'none';
    sndTab.style.marginBottom = floor === 'second' ? '-2px' : '0';
    sndTab.style.fontWeight = floor === 'second' ? '700' : '400';
  }
  showToast(floor === 'ground' ? 'Ground Floor' : '2nd Floor');
}

function toggleMapPath() {
  const overlay = document.getElementById('map-path-overlay');
  if (overlay) overlay.style.display = overlay.style.display === 'none' ? 'block' : 'none';
}

// ═══════════════════════════════════════════════════════════
// SETTINGS
// ═══════════════════════════════════════════════════════════
function syncSettings() {
  const s = STATE.settings;
  setToggle('toggle-narration', s.narration);
  setToggle('toggle-autoplay', s.autoplay !== false);
  setToggle('toggle-music', s.music);
  setToggle('toggle-contrast', s.highContrast);
  setToggle('toggle-darkmode', s.darkMode);
  refreshPickers();
  checkAmbientAvailable();
  applyNarrationUI();
}

/* ── Pickers ──
   The four Settings dropdowns were native <select>s. A native select ignores
   the page font, is forced up to 16px on touch devices (the iOS focus-zoom
   guard), and its option list is drawn by the OS — unstyleable, and never in
   dark mode. Each is now a button that opens the app's own bottom sheet. */
const PICKERS = {
  'settings-lang': {
    title: 'Content language', sub: 'Exhibit text and narration',
    options: [['en', 'English'], ['fil', 'Filipino'], ['es', 'Español']],
    get: () => STATE.lang || 'en', set: v => setAppLanguage(v),
  },
  'settings-mode': {
    title: 'Explore mode', sub: 'How the museum is walked',
    options: [['storyline', 'Storyline'], ['free', 'Free Explore']],
    get: () => STATE.mode || 'storyline', set: v => toggleExploreMode(v),
  },
  'settings-speed': {
    title: 'Playback speed', sub: 'Narration and audio guides',
    options: [['0.75', '0.75×'], ['1', '1.0×'], ['1.25', '1.25×'], ['1.5', '1.5×'], ['2', '2.0×']],
    get: () => String(STATE.settings.speed || 1), set: v => setPlaybackSpeed(v),
  },
  'settings-textsize': {
    title: 'Text size', sub: 'Applies across the whole app',
    options: [['small', 'Small'], ['medium', 'Medium'], ['large', 'Large']],
    get: () => STATE.settings.textSize || 'medium', set: v => setTextSize(v),
  },
};

function setPickerValue(id, value) {
  const p = PICKERS[id]; const btn = document.getElementById(id);
  if (!p || !btn) return;
  const v = String(value);
  btn.dataset.value = v;
  const hit = p.options.find(o => o[0] === v);
  const label = btn.querySelector('.pick-label');
  if (label) label.textContent = hit ? hit[1] : v;
}

function refreshPickers() {
  Object.keys(PICKERS).forEach(id => setPickerValue(id, PICKERS[id].get()));
}

function openPicker(id) {
  const p = PICKERS[id]; if (!p) return;
  closePicker();
  const current = String(p.get());
  const sheet = document.createElement('div');
  sheet.id = 'picker-sheet';
  sheet.className = 'sheet-overlay open';
  sheet.innerHTML =
    '<div class="sheet">' +
      '<div class="sheet-handle"></div>' +
      '<div class="pick-title">' + p.title + '</div>' +
      (p.sub ? '<div class="pick-sub">' + p.sub + '</div>' : '') +
      '<div class="pick-list">' +
        p.options.map(([v, l]) =>
          '<button class="pick-row' + (v === current ? ' on' : '') + '" data-value="' + v + '">' +
            '<span>' + l + '</span>' +
            '<span class="material-icons-round">' + (v === current ? 'radio_button_checked' : 'radio_button_unchecked') + '</span>' +
          '</button>').join('') +
      '</div>' +
      '<button class="btn btn-outline btn-full" style="margin-top:6px;" onclick="closePicker()">Cancel</button>' +
    '</div>';
  sheet.addEventListener('click', e => {
    if (e.target === sheet) { closePicker(); return; }
    const row = e.target.closest('.pick-row');
    if (!row) return;
    const v = row.dataset.value;
    closePicker();
    setPickerValue(id, v);
    p.set(v);
  });
  document.getElementById('app').appendChild(sheet);
}

function closePicker() {
  const s = document.getElementById('picker-sheet');
  if (s) s.remove();
}

function setToggle(id, on) {
  const el = document.getElementById(id);
  if (!el) return;
  const knob = el.querySelector('div');
  if (on) {
    el.style.background = 'var(--gm)';
    el.classList.add('on');
    if (knob) { knob.style.left = ''; knob.style.right = '2px'; }
  } else {
    el.style.background = 'var(--toggle-off)';
    el.classList.remove('on');
    if (knob) { knob.style.right = ''; knob.style.left = '2px'; }
  }
}

function toggleAutoplay() {
  STATE.settings.autoplay = !STATE.settings.autoplay;
  setToggle('toggle-autoplay', STATE.settings.autoplay);
  saveState();
  showToast(STATE.settings.autoplay ? 'Narration will start on scan' : 'Narration waits for Play');
}

function toggleNarration() {
  STATE.settings.narration = !STATE.settings.narration;
  setToggle('toggle-narration', STATE.settings.narration);
  // Switching it off mid-sentence should stop the sentence.
  if (!STATE.settings.narration) { try { stopAudio(); } catch(e){} }
  applyNarrationUI();
  saveState();
  showToast('Narration ' + (STATE.settings.narration ? 'on' : 'off'));
}

// Fade the player on the exhibit screen rather than leaving a live-looking
// control that answers every tap with a toast.
function applyNarrationUI() {
  const on = !!STATE.settings.narration;
  // #audio-player-bar is the transport on the exhibit screen; .audio-bar is the
  // stylesheet's own class, kept in the list in case markup starts using it.
  document.querySelectorAll('#audio-player-bar, .audio-bar').forEach(bar => {
    bar.style.opacity = on ? '' : '0.45';
    bar.title = on ? '' : 'Audio narration is off (Settings)';
  });
  const headphones = document.querySelector('[onclick="toggleAudio()"]');
  if (headphones) headphones.style.opacity = on ? '' : '0.45';
}

/* Background music.
   There is no ambient track in the repo, so rather than ship a switch that
   controls nothing, the row hides itself unless ./audio/ambient.mp3 exists --
   and starts working the moment one is dropped in. */
const AMBIENT_SRC = './audio/ambient.mp3';
let ambientEl = null;

function ambientAudio() {
  if (!ambientEl) {
    ambientEl = document.createElement('audio');
    ambientEl.id = 'ambient-player';
    ambientEl.src = AMBIENT_SRC;
    ambientEl.loop = true;
    ambientEl.volume = 0.18;   // under the narration, never over it
    ambientEl.preload = 'none';
    document.body.appendChild(ambientEl);
  }
  return ambientEl;
}

function toggleMusic() {
  STATE.settings.music = !STATE.settings.music;
  setToggle('toggle-music', STATE.settings.music);
  saveState();
  applyMusic();
  showToast('Music ' + (STATE.settings.music ? 'on' : 'off'));
}

function applyMusic() {
  const el = ambientAudio();
  if (STATE.settings.music) {
    // Autoplay stays blocked until the page has been interacted with; by the
    // time anyone reaches Settings it has been, so this normally just works.
    el.play().catch(() => showToast('Tap anywhere first, then turn music on'));
  } else {
    el.pause();
  }
}

// Hide the row when there is nothing to play, so the switch is never a lie.
function checkAmbientAvailable() {
  const row = document.getElementById('row-music');
  if (!row) return;
  fetch(AMBIENT_SRC, { method: 'HEAD' })
    .then(r => { if (!r.ok) row.style.display = 'none'; })
    .catch(() => { row.style.display = 'none'; });
}

function setPlaybackSpeed(val) {
  STATE.settings.speed = parseFloat(val);
  saveState();
  // The exhibit screen has its own speed chip. Both write the same setting, so
  // both have to show the same number.
  const label = document.getElementById('speed-label');
  if (label) label.textContent = STATE.settings.speed + '×';
  setPickerValue('settings-speed', STATE.settings.speed);
  applyPlaybackSpeed();
  showToast('Speed: ' + val + '×');
}

function toggleDarkMode() {
  STATE.settings.darkMode = !STATE.settings.darkMode;
  applyDarkMode();
  setToggle('toggle-darkmode', STATE.settings.darkMode);
  saveState();
  showToast(`Dark mode ${STATE.settings.darkMode ? 'on' : 'off'}`);
}

function applyDarkMode() {
  const on = !!STATE.settings.darkMode;
  document.body.classList.toggle('dark-mode', on);
  // The browser's own chrome (address bar, task-switcher card) reads this, so
  // leaving it green in dark mode puts a bright band above a dark app.
  const meta = document.querySelector('meta[name="theme-color"]');
  if (meta) meta.setAttribute('content', on ? '#0e1013' : '#2D5016');
}

// First run only: inherit whatever the phone is already set to. Once the
// visitor has touched the switch, their choice is what counts.
function initDarkModePreference() {
  if (typeof STATE.settings.darkMode === 'boolean') return;
  STATE.settings.darkMode =
    !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
}

function applyTextSize(size) {
  document.body.classList.remove('text-small','text-medium','text-large');
  document.body.classList.add('text-' + (size || 'medium'));
}

// Dark Mode, Text Size and High Contrast are all just classes on <body>, so one
// place re-applies the lot: on boot, and after anything that rebuilds it.
function applyAllPreferences() {
  initDarkModePreference();
  applyDarkMode();
  applyTextSize(STATE.settings.textSize);
  document.body.classList.toggle('high-contrast', !!STATE.settings.highContrast);
  applyModeTheme();
}

function toggleHighContrast() {
  STATE.settings.highContrast = !STATE.settings.highContrast;
  setToggle('toggle-contrast', STATE.settings.highContrast);
  document.body.classList.toggle('high-contrast', STATE.settings.highContrast);
  saveState();
  showToast(`High contrast ${STATE.settings.highContrast ? 'on' : 'off'}`);
}

/* Content language.
   The Settings dropdown used to call setLang(), which is the *exhibit screen's*
   language picker: it relabels that screen and refetches the exhibit on view,
   but never writes STATE.lang -- so the setting did nothing and did not even
   remember itself. STATE.lang is what the exhibit API and the speech
   synthesiser are keyed on, so that is what this writes.

   Scope, stated plainly: this switches exhibit text and narration, which is
   where the museum's own translations live. The app's own chrome is not
   translated -- there is no string table for it, and inventing one is a
   separate piece of work. The label in Settings says so. */
function setAppLanguage(lang) {
  const labels = { en: 'English', fil: 'Filipino', es: 'Espanol' };
  STATE.lang = lang || 'en';
  saveState();

  setPickerValue('settings-lang', STATE.lang);

  // Anything already narrating is in the old language.
  try { stopAudio(); } catch(e) {}

  // Drop the cached exhibit payloads so the next read comes back translated.
  try {
    Object.keys(sessionStorage)
      .filter(k => k.indexOf('mb_exhibits') === 0)
      .forEach(k => sessionStorage.removeItem(k));
  } catch(e) {}

  // Re-pull whatever is on screen, in the new language.
  if (currentExhibit) { try { setLang(STATE.lang); } catch(e) {} }
  try { loadExhibits(STATE.lang); } catch(e) {}

  showToast('Language: ' + (labels[STATE.lang] || STATE.lang));
}

function toggleExploreMode(val) {
  STATE.mode = val || (STATE.mode === 'storyline' ? 'free' : 'storyline');
  saveState();
  applyModeTheme();
  showToast(`Mode: ${STATE.mode === 'storyline' ? 'Storyline' : 'Free Explore'}`);
  setPickerValue('settings-mode', STATE.mode);
}

// ═══════════════════════════════════════════════════════════
// FEEDBACK — the ARTA Client Satisfaction Measurement survey
//
// Three steps in one sheet: Citizen's Charter (CC1–3), Service Quality
// Dimensions (SQD0–8), then the museum's own — star rating, guide, the
// APP_* questions and a comment. Steps 1–2 and the APP_* block are built
// from the question list the `survey` action returns, so the Tourism
// office can edit wording, add or retire questions without touching this.
// ═══════════════════════════════════════════════════════════
const SURVEY = { questions: [], answers: {}, step: 1, defaults: {}, regions: [], guided: false };

// Question text is typed by the Tourism office, not by visitors, but it is
// still text going into innerHTML — a stray "<" in a question must not eat
// the rest of the sheet.
function escapeHtml(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// Text in the visitor's language. The paper form is Filipino; the sheet is
// Filipino-first with English underneath when the app is set to Filipino,
// English-first otherwise.
function surveyLang() { return STATE.lang === 'fil' ? 'fil' : 'en'; }
function surveyText(q, key) {
  const lang = surveyLang(), other = lang === 'fil' ? 'en' : 'fil';
  return { main: q[key + '_' + lang] || q[key + '_' + other] || '', sub: q[key + '_' + other] || '' };
}

const SURVEY_FACES = {
  1: 'sentiment_very_dissatisfied',
  2: 'sentiment_dissatisfied',
  3: 'sentiment_neutral',
  4: 'sentiment_satisfied',
  5: 'sentiment_very_satisfied',
};

function openFeedback() {
  feedbackRating = 0;
  guideRating = 0;
  SURVEY.answers = {};
  SURVEY.step = 1;
  document.querySelectorAll('.star').forEach(s => s.classList.remove('active'));
  const textEl = document.getElementById('feedback-text');
  if (textEl) textEl.value = '';

  // Sheet chrome in the visitor's language.
  const lang = surveyLang();
  document.querySelectorAll('#feedback-sheet [data-fil]').forEach(el => {
    el.textContent = el.getAttribute('data-' + lang) || el.getAttribute('data-en');
  });
  if (textEl) textEl.placeholder = textEl.getAttribute('data-' + lang + '-ph') || textEl.getAttribute('data-en-ph');

  document.getElementById('survey-loading').style.display = 'block';
  document.querySelectorAll('.survey-step').forEach(s => s.style.display = 'none');
  document.getElementById('survey-nav').style.display = 'none';
  document.getElementById('feedback-sheet').classList.add('open');

  if (!STATE.visitorId) return;

  apiFetch(`${API_BASE}/visitor.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'survey' })
  })
    .then(r => r.json())
    .then(data => {
      SURVEY.questions = (data && data.questions) || [];
      SURVEY.defaults  = (data && data.defaults)  || {};
      SURVEY.regions   = (data && data.regions)   || [];
      SURVEY.guided    = !!(data && data.guided);
      SURVEY.answers   = { _client_type: SURVEY.defaults.client_type || 'citizen', _region: SURVEY.defaults.region || '' };

      // SQD5 defaults to N/A — admission is usually free.
      SURVEY.questions.forEach(q => { if (q.default_na) SURVEY.answers[q.code] = null; });

      const block = document.getElementById('guide-feedback');
      if (block) {
        block.style.display = SURVEY.guided ? 'block' : 'none';
        if (SURVEY.guided) {
          const label = document.getElementById('guide-feedback-label');
          if (label) label.textContent = (lang === 'fil' ? 'Kumusta ang iyong guide, ' : 'How was your guide, ') + data.guide_name + '?';
        }
      }

      renderSurveyStep(1);
      renderSurveyStep(2);
      renderSurveyStep(3);
      document.getElementById('survey-loading').style.display = 'none';
      document.getElementById('survey-nav').style.display = 'block';
      showSurveyStep(1);
    })
    .catch(() => {
      // Offline or an old server: fall back to the plain star rating.
      SURVEY.questions = [];
      document.getElementById('survey-loading').style.display = 'none';
      document.getElementById('survey-nav').style.display = 'block';
      document.getElementById('survey-app-questions').innerHTML = '';
      showSurveyStep(3);
    });
}

// Sections map to steps; "app" questions live inside step 3.
function surveyQuestionsFor(step) {
  const section = { 1: 'cc', 2: 'sqd', 3: 'app' }[step];
  return SURVEY.questions.filter(q => q.section === section);
}

function renderSurveyStep(step) {
  const host = step === 3
    ? document.getElementById('survey-app-questions')
    : document.getElementById('survey-step-' + step);
  const qs = surveyQuestionsFor(step);
  const lang = surveyLang();
  let html = '';

  // The paper form's header fields that the visitor record cannot answer.
  if (step === 1) {
    const ct = SURVEY.answers._client_type || 'citizen';
    const ctLabels = lang === 'fil'
      ? { citizen: 'Mamamayan', business: 'Negosyo', government: 'Gobyerno' }
      : { citizen: 'Citizen', business: 'Business', government: 'Government' };
    html += `<div class="survey-q">
      <div class="survey-q-text">${lang === 'fil' ? 'Uri ng Kliyente at Rehiyon' : 'Client type and region'}</div>
      <div class="survey-header" style="margin-top:8px">
        <select class="fi" onchange="SURVEY.answers._client_type=this.value">
          ${Object.keys(ctLabels).map(k => `<option value="${k}" ${k === ct ? 'selected' : ''}>${ctLabels[k]}</option>`).join('')}
        </select>
        <select class="fi" onchange="SURVEY.answers._region=this.value">
          ${SURVEY.regions.map(r => `<option value="${escapeHtml(r)}" ${r === SURVEY.answers._region ? 'selected' : ''}>${escapeHtml(r)}</option>`).join('')}
        </select>
      </div>
    </div>`;
  }

  qs.forEach(q => {
    const t = surveyText(q, 'text'), h = surveyText(q, 'hint');
    // CC1 / SQD0 are printed on the paper form, so visitors recognise them;
    // the museum's own codes are bookkeeping and stay out of sight.
    html += `<div class="survey-q" id="sq-${q.code}" data-code="${q.code}">
      ${q.section === 'app' ? '' : `<div class="survey-q-code">${escapeHtml(q.code)}</div>`}
      <div class="survey-q-text">${escapeHtml(t.main)}</div>
      ${t.sub ? `<div class="survey-q-sub">${escapeHtml(t.sub)}</div>` : ''}
      ${h.main ? `<div class="survey-q-hint">${escapeHtml(h.main)}</div>` : ''}
      ${q.scale === 'choice' ? renderChoices(q, lang) : renderScale(q, lang)}
    </div>`;
  });

  host.innerHTML = html;
  qs.forEach(q => paintSurveyAnswer(q));
}

function renderScale(q, lang) {
  return `<div class="survey-scale">` + q.choices.map(c => {
    const v = c.na ? 'na' : c.value;
    const icon = c.na ? 'block' : SURVEY_FACES[c.value] || 'circle';
    return `<button type="button" class="${c.na ? 'na' : ''}" data-v="${v}" onclick="setSurveyAnswer('${q.code}','${v}')" title="${escapeHtml(c[lang] || c.en)}">
      <span class="material-icons-round">${icon}</span><small>${c.na ? 'N/A' : escapeHtml(shortScaleLabel(c.value, lang))}</small>
    </button>`;
  }).join('') + `</div>`;
}

// Face captions need to fit six across a phone.
function shortScaleLabel(v, lang) {
  const fil = { 1: 'Lubos na hindi', 2: 'Hindi', 3: 'Walang kinikilingan', 4: 'Sumasang-ayon', 5: 'Labis' };
  const en  = { 1: 'Strongly disagree', 2: 'Disagree', 3: 'Neutral', 4: 'Agree', 5: 'Strongly agree' };
  return (lang === 'fil' ? fil : en)[v] || '';
}

function renderChoices(q, lang) {
  return `<div class="survey-choices">` + q.choices.map(c => {
    const v = c.na ? 'na' : c.value;
    return `<label class="radio-pill" data-v="${v}" onclick="setSurveyAnswer('${q.code}','${v}')">
      <span class="material-icons-round">radio_button_unchecked</span>
      <span>${escapeHtml(c[lang] || c.en)}</span>
    </label>`;
  }).join('') + `</div>`;
}

function setSurveyAnswer(code, v) {
  SURVEY.answers[code] = v === 'na' ? null : parseInt(v, 10);
  const q = SURVEY.questions.find(x => x.code === code);
  if (q) paintSurveyAnswer(q);
  // A CC1 change can hide or reveal CC2/CC3.
  SURVEY.questions.filter(x => x.show_if && x.show_if.code === code).forEach(paintSurveyAnswer);
}

function surveyIsShown(q) {
  if (!q.show_if || !q.show_if.code) return true;
  const dep = SURVEY.answers[q.show_if.code];
  return dep !== undefined && dep !== null && (q.show_if.in || []).map(Number).includes(dep);
}

function paintSurveyAnswer(q) {
  const el = document.getElementById('sq-' + q.code);
  if (!el) return;
  el.style.display = surveyIsShown(q) ? '' : 'none';
  el.classList.remove('missing');
  const has = Object.prototype.hasOwnProperty.call(SURVEY.answers, q.code);
  const cur = has ? (SURVEY.answers[q.code] === null ? 'na' : String(SURVEY.answers[q.code])) : undefined;
  el.querySelectorAll('[data-v]').forEach(b => {
    const on = has && b.getAttribute('data-v') === cur;
    b.classList.toggle('on', on);
    b.classList.toggle('active', on);
    if (b.classList.contains('radio-pill')) {
      const ic = b.querySelector('.material-icons-round');
      if (ic) ic.textContent = on ? 'radio_button_checked' : 'radio_button_unchecked';
    }
  });
}

function showSurveyStep(step) {
  // Skip a step with nothing to ask (e.g. every CC question retired).
  if (step < 3 && surveyQuestionsFor(step).length === 0) {
    return showSurveyStep(step + (step >= SURVEY.step ? 1 : -1));
  }
  SURVEY.step = step;
  const lang = surveyLang();
  document.querySelectorAll('.survey-step').forEach(s => s.style.display = 'none');
  document.getElementById('survey-step-' + step).style.display = 'block';
  document.querySelectorAll('#survey-dots i').forEach((d, i) => d.classList.toggle('on', i + 1 === step));

  const subs = lang === 'fil'
    ? { 1: "Tungkol sa Citizen's Charter (CC) ng tanggapan", 2: 'Piliin ang sagot na pinakaangkop sa iyo', 3: 'Tungkol sa museo at sa app' }
    : { 1: "About the office's Citizen's Charter (CC)", 2: 'Pick the answer that best fits how you feel', 3: 'About the museum and the app' };
  document.getElementById('survey-step-sub').textContent = subs[step];

  const first = step === 1 || (step === 2 && surveyQuestionsFor(1).length === 0);
  document.getElementById('survey-back').style.display = first ? 'none' : '';
  const next = document.getElementById('survey-next');
  next.innerHTML = step === 3
    ? `<span class="material-icons-round">send</span>${lang === 'fil' ? 'Ipasa' : 'Submit'}`
    : `${lang === 'fil' ? 'Susunod' : 'Next'}<span class="material-icons-round">arrow_forward</span>`;

  document.querySelector('#feedback-sheet .sheet').scrollTop = 0;
}

// Every shown, required question on this step must have an answer.
function surveyStepComplete(step) {
  let ok = true, firstMissing = null;
  surveyQuestionsFor(step).forEach(q => {
    if (!q.required || !surveyIsShown(q)) return;
    if (!Object.prototype.hasOwnProperty.call(SURVEY.answers, q.code)) {
      ok = false;
      const el = document.getElementById('sq-' + q.code);
      if (el) { el.classList.add('missing'); firstMissing = firstMissing || el; }
    }
  });
  if (firstMissing) firstMissing.scrollIntoView({ behavior: 'smooth', block: 'center' });
  return ok;
}

function surveyBack() {
  if (SURVEY.step > 1) showSurveyStep(SURVEY.step - 1);
}

function surveyNext() {
  const lang = surveyLang();
  if (!surveyStepComplete(SURVEY.step)) {
    showToast(lang === 'fil' ? 'Pakisagutan ang lahat ng tanong' : 'Please answer every question');
    return;
  }
  if (SURVEY.step < 3) return showSurveyStep(SURVEY.step + 1);
  submitFeedback();
}

function setGuideRating(n) {
  guideRating = n;
  document.querySelectorAll('.guide-star').forEach((s, i) => {
    s.classList.toggle('active', i < n);
  });
}

function closeFeedback() {
  document.getElementById('feedback-sheet').classList.remove('open');
}

function setRating(n) {
  feedbackRating = n;
  // Scoped to the museum row: the sheet also carries a guide star row,
  // and a bare '.star' selector would light both up together.
  document.querySelectorAll('#star-row .star').forEach((s, i) => {
    s.classList.toggle('active', i < n);
  });
}

function submitFeedback() {
  const lang = surveyLang();
  if (!feedbackRating) { showToast(lang === 'fil' ? 'Pumili ng rating' : 'Please select a rating'); return; }
  const text = document.getElementById('feedback-text')?.value || '';

  // Only the question answers go under `answers`; the header fields are
  // their own keys. No survey loaded (offline fallback) means no `answers`
  // key at all, which the API treats as a star-only submission.
  const body = {
    // Authorship comes from the session token, not from the body.
    action: 'feedback',
    rating: feedbackRating,
    guide_rating: guideRating,
    comment: text,
  };
  if (SURVEY.questions.length) {
    body.answers = {};
    SURVEY.questions.forEach(q => {
      if (Object.prototype.hasOwnProperty.call(SURVEY.answers, q.code)) body.answers[q.code] = SURVEY.answers[q.code];
    });
    body.client_type = SURVEY.answers._client_type || 'citizen';
    body.region = SURVEY.answers._region || '';
  }

  showLoading(true);
  apiFetch(`${API_BASE}/visitor.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  })
  .then(r => r.json())
  .then(d => {
    if (d && d.error === 'missing_answer') {
      // The question bank changed while the sheet was open; reload it.
      showToast(lang === 'fil' ? 'May kulang na sagot — pakisubukang muli' : 'An answer is missing — please try again');
      openFeedback();
      return;
    }
    closeFeedback();
    showToast(lang === 'fil' ? 'Maraming salamat sa iyong sagot!' : 'Thank you for your feedback!');
  })
  .catch(() => {
    closeFeedback();
    showToast(lang === 'fil' ? 'Maraming salamat sa iyong sagot!' : 'Thank you for your feedback!');
  })
  .finally(() => showLoading(false));
}

// ═══════════════════════════════════════════════════════════
// LOGOUT
// ═══════════════════════════════════════════════════════════
function confirmLogout() {
  document.getElementById('logout-sheet').classList.add('open');
}

function doLogout() {
  stopAudio();
  stopScanner();
  stopClearancePolling();

  // Tell the server to forget the token too, so signing out on a borrowed
  // handset actually ends the session rather than just hiding it locally.
  if (STATE.token) {
    apiFetch(`${API_BASE}/visitor.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'logout' })
    }).catch(() => {});
  }

  STATE = {
    visitorId: null, name: '', email: '', provider: 'manual',
    firstName: '', lastName: '', middleName: '', age: '', sex: '',
    country: 'Philippines', city: '', province: '',
    visitType: 'Walk-in', visitorType: 'Local',
    admissionFee: 0, paymentStatus: 'Free', idVerified: false,
    token: null, clearance: 'pending_payment', cleared: false, group: null, modeSelected: false,
    mode: 'storyline', lang: 'en',
    scanned: [], bookmarks: [], recentlyViewed: [],
    storylineProgress: 0,
    settings: { narration: true, autoplay: true, music: false, speed: 1, textSize: 'medium', highContrast: false, volume: 1 }
  };
  saveState();
  // Clear the sign-in form so the next visitor starts from a blank slate —
  // especially the password fields, on a device that gets handed around.
  pendingSignup = null;
  ['reg-email', 'reg-first', 'reg-last', 'reg-password', 'reg-password2'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  showRegError('');
  setAuthMode('signin');
  document.getElementById('logout-sheet').classList.remove('open');
  showScreen('s-register');
}

// ═══════════════════════════════════════════════════════════
// NOTIFICATIONS
// ═══════════════════════════════════════════════════════════
function loadNotifications() {
  return apiFetch(`${API_BASE}/notifications.php?visitorId=${STATE.visitorId}`)
    .then(r => r.json())
    .catch(() => []);
}

// ═══════════════════════════════════════════════════════════
// SERVICE WORKER RETIREMENT + PWA INSTALL
// ═══════════════════════════════════════════════════════════
/* The app no longer ships a service worker: offline support was removed. A
   phone that installed the old one still has it, though, and a registered
   worker keeps answering from its cache until something unregisters it —
   including serving the *old* app that still tries to register it. This runs
   on every boot and is a no-op once the device is clean. */
function retireServiceWorker() {
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistrations()
      .then(regs => regs.forEach(r => r.unregister()))
      .catch(() => {});
  }
  if (window.caches) {
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k.indexOf('museobaler') === 0).map(k => caches.delete(k))))
      .catch(() => {});
  }
}

let _installPromptEvent = null;

// Add-to-home-screen. Chrome fires beforeinstallprompt off the manifest alone;
// no worker is involved. The sheet only ever shows if the event arrives.
function setupInstallPrompt() {
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    _installPromptEvent = e;
    // Show install sheet after a short delay (don't interrupt on first load)
    setTimeout(() => {
      const sheet = document.getElementById('pwa-install-sheet');
      // Only show if not already installed and user is on home screen
      if (sheet && !window.matchMedia('(display-mode: standalone)').matches) {
        sheet.style.display = 'block';
      }
    }, 8000);
  });

  // Hide install sheet if already installed
  window.addEventListener('appinstalled', () => {
    const sheet = document.getElementById('pwa-install-sheet');
    if (sheet) sheet.style.display = 'none';
    showToast('App installed! Find it on your home screen.');
  });
}

function triggerInstall() {
  if (!_installPromptEvent) return;
  _installPromptEvent.prompt();
  _installPromptEvent.userChoice.then(() => {
    _installPromptEvent = null;
    const sheet = document.getElementById('pwa-install-sheet');
    if (sheet) sheet.style.display = 'none';
  });
}

function dismissInstallPrompt() {
  const sheet = document.getElementById('pwa-install-sheet');
  if (sheet) sheet.style.display = 'none';
}

// ═══════════════════════════════════════════════════════════
// UI HELPERS
// ═══════════════════════════════════════════════════════════
function showToast(msg, duration = 2500) {
  const el = document.getElementById('toast');
  if (!el) return;
  el.textContent = msg;
  el.classList.add('show');
  setTimeout(() => el.classList.remove('show'), duration);
}

function showLoading(show) {
  const el = document.getElementById('loading');
  if (el) el.classList.toggle('show', show);
}

// ── MUSEUM INFO ──────────────────────────────────────────────
let _museumInfoLoaded = false;

function loadMuseumInfo() {
  if (_museumInfoLoaded) return;
  // BUGFIX: this used to point at /controller/museumController.php, which
  // doesn't exist (only qrController.php lives in that folder) — every call
  // 404'd and silently failed via the catch below, so the About screen never
  // actually loaded story/hours/contact/halls from the database.
  apiFetch(`${API_BASE}/museum.php`)
    .then(r => r.json())
    .then(data => {
      const info  = data.info  || {};
      const halls = data.halls || [];

      // Story
      const s1 = document.getElementById('about-story');
      const s2 = document.getElementById('about-story2');
      if (s1 && info.story)  s1.textContent = info.story;
      if (s2 && info.story2) s2.textContent = info.story2;

      // Contact info
      const set = (id, val) => { const el = document.getElementById(id); if (el && val) el.textContent = val; };
      set('about-address',   info.address);
      set('about-hours',     info.hours);
      set('about-closed',    'Closed on ' + (info.closed_on || ''));
      set('about-admission', info.admission);
      set('about-phone',     info.phone);
      set('about-email',     info.email);

      // Halls
      const hallsEl = document.getElementById('about-halls');
      if (hallsEl && halls.length) {
        hallsEl.innerHTML = halls.map(h => `
          <div style="display:flex;align-items:center;gap:10px;padding:10px;background:var(--ew);border-radius:10px;">
            <div style="width:32px;height:32px;border-radius:8px;background:var(--mode-pale);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
              <span class="material-icons-round" style="font-size:16px;color:var(--mode-primary);">${h.icon || 'meeting_room'}</span>
            </div>
            <div>
              <div style="font-size:calc(13px * var(--fs));font-weight:600;color:var(--td);">${h.name}</div>
              <div style="font-size:calc(11px * var(--fs));color:var(--tl);">${h.floor || ''} · ${h.description || ''}</div>
            </div>
          </div>`).join('');
      }

      _museumInfoLoaded = true;
    })
    .catch(() => {}); // silently fail — fallback content stays
}

// ═══════════════════════════════════════════════════════════
// LIGHTBOX
// ═══════════════════════════════════════════════════════════
function openLightbox(url, caption) {
  var existing = document.getElementById('app-lightbox');
  if (existing) existing.remove();

  var lb = document.createElement('div');
  lb.id = 'app-lightbox';
  lb.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.92);z-index:99999;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px;';
  lb.innerHTML =
    '<button onclick="document.getElementById(\'app-lightbox\').remove()" style="position:absolute;top:16px;right:16px;background:rgba(255,255,255,0.15);border:none;border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;cursor:pointer;">' +
      '<span class="material-icons-round" style="color:white;font-size:20px;">close</span>' +
    '</button>' +
    '<img src="' + url + '" style="max-width:100%;max-height:75vh;border-radius:10px;object-fit:contain;" alt="' + (caption||'') + '">' +
    (caption ? '<div style="color:rgba(255,255,255,0.75);font-size:calc(13px * var(--fs));margin-top:12px;text-align:center;">' + caption + '</div>' : '');

  lb.addEventListener('click', function(e) { if (e.target === lb) lb.remove(); });
  document.getElementById('app').appendChild(lb);
}

// ═══════════════════════════════════════════════════════════
// GEOFENCING — ENTRY / EXIT DETECTION
// ═══════════════════════════════════════════════════════════

// Defaults — overwritten by _loadMuseumConfig() from the admin-editable
// Museum Info page (museum_info.latitude/longitude/geofence_radius_m) once
// that fetch resolves. Kept as fallbacks so geofencing still works if the
// fetch fails or the fields haven't been set yet.
let MUSEUM_LAT      = 15.760440549923766;
let MUSEUM_LNG      = 121.56169583726937;
let GEOFENCE_RADIUS  = 150; // meters — production

function _loadMuseumConfig() {
  apiFetch(`${API_BASE}/museum.php`)
    .then(r => r.json())
    .then(data => {
      const info = data && data.info;
      if (!info) return;
      // Admission fee — the sign-up fee box may already be on screen.
      if (info.admission_fee != null && !isNaN(parseFloat(info.admission_fee))) {
        ADMISSION_FEE = parseFloat(info.admission_fee);
        try { updateFeeBox(); } catch (e) {}
      }
      if (info.latitude != null && info.longitude != null) {
        MUSEUM_LAT = parseFloat(info.latitude);
        MUSEUM_LNG = parseFloat(info.longitude);
      }
      if (info.geofence_radius_m != null) {
        GEOFENCE_RADIUS = parseInt(info.geofence_radius_m, 10);
      }
      // The server, not the hostname, says whether the fence is relaxed for
      // testing. Until it answers (or if it never does) the fence is enforced.
      _geoServerRelaxed = info.geofence_enforced === false;
    })
    .catch(() => {}); // silently fail — hardcoded defaults stay in effect
}

// True only when the server said so: VISITOR_GEOFENCE=false on a non-
// production install (see api/museum.php). Used to be a hostname sniff —
// localhost, any IP address, tunnel domains — which would have switched the
// fence off for every visitor the day the museum served the app on its LAN.
let _geoServerRelaxed = false;

// While the server has relaxed the fence, the entry/exit flow can be
// exercised from a desk instead of the museum grounds. That is the wrong
// behavior when demoing the real geofence over a tunnel — everyone standing
// anywhere gets the welcome toast — so it can be overridden: load the app
// once with ?geo=strict to enforce the true radius on this device, or
// ?geo=relax to go back. The choice sticks in localStorage so the query
// string does not have to be reapplied on every navigation. It can only
// tighten the fence, never loosen it: on production the server never relaxes.
let _geoStrict = false;
try {
  const _geoParam = new URLSearchParams(window.location.search).get('geo');
  if (_geoParam === 'strict') localStorage.setItem('mb_geo_strict', '1');
  if (_geoParam === 'relax')  localStorage.removeItem('mb_geo_strict');
  _geoStrict = localStorage.getItem('mb_geo_strict') === '1';
} catch (e) { /* private mode — fall through to the server's answer */ }

// True only while the fence should be bypassed entirely.
function _geoRelaxed() {
  return _geoServerRelaxed && !_geoStrict;
}

// NOTE: read fresh inside initGeofence()'s watchPosition callback, not
// cached — GEOFENCE_RADIUS can change after _loadMuseumConfig() resolves,
// and a const snapshot taken at this point would go stale.
function _activeRadius() {
  return GEOFENCE_RADIUS;
}

function haversineDistance(lat1, lng1, lat2, lng2) {
  const R = 6371000;
  const toRad = d => d * Math.PI / 180;
  const dLat = toRad(lat2 - lat1);
  const dLng = toRad(lng2 - lng1);
  const a = Math.sin(dLat/2)**2 + Math.cos(toRad(lat1))*Math.cos(toRad(lat2))*Math.sin(dLng/2)**2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
}

let _geofenceWatchId = null;
let _insideGeofence  = false;
let _entryTime       = null;

// How much vaguer than the fence a position fix may be before it is ignored.
// See the reasoning at the gate itself in initGeofence().
const ACCURACY_CEILING_MULTIPLE = 3;

function _today() {
  return new Date().toISOString().slice(0, 10);
}

// True when this device already has an open attendance record for today, i.e.
// the visitor is returning to an app they had open earlier in the same visit.
function _sameVisitInProgress() {
  return !!(STATE._attendanceId && STATE._attendanceDate === _today() && STATE._entryTime);
}

function initGeofence() {
  if (!navigator.geolocation) return;

  _geofenceWatchId = navigator.geolocation.watchPosition(
    (pos) => {
      const { latitude, longitude, accuracy } = pos.coords;
      const radius = _activeRadius();

      // Only readings so vague they say nothing about which side of the fence
      // the phone is on get thrown away. `accuracy` is the phone's own radius
      // of uncertainty, not a margin on the museum's pin: a fix meaning
      // "somewhere within 500m of here" can land on the museum while its owner
      // is at the market, and that becomes a visitor who never walked in.
      //
      // The ceiling is deliberately generous rather than set at the radius.
      // Indoors — concrete walls, a roof — accuracy routinely degrades past
      // the width of the fence itself, so a tighter gate would discard the
      // readings of people actually standing in the building, which is the one
      // group that must never be missed. Anything looser than three fences is
      // a cell-tower guess rather than a position; below that we accept the
      // reading and let the distance check decide.
      if (!_geoRelaxed() && accuracy != null && accuracy > radius * ACCURACY_CEILING_MULTIPLE) return;

      const dist   = haversineDistance(latitude, longitude, MUSEUM_LAT, MUSEUM_LNG);
      const inside = _geoRelaxed() || dist <= radius;

      if (inside && !_insideGeofence) {
        // ── ENTRY ──────────────────────────────────────────
        _insideGeofence = true;

        // Reopening the app mid-visit continues that visit instead of starting
        // a second one: _insideGeofence lives in memory and resets on every
        // page load, so without this a visitor who reloads looks like a fresh
        // arrival and their dwell time restarts from zero.
        const resuming = _sameVisitInProgress();
        _entryTime = resuming ? STATE._entryTime : Date.now();
        STATE._entryTime = _entryTime;
        saveState();

        if (!resuming) showToast('🏛️ Welcome to Museo de Baler!', 3500);
        logAttendance(latitude, longitude, Math.round(accuracy));

      } else if (!inside && _insideGeofence) {
        // ── EXIT ───────────────────────────────────────────
        _insideGeofence = false;
        const mins = _entryTime ? Math.round((Date.now() - _entryTime) / 60000) : null;
        _entryTime = null;
        const msg = mins !== null ? `👋 Thanks for visiting! (${mins} min)` : '👋 Thanks for visiting!';
        showToast(msg, 3500);
        logAttendanceExit(mins);
      }
    },
    () => {},
    // High accuracy is required, not a nicety: a 150 m fence cannot be
    // resolved by coarse network positioning, which routinely reports a
    // several-hundred-metre radius and would now be rejected outright by the
    // accuracy gate above. maximumAge keeps this from re-fixing on every
    // sample, which is what actually costs battery.
    { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 }
  );
}

function logAttendance(lat, lng, accuracy) {
  // One record per device per day. The server only de-duplicates once it knows
  // a visitor_id, and an anonymous arrival by definition has none yet — so
  // without this guard every reopen of the app inserted another attendance row
  // and inflated the day's count.
  if (_sameVisitInProgress()) return;

  apiFetch(`${API_BASE}/attendance.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      visitor_id:   parseInt(STATE.visitorId) || null,
      visitor_name: STATE.name || '',
      latitude:     lat,
      longitude:    lng,
      accuracy:     accuracy,
      method:       'geofence',
    })
  })
  .then(r => r.ok ? r.json() : null)
  .then(data => {
    if (data && data.ok && data.attendance_id) {
      STATE._attendanceId   = data.attendance_id;
      STATE._attendanceDate = _today();
      saveState();
    }
  })
  .catch(() => {});
}

// ── Dwell time ───────────────────────────────────────────────
// watchPosition stops firing the moment the page is hidden or the phone locks,
// so for most visits the real exit is never observed and duration_mins would
// stay null on nearly every record. Instead, mark the visitor as still present
// whenever the app goes away; the record then carries the last moment they were
// confirmed on site. The server keeps whichever duration is longest, so a
// visitor who backgrounds the app repeatedly extends their visit rather than
// truncating it.
function markLastSeen() {
  if (!_insideGeofence || !_entryTime) return;

  const aid = STATE._attendanceId;
  if (!aid) return;

  apiFetch(`${API_BASE}/attendance.php`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    // keepalive lets the request outlive the page being hidden or closed. A
    // plain fetch here is routinely cancelled before it leaves the device,
    // which is the whole reason dwell time was going unrecorded.
    keepalive: true,
    body: JSON.stringify({
      attendance_id: aid,
      event:         'exit',
      duration_mins: Math.round((Date.now() - _entryTime) / 60000),
    })
  }).catch(() => {});
}

document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'hidden') markLastSeen();
});
// pagehide covers the cases visibilitychange misses — notably Safari on iOS,
// where a swipe back to the home screen can skip straight to teardown.
window.addEventListener('pagehide', markLastSeen);

function logAttendanceExit(durationMins) {
  const aid = STATE._attendanceId;
  if (!aid) return;
  apiFetch(`${API_BASE}/attendance.php`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      attendance_id: aid,
      event:         'exit',
      duration_mins: durationMins,
    })
  }).catch(() => {});
}

function claimAttendance(visitorId, visitorName) {
  const aid = STATE._attendanceId;
  if (!aid || !visitorId || STATE._attendanceClaimed) return;
  apiFetch(`${API_BASE}/attendance.php`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      attendance_id: aid,
      visitor_id:    parseInt(visitorId),
      visitor_name:  visitorName,
    })
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok && data.claimed) {
      // The id is deliberately kept rather than cleared. markLastSeen() needs
      // it to keep extending dwell time, and dropping it here meant a visitor
      // stopped being tracked the moment they registered. Re-claiming is
      // harmless: the server only claims rows that are still anonymous, so a
      // second attempt simply comes back claimed:false.
      STATE._attendanceClaimed = true;
      saveState();
    }
  })
  .catch(() => {});
}
