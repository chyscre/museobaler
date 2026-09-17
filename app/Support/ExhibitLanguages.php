<?php

namespace App\Support;

/**
 * The languages the visitor app can show an exhibit in.
 *
 * Mirrors the content-language picker in public/visitor/js/app.js — a
 * translation in any other language would be saved and never shown. The
 * AI generates one card per language here, so adding a language to the app
 * means adding it here as well.
 */
class ExhibitLanguages
{
    public const ALL = [
        'en'  => 'English',
        'fil' => 'Filipino',
        'es'  => 'Spanish',
    ];

    public static function codes(): array
    {
        return array_keys(self::ALL);
    }

    public static function label(string $code): string
    {
        return self::ALL[$code] ?? strtoupper($code);
    }

    public static function isSupported(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::ALL);
    }
}
