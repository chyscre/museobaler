<?php

namespace App\Models;

use App\Support\ArtaSurvey;
use Illuminate\Database\Eloquent\Model;

/**
 * One question on the visitor survey. See App\Support\ArtaSurvey for the
 * meaning of section, scale, options and show_if.
 */
class SurveyQuestion extends Model
{
    protected $primaryKey = 'question_id';

    protected $fillable = [
        'code', 'section', 'scale', 'text_fil', 'text_en', 'hint_fil', 'hint_en',
        'options', 'show_if', 'allow_na', 'default_na', 'required', 'locked',
        'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'options'    => 'array',
            'show_if'    => 'array',
            'allow_na'   => 'boolean',
            'default_na' => 'boolean',
            'required'   => 'boolean',
            'locked'     => 'boolean',
            'is_active'  => 'boolean',
        ];
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true)->orderBy('sort_order')->orderBy('question_id');
    }

    /**
     * The options a visitor picks from, in one shape for both scales, so the
     * app and the report never need to know which scale a question uses.
     *
     * @return array<int, array{value:int|null, fil:string, en:string, na:bool}>
     */
    public function choices(): array
    {
        if ($this->scale === 'choice') {
            return array_map(fn ($o) => [
                'value' => (int) $o['value'],
                'fil'   => $o['fil'] ?? '',
                'en'    => $o['en']  ?? '',
                'na'    => (bool) ($o['na'] ?? false),
            ], $this->options ?? []);
        }

        $out = [];
        foreach (ArtaSurvey::AGREE_LABELS as $v => $l) {
            $out[] = ['value' => $v, 'fil' => $l['fil'], 'en' => $l['en'], 'na' => false];
        }
        if ($this->allow_na) {
            $out[] = ['value' => null, 'fil' => ArtaSurvey::NA_LABEL['fil'], 'en' => ArtaSurvey::NA_LABEL['en'], 'na' => true];
        }
        return $out;
    }

    /**
     * Label for a stored answer value, in the given language.
     */
    public function labelFor(?int $value, string $lang = 'en'): string
    {
        foreach ($this->choices() as $c) {
            if ($c['value'] === $value || ($value === null && $c['na'])) {
                return $c[$lang] ?? $c['en'];
            }
        }
        return $value === null ? 'N/A' : (string) $value;
    }
}
