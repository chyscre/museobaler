{{-- Notifications in the top-right corner, shared by the desktop and phone
     layouts.

     Saved / failed messages go here rather than into a banner above the page:
     a banner pushed the whole page down as it appeared and again as it left,
     which moved whatever was being read or clicked. The text is still in the
     HTML (#flashMsg), so it is there for anything reading the page rather
     than looking at it.

     A form the server sent back gets one plain sentence here, and the fields
     it named get a red border. The specific reasons stay next to the form,
     where the page puts them. --}}
<div class="toasts" id="toasts" role="status" aria-live="polite"></div>

@if(session('success') || session('error'))
  <div id="flashMsg" hidden data-kind="{{ session('error') ? 'red' : 'green' }}">{{ session('error') ?: session('success') }}</div>
@elseif($errors->any())
  <div id="flashMsg" hidden data-kind="red">Some information is missing. Please check the highlighted fields.</div>
@endif

<script>
(function () {
  var ICONS = {
    ok:   '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    bad:  '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
    info: '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>'
  };
  var MAX = 4;

  function dismiss(el, acted) {
    if (el._gone) return;
    el._gone = true;
    clearTimeout(el._timer);
    el.classList.remove('show');
    setTimeout(function () { el.remove(); }, 250);
    if (el._onClose) el._onClose(!!acted);
  }

  // toast(message, 'green' | 'gold' | 'red' | 'blue', actions, onClose). Global:
  // pages and the live-activity poll call it.
  //
  // actions, optional: [{ label, href }] for a link, or [{ label, run,
  // primary }] for something done in place - run(button) is called and the
  // toast closes. A primary one is the filled button. onClose(acted) runs
  // when it goes, acted being whether one of its buttons was used.
  window.toast = function (msg, type, actions, onClose) {
    type = type || 'green';
    actions = actions || [];
    var box = document.getElementById('toasts');
    if (!box || !msg) return;

    var el = document.createElement('div');
    el.className = 'toast t-' + type;
    el.setAttribute('role', type === 'red' ? 'alert' : 'status');
    var icon = type === 'red' ? ICONS.bad : type === 'blue' ? ICONS.info : ICONS.ok;
    el.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' + icon + '</svg>'
      + '<div class="toast-body"><span class="toast-msg"></span></div>'
      + '<button type="button" class="toast-x" aria-label="Dismiss">&times;</button>';
    // As text, never markup: messages carry visitor-supplied names.
    el.querySelector('.toast-msg').textContent = msg;
    el._onClose = onClose;
    el.querySelector('.toast-x').addEventListener('click', function () { dismiss(el); });

    if (actions.length) {
      var row = document.createElement('div');
      row.className = 'toast-actions';
      actions.forEach(function (a) {
        var b = document.createElement(a.href ? 'a' : 'button');
        b.className = 'toast-btn' + (a.primary ? ' primary' : '');
        b.textContent = a.label;
        if (a.href) b.href = a.href;
        else {
          b.type = 'button';
          b.addEventListener('click', function () { dismiss(el, true); a.run(b); });
        }
        row.appendChild(b);
      });
      el.querySelector('.toast-body').appendChild(row);
    }

    // Four seconds, held while the pointer is on it so a long one can be
    // read. One with buttons gets eight: four is not long enough to notice
    // it, move the mouse over and decide.
    var life = actions.length ? 8000 : 4000;
    function arm() { el._timer = setTimeout(function () { dismiss(el); }, life); }
    el.addEventListener('mouseenter', function () { clearTimeout(el._timer); });
    el.addEventListener('mouseleave', arm);

    box.insertBefore(el, box.firstChild);
    while (box.children.length > MAX) dismiss(box.lastChild);
    requestAnimationFrame(function () { el.classList.add('show'); });
    arm();
  };

  // What to tell a person when a request from the page fails. Takes the
  // fetch Response (or its status) when there was one, nothing when the
  // request never got an answer. Status codes stay out of the message: they
  // mean nothing to the person reading it.
  window.friendlyError = function (res) {
    var s = typeof res === 'number' ? res : (res && typeof res.status === 'number' ? res.status : 0);
    if (!s || !navigator.onLine) return 'Connection lost. Please check your network and try again.';
    if (s === 422) return 'Some information is missing. Please check the highlighted fields.';
    if (s === 419) return 'This page was open too long. Reload it and try again.';
    if (s === 401) return 'You have been signed out. Sign in again, then try again.';
    if (s === 403) return 'Your account is not allowed to do that.';
    if (s === 413) return 'That file is too large. Try a smaller one.';
    if (s === 429) return 'Too many tries in a row. Wait a minute and try again.';
    return 'Something went wrong. Please try again in a moment.';
  };

  // Mark the fields a refused form named. Laravel keys array fields as
  // "photos.0", which is the input named "photos[]".
  var bad = @json($errors->keys());
  bad.forEach(function (key) {
    var base  = key.split('.')[0];
    var names = [key, base, base + '[]'];
    names.forEach(function (n) {
      document.querySelectorAll('[name="' + n.replace(/"/g, '') + '"]').forEach(function (f) {
        if (f.type === 'hidden' || f.type === 'radio' || f.type === 'checkbox') return;
        f.classList.add('is-invalid');
        f.setAttribute('aria-invalid', 'true');
        f.addEventListener('input', function () {
          f.classList.remove('is-invalid');
          f.removeAttribute('aria-invalid');
        }, { once: true });
      });
    });
  });

  var fm = document.getElementById('flashMsg');
  if (fm) setTimeout(function () { window.toast(fm.textContent.trim(), fm.dataset.kind); }, 60);
})();
</script>
