@extends('layouts.admin')
@section('title','Feedback — Museo de Baler')

@section('content')
<div class="ph">
  <div class="ph-left">
    <h2>Feedback</h2>
    <p>Visitor feedback and survey responses</p>
  </div>
  <div class="ph-right">
    {{-- The question bank is a Tourism-only screen, reached from here rather
         than from the sidebar: it is a setting of the feedback, not a
         section of its own. --}}
    @if(auth()->user()->isTourismHead())
      <a href="{{ route('survey.index') }}" class="btn btn-outline btn-sm">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        Survey questions
      </a>
    @endif
    @include('partials.report-menu', [
      'id'      => 'feedbackReportMenu',
      'mode'    => 'range',
      'reports' => [
        ['label' => 'Feedback & CSM report', 'url' => route('reports.feedback'), 'csv' => route('reports.feedback.csv')],
      ],
    ])
  </div>
</div>

<div class="card card-p" style="display:flex;align-items:center;gap:20px;margin-bottom:18px">
  <div style="display:flex;align-items:center;gap:8px">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;color:var(--green-dark)"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    <span style="font-size:13px;color:var(--text-3)">Total Feedback:</span>
    <span style="font-size:14px;font-weight:700;color:var(--text)">{{ number_format($stats['total']) }}</span>
  </div>
  <div style="width:1px;height:20px;background:var(--border)"></div>
  <div style="display:flex;align-items:center;gap:8px">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;color:var(--gold)"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
    <span style="font-size:13px;color:var(--text-3)">Average Rating:</span>
    <span style="font-size:14px;font-weight:700;color:var(--text)">{{ $stats['avg_rating'] ?: '—' }} / 5</span>
  </div>
  <div style="width:1px;height:20px;background:var(--border)"></div>
  {{-- The ARTA figures. The SQD score is the share of Agree / Strongly Agree
       answers across every SQD item, N/A excluded — the number the office
       files. Only feedback that came with the survey counts. --}}
  <div style="display:flex;align-items:center;gap:8px" title="Share of SQD answers that were Agree or Strongly Agree (N/A excluded), across {{ $stats['respondents'] }} survey responses">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;color:var(--green-dark)"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
    <span style="font-size:13px;color:var(--text-3)">CSM Score:</span>
    <span style="font-size:14px;font-weight:700;color:var(--text)">{{ $stats['sqd_score'] !== null ? $stats['sqd_score'] . '%' : '—' }}</span>
    @if($stats['sqd_label'])
      <span class="badge" style="background:var(--green-pale);color:var(--green-dark)">{{ $stats['sqd_label'] }}</span>
    @endif
  </div>
  <div style="width:1px;height:20px;background:var(--border)"></div>
  <div style="display:flex;align-items:center;gap:8px" title="Share of respondents who knew the Citizen's Charter (CC1 answered 1–3)">
    <span style="font-size:13px;color:var(--text-3)">CC Awareness:</span>
    <span style="font-size:14px;font-weight:700;color:var(--text)">{{ $stats['cc_awareness'] !== null ? $stats['cc_awareness'] . '%' : '—' }}</span>
  </div>
</div>

<form method="GET" action="{{ route('feedback.index') }}">
  <div class="fbar">
    <div class="search-box">
      <svg class="si" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      <input type="text" name="search" value="{{ request('search') }}" placeholder="Search by name or keyword…">
    </div>
    <x-fctl icon="star">
      <select class="fsel" name="rating" onchange="this.form.submit()">
        <option value="">All Ratings</option>
        @foreach([5,4,3,2,1] as $r)
        <option value="{{ $r }}" {{ request('rating')==$r?'selected':'' }}>{{ $r }} Stars</option>
        @endforeach
      </select>
    </x-fctl>
    <x-fctl icon="sort">
      <select class="fsel" name="sort" onchange="this.form.submit()">
        <option value="newest"  {{ request('sort','newest')==='newest'?'selected':'' }}>Sort: Newest First</option>
        <option value="oldest"  {{ request('sort')==='oldest'?'selected':'' }}>Sort: Oldest First</option>
        <option value="highest" {{ request('sort')==='highest'?'selected':'' }}>Sort: Highest Rating</option>
        <option value="lowest"  {{ request('sort')==='lowest'?'selected':'' }}>Sort: Lowest Rating</option>
      </select>
    </x-fctl>
    <span id="fbCount" class="fcount"></span>
  </div>
</form>

<div class="tbl-wrap">
  <table>
    <thead><tr>
      <th>Name</th><th>Rating</th><th>Survey</th><th>Feedback</th><th>Date</th><th>Action</th>
    </tr></thead>
    <tbody>
    @forelse($feedback as $fb)
    <tr>
      <td style="white-space:nowrap;font-weight:600">{{ $fb->visitor?->full_name ?: '—' }}</td>
      <td style="color:var(--gold);letter-spacing:1px">{{ str_repeat('★', $fb->rating) }}{{ str_repeat('☆', 5 - $fb->rating) }}</td>
      <td style="font-size:12px;color:var(--text-3);white-space:nowrap">
        @php $sqd = $fb->answers->filter(fn ($a) => str_starts_with($a->code, 'SQD') && $a->value !== null); @endphp
        @if($sqd->isNotEmpty())
          <span title="{{ $sqd->whereIn('value', [4,5])->count() }} of {{ $sqd->count() }} SQD answers satisfied">{{ round($sqd->whereIn('value', [4,5])->count() / $sqd->count() * 100) }}% · {{ $fb->answers->count() }} ans.</span>
        @elseif($fb->answers->isNotEmpty())
          <span>{{ $fb->answers->count() }} ans.</span>
        @else
          <span style="color:var(--border)">—</span>
        @endif
      </td>
      <td style="max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:12.5px;color:var(--text-3)">{{ $fb->comment }}</td>
      <td style="white-space:nowrap;font-size:12px;color:var(--text-3)">{{ \Carbon\Carbon::parse($fb->submitted_at)->format('M j, Y') }}</td>
      <td>
        <button class="btn btn-outline btn-xs" onclick="openFeedbackModal({{ $fb->feedback_id }})">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          View
        </button>
      </td>
    </tr>
    @empty
    <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-3)">No feedback yet.</td></tr>
    @endforelse
    </tbody>
  </table>
  <div class="tbl-foot">
    <span class="tbl-count">{{ $feedback->total() }} responses</span>
    <div>{{ $feedback->links() }}</div>
  </div>
</div>

<!-- Feedback Detail Modal -->
<div class="overlay" id="feedbackModal">
  <div class="modal" style="max-width:640px">
    <div class="modal-hd">
      <h3>Feedback Detail</h3>
      <button class="modal-close" onclick="closeFeedbackModal()">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div id="feedbackModalBody" style="min-height:100px;display:flex;align-items:center;justify-content:center">
      <div class="spinner"></div>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script>
function openFeedbackModal(id){
  const overlay=document.getElementById('feedbackModal');
  const body=document.getElementById('feedbackModalBody');
  body.innerHTML='<div class="spinner"></div>';
  body.style.display='flex';
  overlay.classList.add('open');
  fetch('{{ url('/') }}/feedback/'+id+'/modal')
    .then(r=>r.json())
    .then(d=>{
      const name=esc(((d.visitor_first||'')+' '+(d.visitor_last||'')).trim()||'—');
      const stars='★'.repeat(d.rating)+'☆'.repeat(5-d.rating);
      // Flex centres the spinner; the detail is a normal block.
      body.style.display='block';
      body.innerHTML=`
        <div class="fi-row" style="margin-bottom:12px">
          <div><div class="fl">Name</div><div class="fi-val">${name}</div></div>
          <div><div class="fl">Rating</div><div class="fi-val" style="color:#d97706;font-size:20px;letter-spacing:2px">${stars}</div></div>
        </div>
        <div class="fi-row" style="margin-bottom:12px">
          <div><div class="fl">Country</div><div class="fi-val">${esc(d.country||'—')}</div></div>
          <div><div class="fl">Date</div><div class="fi-val">${d.date||'—'}</div></div>
        </div>
        ${d.client_type || d.region ? `<div class="fi-row" style="margin-bottom:12px">
          <div><div class="fl">Client type</div><div class="fi-val">${esc(d.client_type||'—')}</div></div>
          <div><div class="fl">Region</div><div class="fi-val">${esc(d.region||'—')}</div></div>
        </div>` : ''}
        ${answersTable(d.answers||[])}
        <div class="fl">Comment</div>
        <div class="fi-val" style="white-space:pre-line;min-height:60px;line-height:1.6">${esc(d.comment)||'No comment provided.'}</div>
        <div class="modal-ft"><button class="btn btn-outline" onclick="closeFeedbackModal()">Close</button></div>`;
    })
    .catch(()=>{ body.innerHTML='<p style="color:var(--red);padding:20px">Failed to load feedback.</p>'; });
}
function esc(s){ return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
// The survey answers, grouped the way the paper form is. Feedback from
// before the survey existed has none and shows nothing here.
function answersTable(answers){
  if (!answers.length) return '';
  const groups = { cc: 'Citizen\x27s Charter', sqd: 'Service Quality Dimensions', app: 'Museum & app' };
  let html = '';
  Object.keys(groups).forEach(sec => {
    const rows = answers.filter(a => a.section === sec);
    if (!rows.length) return;
    html += `<div class="fl" style="margin-top:10px">${groups[sec]}</div>
      <table style="width:100%;border-collapse:collapse;font-size:12.5px;margin-bottom:8px">` +
      rows.map(a => `<tr style="border-bottom:1px solid var(--border-light)">
        <td style="padding:5px 6px 5px 0;color:var(--text-3);font-family:ui-monospace,monospace;font-size:11px;white-space:nowrap;vertical-align:top">${esc(a.code)}</td>
        <td style="padding:5px 6px;color:var(--text-2);vertical-align:top">${esc(a.text)}</td>
        <td style="padding:5px 0 5px 6px;white-space:nowrap;text-align:right;vertical-align:top;font-weight:600;color:${a.value===null?'var(--text-3)':(a.value>=4?'var(--green-dark)':(a.value<=2?'#b91c1c':'var(--text)'))}">${esc(a.label)}</td>
      </tr>`).join('') + `</table>`;
  });
  return html;
}
function closeFeedbackModal(){
  document.getElementById('feedbackModal').classList.remove('open');
}
document.getElementById('feedbackModal').addEventListener('click',function(e){if(e.target===this)closeFeedbackModal()});
</script>
@endpush
