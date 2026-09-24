<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Entrance Poster — Museo de Baler</title>
  <link href="https://fonts.googleapis.com/css2?family=Young+Serif&family=Instrument+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    /* Printed on A4 and mounted beside the queue. Built like the staff-room
       attendance screen: one instruction, the code on a lit card, and the
       three steps - nothing else competing with the thing people have to do.
       No panel chrome, because this leaves the building on paper. */
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Instrument Sans', system-ui, sans-serif;
      background: #1c1917; color: #fafaf9;
      min-height: 100vh; display: flex; flex-direction: column;
      align-items: center; justify-content: center; padding: 40px 24px;
      text-align: center;
    }

    h1 {
      font-family: 'Young Serif', serif; font-weight: 400;
      font-size: clamp(26px, 4.4vw, 40px); line-height: 1.2;
      max-width: 15ch; margin-bottom: 34px;
    }

    .frame {
      background: #fff; padding: 22px; border-radius: 22px;
      box-shadow: 0 10px 50px rgba(0,0,0,.4);
      width: min(78vw, 380px); aspect-ratio: 1;
      display: flex; align-items: center; justify-content: center;
    }
    .frame img { width: 100%; height: 100%; object-fit: contain; display: block; }

    .steps { margin-top: 36px; max-width: 430px; }
    .steps ol { list-style: none; }
    .steps li {
      display: flex; align-items: flex-start; gap: 13px; text-align: left;
      font-size: 15.5px; line-height: 1.55; color: #d6d3d1; margin-bottom: 13px;
    }
    .steps li:last-child { margin-bottom: 0; }
    .num {
      flex-shrink: 0; width: 25px; height: 25px; border-radius: 50%;
      background: #22c55e; color: #052e16;
      font-size: 13px; font-weight: 700; line-height: 25px; text-align: center;
    }

    @media print {
      /* The browser drops background colours by default, which would print
         white text on white paper. */
      -webkit-print-color-adjust: exact;
      body {
        print-color-adjust: exact; -webkit-print-color-adjust: exact;
        min-height: 100vh; padding: 0;
      }
      @page { margin: 14mm; }
      h1 { font-size: 40px; }
      .frame { width: 360px; }
      .steps li { font-size: 17px; }
    }
  </style>
</head>
<body>

  <h1>Scan to sign in before you go inside</h1>

  <div class="frame">
    <img src="{{ $qr }}" alt="Registration QR code">
  </div>

  <div class="steps">
    <ol>
      <li><span class="num">1</span><span>Open your phone camera and point it at the code</span></li>
      <li><span class="num">2</span><span>Tap the link, then fill in your name and where you are from</span></li>
      <li><span class="num">3</span><span>Show your screen at the desk</span></li>
    </ol>
  </div>

</body>
</html>
