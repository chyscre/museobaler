<?php

namespace App\Services;

use App\Support\ExhibitLanguages;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Gemini, for the two jobs the exhibit form hands to an AI: turning
 * the label staff wrote into the other visitor languages, and reading each
 * one aloud for the audio guide.
 *
 * Everything that comes back is a draft. The admin reads it, corrects it and
 * listens to it in the form before anything is saved - nothing here writes
 * to the database.
 */
class Gemini
{
    public function __construct(
        private readonly ?string $key,
        private readonly string $textModel,
        private readonly string $ttsModel,
        private readonly string $voice,
        private readonly string $endpoint,
    ) {}

    public static function fromConfig(): self
    {
        $c = config('services.gemini');

        return new self($c['key'] ?: null, $c['text_model'], $c['tts_model'], $c['tts_voice'], rtrim($c['endpoint'], '/'));
    }

    public function isConfigured(): bool
    {
        return !empty($this->key);
    }

    /**
     * Translate one exhibit's label into the given languages.
     *
     * @param  array{title:string,description:string,fun_facts:string} $source
     * @param  string[] $targets  language codes from ExhibitLanguages
     * @return array<string, array{title:string,description:string,fun_facts:string}> keyed by language code
     */
    public function translate(array $source, string $from, array $targets): array
    {
        $this->requireKey();

        $targets = array_values(array_filter($targets, fn ($t) => $t !== $from && ExhibitLanguages::isSupported($t)));
        if (!$targets) {
            return [];
        }

        $targetNames = implode(', ', array_map(
            fn ($t) => ExhibitLanguages::label($t) . " ($t)",
            $targets
        ));

        $prompt = <<<PROMPT
        You translate exhibit labels for Museo de Baler, the municipal museum of Baler, Aurora, Philippines.

        Translate the exhibit below from {$this->langName($from)} into each of: {$targetNames}.

        Rules:
        - Keep proper nouns, place names, personal names, dates and numbers exactly as written.
        - Keep the register of a museum label: clear, warm, plain. Do not add or drop information.
        - "fun_facts" is one fact per line. Keep the same number of lines, in the same order, one translated fact per line. If it is empty, return an empty string.
        - Return one entry per requested language, using the language code given.

        TITLE:
        {$source['title']}

        DESCRIPTION:
        {$source['description']}

        FUN_FACTS:
        {$source['fun_facts']}
        PROMPT;

        $schema = [
            'type'       => 'object',
            'properties' => [
                'translations' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'language_code' => ['type' => 'string'],
                            'title'         => ['type' => 'string'],
                            'description'   => ['type' => 'string'],
                            'fun_facts'     => ['type' => 'string'],
                        ],
                        'required' => ['language_code', 'title', 'description', 'fun_facts'],
                    ],
                ],
            ],
            'required' => ['translations'],
        ];

        $res = $this->post($this->textModel, [
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature'      => 0.2,
                'responseMimeType' => 'application/json',
                'responseSchema'   => $schema,
            ],
        ], 60);

        $text = $res->json('candidates.0.content.parts.0.text');
        $data = is_string($text) ? json_decode($text, true) : null;
        if (!is_array($data) || !isset($data['translations'])) {
            throw new RuntimeException('The translation service returned something unexpected. Try again.');
        }

        $out = [];
        foreach ($data['translations'] as $t) {
            $code = strtolower(trim((string) ($t['language_code'] ?? '')));
            if (!in_array($code, $targets, true)) {
                continue;
            }
            $out[$code] = [
                'title'       => trim((string) ($t['title'] ?? '')),
                'description' => trim((string) ($t['description'] ?? '')),
                'fun_facts'   => trim((string) ($t['fun_facts'] ?? '')),
            ];
        }

        $missing = array_diff($targets, array_keys($out));
        if ($missing) {
            throw new RuntimeException('No translation came back for: ' . implode(', ', array_map([ExhibitLanguages::class, 'label'], $missing)) . '. Try again.');
        }

        return $out;
    }

    /**
     * Read a text aloud in the given language. Returns WAV bytes.
     *
     * Gemini's TTS models hand back raw 16-bit PCM; the header is added
     * here so the file plays in an <audio> tag and on the visitor's phone.
     */
    public function narrate(string $text, string $language): string
    {
        $this->requireKey();

        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('There is nothing to narrate yet - write the description first.');
        }

        // The leading instruction steers delivery; the models treat it as
        // direction rather than reading it out.
        $script = 'Read the following museum audio guide in ' . $this->langName($language)
            . ', as a warm, clear, unhurried narrator:' . "\n\n" . $text;

        $res = $this->post($this->ttsModel, [
            'contents'         => [['parts' => [['text' => $script]]]],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig'       => [
                    'voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $this->voice]],
                ],
            ],
        ], 120);

        $part = $res->json('candidates.0.content.parts.0.inlineData');
        if (!is_array($part) || empty($part['data'])) {
            throw new RuntimeException('The narration service returned no audio. Try again.');
        }

        $pcm = base64_decode($part['data'], true);
        if ($pcm === false || $pcm === '') {
            throw new RuntimeException('The narration service returned unreadable audio. Try again.');
        }

        $rate = 24000;
        if (preg_match('/rate=(\d+)/', (string) ($part['mimeType'] ?? ''), $m)) {
            $rate = (int) $m[1];
        }

        return self::wav($pcm, $rate);
    }

    /** Wrap 16-bit mono PCM in a RIFF/WAVE header. */
    public static function wav(string $pcm, int $sampleRate, int $channels = 1, int $bits = 16): string
    {
        $byteRate   = $sampleRate * $channels * $bits / 8;
        $blockAlign = $channels * $bits / 8;
        $dataLen    = strlen($pcm);

        return 'RIFF' . pack('V', 36 + $dataLen) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, $channels, $sampleRate, (int) $byteRate, (int) $blockAlign, $bits)
            . 'data' . pack('V', $dataLen) . $pcm;
    }

    private function post(string $model, array $body, int $timeout): Response
    {
        $res = Http::timeout($timeout)
            ->withHeaders(['x-goog-api-key' => $this->key])
            ->acceptJson()
            ->post("{$this->endpoint}/models/{$model}:generateContent", $body);

        if ($res->failed()) {
            $msg = $res->json('error.message') ?: ('HTTP ' . $res->status());
            throw new RuntimeException('Gemini refused the request: ' . $msg);
        }

        if ($res->json('promptFeedback.blockReason')) {
            throw new RuntimeException('Gemini declined this text (' . $res->json('promptFeedback.blockReason') . ').');
        }

        return $res;
    }

    private function requireKey(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('AI generation is not set up: add GEMINI_API_KEY to the .env file (a Google AI Studio key).');
        }
    }

    private function langName(string $code): string
    {
        return ExhibitLanguages::label($code);
    }
}
