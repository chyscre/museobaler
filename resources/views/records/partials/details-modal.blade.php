{{-- One window for every View Details / View Breakdown button. A button
     carries data-details="<url>"; the body is fetched from
     RecordDetailsController and put in as it comes. --}}
<div class="overlay" id="detailsModal">
  <div class="modal" style="max-width:720px">
    <div class="modal-hd">
      <h3 id="detailsTitle">Details</h3>
      <button type="button" class="modal-close" onclick="closeDetails()" aria-label="Close">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div id="detailsBody" class="dt-body"></div>
  </div>
</div>

@push('styles')
<style>
  .dt-body { max-height: 72vh; overflow-y: auto; font-size: 13px; color: var(--text-2); }
  .dt-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; flex-wrap: wrap; margin-bottom: 4px; }
  .dt-title { font-size: 16px; font-weight: 700; color: var(--text); }
  .dt-sub { font-size: 12px; color: var(--text-3); margin-top: 2px; }
  .dt-ref { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
  .dt-body code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; font-weight: 700; background: var(--border-light); border-radius: 6px; padding: 2px 7px; color: var(--text); }
  .dt-muted { color: var(--text-3); font-weight: 400; }
  .dt-section { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--text-3); margin: 18px 0 8px; }
  .dt-grid { display: grid; grid-template-columns: 160px 1fr; gap: 6px 14px; margin: 10px 0 0; }
  .dt-grid dt { color: var(--text-3); }
  .dt-grid dd { margin: 0; color: var(--text); font-weight: 500; }
  .dt-receipt { margin-top: 12px; border: 1px dashed var(--border); border-radius: 10px; padding: 10px 12px; }
  .dt-receipt-hd { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; font-size: 12px; color: var(--text-3); margin-bottom: 6px; }
  .dt-line { display: flex; justify-content: space-between; gap: 10px; padding: 2px 0; }
  .dt-line span:last-child { white-space: nowrap; font-variant-numeric: tabular-nums; }
  .dt-total { border-top: 1px solid var(--border-light); margin-top: 4px; padding-top: 5px; font-weight: 700; color: var(--text); }
  .dt-note { font-size: 11.5px; color: var(--text-3); margin: 8px 0 0; line-height: 1.5; }
  .dt-scans { list-style: none; margin: 0; padding: 0; }
  .dt-scans li { display: flex; gap: 12px; padding: 6px 0; border-top: 1px solid var(--border-light); }
  .dt-scans li:first-child { border-top: 0; }
  .dt-scans strong { color: var(--text); }
  .dt-scans small { display: block; font-size: 11.5px; color: var(--text-3); }
  .dt-scans code { margin-left: 6px; font-size: 11px; padding: 1px 5px; }
  .dt-time { flex-shrink: 0; width: 64px; font-variant-numeric: tabular-nums; color: var(--text-3); }
  .dt-table-wrap { overflow-x: auto; }
  .dt-table { width: 100%; border-collapse: collapse; }
  .dt-table th { font-size: 11px; text-transform: uppercase; color: var(--text-3); text-align: left; padding: 7px 8px; border-bottom: 1.5px solid var(--border); }
  .dt-table td { padding: 8px; border-bottom: 1px solid var(--border-light); vertical-align: top; }
  .dt-table summary { cursor: pointer; color: var(--green-dark); font-weight: 600; }
  @media (max-width: 560px) { .dt-grid { grid-template-columns: 1fr; } .dt-grid dd { margin-bottom: 6px; } .dt-ref { align-items: flex-start; } }
</style>
@endpush

@push('scripts')
<script>
  (function () {
    var modal = document.getElementById('detailsModal');
    var body  = document.getElementById('detailsBody');
    var title = document.getElementById('detailsTitle');

    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-details]');
      if (!btn) return;
      title.textContent = btn.dataset.title || 'Details';
      body.innerHTML = '<p class="dt-muted">Loading…</p>';
      modal.classList.add('open');

      fetch(btn.dataset.details, { headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
        .then(function (html) { body.innerHTML = html; })
        .catch(function () { body.innerHTML = '<p class="dt-muted">Could not load the details. Refresh the page and try again.</p>'; });
    });

    modal.addEventListener('click', function (e) { if (e.target === modal) closeDetails(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('open')) closeDetails(); });
  })();
  function closeDetails() { document.getElementById('detailsModal').classList.remove('open'); }
</script>
@endpush
