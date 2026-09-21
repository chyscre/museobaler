@extends('layouts.admin')
@section('title', 'Exhibit Recognition — Museo de Baler')

@push('styles')
<style>
.rc-grid{display:grid;grid-template-columns:1.1fr 1fr;gap:20px;align-items:start;margin-bottom:20px}
.rc-stat{display:flex;gap:14px;align-items:flex-start}
.rc-stat-ico{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.rc-stat-ico svg{width:20px;height:20px}
.rc-warn{display:flex;gap:9px;align-items:flex-start;padding:10px 12px;border-radius:9px;font-size:12.5px;line-height:1.5;margin-top:10px}
.rc-warn svg{width:15px;height:15px;flex-shrink:0;margin-top:2px}
.rc-warn.gold{background:var(--gold-pale);color:#92400e}
.rc-warn.red{background:var(--red-pale);color:#b91c1c}
.rc-warn.green{background:var(--green-pale);color:var(--green-dark)}
.rc-bar{height:8px;border-radius:6px;background:var(--border-light);overflow:hidden;margin-top:12px}
.rc-bar > div{height:100%;width:0;background:var(--green);transition:width .3s}
.rc-log{font:12px/1.6 ui-monospace,Menlo,Consolas,monospace;color:var(--text-2);background:#f9fafb;border:1px solid var(--border-light);border-radius:8px;padding:10px 12px;margin-top:12px;max-height:180px;overflow:auto;white-space:pre-wrap;display:none}
.rc-steps{font-size:13px;color:var(--text-2);line-height:1.65;margin:0;padding-left:20px}
.rc-steps li{margin-bottom:6px}
.rc-steps b{color:var(--text)}
.tbl-wrap table{min-width:0}
.rc-photos{display:inline-flex;align-items:center;gap:8px}
.rc-mini{width:80px;height:6px;border-radius:4px;background:var(--border-light);overflow:hidden;flex-shrink:0}
.rc-mini > div{height:100%}
</style>
@endpush

@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-error">{{ session('error') }}</div>@endif

<div class="ph">
  <div class="ph-left">
    <h2>Exhibit Recognition</h2>
    <p>Teach the visitor app to recognise exhibits through the camera</p>
  </div>
  <div class="ph-right">
    <a href="{{ route('exhibits.index') }}" class="btn btn-outline btn-sm">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
      Back to Exhibits
    </a>
  </div>
</div>

<div class="rc-grid">
  {{-- What the visitor app has right now --}}
  <div class="card card-p">
    <div class="rc-stat">
      @if($model)
        <div class="rc-stat-ico" style="background:var(--green-pale);color:var(--green-dark)">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div>
          <div style="font-size:14px;font-weight:700;color:var(--text)">Recognition is on</div>
          <div style="font-size:12.5px;color:var(--text-3);margin-top:2px">
            Last trained {{ $model['trained_at'] ? $model['trained_at']->timezone(config('app.timezone'))->format('M j, Y · g:i A') : 'at an unknown time' }}
            · knows {{ count(array_filter($model['labels'], fn($l) => strtoupper($l) !== 'BACKGROUND')) }} exhibit(s)
          </div>
        </div>
      @else
        <div class="rc-stat-ico" style="background:var(--gold-pale);color:var(--gold-dark)">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <div>
          <div style="font-size:14px;font-weight:700;color:var(--text)">No model trained yet</div>
          <div style="font-size:12.5px;color:var(--text-3);margin-top:2px">Visitors get the basic photo-match fallback and the QR codes. Add photos and train to turn recognition on.</div>
        </div>
      @endif
    </div>

    @if($model && $missing->count())
      <div class="rc-warn gold">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <div><strong>{{ $missing->count() }} exhibit(s) are not in the model:</strong> {{ $missing->pluck('name')->join(', ') }}. Add photos for them and train again.</div>
      </div>
    @endif
    @if(count($stale))
      <div class="rc-warn gold">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <div>The model still knows codes that are no longer active exhibits ({{ implode(', ', $stale) }}). Training again will drop them.</div>
      </div>
    @endif
    @if($thin->count())
      <div class="rc-warn red">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
        <div><strong>{{ $thin->count() }} exhibit(s) have fewer than {{ $minPhotos }} photos.</strong> They will be recognised poorly, or not at all, until more are taken.</div>
      </div>
    @endif
    @if($backgroundCount < $minPhotos)
      <div class="rc-warn red">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/></svg>
        <div><strong>Only {{ $backgroundCount }} background photo(s).</strong> Without walls, floors and empty cases to learn from, the model has to guess an exhibit for every frame. <a href="{{ route('recognition.background') }}" style="color:inherit;font-weight:700">Add background photos</a>.</div>
      </div>
    @endif
    @if($model && !$missing->count() && !count($stale) && !$thin->count() && $backgroundCount >= $minPhotos)
      <div class="rc-warn green">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        <div>Every active exhibit is in the model and has enough photos. Nothing to do unless an exhibit is added, moved or re-lit.</div>
      </div>
    @endif
  </div>

  {{-- Train --}}
  <div class="card card-p">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div>
        <div style="font-size:14px;font-weight:700;color:var(--text)">Train the model</div>
        <div style="font-size:12.5px;color:var(--text-3);margin-top:2px">Takes a minute or two on this computer. Visitors get the new model the next time they open the scanner.</div>
      </div>
      <button class="btn btn-green" id="trainBtn" disabled>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px"><polygon points="5 3 19 12 5 21 5 3"/></svg>
        <span id="trainBtnLabel">Loading…</span>
      </button>
    </div>
    <div class="rc-bar"><div id="trainBar"></div></div>
    <div id="trainStatus" style="font-size:12.5px;color:var(--text-3);margin-top:8px">Preparing the trainer…</div>
    <div class="rc-log" id="trainLog"></div>
  </div>
</div>

{{-- Per-exhibit photo counts --}}
<div class="tbl-wrap" style="margin-bottom:20px">
  <table>
    <thead>
      <tr>
        <th>Exhibit</th>
        <th>Code</th>
        <th>Photos</th>
        <th>In current model</th>
        <th style="text-align:right">Photos</th>
      </tr>
    </thead>
    <tbody>
      @php
        $row = function ($name, $sub, $count, $inModel, $url, $isBg = false) use ($minPhotos, $goodPhotos) {
          $tone = $count >= $goodPhotos ? 'var(--green)' : ($count >= $minPhotos ? 'var(--gold)' : 'var(--red)');
          $pct  = min(100, (int) round($count / $goodPhotos * 100));
          return compact('name', 'sub', 'count', 'inModel', 'url', 'isBg', 'tone', 'pct');
        };
      @endphp
      @foreach($exhibits as $e)
        @php $r = $row($e->name, $e->hall ?: '', $e->training_images_count, $e->in_model, route('recognition.photos', $e)); @endphp
        <tr>
          <td><div style="font-weight:600;color:var(--text)">{{ $r['name'] }}</div>@if($r['sub'])<div style="font-size:11.5px;color:var(--text-4)">{{ $r['sub'] }}</div>@endif</td>
          <td><span style="font-weight:700;color:var(--green-dark);font-size:12px">{{ $e->exhibit_code }}</span></td>
          <td>
            <span class="rc-photos">
              <span class="rc-mini"><div style="width:{{ $r['pct'] }}%;background:{{ $r['tone'] }}"></div></span>
              <span style="font-weight:600;color:var(--text)">{{ $r['count'] }}</span>
              @if($r['count'] < $minPhotos)<span class="badge b-red">too few</span>@endif
            </span>
          </td>
          <td>
            @if(!$model)<span class="badge b-gray">no model</span>
            @elseif($r['inModel'])<span class="badge b-green">yes</span>
            @else<span class="badge b-gold">not yet</span>@endif
          </td>
          <td style="text-align:right"><a href="{{ $r['url'] }}" class="btn btn-outline btn-xs">Manage photos</a></td>
        </tr>
      @endforeach
      @php $r = $row('Background', 'Walls, floors, cases — not an exhibit', $backgroundCount, $model && in_array('BACKGROUND', array_map('strtoupper', $model['labels']), true), route('recognition.background'), true); @endphp
      <tr style="background:#fafafa">
        <td><div style="font-weight:600;color:var(--text)">{{ $r['name'] }}</div><div style="font-size:11.5px;color:var(--text-4)">{{ $r['sub'] }}</div></td>
        <td><span style="font-size:12px;color:var(--text-4)">—</span></td>
        <td>
          <span class="rc-photos">
            <span class="rc-mini"><div style="width:{{ $r['pct'] }}%;background:{{ $r['tone'] }}"></div></span>
            <span style="font-weight:600;color:var(--text)">{{ $r['count'] }}</span>
            @if($r['count'] < $minPhotos)<span class="badge b-red">too few</span>@endif
          </span>
        </td>
        <td>
          @if(!$model)<span class="badge b-gray">no model</span>
          @elseif($r['inModel'])<span class="badge b-green">yes</span>
          @else<span class="badge b-gold">not yet</span>@endif
        </td>
        <td style="text-align:right"><a href="{{ $r['url'] }}" class="btn btn-outline btn-xs">Manage photos</a></td>
      </tr>
    </tbody>
  </table>
</div>

{{-- How it works, for whoever runs this after us --}}
<div class="card card-p">
  <div style="font-size:14px;font-weight:700;color:var(--text);margin-bottom:10px">How this works</div>
  <ol class="rc-steps">
    <li><b>Take photos of each exhibit.</b> Open <em>Manage photos</em> on a phone, stand where a visitor would, and take {{ $goodPhotos }} or more from different angles and distances. Use the hall's normal lighting.</li>
    <li><b>Take background photos.</b> Walls, floors, empty cases, doorways — anything the camera might see that is not an exhibit. {{ $goodPhotos }} or more.</li>
    <li><b>Press Train.</b> This computer builds the model from the photos and saves it for the visitor app. Nothing is sent anywhere except the model base it downloads once.</li>
    <li><b>Train again</b> whenever an exhibit is added, removed, moved to a different spot, or the lighting around it changes. Until then the QR codes and the fallback still work, so nothing breaks — new exhibits just are not recognised by camera yet.</li>
  </ol>
</div>

<script id="rc-config" type="application/json">{!! json_encode([
  'dataset' => route('recognition.dataset'),
  'save'    => route('recognition.model.save'),
  'csrf'    => csrf_token(),
  'min'     => $minPhotos,
  'base'    => asset('js/vendor/mobilenet-base/model.json'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection

@push('scripts')
{{-- Same library and version as the visitor app, so what trains here is
     exactly what runs there. Served from this server rather than a CDN: the
     museum's own network could not reach jsDelivr, which left the trainer
     showing "Unavailable" and the visitor app silently on the photo-match
     fallback. TensorFlow.js 4.20.0 and @teachablemachine/image 0.8.5. --}}
<script src="{{ asset('js/vendor/tf.min.js') }}"></script>
<script src="{{ asset('js/vendor/teachablemachine-image.min.js') }}"></script>
<script>
(function () {
  var cfg    = JSON.parse(document.getElementById('rc-config').textContent);
  var btn    = document.getElementById('trainBtn');
  var label  = document.getElementById('trainBtnLabel');
  var bar    = document.getElementById('trainBar');
  var status = document.getElementById('trainStatus');
  var logEl  = document.getElementById('trainLog');

  // The same numbers Teachable Machine's site uses by default. They are not
  // exposed on the page on purpose - there is nothing for the museum to tune.
  var PARAMS = { denseUnits: 100, epochs: 50, batchSize: 16, learningRate: 0.001 };
  var SIZE = 224;

  function say(msg) { status.textContent = msg; }
  function log(msg) { logEl.style.display = 'block'; logEl.textContent += msg + '\n'; logEl.scrollTop = logEl.scrollHeight; }
  function progress(p) { bar.style.width = Math.max(0, Math.min(100, p)) + '%'; }

  if (typeof tf === 'undefined' || typeof tmImage === 'undefined') {
    say('The trainer could not load. This computer needs internet access to train — check the connection and reload.');
    label.textContent = 'Unavailable';
    return;
  }
  btn.disabled = false;
  label.textContent = 'Train';
  say('Ready.');

  function loadImage(url) {
    return new Promise(function (resolve, reject) {
      var img = new Image();
      img.onload = function () { resolve(img); };
      img.onerror = function () { reject(new Error('Could not load ' + url)); };
      img.src = url;
    });
  }

  // The model sees a 224x224 square, so every photo is centre-cropped to one.
  // The mirrored copy is free extra data: an exhibit is still itself in a
  // mirror, and it makes the model less fussy about which side you stand on.
  function crops(img) {
    var s  = Math.min(img.naturalWidth, img.naturalHeight);
    var sx = (img.naturalWidth - s) / 2, sy = (img.naturalHeight - s) / 2;
    var out = [];
    [false, true].forEach(function (flip) {
      var c = document.createElement('canvas'); c.width = c.height = SIZE;
      var ctx = c.getContext('2d');
      if (flip) { ctx.translate(SIZE, 0); ctx.scale(-1, 1); }
      ctx.drawImage(img, sx, sy, s, s, 0, 0, SIZE, SIZE);
      out.push(c);
    });
    return out;
  }

  async function train() {
    btn.disabled = true; label.textContent = 'Training…';
    logEl.textContent = ''; progress(0);

    try {
      say('Fetching photos…');
      var res  = await fetch(cfg.dataset, { headers: { 'Accept': 'application/json' } });
      var data = await res.json();
      var classes = data.classes.filter(function (c) { return c.photos.length > 0; });
      var skipped = data.classes.filter(function (c) { return c.photos.length === 0; });

      skipped.forEach(function (c) { log('Skipped ' + c.label + ' (' + c.name + '): no photos.'); });
      classes.forEach(function (c) {
        if (c.photos.length < cfg.min) log('Warning: ' + c.label + ' has only ' + c.photos.length + ' photos - expect weak recognition.');
      });

      if (classes.length < 2) {
        throw new Error('Need photos for at least two things (one exhibit plus Background, or two exhibits) before training.');
      }

      var total = classes.reduce(function (n, c) { return n + c.photos.length; }, 0);
      log('Training on ' + total + ' photos across ' + classes.length + ' classes: ' + classes.map(function (c) { return c.label; }).join(', '));

      say('Loading the model base…');
      // The MobileNet base the classifier is built on is served from this
      // server too (public/js/vendor/mobilenet-base/), so training needs no
      // internet at all. checkpointUrl + trainingLayer together tell the
      // library to use it instead of fetching from storage.googleapis.com;
      // 'out_relu' is the library's own default layer for MobileNet v2, and
      // the file is the exact v2 / alpha 0.35 / 224px checkpoint it would
      // otherwise download.
      var model = await tmImage.createTeachable(
        { tfjsVersion: tf.version.tfjs },
        { version: 2, alpha: 0.35, checkpointUrl: cfg.base, trainingLayer: 'out_relu' }
      );
      model.setLabels(classes.map(function (c) { return c.label; }));
      model.setName('museo-de-baler');

      var done = 0;
      for (var i = 0; i < classes.length; i++) {
        for (var j = 0; j < classes[i].photos.length; j++) {
          var img = await loadImage(classes[i].photos[j]);
          var cs = crops(img);
          for (var k = 0; k < cs.length; k++) await model.addExample(i, cs[k]);
          done++;
          progress(done / total * 40);
          say('Reading photos… ' + done + ' / ' + total);
        }
      }

      say('Training… epoch 0 / ' + PARAMS.epochs);
      var lastAcc = null;
      await model.train(PARAMS, {
        onEpochEnd: function (epoch, logs) {
          lastAcc = logs && (logs.val_acc != null ? logs.val_acc : logs.acc);
          progress(40 + (epoch + 1) / PARAMS.epochs * 50);
          say('Training… epoch ' + (epoch + 1) + ' / ' + PARAMS.epochs + (lastAcc != null ? ' · accuracy ' + Math.round(lastAcc * 100) + '%' : ''));
        }
      });
      if (lastAcc != null) log('Finished training. Accuracy on held-back photos: ' + Math.round(lastAcc * 100) + '%.');

      say('Saving…');
      var artifacts = await new Promise(function (resolve, reject) {
        model.save(tf.io.withSaveHandler(async function (a) {
          resolve(a);
          return { modelArtifactsInfo: { dateSaved: new Date(), modelTopologyType: 'JSON' } };
        })).catch(reject);
      });

      // Written the way Teachable Machine's export is laid out, because that
      // is what the visitor app expects: model.json points at ./weights.bin.
      var modelJson = {
        modelTopology:   artifacts.modelTopology,
        format:          artifacts.format,
        generatedBy:     artifacts.generatedBy,
        convertedBy:     artifacts.convertedBy || null,
        weightsManifest: [{ paths: ['./weights.bin'], weights: artifacts.weightSpecs }]
      };
      var wd = artifacts.weightData;
      var weightsBlob = new Blob(Array.isArray(wd) ? wd : [wd], { type: 'application/octet-stream' });

      var meta = model.getMetadata();
      meta.tfjsVersion = tf.version.tfjs;
      meta.timeStamp   = new Date().toISOString();
      meta.modelName   = 'museo-de-baler';

      var fd = new FormData();
      fd.append('_token', cfg.csrf);
      fd.append('model_json',    new Blob([JSON.stringify(modelJson)], { type: 'application/json' }), 'model.json');
      fd.append('weights',       weightsBlob, 'weights.bin');
      fd.append('metadata_json', new Blob([JSON.stringify(meta)], { type: 'application/json' }), 'metadata.json');

      var saveRes = await fetch(cfg.save, { method: 'POST', body: fd, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
      var saved = await saveRes.json().catch(function () { return {}; });
      if (!saveRes.ok || !saved.ok) throw new Error(saved.message || 'The server did not accept the model.');

      progress(100);
      say('Done. The visitor app will use the new model from its next scan.');
      log('Saved. Model knows: ' + saved.labels.join(', '));
      label.textContent = 'Trained';
      setTimeout(function () { window.location.reload(); }, 2500);
    } catch (e) {
      console.error(e);
      say('Training failed: ' + (e && e.message ? e.message : e));
      log('Error: ' + (e && e.message ? e.message : e));
      btn.disabled = false; label.textContent = 'Try again';
    }
  }

  btn.addEventListener('click', function () {
    if (btn.disabled) return;
    train();
  });
})();
</script>
@endpush
