<div style="padding:18px 22px">

  {{-- ── Details form — wraps everything so Save submits all fields ──

       Folded into sections the way the front desk is: an exhibit carries
       four unrelated jobs - its label, where it stands, its translations,
       its pictures - and showing all four at once made a form nobody could
       see the end of. Only the first is open; the rest say what they hold
       in their summary and open when asked. Everything saves in one press,
       whether its section was opened or not. --}}
  <form method="POST" action="{{ route('exhibits.update', $exhibit) }}" enctype="multipart/form-data">
    @csrf @method('PUT')

    @php
      $translationCards = $exhibit->translations->map(fn($t) => [
          'language_code' => $t->language_code,
          'title'         => $t->title,
          'description'   => $t->description,
          'fun_facts'     => $t->fun_facts,
          'audio_url'     => $t->audio_url,
          'audio_stale'   => $t->audio_stale,
          'audio_made_at' => $t->audio_made_at?->format('M j, Y'),
      ])->values();
      $galleryCount = $exhibit->images->count();
      $transCount   = $exhibit->translations->count();
      $staleAudio   = $exhibit->translations->filter(fn($t) => $t->audio_stale === true)->count();
      $trainCount   = $exhibit->trainingImages()->count();
    @endphp

    <div style="display:grid;gap:12px">

      {{-- ── The label itself ──────────────────────────────────── --}}
      <details class="fold" open>
        <summary>
          <div class="fold-ico">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
          </div>
          <div class="fold-text">
            <div class="fold-title">Exhibit details</div>
            <div class="fold-sub">Name, code, category and the text visitors read</div>
          </div>
          <svg class="fold-chev" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="fold-body">
          <div class="ex-two">
            <div>
              <div class="fi-row">
                <div class="fg"><label class="fl">Exhibit Code</label><input class="fi" name="exhibit_code" value="{{ $exhibit->exhibit_code }}" required></div>
                <div class="fg"><label class="fl">Category</label>
                  <select class="fi" name="category_id">
                    <option value="">— None —</option>
                    @foreach($categories as $cat)
                    <option value="{{ $cat->category_id }}" {{ $exhibit->category_id == $cat->category_id ? 'selected' : '' }}>{{ $cat->name }}</option>
                    @endforeach
                  </select>
                </div>
              </div>
              <div class="fg"><label class="fl">Name</label><input class="fi" name="name" value="{{ $exhibit->name }}" required></div>
              <div class="fg"><label class="fl">Authors</label><input class="fi" name="authors" value="{{ $exhibit->authors }}" placeholder="e.g. Dr. Juan Dela Cruz"></div>
              <div class="fg"><label class="fl">Description</label><textarea class="fi" name="description" rows="4" data-autogrow placeholder="Exhibit description…">{{ $exhibit->description }}</textarea></div>
              <div class="fg" style="margin-bottom:0"><label class="fl">Fun Facts <span style="font-weight:400;text-transform:none;font-size:11px">(one per line)</span></label><textarea class="fi" name="fun_facts" rows="3" data-autogrow placeholder="Enter each fun fact on a new line…">{{ $exhibit->fun_facts }}</textarea></div>
            </div>

            {{-- Exhibit photos are portrait as often as not, so the cover sits
                 beside the fields and is shown whole rather than cropped. --}}
            <div class="ex-side">
              <label class="fl">Cover picture</label>
              @if($exhibit->image)
              <img src="{{ $exhibit->image_url }}" class="ex-side-img" style="margin-bottom:8px" alt="">
              @endif
              <div class="fg" style="margin-bottom:0"><input class="fi" type="file" name="image" accept="image/*"></div>
            </div>
          </div>
        </div>
      </details>

      {{-- ── Where it stands ───────────────────────────────────── --}}
      <details class="fold">
        <summary>
          <div class="fold-ico">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
          </div>
          <div class="fold-text">
            <div class="fold-title">Location &amp; storyline</div>
            <div class="fold-sub">{{ $exhibit->museumHall?->name ?? 'No hall yet' }}@if($exhibit->storyline_order) · stop #{{ $exhibit->storyline_order }}@endif</div>
          </div>
          <svg class="fold-chev" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="fold-body">
          <div style="display:grid;grid-template-columns:2fr 1fr 1.5fr;gap:12px">
            <div class="fg" style="margin-bottom:0"><label class="fl">Hall</label>
              <select class="fi" name="hall_id">
                <option value="">No hall yet</option>
                @foreach($halls as $h)
                <option value="{{ $h->hall_id }}" {{ $exhibit->hall_id === $h->hall_id ? 'selected' : '' }}>{{ $h->name }} · {{ $h->floor }}</option>
                @endforeach
              </select>
            </div>
            <div class="fg" style="margin-bottom:0"><label class="fl">Storyline Order</label><input class="fi" type="number" name="storyline_order" value="{{ $exhibit->storyline_order }}" min="0"></div>
            <div class="fg" style="margin-bottom:0"><label class="fl">Languages</label><input class="fi" name="languages" value="{{ $exhibit->languages }}" placeholder="Filipino,English"></div>
          </div>
        </div>
      </details>

      {{-- ── Translations & audio ──────────────────────────────── --}}
      <details class="fold">
        <summary>
          <div class="fold-ico">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 8l6 6"/><path d="M4 14l6-6 2-3"/><path d="M2 5h12"/><path d="M7 2h1"/><path d="M22 22l-5-10-5 10"/><path d="M14 18h6"/></svg>
          </div>
          <div class="fold-text">
            <div class="fold-title">Translations &amp; audio guide</div>
            <div class="fold-sub">
              {{ $transCount ? $transCount . ' language(s) written' : 'None yet - the AI can draft them' }}
              @if($staleAudio)<span style="color:#b45309;font-weight:600">· {{ $staleAudio }} narration(s) read the old text</span>@endif
            </div>
          </div>
          <svg class="fold-chev" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="fold-body">
          {{-- The same review section as the Add modal: existing translations
               load as editable cards, "Generate with AI" drafts the missing
               ones from the fields above, and Save writes it all with the
               rest of the form. See public/js/exhibit-ai.js. --}}
          <div class="ai-section" data-ai-root
               data-translate-url="{{ route('exhibits.ai.translate') }}"
               data-narrate-url="{{ route('exhibits.ai.narrate') }}"
               data-name-field="#exEditBody input[name=name]"
               data-desc-field="#exEditBody textarea[name=description]"
               data-facts-field="#exEditBody textarea[name=fun_facts]"
               data-source-language="{{ $exhibit->source_language ?? 'en' }}"
               data-existing='@json($translationCards)'></div>
        </div>
      </details>

      {{-- ── Gallery ───────────────────────────────────────────── --}}
      <details class="fold">
        <summary>
          <div class="fold-ico">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
          </div>
          <div class="fold-text">
            <div class="fold-title">Gallery pictures</div>
            <div class="fold-sub">{{ $galleryCount ? $galleryCount . ' picture(s) visitors can swipe through' : 'None yet' }}</div>
          </div>
          <svg class="fold-chev" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="fold-body">
          {{-- Part of the same form as everything else, so nothing here happens
               until Save: a removed picture is only marked (and can be put
               back), and new pictures upload with the rest of the changes.
               This used to be two nested forms, which browsers flatten - the
               first x submitted the whole exhibit form instead. --}}
          @if($galleryCount)
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px;margin-bottom:12px" data-gallery>
            @foreach($exhibit->images as $img)
            <div style="position:relative;border-radius:var(--r-sm);overflow:hidden;background:var(--border-light)" data-gallery-item>
              <img src="{{ $img->url }}" style="width:100%;height:80px;object-fit:cover;display:block;transition:opacity .15s" alt="{{ $img->caption }}">
              <input type="checkbox" name="remove_images[]" value="{{ $img->image_id }}" style="display:none">
              <button type="button" data-gallery-remove title="Remove" style="position:absolute;top:3px;right:3px;background:rgba(220,38,38,.85);border:none;border-radius:50%;width:20px;height:20px;color:white;cursor:pointer;font-size:13px;line-height:1;display:flex;align-items:center;justify-content:center">×</button>
            </div>
            @endforeach
          </div>
          <p data-gallery-note style="display:none;font-size:11.5px;color:var(--text-3);margin:-4px 0 10px">
            <span data-gallery-count>0</span> picture(s) removed — saved when you press Save Changes.
            <button type="button" data-gallery-undo style="background:none;border:none;padding:0;color:var(--green-dark);font-weight:600;font-size:11.5px;cursor:pointer">Put them back</button>
          </p>
          @else
          <p style="font-size:13px;color:var(--text-3);margin-bottom:10px">No gallery pictures yet.</p>
          @endif
          <div class="fi-row" style="margin-bottom:0">
            <div class="fg" style="margin-bottom:0"><label class="fl">Add pictures</label><input class="fi" type="file" name="gallery_images[]" accept="image/*" multiple></div>
            <div class="fg" style="margin-bottom:0"><label class="fl">Caption (optional)</label><input class="fi" name="gallery_caption" placeholder="e.g. Front view"></div>
          </div>
        </div>
      </details>

      {{-- ── Camera recognition ────────────────────────────────── --}}
      <details class="fold">
        <summary>
          <div class="fold-ico">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
          </div>
          <div class="fold-text">
            <div class="fold-title">Camera recognition</div>
            <div class="fold-sub">{{ $trainCount }} training photo(s)@if($trainCount < \App\Services\Recognition::MIN_PHOTOS) · needs more @endif</div>
          </div>
          <svg class="fold-chev" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="fold-body">
          <p style="font-size:12.5px;color:var(--text-3);line-height:1.6;margin:0 0 10px">
            The photos the visitor app learns from, so that pointing a camera at this exhibit opens it.
            Kept apart from the gallery and managed on their own page — {{ \App\Services\Recognition::GOOD_PHOTOS }} or more, from different angles, works best.
          </p>
          <a href="{{ route('recognition.photos', $exhibit) }}" class="btn btn-outline btn-sm">Manage recognition photos</a>
        </div>
      </details>

    </div>

    {{-- ── Cancel / Save at the very bottom ── --}}
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeEditModal()">Cancel</button>
      <button type="submit" class="btn btn-green btn-sm">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Save Changes
      </button>
    </div>

  </form>

</div>
