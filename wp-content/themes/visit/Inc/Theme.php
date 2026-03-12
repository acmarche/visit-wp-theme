<?php

namespace VisitMarche\ThemeWp\Inc;

class Theme
{
    public const PAGE_INTRO = 115;
    public const PAGE_DECOUVRIR = 828;
    public const CATEGORY_ARTS = 10;
    public const CATEGORY_BALADES = 11;
    public const CATEGORY_BIKE = 130;
    public const CATEGORY_FOOT = 131;
    public const CATEGORY_HIKES = 132;
    public const CATEGORY_FETES = 12;
    public const CATEGORY_GOURMANDISES = 13;
    public const CATEGORY_PATRIMOINES = 9;
    public const CATEGORIES_AGENDA = 8;
    public const CATEGORIES_HEBERGEMENT = 6;
    public const CATEGORIES_RESTAURATION = 5;
    public const CATEGORY_INSPIRATION = 2;
    public const CATEGORY_NOT_CATEGORIZED = 1;

    public static function isHomePage(): bool
    {
        global $wp;

        return '' === $wp->request;
    }
}
