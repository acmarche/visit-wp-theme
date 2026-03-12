<?php

declare(strict_types=1);

namespace VisitMarche\ThemeWp\Enums;

enum LanguageEnum: string
{
    case FRENCH = 'fr';
    case ENGLISH = 'en';
    case DUTCH = 'nl';
    case GERMAN = 'de';

    public function label(): string
    {
        return match ($this) {
            self::FRENCH => 'French',
            self::ENGLISH => 'English',
            self::DUTCH => 'Dutch',
            self::GERMAN => 'German',
        };
    }
}
