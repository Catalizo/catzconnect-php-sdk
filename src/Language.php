<?php

namespace CatzConnect;

/**
 * The optional top-level 'language' of send(): the language of the built-in
 * (default) Otp/Verification templates.
 */
final class Language
{
    public const SUPPORTED = ['en', 'de', 'ja', 'ar', 'hi'];

    /**
     * 'de', 'DE', 'de-DE' and 'de_DE' all become 'de' (the BCP-47 primary
     * subtag). Throws \InvalidArgumentException for anything else.
     */
    public static function normalise($language): string
    {
        if (!is_string($language)) {
            throw new \InvalidArgumentException("'language' must be a string: one of en, de, ja, ar, hi (or a BCP-47 tag such as de-DE)");
        }
        $tag = trim($language);
        $primary = strtolower(preg_split('/[-_]/', $tag)[0]);
        if (!preg_match('/^[A-Za-z0-9]+(?:[-_][A-Za-z0-9]+)*$/', $tag) || !in_array($primary, self::SUPPORTED, true)) {
            throw new \InvalidArgumentException(
                "Unsupported 'language' \"$language\": use one of en, de, ja, ar, hi (or a BCP-47 tag such as de-DE)"
            );
        }
        return $primary;
    }

    public static function isValid($language): bool
    {
        try {
            self::normalise($language);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }
}
