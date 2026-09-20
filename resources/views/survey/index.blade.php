@extends('layouts.admin')
@section('title','Survey Questions — Museo de Baler')

@push('styles')
<style>
.sq-section{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow-sm);margin-bottom:14px;overflow:hidden}
.sq-section-hd{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;border-bottom:1px solid var(--border);background:#f9fafb}
.sq-section-title{font-size:13px;font-weight:700;color:var(--text)}
.sq-section-sub{font-size:11.5px;color:var(--text-3);margin-top:2px}
.sq-list{padding:10px 12px}
.sq-row{background:var(--border-light);border:1px solid var(--border);border-radius:var(--r-sm);margin-bottom:8px;overflow:hidden}
.sq-row.inactive{opacity:.6}
.sq-row.dragging{opacity:.4}
.sq-hd{display:flex;align-items:center;gap:10px;padding:10px 12px;cursor:pointer;user-select:none}
.sq-hd:hover{background:#f0f0f0}
.sq-hd.open{background:var(--green-pale)}
.sq-grip{cursor:grab;color:var(--text-3);display:flex;align-items:center}
.sq-grip svg{width:14px;height:14px}
.sq-code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;font-weight:700;color:var(--green-dark);min-width:88px}
.sq-title{flex:1;font-size:12.5px;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sq-meta{display:flex;gap:5px;align-items:center;flex-shrink:0}
.sq-tag{font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:2px 7px;border-radius:20px;background:#fff;border:1px solid var(--border);color:var(--text-3)}
.sq-tag.lock{color:var(--gold-dark);border-color:var(--gold)}
.sq-tag.off{color:#b91c1c;border-color:#fecaca}
.sq-bd{display:none;padding:12px;border-top:1px solid var(--border);background:var(--surface)}
.sq-bd.open{display:block}
.sq-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.sq-grid .fg{margin-bottom:0}
.sq-bd textarea.fi{min-height:56px;resize:vertical}
.sq-opts{margin-top:6px}
.sq-opt{display:grid;grid-template-columns:52px 1fr 1fr 70px 30px;gap:6px;align-items:center;margin-bottom:6px}
.sq-opt .fi{padding:6px 8px;font-size:12px}
.sq-opt label{font-size:11px;color:var(--text-3);display:flex;align-items:center;gap:4px;white-space:nowrap}
.sq-flags{display:flex;flex-wrap:wrap;gap:14px;margin-top:10px}
.sq-flags label{font-size:12px;color:var(--text-2);display:flex;align-items:center;gap:6px;cursor:pointer}
.sq-note{font-size:11.5px;color:var(--text-3);margin-top:8px;line-height:1.5}
.sq-actions{display:flex;gap:6px;margin-top:12px;justify-content:space-between;align-items:center}
.btn-muted{background:var(--surface);color:var(--text-3);border:1.5px solid var(--border)}
.btn-muted:hover{border-color:var(--text-3);color:var(--text)}
.sq-preview{font-size:12px;color:var(--text-2);background:var(--border-light);border-radius:var(--r-sm);padding:8px 10px;margin-top:8px;line-height:1.5}
.sq-empty{font-size:12.5px;color:var(--text-3);padding:14px 4px}
</style>
@endpush

@section('content')
<div class="ph">
  <div class="ph-left">
    <h2>Survey Questions</h2>
    <p>What the visitor app asks after a visit — the ARTA Client Satisfaction Measurement plus the museum's own questions</p>
  </div>
  <div class="ph-right" style="display:flex;gap:8px">
    <a href="{{ route('feedback.index') }}" class="btn btn-outline btn-sm">← Feedback</a>
    <form method="POST" action="{{ route('survey.restore') }}" onsubmit="return confirm('Re-add any default question that has been deleted? Existing questions are not changed.')">
      @csrf
      <button type="submit" class="btn btn-outline btn-sm">Restore defaults</button>
    </form>
    <button type="button" class="btn btn-green btn-sm" onclick="saveQuestions()">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      Save changes
    </button>
  </div>
</div>

<div class="card card-p" style="margin-bottom:18px;font-size:12.5px;color:var(--text-2);line-height:1.6">
  The first twelve questions are the <strong>ARTA CSM form</strong> (CC1–CC3, SQD0–SQD8). Their wording can be edited and any question can be reordered by dragging,
  but the ARTA questions keep their code and cannot be removed or switched off — the report is only a CSM report while every one of them is in it.
  Everything in <strong>About the museum &amp; app</strong> is yours: add, edit, switch off or delete. The app picks up changes the next time a visitor opens the survey.
</div>

<form id="questionsForm" method="POST" action="{{ route('survey.save') }}">
  @csrf
  <input type="hidden" name="questions" id="questionsInput">
</form>

@php
  $sections = [
    'cc'  => ['Citizen\'s Charter', 'Step 1 in the app · CC1–CC3 · single choice'],
    'sqd' => ['Service Quality Dimensions', 'Step 2 in the app · SQD0–SQD8 · five faces + N/A'],
    'app' => ['About the museum & app', 'Step 3 in the app · shown with the star rating and comment'],
  ];
@endphp

@foreach($sections as $key => [$title, $sub])
<div class="sq-section" data-section="{{ $key }}">
  <div class="sq-section-hd">
    <div>
      <div class="sq-section-title">{{ $title }}</div>
      <div class="sq-section-sub">{{ $sub }}</div>
    </div>
    <button type="button" class="btn btn-outline btn-xs" onclick="addQuestion('{{ $key }}')">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:11px;height:11px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add question
    </button>
  </div>
  <div class="sq-list" id="list-{{ $key }}" ondragover="dragOver(event)" ondrop="dragDrop(event)">
    @foreach($questions->where('section', $key) as $q)
      <div class="sq-row {{ $q->is_active ? '' : 'inactive' }}" draggable="true" data-id="{{ $q->question_id }}" data-locked="{{ $q->locked ? 1 : 0 }}"
           data-json="{{ json_encode([
             'id' => $q->question_id, 'code' => $q->code, 'section' => $q->section, 'scale' => $q->scale,
             'text_fil' => $q->text_fil, 'text_en' => $q->text_en, 'hint_fil' => $q->hint_fil, 'hint_en' => $q->hint_en,
             'options' => $q->options, 'show_if' => $q->show_if, 'allow_na' => $q->allow_na, 'default_na' => $q->default_na,
             'required' => $q->required, 'is_active' => $q->is_active, 'locked' => $q->locked,
             'answered' => (int) ($answered[$q->code] ?? 0),
           ]) }}"></div>
    @endforeach
  </div>
</div>
@endforeach
@endsection

@push('scripts')
<script>
// Each .sq-row carries its state in data-json and is rendered from it, so
// a drag between sections or an edit only ever touches one object. On save
// the rows are read back in document order — that is the new sort order.
const AGREE = @json($agreeLabels);
let newCount = 0;

document.querySelectorAll('.sq-row').forEach(row => {
  row._q = JSON.parse(row.dataset.json);
  renderRow(row);
});

function esc(s){ return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function renderRow(row){
  const q = row._q, locked = !!q.locked;
  row.classList.toggle('inactive', !q.is_active);
  row.innerHTML = `
    <div class="sq-hd" onclick="toggleRow(this)">
      <span class="sq-grip" title="Drag to reorder" onclick="event.stopPropagation()">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
      </span>
      <span class="sq-code">${esc(q.code) || '<em style="color:var(--text-3)">new</em>'}</span>
      <span class="sq-title">${esc(q.text_en || q.text_fil) || '<em style="color:var(--text-3)">Untitled question</em>'}</span>
      <span class="sq-meta">
        ${q.answered ? `<span class="sq-tag" title="Answers on record">${q.answered} ans.</span>` : ''}
        <span class="sq-tag">${q.scale === 'choice' ? 'choice' : 'faces'}</span>
        ${locked ? '<span class="sq-tag lock">ARTA</span>' : ''}
        ${q.is_active ? '' : '<span class="sq-tag off">off</span>'}
      </span>
    </div>
    <div class="sq-bd">
      <div class="sq-grid">
        <div class="fg"><label class="fl">Code</label>
          <input class="fi f-code" value="${esc(q.code)}" ${locked ? 'readonly style="background:#f3f4f6"' : ''} placeholder="APP_STAFF" oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9_]/g,'')">
        </div>
        <div class="fg"><label class="fl">Answer type</label>
          <select class="fi f-scale" ${locked ? 'disabled' : ''} onchange="scaleChanged(this)">
            <option value="agree5" ${q.scale === 'agree5' ? 'selected' : ''}>Five faces (strongly disagree → strongly agree)</option>
            <option value="choice" ${q.scale === 'choice' ? 'selected' : ''}>Single choice (your own options)</option>
          </select>
        </div>
      </div>
      <div class="sq-grid" style="margin-top:10px">
        <div class="fg"><label class="fl">Question (Filipino)</label><textarea class="fi f-text-fil">${esc(q.text_fil)}</textarea></div>
        <div class="fg"><label class="fl">Question (English)</label><textarea class="fi f-text-en">${esc(q.text_en)}</textarea></div>
      </div>
      <div class="sq-grid" style="margin-top:10px">
        <div class="fg"><label class="fl">Hint (Filipino, optional)</label><input class="fi f-hint-fil" value="${esc(q.hint_fil)}" placeholder="e.g. Kung libre ang serbisyo, piliin ang N/A."></div>
        <div class="fg"><label class="fl">Hint (English, optional)</label><input class="fi f-hint-en" value="${esc(q.hint_en)}" placeholder="e.g. If the service was free, choose N/A."></div>
      </div>

      <div class="f-choice-block" style="${q.scale === 'choice' ? '' : 'display:none'};margin-top:12px">
        <label class="fl">Options</label>
        <div class="sq-opt" style="margin-bottom:2px">
          <span style="font-size:10.5px;color:var(--text-3)">Value</span><span style="font-size:10.5px;color:var(--text-3)">Filipino</span><span style="font-size:10.5px;color:var(--text-3)">English</span><span></span><span></span>
        </div>
        <div class="sq-opts">${(q.options || []).map(optRow).join('')}</div>
        <button type="button" class="btn btn-outline btn-xs" onclick="addOption(this)">+ Option</button>
        <div class="sq-note">Values are the numbers stored in reports. Tick <strong>N/A</strong> on the option that means "not applicable" — it is what gets recorded when the question is skipped.</div>
      </div>

      <div class="f-agree-block" style="${q.scale === 'agree5' ? '' : 'display:none'}">
        <div class="sq-preview">${Object.entries(AGREE).map(([v,l]) => `${v} · ${esc(l.en)}`).join(' &nbsp;·&nbsp; ')}</div>
      </div>

      <div class="sq-flags">
        <label class="f-allow-na-wrap" style="${q.scale === 'agree5' ? '' : 'display:none'}"><input type="checkbox" class="f-allow-na" ${q.allow_na ? 'checked' : ''}> Offer an N/A answer</label>
        <label class="f-default-na-wrap" style="${q.scale === 'agree5' ? '' : 'display:none'}"><input type="checkbox" class="f-default-na" ${q.default_na ? 'checked' : ''}> Pre-select N/A</label>
        <label><input type="checkbox" class="f-required" ${q.required ? 'checked' : ''} ${locked ? 'disabled' : ''}> Required</label>
        <label><input type="checkbox" class="f-active" ${q.is_active ? 'checked' : ''} ${locked ? 'disabled' : ''}> Shown in the app</label>
      </div>

      <div class="sq-grid" style="margin-top:12px">
        <div class="fg"><label class="fl">Only ask when… (optional)</label>
          <div style="display:flex;gap:6px;align-items:center">
            <input class="fi f-showif-code" value="${esc(q.show_if?.code || '')}" placeholder="CC1" style="max-width:110px" oninput="this.value=this.value.toUpperCase()">
            <span style="font-size:12px;color:var(--text-3);white-space:nowrap">was answered</span>
            <input class="fi f-showif-in" value="${esc((q.show_if?.in || []).join(','))}" placeholder="1,2,3" style="max-width:110px">
          </div>
        </div>
        <div class="fg"><div class="sq-note" style="margin-top:22px">Leave blank to always ask. CC2 and CC3 use this: they only appear when CC1 was 1, 2 or 3, and are filed as N/A otherwise — the paper form's own rule.</div></div>
      </div>

      <div class="sq-actions">
        <span class="sq-note" style="margin:0">${locked ? 'ARTA question — wording and order are editable; code, type and removal are not.' : (q.answered ? `${q.answered} answer(s) on record stay in reports under this code even if the question is deleted.` : '')}</span>
        <div style="display:flex;gap:6px">
          <button type="button" class="btn btn-outline btn-xs" onclick="toggleRow(this.closest('.sq-row').querySelector('.sq-hd'))">Done</button>
          ${locked ? '' : '<button type="button" class="btn btn-muted btn-xs" onclick="removeRow(this)">Delete</button>'}
        </div>
      </div>
    </div>`;
  row.ondragstart = dragStart;
  row.ondragend   = e => row.classList.remove('dragging');
}

function optRow(o){
  return `<div class="sq-opt">
    <input class="fi o-value" type="number" min="1" max="99" value="${esc(o?.value ?? '')}">
    <input class="fi o-fil" value="${esc(o?.fil)}">
    <input class="fi o-en" value="${esc(o?.en)}">
    <label><input type="checkbox" class="o-na" ${o?.na ? 'checked' : ''}> N/A</label>
    <button type="button" class="btn btn-muted btn-xs" style="padding:3px 6px" onclick="this.closest('.sq-opt').remove()" title="Remove">×</button>
  </div>`;
}
function addOption(btn){
  const opts = btn.previousElementSibling;
  const next = opts.querySelectorAll('.sq-opt').length + 1;
  opts.insertAdjacentHTML('beforeend', optRow({ value: next, fil: '', en: '', na: false }));
}
function scaleChanged(sel){
  const bd = sel.closest('.sq-bd'), choice = sel.value === 'choice';
  bd.querySelector('.f-choice-block').style.display = choice ? '' : 'none';
  bd.querySelector('.f-agree-block').style.display  = choice ? 'none' : '';
  bd.querySelector('.f-allow-na-wrap').style.display   = choice ? 'none' : '';
  bd.querySelector('.f-default-na-wrap').style.display = choice ? 'none' : '';
  if (choice && !bd.querySelectorAll('.sq-opt').length) {
    const opts = bd.querySelector('.sq-opts');
    opts.insertAdjacentHTML('beforeend', optRow({ value: 1, fil: '', en: '', na: false }) + optRow({ value: 2, fil: '', en: '', na: false }));
  }
}

function toggleRow(hd){
  const row = hd.closest('.sq-row'), bd = row.querySelector('.sq-bd');
  const open = !bd.classList.contains('open');
  if (!open) { readRow(row); renderRow(row); return; }
  bd.classList.add('open'); hd.classList.add('open');
}

// Pull the inputs back into the row's state object.
function readRow(row){
  const bd = row.querySelector('.sq-bd'); if (!bd) return;
  const q = row._q;
  q.code     = bd.querySelector('.f-code').value.trim();
  q.scale    = bd.querySelector('.f-scale').value;
  q.text_fil = bd.querySelector('.f-text-fil').value;
  q.text_en  = bd.querySelector('.f-text-en').value;
  q.hint_fil = bd.querySelector('.f-hint-fil').value;
  q.hint_en  = bd.querySelector('.f-hint-en').value;
  q.allow_na   = bd.querySelector('.f-allow-na').checked;
  q.default_na = bd.querySelector('.f-default-na').checked;
  q.required   = bd.querySelector('.f-required').checked;
  q.is_active  = bd.querySelector('.f-active').checked;
  q.options = q.scale === 'choice'
    ? Array.from(bd.querySelectorAll('.sq-opt')).filter(o => o.querySelector('.o-value')).map(o => ({
        value: parseInt(o.querySelector('.o-value').value, 10) || 0,
        fil: o.querySelector('.o-fil').value, en: o.querySelector('.o-en').value,
        na: o.querySelector('.o-na').checked,
      }))
    : null;
  const sc = bd.querySelector('.f-showif-code').value.trim();
  const si = bd.querySelector('.f-showif-in').value.split(',').map(s => parseInt(s.trim(), 10)).filter(n => !isNaN(n));
  q.show_if = sc && si.length ? { code: sc, in: si } : null;
}

function addQuestion(section){
  newCount++;
  const row = document.createElement('div');
  row.className = 'sq-row'; row.draggable = true; row.dataset.id = ''; row.dataset.locked = '0';
  row._q = { id: '', code: '', section, scale: 'agree5', text_fil: '', text_en: '', hint_fil: '', hint_en: '',
             options: null, show_if: null, allow_na: true, default_na: false, required: true, is_active: true, locked: false, answered: 0 };
  document.getElementById('list-' + section).appendChild(row);
  renderRow(row);
  toggleRow(row.querySelector('.sq-hd'));
  row.querySelector('.f-code').focus();
}

function removeRow(btn){
  const row = btn.closest('.sq-row');
  const q = row._q;
  const msg = q.answered
    ? `Delete ${q.code}? Its ${q.answered} recorded answer(s) stay in reports, but visitors will no longer be asked.`
    : `Delete ${q.code || 'this question'}?`;
  if (confirm(msg)) row.remove();
}

// ── Drag to reorder (within or across sections) ──────────────
let dragged = null;
function dragStart(e){ dragged = this; this.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; }
function dragOver(e){
  e.preventDefault();
  if (!dragged) return;
  const list = e.currentTarget;
  const after = Array.from(list.querySelectorAll('.sq-row:not(.dragging)'))
    .find(r => e.clientY <= r.getBoundingClientRect().top + r.getBoundingClientRect().height / 2);
  if (after) list.insertBefore(dragged, after); else list.appendChild(dragged);
}
function dragDrop(e){ e.preventDefault(); dragged = null; }

// ── Save ─────────────────────────────────────────────────────
function saveQuestions(){
  const out = [];
  document.querySelectorAll('.sq-section').forEach(sec => {
    sec.querySelectorAll('.sq-row').forEach(row => {
      readRow(row);
      const q = row._q;
      // A row's section is wherever it was dropped — unless it is an ARTA
      // question, which stays on its own step whatever the drag did.
      out.push({
        id: q.id, code: q.code, section: q.locked ? q.section : sec.dataset.section, scale: q.scale,
        text_fil: q.text_fil, text_en: q.text_en, hint_fil: q.hint_fil, hint_en: q.hint_en,
        options: q.options, show_if: q.show_if,
        allow_na: q.allow_na, default_na: q.default_na, required: q.required, is_active: q.is_active,
      });
    });
  });
  document.getElementById('questionsInput').value = JSON.stringify(out);
  document.getElementById('questionsForm').submit();
}
</script>
@endpush
