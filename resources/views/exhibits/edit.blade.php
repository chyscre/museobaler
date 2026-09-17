@extends('layouts.admin')
@section('title', 'Edit ' . $exhibit->name . ' — Museo Baler')

@section('content')
@if(session('success'))
<div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="ph">
  <div class="ph-left">
    <h2>Edit Exhibit</h2>
    <p>{{ $exhibit->name }}</p>
  </div>
  <div class="ph-right">
    <a href="{{ route('exhibits.index') }}" class="btn btn-outline btn-sm">← Back to Exhibits</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">
  <!-- Main form -->
  <div>
    <div class="card card-p">
      <div style="font-size:14px;font-weight:700;color:var(--text);margin-bottom:16px">Exhibit Details</div>
      <form method="POST" action="{{ route('exhibits.update', $exhibit) }}" enctype="multipart/form-data">
        @csrf @method('PUT')
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
          <div class="fg"><label class="fl">Floor</label>
            <select class="fi" name="floor">
              @foreach(['Ground Floor','2nd Floor'] as $f)
              <option {{ $exhibit->floor === $f ? 'selected' : '' }}>{{ $f }}</option>
              @endforeach
            </select>
          </div>
          <div class="fg"><label class="fl">Hall</label>
            <select class="fi" name="hall">
              @foreach(['Hall A','Hall B','Hall C','Hall D','Hall E'] as $h)
              <option {{ $exhibit->hall === $h ? 'selected' : '' }}>{{ $h }}</option>
              @endforeach
            </select>
          </div>
        </div>
        <div class="fg"><label class="fl">Authors</label><input class="fi" name="authors" value="{{ $exhibit->authors }}"></div>
        <div class="fg"><label class="fl">Description</label><textarea class="fi" name="description" rows="4">{{ $exhibit->description }}</textarea></div>
        <div class="fg"><label class="fl">Fun Facts <span style="font-weight:400;font-size:11px">(one per line)</span></label><textarea class="fi" name="fun_facts" rows="4">{{ $exhibit->fun_facts }}</textarea></div>
        <div class="fi-row">
          <div class="fg"><label class="fl">Languages</label><input class="fi" name="languages" value="{{ $exhibit->languages }}"></div>
          <div class="fg"><label class="fl">Storyline Order</label><input class="fi" type="number" name="storyline_order" value="{{ $exhibit->storyline_order }}" min="0"></div>
        </div>
        <div class="fg"><label class="fl">Image</label>
          @if($exhibit->image)
          <img src="{{ $exhibit->image_url }}" style="width:80px;height:60px;object-fit:cover;border-radius:6px;margin-bottom:6px;display:block">
          @endif
          <input class="fi" type="file" name="image" accept="image/*">
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:8px">
          <a href="{{ route('exhibits.index') }}" class="btn btn-outline">Cancel</a>
          <button type="submit" class="btn btn-green">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Translations & Gallery -->
  <div>
    <!-- Translations -->
    <div class="card card-p" style="margin-bottom:16px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
        <div style="font-size:14px;font-weight:700;color:var(--text)">Translations & Audio</div>
        <button class="btn btn-green btn-xs" onclick="document.getElementById('addTransForm').style.display=document.getElementById('addTransForm').style.display==='none'?'block':'none'">+ Add</button>
      </div>

      <!-- Add translation form -->
      <div id="addTransForm" style="display:none;background:#f9f5f0;border-radius:8px;padding:14px;margin-bottom:14px">
        <form method="POST" action="{{ route('exhibits.translations.store', $exhibit) }}" enctype="multipart/form-data">
          @csrf
          <div class="fi-row">
            <div class="fg"><label class="fl">Code</label><input class="fi" name="language_code" placeholder="en" required maxlength="10"></div>
            <div class="fg"><label class="fl">Label</label><input class="fi" name="language_label" placeholder="English" required></div>
          </div>
          <div class="fg"><label class="fl">Title</label><input class="fi" name="title" placeholder="Exhibit title in this language"></div>
          <div class="fg"><label class="fl">Description</label><textarea class="fi" name="description" rows="3"></textarea></div>
          <div class="fg"><label class="fl">Audio (MP3/WAV)</label><input class="fi" type="file" name="audio" accept="audio/*"></div>
          <div style="display:flex;justify-content:flex-end;gap:6px">
            <button type="button" class="btn btn-outline btn-xs" onclick="document.getElementById('addTransForm').style.display='none'">Cancel</button>
            <button type="submit" class="btn btn-green btn-xs">Save</button>
          </div>
        </form>
      </div>

      @forelse($exhibit->translations as $t)
      <div style="background:#f9f5f0;border-radius:8px;padding:12px;margin-bottom:8px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
          <span style="font-size:12px;font-weight:700">{{ $t->language_label }} <span style="color:#888;font-weight:400">({{ $t->language_code }})</span></span>
          <form method="POST" action="{{ route('exhibits.translations.destroy', $t) }}" onsubmit="return confirm('Delete this translation?')">
            @csrf @method('DELETE')
            <button class="btn btn-red btn-xs">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:11px;height:11px"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
            </button>
          </form>
        </div>
        <div style="font-size:11.5px;color:#888">{{ Str::limit($t->title, 60) }}</div>
        @if($t->audio_file)
        <audio controls style="height:28px;width:100%;margin-top:6px"><source src="{{ $t->audio_url }}"></audio>
        @endif
      </div>
      @empty
      <p style="font-size:13px;color:#888">No translations yet.</p>
      @endforelse
    </div>

    <!-- Recognition photos: the ones the camera learns from, not the ones visitors see -->
    @php $trainCount = $exhibit->trainingImages()->count(); @endphp
    <div class="card card-p" style="margin-bottom:16px">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div>
          <div style="font-size:14px;font-weight:700;color:var(--text)">Camera Recognition</div>
          <div style="font-size:12.5px;color:var(--text-3);margin-top:2px">
            {{ $trainCount }} training photo(s)
            @if($trainCount < \App\Services\Recognition::MIN_PHOTOS) · <span style="color:#b91c1c;font-weight:600">needs {{ \App\Services\Recognition::GOOD_PHOTOS }}+ to be recognised well</span>@endif
          </div>
        </div>
        <a href="{{ route('recognition.photos', $exhibit) }}" class="btn btn-outline btn-sm">Manage photos</a>
      </div>
    </div>

    <!-- Gallery -->
    <div class="card card-p">
      <div style="font-size:14px;font-weight:700;color:var(--text);margin-bottom:14px">Gallery Images</div>
      @if($exhibit->images->count())
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px">
        @foreach($exhibit->images as $img)
        <div style="position:relative;border-radius:8px;overflow:hidden">
          <img src="{{ $img->url }}" style="width:100%;height:80px;object-fit:cover;display:block">
          <form method="POST" action="{{ route('exhibits.gallery.destroy', $img) }}" style="position:absolute;top:4px;right:4px" onsubmit="return confirm('Remove image?')">
            @csrf @method('DELETE')
            <button style="background:rgba(220,38,38,.85);border:none;border-radius:50%;width:22px;height:22px;color:white;cursor:pointer;font-size:12px">×</button>
          </form>
        </div>
        @endforeach
      </div>
      @endif
      <form method="POST" action="{{ route('exhibits.gallery.upload', $exhibit) }}" enctype="multipart/form-data">
        @csrf
        <div class="fg"><label class="fl">Add Images</label><input class="fi" type="file" name="images[]" accept="image/*" multiple></div>
        <div class="fg"><label class="fl">Caption (optional)</label><input class="fi" name="caption" placeholder="e.g. Front view"></div>
        <button type="submit" class="btn btn-green btn-sm">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Upload
        </button>
      </form>
    </div>
  </div>
</div>
@endsection
