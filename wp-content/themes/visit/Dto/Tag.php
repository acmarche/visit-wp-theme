<?php

declare(strict_types=1);

namespace VisitMarche\ThemeWp\Dto;

use VisitMarche\ThemeWp\Inc\RouterPivot;

class Tag
{
    public function __construct(
        public string $name,
        readonly public string|int $value,
        readonly public ?string $url = null,
    ) {
    }

    /**
     * Create a tag linking to a WordPress category.
     */
    public static function createFromCategory(\WP_Term $category): self
    {
        return new self(
            name: $category->name,
            value: $category->term_id,
            url: get_category_link($category->term_id),
        );
    }

    /**
     * Create a tag linking to the classification-offers page.
     */
    public static function createFromClassificationUrn(string $name, string $urn): self
    {
        return new self(
            name: $name,
            value: $urn,
            url: RouterPivot::getUrlByUrn($urn),
        );
    }
}
