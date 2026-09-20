<div style="padding:18px 22px">

  {{-- ── Details form — wraps everything so Save submits all fields ── --}}
  <form method="POST" action="{{ route('exhibits.update', $exhibit) }}" enctype="multipart/form-data">
    @csrf @method('PUT')

    {{-- Identity --}}
    <div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px;padding-bottom:6px;border-bottom:1px solid var(--border)">Identity</div>
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
    <div class="fi-row">
      <div class="fg"><label class="fl">Authors</label><input class="fi" name="authors" value="{{ $exhibit->authors }}" placeholder="e.g. Dr. Juan Dela Cruz"></div>
      <div class="fg"><label class="fl">Languages</label><input class="fi" name="languages" value="{{ $exhibit->languages }}" placeholder="Filipino,English"></div>
    </div>

    {{-- Location --}}
    <div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;margin:16px 0 12px;padding-bottom:6px;border-bottom:1px solid var(--border)">Location & Order</div>
    <div class="fi-row">
      <div class="fg"><label class="fl">Hall</label>
        <select class="fi" name="hall_id">
          <option value="">No hall yet</option>
          @foreach($halls as $h)
          <option value="{{ $h->hall_id }}" {{ $exhibit->hall_id === $h->hall_id ? 'selected' : '' }}>{{ $h->name }} · {{ $h->floor }}</option>
          @endforeach
        </select>
      </div>
    </div>
    <div style="max-width:50%">
      <div class="fg"><label class="fl">Storyline Order</label><input class="fi" type="number" name="storyline_order" value="{{ $exhibit->storyline_order }}" min="0"></div>
    </div>

    {{-- Content --}}
    <div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;margin:16px 0 12px;padding-bottom:6px;border-bottom:1px solid var(--border)">Content</div>
    <div class="fg"><label class="fl">Description</label><textarea class="fi" name="description" rows="4" placeholder="Exhibit description…">{{ $exhibit->description }}</textarea></div>
    <div class="fg"><label class="fl">Fun Facts <span style="font-weight:400;text-transform:none;font-size:11px">(one per line)</span></label><textarea class="fi" name="fun_facts" rows="3" placeholder="Enter each fun fact on a new line…">{{ $exhibit->fun_facts }}</textarea></div>

    {{-- Cover Image --}}
    <div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;margin:16px 0 12px;padding-bottom:6px;border-bottom:1px solid var(--border)">Cover Image</div>
    @if($exhibit->image)
    <img src="{{ $exhibit->image_url }}" style="width:100%;height:110px;object-fit:cover;border-radius:var(--r-sm);margin-bottom:8px;display:block" alt="">
    @endif
    <div class="fg"><input class="fi" type="file" name="image" accept="image/*"></div>

    {{-- Translations & audio: the same review section as the Add modal.
         Existing translations load as editable cards; "Generate with AI"
         drafts the missing ones from the fields above; Save writes it all
         with the rest of the form. See public/js/exhibit-ai.js. --}}
    @php
      $translationCards = $exhibit->translations->map(fn($t) => [
          'language_code' => $t->language_code,
          'title'         => $t->title,
          'description'   => $t->description,
          'fun_facts'     => $t->fun_facts,
          'audio_url'     => $t->audio_url,
      ])->values();
    @endphp
    <div class="ai-title">Translations &amp; Audio Guide</div>
    <div class="ai-section" data-ai-root
         data-translate-url="{{ route('exhibits.ai.translate') }}"
         data-narrate-url="{{ route('exhibits.ai.narrate') }}"
         data-name-field="#exEditBody input[name=name]"
         data-desc-field="#exEditBody textarea[name=description]"
         data-facts-field="#exEditBody textarea[name=fun_facts]"
         data-source-language="{{ $exhibit->source_language ?? 'en' }}"
         data-existing='@json($translationCards)'></div>
    {{-- Gallery --}}
    <div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;margin:20px 0 12px;padding-bottom:6px;border-bottom:1px solid var(--border)">Gallery Images</div>
    @if($exhibit->images->count())
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px">
      @foreach($exhibit->images as $img)
      <div style="position:relative;border-radius:var(--r-sm);overflow:hidden;background:var(--border-light)">
        <img src="{{ $img->url }}" style="width:100%;height:70px;object-fit:cover;display:block" alt="{{ $img->caption }}">
        <form method="POST" action="{{ route('exhibits.gallery.destroy', $img) }}" style="position:absolute;top:3px;right:3px" onsubmit="return confirm('Remove this image?')">
          @csrf @method('DELETE')
          <button style="background:rgba(220,38,38,.85);border:none;border-radius:50%;width:20px;height:20px;color:white;cursor:pointer;font-size:13px;line-height:1;display:flex;align-items:center;justify-content:center">×</button>
        </form>
      </div>
      @endforeach
    </div>
    @else
    <p style="font-size:13px;color:var(--text-3);margin-bottom:10px">No gallery images yet.</p>
    @endif
    {{-- Gallery upload uses its own form --}}
    <form method="POST" action="{{ route('exhibits.gallery.upload', $exhibit) }}" enctype="multipart/form-data">
      @csrf
      <div class="fi-row">
        <div class="fg"><label class="fl">Add Images</label><input class="fi" type="file" name="images[]" accept="image/*" multiple></div>
        <div class="fg"><label class="fl">Caption (optional)</label><input class="fi" name="caption" placeholder="e.g. Front view"></div>
      </div>
      <div style="display:flex;justify-content:flex-end">
        <button type="submit" class="btn btn-outline btn-sm">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Upload
        </button>
      </div>
    </form>

    {{-- ── Cancel / Save at the very bottom ── --}}
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:24px;padding-top:16px;border-top:1px solid var(--border)">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeEditModal()">Cancel</button>
      <button type="submit" class="btn btn-green btn-sm">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Save Changes
      </button>
    </div>

  </form>

</div>
