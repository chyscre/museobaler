/* ═══════════════════════════════════════════════════════════
   Museo de Baler — Visitor App  |  viewport.js
   ───────────────────────────────────────────────────────────
   Everything the CSS cannot work out on its own about the device
   the app is actually running on. Loaded before app.js and kept
   deliberately standalone — it touches no app state.
   ═══════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  var root = document.documentElement;

  // ── 1 · REAL VIEWPORT HEIGHT ────────────────────────────────
  // css/app.css sets --app-h to 100dvh where the browser supports it.
  // Where it does not (iOS < 15.4, Chrome < 108, and any WebView built
  // on them), 100vh is the fallback — and 100vh on those browsers is
  // measured as if the URL bar were collapsed, so it is 60–100px taller
  // than the screen. The bottom nav ends up below the fold with no way
  // to reach it but pinch-zooming the page out.
  //
  // window.innerHeight is the height that is genuinely visible right now,
  // so on those browsers we write it into --app-h ourselves. An inline
  // style on :root outranks the stylesheet, and we only opt in when dvh
  // is missing so modern browsers keep the smoother native behaviour.
  var hasDvh = window.CSS && CSS.supports && CSS.supports('height', '100dvh');

  function syncHeight() {
    root.style.setProperty('--app-h', window.innerHeight + 'px');
  }

  if (!hasDvh) {
    syncHeight();
    window.addEventListener('resize', syncHeight);
    // Older iOS reports the pre-rotation height if you measure immediately.
    window.addEventListener('orientationchange', function () {
      setTimeout(syncHeight, 250);
    });
  }

  // ── 2 · SOFT KEYBOARD ───────────────────────────────────────
  // When the keyboard opens, visualViewport shrinks but the layout
  // viewport often does not — so a full-height app keeps its bottom nav
  // pinned under the keyboard, hiding the field being typed into. Flag
  // the state and let the CSS drop the nav (see body.kb-open).
  var vv = window.visualViewport;
  if (vv) {
    var baseline = vv.height;

    vv.addEventListener('resize', function () {
      // A shrink of more than a quarter of the screen is a keyboard, not
      // the URL bar collapsing (which is nearer a tenth).
      var open = vv.height < baseline * 0.75;
      document.body.classList.toggle('kb-open', open);
      if (!open) baseline = Math.max(baseline, vv.height);
    });

    window.addEventListener('orientationchange', function () {
      setTimeout(function () {
        baseline = vv.height;
        document.body.classList.remove('kb-open');
      }, 300);
    });
  }

  // Keep the focused field in view once the keyboard has finished animating.
  document.addEventListener('focusin', function (e) {
    var t = e.target;
    if (!t || !/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)) return;
    if (t.type === 'radio' || t.type === 'checkbox' || t.type === 'file') return;
    setTimeout(function () {
      try { t.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (err) {}
    }, 320);
  });

  // ── 3 · DEBUG BADGE — ?debug=1 ──────────────────────────────
  // The numbers you cannot read off a phone any other way: what the
  // device reports, what the CSS resolved to, and whether the safe-area
  // insets are actually arriving. Used by preview.html and when testing
  // on a real handset over the tunnel.
  if (/[?&]debug=1\b/.test(location.search)) {
    var badge = document.createElement('div');
    badge.id = 'vp-debug';
    document.addEventListener('DOMContentLoaded', function () {
      document.body.appendChild(badge);
    });

    var read = function (name) {
      return getComputedStyle(root).getPropertyValue(name).trim() || '0px';
    };

    function paint() {
      var app = document.getElementById('app');
      badge.textContent =
        'screen  ' + window.innerWidth + '\u00d7' + window.innerHeight +
        '  dpr ' + (window.devicePixelRatio || 1) + '\n' +
        'app     ' + (app ? Math.round(app.offsetWidth) + '\u00d7' + Math.round(app.offsetHeight) : '\u2014') +
        '  dvh ' + (hasDvh ? 'yes' : 'no') + '\n' +
        'safe    t' + read('--safe-t') + ' b' + read('--safe-b') +
        ' l' + read('--safe-l') + ' r' + read('--safe-r');
    }

    paint();
    window.addEventListener('resize', paint);
    window.addEventListener('orientationchange', function () { setTimeout(paint, 300); });
    document.addEventListener('DOMContentLoaded', paint);
    setInterval(paint, 1000);
  }
})();
