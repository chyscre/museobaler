/* Translations & Audio — the review step of the exhibit form.
 *
 * One card per visitor-app language. "Generate with AI" fills the cards
 * from the exhibit's own name, description and fun facts; every field stays
 * editable, and each card can narrate its (edited) text and play it back.
 * Nothing leaves the form until the admin presses Save: the cards are plain
 * form fields (t_code[], t_title[] …) and narration is parked on the server
 * as a draft token (t_audio_draft[]) that Save turns into the real file.
 *
 * Mounted twice: in the Add Exhibit modal, and in the Edit form (which is
 * fetched as HTML and injected, so the markup carries its settings as data-
 * attributes and this file does the wiring).
 */
(function () {
  'use strict';

  const LANGS = [['en', 'English'], ['fil', 'Filipino'], ['es', 'Spanish']];
  const label = code => (LANGS.find(l => l[0] === code) || [code, code.toUpperCase()])[1];

  const esc = s => String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

  function csrf() {
    const m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.content : '';
  }

  // Google's free tier allows a limited number of calls a minute, and one
  // exhibit with three languages uses several. Hitting it is a wait, not an
  // error, so the form waits it out and tries again rather than handing the
  // quota paragraph to whoever is writing an exhibit label. onWait is called
  // each second so the card can show the countdown.
  async function postJson(url, body, onWait) {
    for (let attempt = 0; ; attempt++) {
      const out = await postOnce(url, body);
      if (!out.busy || attempt >= 2) {
        if (out.error) throw out.error;
        return out.data;
      }
      let left = Math.min(90, Math.max(5, out.retryAfter || 30));
      while (left > 0) {
        if (onWait) onWait(left, out.message);
        await new Promise(r => setTimeout(r, 1000));
        left--;
      }
    }
  }

  async function postOnce(url, body) {
    const r = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
      body: JSON.stringify(body),
    });
    let data = null;
    try { data = await r.json(); } catch (e) { /* fall through */ }
    if (r.status === 503 && data && data.error) {
      return { busy: true, retryAfter: data.retry_after, message: data.error };
    }
    if (!r.ok) {
      const msg = (data && (data.error || data.message)) ||
        (r.status === 419 ? 'Your session expired. Reload the page and try again.' : 'Request failed (' + r.status + ').');
      // Laravel validation payload: surface the first field message.
      const first = data && data.errors && Object.values(data.errors)[0];
      return { error: new Error(first ? first[0] : msg) };
    }
    return { data };
  }

  /** What gets read aloud: title, description, then the facts. */
  function narrationText(card) {
    const t = card.querySelector('[data-f="title"]').value.trim();
    const d = card.querySelector('[data-f="desc"]').value.trim();
    const f = card.querySelector('[data-f="facts"]').value.trim();
    return [t, d, f].filter(Boolean).join('\n\n');
  }

  class Section {
    constructor(root) {
      this.root = root;
      this.urls = { translate: root.dataset.translateUrl, narrate: root.dataset.narrateUrl };
      this.source = {
        name:  document.querySelector(root.dataset.nameField),
        desc:  document.querySelector(root.dataset.descField),
        facts: document.querySelector(root.dataset.factsField),
      };
      this.existing = JSON.parse(root.dataset.existing || '[]');
      this.render();
    }

    render() {
      const r = this.root;
      const from = r.dataset.sourceLanguage || 'en';
      r.innerHTML = `
        <div class="ai-head">
          <div class="ai-head-left">
            <label class="fl" style="margin:0">Written in</label>
            <select class="fi ai-from" name="source_language" style="width:auto;padding:5px 28px 5px 9px">
              ${LANGS.map(([c, n]) => `<option value="${c}" ${c === from ? 'selected' : ''}>${n}</option>`).join('')}
            </select>
          </div>
          <div class="ai-head-right">
            <button type="button" class="btn btn-outline btn-xs ai-generate">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 17l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7z"/></svg>
              Generate with AI
            </button>
            <button type="button" class="btn btn-outline btn-xs ai-narrate-all" title="Narrate every card that has text">Narrate all</button>
          </div>
        </div>
        <div class="ai-status" hidden></div>
        <div class="ai-cards"></div>
        <div class="ai-foot">
          <span class="ai-hint">Read each translation, fix anything, listen to the audio — then save the exhibit.</span>
          <span class="ai-add">
            Add language:
            ${LANGS.map(([c, n]) => `<button type="button" class="btn btn-muted btn-xs ai-add-lang" data-lang="${c}">${n}</button>`).join(' ')}
          </span>
        </div>`;

      this.cards = r.querySelector('.ai-cards');
      this.status = r.querySelector('.ai-status');

      r.querySelector('.ai-generate').addEventListener('click', () => this.generate());
      r.querySelector('.ai-narrate-all').addEventListener('click', () => this.narrateAll());
      r.querySelectorAll('.ai-add-lang').forEach(b => b.addEventListener('click', () => {
        this.ensureCard(b.dataset.lang, {}, false);
        this.refreshAddButtons();
      }));

      this.existing.forEach(t => this.ensureCard(t.language_code, t, false));
      this.refreshAddButtons();
    }

    say(msg, kind) {
      if (!msg) { this.status.hidden = true; return; }
      this.status.hidden = false;
      this.status.className = 'ai-status ai-status-' + (kind || 'info');
      this.status.textContent = msg;
    }

    /** Source text as typed in the main form right now. */
    sourceText() {
      return {
        title:       this.source.name  ? this.source.name.value.trim()  : '',
        description: this.source.desc  ? this.source.desc.value.trim()  : '',
        fun_facts:   this.source.facts ? this.source.facts.value.trim() : '',
      };
    }

    fromLang() { return this.root.querySelector('.ai-from').value; }

    async generate() {
      const src = this.sourceText();
      if (!src.title || !src.description) {
        this.say('Write the exhibit name and description first — that is what gets translated.', 'warn');
        return;
      }
      const btn = this.root.querySelector('.ai-generate');
      const from = this.fromLang();

      // The original, in its own language, is a card too: it is what the
      // visitor app reads aloud when they pick that language.
      const own = this.ensureCard(from, { title: src.title, description: src.description, fun_facts: src.fun_facts }, true);
      own.querySelector('[data-f="title"]').value = src.title;
      own.querySelector('[data-f="desc"]').value  = src.description;
      own.querySelector('[data-f="facts"]').value = src.fun_facts;
      this.markStale(own);

      btn.disabled = true;
      this.say('Translating…', 'info');
      try {
        const data = await postJson(this.urls.translate, { ...src, from }, (left) => {
          this.say('The AI service is at its limit for the minute. Retrying in ' + left + 's… nothing is lost.', 'warn');
        });
        (data.translations || []).forEach(t => {
          const card = this.ensureCard(t.language_code, t, true);
          card.querySelector('[data-f="title"]').value = t.title || '';
          card.querySelector('[data-f="desc"]').value  = t.description || '';
          card.querySelector('[data-f="facts"]').value = t.fun_facts || '';
          this.markStale(card);
        });
        this.say('Translations drafted. Read them over, then narrate.', 'ok');
        this.refreshAddButtons();
      } catch (e) {
        this.say(e.message, 'error');
      } finally {
        btn.disabled = false;
      }
    }

    // Every language at once. The wait is the speech service generating a
    // minute or more of audio per language; three of those back to back was
    // three times the wait for no reason. The per-account ceiling on these
    // calls (throttle:ai) has room for it.
    async narrateAll() {
      const cards = Array.from(this.cards.querySelectorAll('.ai-card')).filter(narrationText);
      await Promise.all(cards.map(c => this.narrate(c)));
    }

    async narrate(card) {
      const text = narrationText(card);
      if (!text) { this.setAudioNote(card, 'Nothing to narrate yet.', 'warn'); return; }
      const btn = card.querySelector('.ai-narrate');
      btn.disabled = true;
      // A running clock, because a still message over a long wait reads as
      // a hang. The estimate comes from the text: a person reads about 150
      // words a minute, and the service takes roughly a third of the
      // finished audio's length to make it.
      const words = text.split(/\s+/).length;
      const guess = Math.max(10, Math.round(words / 150 * 60 / 3));
      const t0 = Date.now();
      const tick = () => {
        const s = Math.round((Date.now() - t0) / 1000);
        this.setAudioNote(card, `Narrating… ${s}s (usually about ${guess}s for this much text). You can keep editing the other fields meanwhile.`, 'info');
      };
      tick();
      const timer = setInterval(tick, 1000);
      try {
        const data = await postJson(this.urls.narrate, { language: card.dataset.lang, text }, (left) => {
          clearInterval(timer);
          this.setAudioNote(card, 'The AI service is at its limit for the minute. Retrying in ' + left + 's…', 'warn');
        });
        card.querySelector('[data-f="draft"]').value = data.draft;
        card.querySelector('[data-f="upload"]').value = '';
        this.setAudio(card, data.url, `New narration, ready in ${Math.round((Date.now() - t0) / 1000)}s — listen before saving.`);
        card.dataset.narrated = narrationText(card);
      } catch (e) {
        this.setAudioNote(card, e.message, 'error');
      } finally {
        clearInterval(timer);
        btn.disabled = false;
      }
    }

    setAudio(card, url, note) {
      const box = card.querySelector('.ai-audio');
      box.innerHTML = `<audio controls preload="none" style="height:28px;width:100%"><source src="${esc(url)}"></audio>`;
      this.setAudioNote(card, note, 'ok');
    }

    setAudioNote(card, msg, kind) {
      const n = card.querySelector('.ai-audio-note');
      n.textContent = msg || '';
      n.className = 'ai-audio-note ai-status-' + (kind || 'info');
    }

    /** Text changed after narration: say so, so nobody ships a mismatch. */
    markStale(card) {
      const has = card.querySelector('.ai-audio audio');
      if (has && card.dataset.narrated && card.dataset.narrated !== narrationText(card)) {
        this.setAudioNote(card, 'Text changed since this was narrated — narrate again to match.', 'warn');
      }
    }

    ensureCard(code, data, fromAi) {
      let card = this.cards.querySelector(`.ai-card[data-lang="${code}"]`);
      if (card) return card;

      card = document.createElement('div');
      card.className = 'ai-card';
      card.dataset.lang = code;
      card.innerHTML = `
        <div class="ai-card-head">
          <div>
            <span class="ai-card-lang">${esc(label(code))}</span>
            <span class="badge b-gray" style="font-size:10px;margin-left:4px">${esc(code)}</span>
            ${fromAi ? '<span class="badge b-gold" style="font-size:10px;margin-left:4px">AI draft — review</span>' : ''}
          </div>
          <button type="button" class="btn btn-muted btn-xs ai-remove" title="Remove this language">Remove</button>
        </div>
        <input type="hidden" name="t_code[]" value="${esc(code)}">
        <input type="hidden" name="t_label[]" value="${esc(label(code))}">
        <input type="hidden" name="t_audio_draft[]" data-f="draft" value="${esc(data.draft || '')}">
        <div class="fg"><label class="fl">Title</label><input class="fi" name="t_title[]" data-f="title" value="${esc(data.title)}"></div>
        <div class="fg"><label class="fl">Description</label><textarea class="fi" name="t_desc[]" data-f="desc" rows="4">${esc(data.description)}</textarea></div>
        <div class="fg"><label class="fl">Fun Facts <span style="font-weight:400;text-transform:none">(one per line)</span></label><textarea class="fi" name="t_facts[]" data-f="facts" rows="2">${esc(data.fun_facts)}</textarea></div>
        <div class="ai-audio-row">
          <button type="button" class="btn btn-outline btn-xs ai-narrate">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px"><path d="M11 5L6 9H2v6h4l5 4V5z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M19 5a10 10 0 0 1 0 14"/></svg>
            ${data.audio_url ? 'Narrate again' : 'Narrate'}
          </button>
          <div class="ai-audio">${data.audio_url ? `<audio controls preload="none" style="height:28px;width:100%"><source src="${esc(data.audio_url)}"></audio>` : ''}</div>
          <label class="ai-upload" title="Or upload your own recording">
            <input type="file" name="t_audio[]" data-f="upload" accept=".mp3,.wav,.ogg,.m4a,audio/*">
            <span>Upload</span>
          </label>
        </div>
        <div class="ai-audio-note ai-status-${data.audio_stale ? 'warn' : 'info'}">${
          !data.audio_url ? 'No audio yet.'
            : data.audio_stale
              ? 'This recording reads the older text' + (data.audio_made_at ? ' from ' + esc(data.audio_made_at) : '') + '. Narrate again so it matches what visitors now read.'
              : 'Current recording' + (data.audio_made_at ? ', made ' + esc(data.audio_made_at) : '') + '.'
        }</div>`;

      card.querySelector('.ai-narrate').addEventListener('click', () => this.narrate(card));
      card.querySelector('.ai-remove').addEventListener('click', () => {
        // Existing translations are deleted on save; drafts just vanish.
        if (this.existing.some(t => t.language_code === code)) {
          const del = document.createElement('input');
          del.type = 'hidden'; del.name = 't_delete[]'; del.value = code;
          this.root.appendChild(del);
        }
        card.remove();
        this.refreshAddButtons();
      });
      card.querySelectorAll('[data-f="title"],[data-f="desc"],[data-f="facts"]').forEach(el =>
        el.addEventListener('input', () => this.markStale(card)));
      card.querySelector('[data-f="upload"]').addEventListener('change', e => {
        if (e.target.files.length) {
          card.querySelector('[data-f="draft"]').value = '';
          this.setAudioNote(card, 'Your file will be used: ' + e.target.files[0].name, 'ok');
        }
      });

      // Keep visitor-app order: en, fil, es.
      const order = LANGS.map(l => l[0]);
      const after = Array.from(this.cards.children).find(c => order.indexOf(c.dataset.lang) > order.indexOf(code));
      this.cards.insertBefore(card, after || null);

      // Removing an existing card and adding it back must not delete it.
      this.root.querySelectorAll(`input[name="t_delete[]"][value="${code}"]`).forEach(d => d.remove());
      return card;
    }

    refreshAddButtons() {
      this.root.querySelectorAll('.ai-add-lang').forEach(b => {
        b.hidden = !!this.cards.querySelector(`.ai-card[data-lang="${b.dataset.lang}"]`);
      });
      this.root.querySelector('.ai-add').hidden =
        Array.from(this.root.querySelectorAll('.ai-add-lang')).every(b => b.hidden);
    }
  }

  window.ExhibitAI = {
    mount(root) {
      if (!root || root.dataset.mounted) return null;
      root.dataset.mounted = '1';
      return new Section(root);
    },
    mountAll(scope) {
      (scope || document).querySelectorAll('[data-ai-root]').forEach(r => this.mount(r));
    },
  };

  document.addEventListener('DOMContentLoaded', () => window.ExhibitAI.mountAll());
})();
