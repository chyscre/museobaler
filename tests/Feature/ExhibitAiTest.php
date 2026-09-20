<?php

namespace Tests\Feature;

use App\Models\Exhibit;
use App\Models\Staff;
use App\Services\ExhibitQr;
use App\Services\Gemini;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Adding an exhibit with the AI doing the legwork.
 *
 * Staff write the label once; the form drafts the other languages and the
 * narration, and the admin reads, corrects and listens before Save. The QR
 * for the display case is written the moment the exhibit exists.
 *
 * Gemini is faked at the HTTP layer so the whole path is exercised - the
 * prompt going out, the JSON and PCM coming back, the draft file, the save -
 * without a key or a network.
 */
class ExhibitAiTest extends TestCase
{
    use RefreshDatabase;

    private const LAPTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    /** Files this test writes under public/, removed afterwards. */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.key' => 'test-key']);
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $f) {
            if (is_file($f)) @unlink($f);
        }
        parent::tearDown();
    }

    private function staff()
    {
        return $this->withHeader('User-Agent', self::LAPTOP)
            ->actingAs(Staff::factory()->administrator()->create());
    }

    private function fakeGemini(): void
    {
        Http::fake([
            '*gemini-3.6-flash:generateContent' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['translations' => [
                    ['language_code' => 'fil', 'title' => 'Simbahan ng Baler', 'description' => 'Ang simbahan.', 'fun_facts' => "Isa\nDalawa"],
                    ['language_code' => 'es',  'title' => 'Iglesia de Baler',  'description' => 'La iglesia.',  'fun_facts' => "Uno\nDos"],
                ]])]]]]],
            ]),
            '*gemini-3.1-flash-tts-preview:generateContent' => Http::response([
                'candidates' => [['content' => ['parts' => [['inlineData' => [
                    'mimeType' => 'audio/L16;codec=pcm;rate=24000',
                    'data'     => base64_encode(str_repeat("\x00\x01", 2400)),
                ]]]]]],
            ]),
        ]);
    }

    // -- Translation -------------------------------------------------------

    public function test_the_ai_drafts_every_other_visitor_language_from_the_label(): void
    {
        $this->fakeGemini();

        $res = $this->staff()->postJson('/exhibits/ai/translate', [
            'title' => 'Baler Church', 'description' => 'The church.', 'fun_facts' => "One\nTwo", 'from' => 'en',
        ]);

        $res->assertOk()->assertJsonCount(2, 'translations');
        $res->assertJsonPath('translations.0.language_code', 'fil');
        $res->assertJsonPath('translations.0.language_label', 'Filipino');
        $res->assertJsonPath('translations.0.title', 'Simbahan ng Baler');
        $res->assertJsonPath('translations.1.language_code', 'es');

        Http::assertSent(function ($req) {
            return str_contains($req->url(), 'gemini-3.6-flash:generateContent')
                && $req->hasHeader('x-goog-api-key', 'test-key')
                && str_contains($req['contents'][0]['parts'][0]['text'], 'Baler Church')
                && $req['generationConfig']['responseMimeType'] === 'application/json';
        });
    }

    public function test_translating_needs_the_description_first(): void
    {
        $this->fakeGemini();

        $this->staff()->postJson('/exhibits/ai/translate', ['title' => 'X', 'description' => '', 'from' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('errors.description.0', 'Write the description first - that is what gets translated.');

        Http::assertNothingSent();
    }

    public function test_without_a_key_the_button_explains_what_is_missing(): void
    {
        config(['services.gemini.key' => null]);
        Http::fake();

        $this->staff()->postJson('/exhibits/ai/translate', ['title' => 'X', 'description' => 'Y', 'from' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'AI generation is not set up: add GEMINI_API_KEY to the .env file (a Google AI Studio key).');
    }

    public function test_a_refusal_from_gemini_is_passed_on_in_plain_words(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'API key not valid']], 400)]);

        $this->staff()->postJson('/exhibits/ai/translate', ['title' => 'X', 'description' => 'Y', 'from' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Gemini refused the request: API key not valid');
    }

    // -- Narration ---------------------------------------------------------

    public function test_narration_comes_back_as_a_playable_draft(): void
    {
        $this->fakeGemini();

        $res = $this->staff()->postJson('/exhibits/ai/narrate', ['language' => 'fil', 'text' => 'Ang simbahan.']);
        $res->assertOk();

        $token = $res->json('draft');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $token);
        $this->written[] = $file = public_path("audio/drafts/$token.wav");

        $this->assertFileExists($file);
        // A real WAV: RIFF header, 16-bit mono at the rate Gemini reported.
        $head = file_get_contents($file, false, null, 0, 44);
        $this->assertSame('RIFF', substr($head, 0, 4));
        $this->assertSame('WAVE', substr($head, 8, 4));
        $this->assertSame(24000, unpack('V', substr($head, 24, 4))[1]);
        $this->assertStringContainsString("audio/drafts/$token.wav", $res->json('url'));

        Http::assertSent(fn ($req) => str_contains($req->url(), 'flash-tts-preview')
            && $req['generationConfig']['responseModalities'] === ['AUDIO']
            && str_contains($req['contents'][0]['parts'][0]['text'], 'Filipino'));
    }

    public function test_the_wav_wrapper_is_right(): void
    {
        $wav = Gemini::wav(str_repeat("\x00", 100), 24000);

        $this->assertSame(144, strlen($wav));
        $this->assertSame(136, unpack('V', substr($wav, 4, 4))[1]);   // RIFF size = 36 + data
        $this->assertSame(1, unpack('v', substr($wav, 22, 2))[1]);    // channels
        $this->assertSame(16, unpack('v', substr($wav, 34, 2))[1]);   // bits
        $this->assertSame(100, unpack('V', substr($wav, 40, 4))[1]);  // data size
    }

    // -- Saving the reviewed drafts ----------------------------------------

    public function test_saving_the_exhibit_keeps_the_corrected_text_and_moves_the_narration_next_to_it(): void
    {
        $this->fakeGemini();

        $token = $this->staff()->postJson('/exhibits/ai/narrate', ['language' => 'fil', 'text' => 'x'])->json('draft');
        $draft = public_path("audio/drafts/$token.wav");
        $this->assertFileExists($draft);

        $this->staff()->from('/exhibits')->post('/exhibits', [
            '_form' => 'add_exhibit', 'exhibit_code' => 'EXH-010', 'name' => 'Baler Church',
            'description' => 'The church.',
            'languages' => 'Filipino,English', 'storyline_order' => 10, 'source_language' => 'en',
            // The admin corrected the Filipino title before saving.
            't_code'        => ['en', 'fil'],
            't_label'       => ['English', 'Filipino'],
            't_title'       => ['Baler Church', 'Simbahan ng Baler (corrected)'],
            't_desc'        => ['The church.', 'Ang simbahan.'],
            't_facts'       => ['', "Isa\nDalawa"],
            't_audio_draft' => ['', $token],
        ])->assertRedirect('/exhibits')->assertSessionHas('success');

        $exhibit = Exhibit::where('exhibit_code', 'EXH-010')->firstOrFail();
        $this->written[] = public_path('images/qr/' . $exhibit->qr_file);

        $this->assertSame('en', $exhibit->source_language);
        $this->assertCount(2, $exhibit->translations);

        $fil = $exhibit->translations->firstWhere('language_code', 'fil');
        $this->assertSame('Simbahan ng Baler (corrected)', $fil->title);
        $this->assertSame("Isa\nDalawa", $fil->fun_facts);

        // The draft became the real file, named like every other audio guide.
        $this->assertMatchesRegularExpression('/^exhibit_\d+_fil_\d+\.wav$/', $fil->audio_file);
        $this->written[] = $final = public_path('audio/' . $fil->audio_file);
        $this->assertFileExists($final);
        $this->assertFileDoesNotExist($draft);
        $this->assertStringContainsString('/exhibit-audio/', $fil->audio_url);

        $en = $exhibit->translations->firstWhere('language_code', 'en');
        $this->assertNull($en->audio_file);
    }

    public function test_editing_updates_cards_in_place_and_removes_the_ones_taken_away(): void
    {
        $exhibit = Exhibit::create(['exhibit_code' => 'EXH-011', 'name' => 'Bay', 'description' => 'd']);
        $exhibit->translations()->create(['language_code' => 'fil', 'language_label' => 'Filipino', 'title' => 'Look']);
        $exhibit->translations()->create(['language_code' => 'es',  'language_label' => 'Spanish',  'title' => 'Bahía']);

        $this->staff()->from('/exhibits')->put("/exhibits/{$exhibit->exhibit_id}", [
            'exhibit_code' => 'EXH-011', 'name' => 'Bay',
            't_code' => ['fil'], 't_label' => ['Filipino'], 't_title' => ['Look ng Baler'], 't_desc' => [''], 't_facts' => [''],
            't_delete' => ['es'],
        ])->assertRedirect('/exhibits');

        $exhibit->refresh();
        $this->written[] = public_path('images/qr/' . $exhibit->qr_file);

        $this->assertSame(['fil'], $exhibit->translations->pluck('language_code')->all());
        $this->assertSame('Look ng Baler', $exhibit->translations->first()->title);
    }

    // -- QR ----------------------------------------------------------------

    public function test_the_qr_is_made_on_creation_and_remade_when_the_code_changes(): void
    {
        $this->staff()->from('/exhibits')->post('/exhibits', [
            '_form' => 'add_exhibit', 'exhibit_code' => 'EXH-012', 'name' => 'Agta',
            'languages' => 'Filipino,English',
        ])->assertRedirect('/exhibits');

        $exhibit = Exhibit::where('exhibit_code', 'EXH-012')->firstOrFail();
        $this->assertSame('EXH-012.svg', $exhibit->qr_file);
        $this->written[] = $first = public_path('images/qr/EXH-012.svg');
        $this->assertFileExists($first);
        $this->assertStringContainsString('<svg', file_get_contents($first));
        $this->assertSame(url('/visitor/index.php') . '?scan=EXH-012', ExhibitQr::scanUrl($exhibit));

        // Shown in the exhibit's own modal, and downloadable from there.
        $this->staff()->getJson("/exhibits/{$exhibit->exhibit_id}/modal")
            ->assertJsonPath('qr_url', route('exhibits.qr.single', $exhibit))
            ->assertJsonPath('scan_url', ExhibitQr::scanUrl($exhibit));
        $this->staff()->get("/qr-codes/{$exhibit->exhibit_id}")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->staff()->get("/qr-codes/{$exhibit->exhibit_id}?download=1")
            ->assertOk()->assertDownload('EXH-012.svg');

        $this->staff()->from('/exhibits')->put("/exhibits/{$exhibit->exhibit_id}", [
            'exhibit_code' => 'EXH-112', 'name' => 'Agta',
        ])->assertRedirect('/exhibits');

        $exhibit->refresh();
        $this->written[] = $second = public_path('images/qr/EXH-112.svg');
        $this->assertSame('EXH-112.svg', $exhibit->qr_file);
        $this->assertFileExists($second);
        $this->assertFileDoesNotExist($first);
        $this->assertStringContainsString('scan%3DEXH-112', rawurlencode(ExhibitQr::scanUrl($exhibit)));
    }

    public function test_the_tourism_office_cannot_use_the_ai(): void
    {
        Http::fake();

        $this->withHeader('User-Agent', self::LAPTOP)
            ->actingAs(Staff::factory()->tourismHead()->create())
            ->postJson('/exhibits/ai/translate', ['title' => 'X', 'description' => 'Y', 'from' => 'en'])
            ->assertForbidden();

        Http::assertNothingSent();
    }
}
