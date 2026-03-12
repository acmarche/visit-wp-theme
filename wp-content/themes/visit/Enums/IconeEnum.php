<?php

namespace VisitMarche\ThemeWp\Enums;

enum IconeEnum: string
{
    case ARTS = "arts";
    case BALADES = "balades";
    case FETES = "fetes";
    case GOURMANDISES = "gourmandises";
    case PATRIMOINE = "partrimoine";

    public static function iconeWhite(string $key): string
    {
        return match ($key) {
            IconeEnum::ARTS->value => 'icon_arts_white.svg',
            IconeEnum::BALADES->value => 'icon_walks_white.svg',
            IconeEnum::FETES->value => 'icon_parties_white.svg',
            IconeEnum::GOURMANDISES->value => 'icon_delicaties_white.svg',
            IconeEnum::PATRIMOINE->value => 'icon_patrimony_white.svg',
            default => 'icon_patrimony_white.svg'
        };
    }

    public static function bgColor(string $key): string
    {
        return match ($key) {
            IconeEnum::ARTS->value => 'bg-art',
            IconeEnum::BALADES->value => 'bg-walk',
            IconeEnum::FETES->value => 'bg-party',
            IconeEnum::GOURMANDISES->value => 'bg-delicacy',
            IconeEnum::PATRIMOINE->value => 'bg-patrimony ',
            default => 'bg-party'
        };
    }

    public static function icone(string $key): string
    {
        return match ($key) {
            IconeEnum::ARTS->value => 'statue.png',
            IconeEnum::BALADES->value => 'sacdos.png',
            IconeEnum::FETES->value => 'tambour.png',
            IconeEnum::GOURMANDISES->value => 'baiser.png',
            IconeEnum::PATRIMOINE->value => 'eglise.png',
            default => ''
        };
    }
}
/**
 * visit_category_header    bg_enjoy.png
 * visit_category_header    sejourner.jpg
 * visit_category_header    Arts.jpg
 * visit_category_header    balades.jpg
 * visit_category_header    gourmandises.jpg
 * visit_category_header    fêtes.jpg
 * visit_category_header    patrimoine.jpg
 * visit_category_icone    statue.png
 * visit_category_icone    sacdos.png
 * visit_category_icone    tambour.png
 * visit_category_icone    eglise.png
 * visit_category_icone    baiser.png
 * visit_category_color    bg-cat-del
 * visit_category_color    bg-cat-art
 * visit_category_color    bg-cat-wal
 * visit_category_color    bg-cat-par
 * visit_category_color    bg-cat-pat
 * visit_category_header    mice
 * visit_category_header    patrimoine.jpg
 * visit_category_icone    eglise.png
 * visit_category_color    bg-cat-pat
 * visit_category_header    patrimoine.jpg
 * visit_category_icone    eglise.png
 * visit_category_color    bg-cat-pat
 * visit_category_header    Arts.jpg
 * visit_category_icone    statue.png
 * visit_category_color    bg-cat-art
 * visit_category_header    Arts.jpg
 * visit_category_icone    statue.png
 * visit_category_color    bg-cat-art
 * visit_category_header    balades.jpg
 * visit_category_icone    sacdos.png
 * visit_category_color    bg-cat-wal
 * visit_category_header    balades.jpg
 * visit_category_icone    sacdos.png
 * visit_category_color    bg-cat-wal
 * visit_category_header    gourmandises.jpg
 * visit_category_icone    baiser.png
 * visit_category_color    bg-cat-del
 * visit_category_header    fêtes.jpg
 * visit_category_icone    tambour.png
 * visit_category_color    bg-cat-par
 * visit_category_header    fêtes.jpg
 * visit_category_icone    tambour.png
 * visit_category_color    bg-cat-par
 * visit_category_header    barbecue,pique_nique
 */