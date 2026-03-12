<?php

declare(strict_types=1);

namespace VisitMarche\ThemeWp\Enums;

enum CommonItemTypeEnum: string
{
    case POST = 'post';
    case OFFER = 'offer';
    case CATEGORY = 'category';
    case MENU_ITEM = 'menu_item';

    public function sortOrder(): int
    {
        return match ($this) {
            self::POST => 0,
            self::OFFER => 1,
            self::CATEGORY => 2,
            self::MENU_ITEM => 3,
        };
    }
}
