<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  {{-- Lets the notification panel POST the admission actions without rendering
       a separate Blade form per pending visitor. --}}
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'Museo de Baler — Admin')</title>
  <link href="https://fonts.googleapis.com/css2?family=Young+Serif&family=Instrument+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  @vite(['resources/css/app.css'])
  @stack('styles')
</head>
<body>
<div class="layout">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-icon">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      </div>
      <div>
        <div class="brand-name">Museo de Baler</div>
        <div class="brand-sub">Admin Panel</div>
      </div>
    </div>

    <nav class="sidebar-nav">
      {{-- Two panels behind one login.

           The Tourism office is one person, working from the municipal office
           and overseeing the museum: she needs the staff, what the staff did,
           what visitors said, and the paperwork. Nothing else. Museum staff
           get the operational screens she has no business in — the desk, the
           exhibits, the map, clocking in. --}}
      @if(auth()->user()->isTourismHead())

        <a href="{{ route('staff-attendance.index') }}" class="nav-item {{ request()->routeIs('staff-attendance.*') || request()->routeIs('corrections.*') ? 'active' : '' }}">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
          <span>Staff Attendance</span>
        </a>
        <a href="{{ route('staff.index') }}" class="nav-item {{ request()->routeIs('staff.*') ? 'active' : '' }}">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <span>Staff</span>
        </a>
        <a href="{{ route('records.index') }}" class="nav-item {{ request()->routeIs('records.*') ? 'active' : '' }}">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>
          <span>Visitor Records</span>
        </a>
        <a href="{{ route('feedback.index') }}" class="nav-item {{ request()->routeIs('feedback.*') || request()->routeIs('survey.*') ? 'active' : '' }}">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          <span>Feedback</span>
        </a>
        <a href="{{ route('logs.index') }}" class="nav-item {{ request()->routeIs('logs.*') ? 'active' : '' }}">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
          <span>Activity Log</span>
        </a>

      @else

        {{-- Seven doors. Pages that belong to one of them - guided tours
             to the desk, recognition to the exhibits, the floor map to
             museum info - open from a button on that page, not from here,
             and keep its item lit while they are open. --}}
        <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
          <span>Dashboard</span>
        </a>
        <a href="{{ route('desk.register') }}" class="nav-item {{ request()->routeIs('desk.*') || request()->routeIs('tours.*') ? 'active' : '' }}">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8"/><path d="M2 21h20"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <span>Front Desk</span>
        </a>
        <a href="{{ route('exhibits.index') }}" class="nav-item {{ request()->routeIs('exhibits.*') || request()->routeIs('recognition.*') ? 'active' : '' }}">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
          <span>Exhibit</span>
        </a>
        <a href="{{ route('records.index') }}" class="nav-item {{ request()->routeIs('records.*') ? 'active' : '' }}">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>
          <span>Records</span>
        </a>
        <a href="{{ route('logs.index') }}" class="nav-item {{ request()->routeIs('logs.*') ? 'active' : '' }}">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
          <span>Logs</span>
        </a>
        <a href="{{ route('staff-attendance.index') }}" class="nav-item {{ request()->routeIs('staff-attendance.*') || request()->routeIs('corrections.*') ? 'active' : '' }}">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
          <span>Staff Attendance</span>
        </a>
        <a href="{{ route('my.attendance') }}" class="nav-item {{ request()->routeIs('my.attendance*') ? 'active' : '' }}">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="9 16 11 18 15 14"/></svg>
          <span>My Attendance</span>
        </a>
        <a href="{{ route('feedback.index') }}" class="nav-item {{ request()->routeIs('feedback.*') || request()->routeIs('survey.*') ? 'active' : '' }}">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          <span>Feedback</span>
        </a>
        <a href="{{ route('museum.index') }}" class="nav-item {{ request()->routeIs('museum.*') ? 'active' : '' }}">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
          <span>Museum Info</span>
        </a>

      @endif
    </nav>

    <div class="sidebar-footer">
      <div class="user-info">
        <div class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 2)) }}</div>
        <div class="user-text">
          <div class="user-name" title="{{ auth()->user()->name }}">{{ auth()->user()->name }}</div>
          <div class="user-role" title="{{ auth()->user()->role_label }}">{{ auth()->user()->role_label }}</div>
        </div>
      </div>
      <div class="user-actions">
        {{-- Everyone owns their own password, including the Tourism office —
             an account nobody can change the password of is an account whose
             credential stays wherever it first leaked. --}}
        <a href="{{ route('password.edit') }}" class="icon-btn" title="Change my password">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <span>Password</span>
        </a>
        {{-- Notification Bell — the live desk feed (check-ins, unpaid
             fees, unverified IDs) is museum-floor work, so the Tourism
             office does not get it. Its poll route is museum-only too. --}}
        @if(!auth()->user()->isTourismHead())
        <button id="notifBtn" class="icon-btn" onclick="toggleNotifPanel()" title="Notifications">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <span id="notifBadge" class="dot" style="display:none"></span>
          <span>Alerts</span>
        </button>
        @endif
        <form method="POST" action="{{ route('logout') }}">
          @csrf
          <button type="submit" class="logout-btn" title="Log out">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <span>Log out</span>
          </button>
        </form>
      </div>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="main-content">
    @if(!auth()->user()->isTourismHead())
    <!-- Notification panel — fixed top-right, won't clip against sidebar -->
    <div id="notifPanel" style="display:none;position:fixed;top:16px;right:16px;width:320px;background:var(--surface);border:1.5px solid var(--border);border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.15);z-index:9999;overflow:hidden">
      <div style="padding:12px 16px 10px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
        <div style="display:flex;align-items:center;gap:8px">
          <span style="font-size:13px;font-weight:700;color:var(--text)">Live Activity</span>
          <span id="notifTodayCount" style="font-size:11px;color:var(--green-dark);font-weight:600;background:var(--green-pale);padding:2px 7px;border-radius:99px"></span>
        </div>
        <button onclick="toggleNotifPanel()" style="border:none;background:none;cursor:pointer;color:var(--text-3);font-size:18px;line-height:1;padding:0 2px">&times;</button>
      </div>
      <div id="notifList" style="max-height:320px;overflow-y:auto">
        <div style="padding:20px;text-align:center;font-size:12px;color:var(--text-3)">No new activity yet</div>
      </div>
      <div style="padding:10px 14px;border-top:1px solid var(--border);display:flex;gap:8px">
        <a href="{{ route('records.index') }}?tab=visitors" style="flex:1;text-align:center;font-size:11px;font-weight:600;color:var(--text-2);text-decoration:none;padding:6px;border-radius:6px;background:var(--border-light)">Visitors</a>
        <a href="{{ route('records.index') }}?tab=attendance" style="flex:1;text-align:center;font-size:11px;font-weight:600;color:var(--green-dark);text-decoration:none;padding:6px;border-radius:6px;background:var(--green-pale)">Attendance</a>
      </div>
    </div>
    @endif

    <!-- TOAST -->
    <div class="toast" id="toast">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px;flex-shrink:0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <span id="toastMsg"></span>
    </div>

    {{-- Saved / failed messages go to the toast in the corner, not a banner
         above the page: a banner pushed the whole page down as it appeared
         and again as it left, which moved whatever was being read or clicked.
         The text is still in the HTML, so it is there for anything reading
         the page rather than looking at it. --}}
    @if(session('success') || session('error'))
      <div id="flashMsg" hidden data-kind="{{ session('error') ? 'red' : 'green' }}">{{ session('error') ?: session('success') }}</div>
    @endif

    @yield('content')
  </main>
</div>

@stack('scripts')
<script>
  lucide.createIcons();

  // Textareas that grow with their text (description, fun facts). A fixed
  // three-row box hid everything past the third line; now the box is as
  // tall as what is in it, never shorter than its rows attribute.
  // Exposed as window.autogrow(root) so forms loaded into a modal can call
  // it after they arrive.
  window.autogrow = function (root) {
    (root || document).querySelectorAll('textarea[data-autogrow]').forEach(function (ta) {
      if (ta._autogrow) return;
      ta._autogrow = true;
      var fit = function () { ta.style.height = 'auto'; ta.style.height = (ta.scrollHeight + 2) + 'px'; };
      ta.style.overflowY = 'hidden';
      ta.addEventListener('input', fit);
      // Hidden at first (a closed modal, a folded section) - measure once
      // it is visible: when a fold above it opens, or on first focus.
      ta._fit = fit;
      if (ta.offsetParent) fit(); else ta.addEventListener('focus', fit, { once: true });
    });
  };
  autogrow();

  // A textarea inside a closed <details> has no size to measure, so fit the
  // ones in a section as it opens.
  document.addEventListener('toggle', function (ev) {
    var d = ev.target;
    if (!d || d.tagName !== 'DETAILS' || !d.open) return;
    d.querySelectorAll('textarea[data-autogrow]').forEach(function (ta) { if (ta._fit) ta._fit(); });
  }, true);

  // Gallery pictures in the exhibit form: x takes the picture off the list
  // straight away, as removing should, but only by ticking a hidden box the
  // form carries - the file goes when the form is saved, and Cancel or
  // "Put them back" brings it back. A one-line note keeps count.
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-gallery-remove], [data-gallery-undo]');
    if (!btn) return;
    var gallery = btn.closest('form').querySelector('[data-gallery]');
    var note    = btn.closest('form').querySelector('[data-gallery-note]');
    if (btn.hasAttribute('data-gallery-remove')) {
      var item = btn.closest('[data-gallery-item]');
      item.querySelector('input[type=checkbox]').checked = true;
      item.style.display = 'none';
    } else {
      gallery.querySelectorAll('[data-gallery-item]').forEach(function (it) {
        it.querySelector('input[type=checkbox]').checked = false;
        it.style.display = '';
      });
    }
    var n = gallery.querySelectorAll('input[type=checkbox]:checked').length;
    note.querySelector('[data-gallery-count]').textContent = n;
    note.style.display = n ? '' : 'none';
  });

  // Filter bar. At phone width the search box is an icon until tapped, then
  // the full-width field; it folds back on blur if nothing was typed. Each
  // iconed select gets `.on` while it is off its first option, and a title
  // with the chosen label, since the icon-only form shows no text.
  document.querySelectorAll('.search-box').forEach(function(box){
    var inp = box.querySelector('input'); if (!inp) return;
    if (inp.value) box.classList.add('open');
    box.addEventListener('click', function(){
      if (box.classList.contains('open')) return;
      box.classList.add('open'); inp.focus();
    });
    inp.addEventListener('blur', function(){ if (!inp.value) box.classList.remove('open'); });
  });
  document.querySelectorAll('.fctl select').forEach(function(sel){
    function mark(){
      sel.parentElement.classList.toggle('on', sel.selectedIndex > 0);
      var opt = sel.options[sel.selectedIndex];
      sel.title = opt ? opt.textContent.trim() : '';
    }
    sel.addEventListener('change', mark); mark();
  });

  // Flash messages from the last request, shown as a toast.
  var fm = document.getElementById('flashMsg');
  if (fm) setTimeout(function () { toast(fm.textContent.trim(), fm.dataset.kind); }, 60);

  // Toast helper
  var _toastTimer;
  function toast(msg, type) {
    type = type || 'green';
    var el = document.getElementById('toast');
    el.className = 'toast t-' + type + ' show';
    document.getElementById('toastMsg').textContent = msg;
    clearTimeout(_toastTimer);
    _toastTimer = setTimeout(function(){ el.classList.remove('show'); }, 3000);
  }

  // ── Live notification polling ──────────────────────────────
  // Museum-floor only: the poll route is museum-staff-only, so running this
  // for the Tourism office would just 403 every 30 seconds.
  @if(!auth()->user()->isTourismHead())
  var _notifOpen = false;
  var _seenIds   = {};
  var _allNotifs = [];
  var _pending   = [];
  var _lastPoll  = null; // set after first poll
  var POLL_URL   = '{{ route("notifications.poll") }}';

  // Built from named routes rather than hardcoded strings so they stay correct
  // when the app is served from a subdirectory (e.g. /museobaler/public).
  var REFRESH_PATHS = [
    '{{ rtrim(parse_url(route("dashboard"), PHP_URL_PATH), "/") }}',
    '{{ rtrim(parse_url(route("records.index"), PHP_URL_PATH), "/") }}',
    '{{ rtrim(parse_url(route("attendance.index"), PHP_URL_PATH), "/") }}',
    '{{ rtrim(parse_url(route("feedback.index"), PHP_URL_PATH), "/") }}'
  ];

  function toggleNotifPanel() {
    _notifOpen = !_notifOpen;
    document.getElementById('notifPanel').style.display = _notifOpen ? 'block' : 'none';
    if (_notifOpen) document.getElementById('notifBadge').style.display = 'none';
  }

  document.addEventListener('click', function(e) {
    if (_notifOpen && !document.getElementById('notifBtn').contains(e.target) && !document.getElementById('notifPanel').contains(e.target)) {
      _notifOpen = false;
      document.getElementById('notifPanel').style.display = 'none';
    }
  });

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
  }

  // Outstanding desk tasks, rendered above the activity feed with the action
  // button inline so the fee or ID check can be cleared without navigating away.
  function renderPending() {
    if (!_pending.length) return '';

    return '<div style="padding:8px 14px 6px;font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--text-3);background:var(--border-light)">Needs attention</div>'
      + _pending.map(function(p) {
        var isPay = p.type === 'payment';
        return '<div style="display:flex;align-items:center;gap:10px;padding:9px 14px;border-bottom:1px solid var(--border-light)">'
          + '<span style="color:' + (isPay ? '#ef4444' : 'var(--green-dark)') + ';margin-top:1px">'
          + '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;flex-shrink:0">'
          + (isPay
              ? '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'
              : '<path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/>')
          + '</svg></span>'
          + '<a href="' + esc(p.url) + '" style="flex:1;min-width:0;font-size:12px;color:var(--text);text-decoration:none;line-height:1.4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(p.message) + '</a>'
          + '<button type="button" class="btn ' + (isPay ? 'btn-green' : 'btn-outline') + ' btn-xs" style="flex-shrink:0"'
          + ' onclick="runAdmissionAction(\'' + esc(p.action_url) + '\')">' + esc(p.action) + '</button>'
          + '</div>';
      }).join('');
  }

  // Posts mark-paid / verify-id straight from the panel, then reloads so the
  // records table and the badge both reflect the change.
  function runAdmissionAction(url) {
    var body = new FormData();
    body.append('_token', document.querySelector('meta[name="csrf-token"]').content);
    fetch(url, { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function() { window.location.reload(); })
      .catch(function() { toast('Could not complete that action', 'blue'); });
  }

  function renderNotifList() {
    var list = document.getElementById('notifList');
    var pendingHtml = renderPending();

    if (!_allNotifs.length) {
      list.innerHTML = pendingHtml
        + '<div style="padding:16px;text-align:center;font-size:12px;color:var(--text-3)">No activity yet today</div>';
      return;
    }
    list.innerHTML = pendingHtml + _allNotifs.slice(0, 10).map(function(n) {
      var ICONS = {
        attendance: '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        scan:       '<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="7" y1="12" x2="17" y2="12"/>',
        visitor:    '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        payment:    '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
        id_check:   '<path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/>',
        feedback:   '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'
      };
      var icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;flex-shrink:0">'
        + (ICONS[n.type] || ICONS.visitor) + '</svg>';
      var color = n.type === 'attendance' ? 'var(--green-dark)'
                : n.type === 'scan'       ? 'var(--green)'
                : n.type === 'payment'    ? '#b45309'
                : n.type === 'id_check'   ? 'var(--green-dark)'
                : n.type === 'feedback'   ? '#2563eb'
                : 'var(--text-2)';
      // Messages embed visitor-supplied names, so escape before injecting.
      return '<a href="' + esc(n.url) + '" style="display:flex;align-items:flex-start;gap:10px;padding:9px 14px;text-decoration:none;border-bottom:1px solid var(--border-light)" onmouseover="this.style.background=\'var(--border-light)\'" onmouseout="this.style.background=\'\'">'
        + '<span style="color:' + color + ';margin-top:1px">' + icon + '</span>'
        + '<div style="flex:1;min-width:0">'
        + '<div style="font-size:12px;color:var(--text);line-height:1.4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(n.message) + '</div>'
        + '<div style="font-size:10px;color:var(--text-3);margin-top:2px">' + esc(n.time) + '</div>'
        + '</div></a>';
    }).join('');
  }

  function pollNotifications(isInit) {
    var url = isInit
      ? POLL_URL + '?init=1'
      : POLL_URL + (_lastPoll ? '?since=' + encodeURIComponent(_lastPoll) : '');

    fetch(url)
      .then(function(r){ return r.json(); })
      .then(function(data) {
        // The server clock is the baseline for the next poll: it is in the
        // app timezone, like the rows, so the window is exactly "since the
        // last answer" - on init as well, so the first real poll does not
        // reach back over hours the init already showed.
        if (data.server_time) _lastPoll = data.server_time;

        var countEl = document.getElementById('notifTodayCount');
        if (countEl) countEl.textContent = (data.today_attendance || 0) + ' today';

        _pending = data.pending || [];

        if (isInit) {
          // Fill the panel with everything that has happened today and mark it
          // seen, so a fresh login shows the day's activity without firing a
          // burst of toasts for events the admin has already missed.
          _allNotifs = (data.items || []).slice(0, 20);
          _allNotifs.forEach(function(n) { _seenIds[n.id] = true; });
          renderNotifList();
          // The badge still lights for outstanding desk tasks — those are not
          // "seen" until someone actually collects the fee or checks the ID.
          if (_pending.length) document.getElementById('notifBadge').style.display = 'block';
          return;
        }

        // Normal poll — only show items we haven't seen before
        var newItems = (data.items || []).filter(function(n) {
          if (_seenIds[n.id]) return false;
          _seenIds[n.id] = true;
          return true;
        });

        // Pending tasks change independently of new events (the desk clearing a
        // fee, or a visitor becoming due one), so keep the panel in step either way.
        renderNotifList();
        if (_pending.length && !_notifOpen) document.getElementById('notifBadge').style.display = 'block';

        if (newItems.length > 0) {
          _allNotifs = newItems.concat(_allNotifs).slice(0, 20);
          renderNotifList();
          if (!_notifOpen) document.getElementById('notifBadge').style.display = 'block';
          toast(newItems.length === 1
            ? newItems[0].message
            : newItems[0].message + ' (+' + (newItems.length - 1) + ' more)',
            newItems[0].type === 'visitor' || newItems[0].type === 'feedback' ? 'blue' : 'green');

          // Auto-refresh the read-only pages whose figures the new record
          // changes. Deliberately limited to these three — reloading while
          // someone is mid-way through an exhibit or staff form would throw
          // away their unsaved input.
          if (REFRESH_PATHS.indexOf(window.location.pathname.replace(/\/$/, '')) > -1) {
            setTimeout(function(){ window.location.reload(); }, 1500);
          }
        }
      })
      .catch(function(){});
  }

  // Init immediately, then every 10s - close enough to live that the desk
  // sees a phone registration before the visitor reaches the counter, and
  // a poll is one small JSON request. Paused while the tab is hidden and
  // caught up the moment it is shown again.
  _lastPoll = null;
  pollNotifications(true);
  var _pollTimer = setInterval(function(){ if (!document.hidden) pollNotifications(false); }, 10000);
  document.addEventListener('visibilitychange', function(){ if (!document.hidden && _lastPoll) pollNotifications(false); });
  @endif
</script>
</body>
</html>
