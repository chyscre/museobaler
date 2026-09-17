<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Staff Attendance — Museo de Baler</title>
  <link href="https://fonts.googleapis.com/css2?family=Young+Serif&family=Instrument+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    /* Meant to be opened once on a tablet or spare monitor by the staff
       entrance and left running all day. Nothing is recorded from here —
       this screen only displays the code. */
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Instrument Sans', system-ui, sans-serif;
      background: #1c1917; color: #fafaf9;
      min-height: 100vh; display: flex; flex-direction: column;
      align-items: center; justify-content: center; padding: 24px;
    }
    h1 { font-family: 'Young Serif', serif; font-size: 26px; font-weight: 400; margin-bottom: 4px; }
    .sub { font-size: 14px; color: #a8a29e; margin-bottom: 26px; }
    .frame {
      background: #fff; padding: 22px; border-radius: 22px;
      box-shadow: 0 10px 50px rgba(0,0,0,.4);
      width: min(78vw, 380px); aspect-ratio: 1;
      display: flex; align-items: center; justify-content: center;
    }
    .frame img { width: 100%; height: 100%; object-fit: contain; display: block; }
    .meter { width: min(78vw, 380px); margin-top: 18px; }
    .track { height: 6px; background: #292524; border-radius: 99px; overflow: hidden; }
    .fill { height: 100%; background: #22c55e; border-radius: 99px; width: 100%;
            transition: width 1s linear; }
    .meter-label {
      display: flex; justify-content: space-between; align-items: center;
      font-size: 12px; color: #a8a29e; margin-top: 9px;
    }
    .steps {
      margin-top: 34px; max-width: 420px; font-size: 14px; color: #d6d3d1; line-height: 1.65;
    }
    .steps ol { padding-left: 20px; }
    .steps li { margin-bottom: 5px; }
    .warn {
      margin-top: 22px; font-size: 12px; color: #78716c; text-align: center; max-width: 380px;
      line-height: 1.6;
    }
    .date { font-size: 13px; color: #78716c; margin-top: 20px; }
  </style>
</head>
<body>
  <h1>Staff Attendance</h1>
  {{-- Deliberately not "scan this with your phone": that sends people to
       their camera app, which finds no link in the code and does nothing,
       because the code is a signed string rather than a URL. It cannot be a
       URL - a link that checks you in would check in anybody who photographed
       it. Who is checking in comes from the signed-in session doing the
       scanning, so the scan has to happen inside the app. --}}
  <p class="sub">Scan from <strong>My Attendance</strong> in the app — not your camera app</p>

  <div class="frame">
    <img id="qr" src="{{ route('attendance.kiosk.qr') }}?w=0" alt="Attendance code">
  </div>

  <div class="meter">
    <div class="track"><div class="fill" id="fill"></div></div>
    <div class="meter-label">
      <span>Code changes automatically</span>
      <span id="count">--s</span>
    </div>
  </div>

  <div class="steps">
    <ol>
      <li>Open Museo de Baler on your phone and sign in.</li>
      <li>Tap <strong>My Attendance</strong>.</li>
      <li>Tap <strong>Scan to check in</strong>, then point at this screen.</li>
    </ol>
  </div>

  <p class="warn">
    Your phone's own camera app will not work on this code, and neither will a
    photo of it: the code changes every {{ $rotateSeconds }} seconds, and it
    only records attendance for the account scanning it.
  </p>

  <p class="date">{{ now()->format('l, F j, Y') }}</p>

<script>
(function () {
  const ROTATE = {{ $rotateSeconds }};
  const img    = document.getElementById('qr');
  const fill   = document.getElementById('fill');
  const count  = document.getElementById('count');

  let remaining = ROTATE;

  function refreshImage(windowId) {
    // Cache-bust on the window id so the browser cannot hand back the
    // previous code after it has already expired server-side.
    img.src = '{{ route('attendance.kiosk.qr') }}?w=' + windowId;
  }

  function paint() {
    fill.style.width = Math.max(0, (remaining / ROTATE) * 100) + '%';
    count.textContent = Math.max(0, remaining) + 's';
  }

  // Ask the server where it is in the cycle rather than trusting this
  // device's clock — a tablet left running for weeks will drift.
  async function sync() {
    try {
      const res = await fetch('{{ route('attendance.kiosk.tick') }}', { cache: 'no-store' });
      const data = await res.json();
      remaining = data.expires_in;
      refreshImage(data.window);
      paint();
    } catch (e) {
      // Offline: keep counting down locally and retry on the next rotation
      // instead of freezing on a stale code.
    }
  }

  sync();
  setInterval(() => {
    remaining -= 1;
    if (remaining <= 0) {
      sync();
    } else {
      paint();
    }
  }, 1000);

  // Re-sync when the screen wakes; a sleeping tablet stops its timers.
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) sync();
  });
})();
</script>
</body>
</html>
