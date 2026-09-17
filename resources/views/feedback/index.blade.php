@extends('layouts.admin')
@section('title','Feedback — Museo de Baler')

@section('content')
<div class="ph">
  <div class="ph-left">
    <h2>Feedback</h2>
    <p>Visitor feedback and survey responses</p>
  </div>
  <div class="ph-right">
    @include('partials.report-menu', [
      'id'      => 'feedbackReportMenu',
      'mode'    => 'range',
      'reports' => [
        ['label' => 'Feedback report', 'url' => route('reports.feedback')],
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
      <th>Last Name</th><th>First Name</th><th>Rating</th><th>Feedback</th><th>Date</th><th>Action</th>
    </tr></thead>
    <tbody>
    @forelse($feedback as $fb)
    <tr>
      <td>{{ $fb->visitor?->last_name ?? '—' }}</td>
      <td>{{ $fb->visitor?->first_name ?? '—' }}</td>
      <td style="color:var(--gold);letter-spacing:1px">{{ str_repeat('★', $fb->rating) }}{{ str_repeat('☆', 5 - $fb->rating) }}</td>
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
  <div class="modal" style="max-width:520px">
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
  overlay.classList.add('open');
  fetch('{{ url('/') }}/feedback/'+id+'/modal')
    .then(r=>r.json())
    .then(d=>{
      const name=((d.visitor_first||'')+' '+(d.visitor_last||'')).trim()||'—';
      const stars='★'.repeat(d.rating)+'☆'.repeat(5-d.rating);
      body.innerHTML=`
        <div class="fi-row" style="margin-bottom:12px">
          <div><div class="fl">Name</div><div class="fi-val">${name}</div></div>
          <div><div class="fl">Rating</div><div class="fi-val" style="color:#d97706;font-size:20px;letter-spacing:2px">${stars}</div></div>
        </div>
        <div class="fi-row" style="margin-bottom:12px">
          <div><div class="fl">Country</div><div class="fi-val">${d.country||'—'}</div></div>
          <div><div class="fl">Date</div><div class="fi-val">${d.date||'—'}</div></div>
        </div>
        <div class="fl">Comment</div>
        <div class="fi-val" style="white-space:pre-line;min-height:80px;line-height:1.6">${d.comment||'No comment provided.'}</div>
        <div class="modal-ft"><button class="btn btn-outline" onclick="closeFeedbackModal()">Close</button></div>`;
    })
    .catch(()=>{ body.innerHTML='<p style="color:var(--red);padding:20px">Failed to load feedback.</p>'; });
}
function closeFeedbackModal(){
  document.getElementById('feedbackModal').classList.remove('open');
}
document.getElementById('feedbackModal').addEventListener('click',function(e){if(e.target===this)closeFeedbackModal()});
</script>
@endpush
