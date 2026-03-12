<?php

declare(strict_types=1);

namespace VisitMarche\ThemeWp\Dto;

use AcMarche\PivotAi\Entity\Pivot\Offer;
use AcMarche\PivotAi\Enums\TypeOffreEnum;
use VisitMarche\ThemeWp\Enums\CommonItemTypeEnum;
use VisitMarche\ThemeWp\Inc\CategoryMetaData;
use VisitMarche\ThemeWp\Inc\RouterPivot;

class CommonItem
{
    public const string PLACEHOLDER_IMAGE = '/assets/images/placeholder.jpg';

    public ?string $url = null;
    public ?string $icon = null;

    /** @var Tag[] */
    public array $tags = [];

    public ?string $content = null;
    /**
     * @var array|null[]|string[]
     */
    public array $dates = [];
    /**
     * @var array{year: ?string, month: ?string, day: ?string}
     */
    public array $nextDateParts = [];
    public bool $hasMultipleDates = false;

    public function __construct(
        public string $id,
        public CommonItemTypeEnum $type,
        public string $name,
        public string $image,
        public ?string $excerpt = null,
    ) {
    }

    public static function createFromPost(\WP_Post $post): CommonItem
    {
        $item = new CommonItem(
            id: (string)$post->ID,
            type: CommonItemTypeEnum::POST,
            name: $post->post_title,
            image: self::getPostThumbnail($post->ID),
            excerpt: $post->post_excerpt
        );

        $categories = get_the_category($post->ID);
        foreach ($categories as $category) {
            $item->tags[] = Tag::createFromCategory($category);
        }

        $item->content = apply_filters('the_content', get_the_content(null, null, $post));
        $item->url = get_permalink($post);

        return $item;
    }

    public static function createFromOffer(Offer $offer): CommonItem
    {
        $image = $offer->getDefaultImage();

        $item = new CommonItem(
            id: $offer->codeCgt ?? '',
            type: CommonItemTypeEnum::OFFER,
            name: $offer->nom ?? '',
            image: $image?->url ?? get_template_directory_uri().self::PLACEHOLDER_IMAGE,
            excerpt: $offer->getShortDescription() ?? '',
        );

        $item->url = RouterPivot::getOfferUrl($offer->codeCgt);
        $item->content = $offer->getDescription();

        if ($offer->typeOffre->idTypeOffre === TypeOffreEnum::EVENT->value) {
            $item->dates = array_map(fn($d) => $d->startDate?->format('Y-m-d'), $offer->dates);
            $item->nextDateParts = $offer->getNextDateParts();
            $item->hasMultipleDates = $offer->hasMultipleDates();

            return $item;
        }

        $item->tags = self::populateTagsForOffer($offer);

        return $item;
    }

    /**
     * @param \WP_Term $category
     * @param string $content
     * @param array<int,Tag> $tags
     * @return CommonItem
     */
    public static function createFromCategory(\WP_Term $category, string $content = '', array $tags = []): CommonItem
    {
        $item = new CommonItem(
            id: (string)$category->term_id,
            type: CommonItemTypeEnum::CATEGORY,
            name: $category->name,
            image: CategoryMetaData::getImage($category),
            excerpt: $category->description
        );

        $item->url = get_category_link($category);
        $item->tags = $tags;
        $item->content = $content;

        return $item;
    }

    /**
     * @return array{id: string, type: string, name: string, image: string, icon: ?string, excerpt: ?string, content: ?string, url: ?string, tags: list<array{name: string, value: string, url: ?string}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'image' => $this->image,
            'icon' => $this->icon,
            'excerpt' => $this->excerpt !== null ? strip_tags($this->excerpt) : null,
            'content' => $this->content !== null ? strip_tags($this->content) : null,
            'url' => $this->url,
            'tags' => array_map(fn(Tag $tag) => ['name' => $tag->name, 'value' => $tag->value, 'url' => $tag->url],
                $this->tags),
        ];
    }

    public static function getPostThumbnail(int $id): string
    {
        if (has_post_thumbnail($id)) {
            $attachment_id = get_post_thumbnail_id($id);
            $images = wp_get_attachment_image_src($attachment_id, 'original');
            $post_thumbnail_url = $images[0];
        } else {
            $post_thumbnail_url = get_template_directory_uri().self::PLACEHOLDER_IMAGE;
        }

        return $post_thumbnail_url;
    }

    /**
     * @param Offer $offer
     * @return array<int,Tag>
     */
    public static function populateTagsForOffer(Offer $offer): array
    {
        $tags = [];

        if ($offer->typeOffre->idTypeOffre === TypeOffreEnum::EVENT->value) {
            foreach ($offer->eventCategories as $urn => $specification) {
                $label = $specification->getLabelByLang('fr');
                if ($label) {
                    $tags[] = Tag::createFromClassificationUrn($label, $urn);
                }
            }

            return $tags;
        }

        if ($offer->typeOffre->idTypeOffre === TypeOffreEnum::RESTAURANT->value) {
            foreach ($offer->culinarySpecialties as $urn => $specification) {
                $label = $specification->getLabelByLang('fr');
                if ($label) {
                    $tags[] = Tag::createFromClassificationUrn($label, $urn);
                }
            }

            return $tags;
        }

        if ($offer->typeOffre) {
            $label = $offer->typeOffre->getLabelByLang('fr');
            if ($label) {
                $tags[] = new Tag(name: $label, value: $offer->typeOffre->idTypeOffre);
            }
        }

        return $tags;
    }

    /**
     * @param \WP_Post $post
     * @return array<int,Tag>
     */
    public static function populateTagsForPost(\WP_Post $post): array
    {
        $tags = [];
        foreach (get_the_category($post->ID) as $category) {
            $tags[] = Tag::createFromCategory($category);
        }

        return $tags;
    }
}
