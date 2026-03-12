<?php

namespace VisitMarche\TheWo;

use VisitMarche\ThemeWp\Enums\CommonItemTypeEnum;
use VisitMarche\ThemeWp\Enums\LanguageEnum;
use VisitMarche\ThemeWp\Inc\CategoryMetaData;
use VisitMarche\ThemeWp\Lib\LocaleHelper;
use VisitMarche\ThemeWp\Lib\OpenAi;
use VisitMarche\ThemeWp\Lib\Twig;
use VisitMarche\ThemeWp\Repository\WpRepository;

get_header();

$cat_ID = get_queried_object_id();
$category = get_category($cat_ID);
$categoryName = single_cat_title('', false);

$returnName = null;
if ($category->parent > 0) {
    $returnName = get_category($category->parent)->name;
}

$wpRepository = new WpRepository();

try {
    $items = $wpRepository->findArticlesAndOffersByWpCategory($category->term_id, true);
} catch (\Exception $e) {
    $items = [];
}

$locale = LocaleHelper::getSelectedLanguage();
$translator = OpenAi::create();
$language = LanguageEnum::tryFrom($locale);
$excerpt = $category->description;

if ($locale !== 'fr' && ($language = LanguageEnum::tryFrom($locale))) {
    $categoryName = $translator->translate($categoryName, $language);
    $returnName = $returnName ? $translator->translate($returnName, $language) : null;
    if ($excerpt) {
        $excerpt = $translator->translate($category->description, $language);
    }
    foreach ($items as $item) {
        if ($item->type === CommonItemTypeEnum::POST) {
            $item->name = $translator->translate($item->name, $language);
        }
        if ($item->excerpt) {
            $item->excerpt = $translator->translate($item->excerpt, $language);
        }
        foreach ($item->tags as $tag) {
            $tag->name = $translator->translate($tag->name, $language);
        }
    }
}

try {
    $itemsJson = json_encode(array_map(fn($item) => $item->toArray(), $items), JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    $itemsJson = null;
}

$children = $wpRepository->getChildrenOfCategory($cat_ID);
foreach ($children as $child) {
    $child->name = $translator->translate($child->name, $language);
}
$image = CategoryMetaData::getImage($category);
$video = CategoryMetaData::getVideo($category);
$icon = CategoryMetaData::getIcon($category);
$color = CategoryMetaData::getColor($category);
if ($excerpt) {
  $excerpt=   make_clickable($excerpt);
}
Twig::renderPage(
    '@Visit/category.html.twig',
    [
        'name' => $categoryName,
        'image' => $image,
        'returnName' => $returnName,
        'returnUrl' => $returnName,
        'excerpt' => $excerpt,
        'icon' => $icon,
        'video' => $video,
        'color' => $color,
        'children' => $children,
        'filters' => $children,
        'offersJson' => $itemsJson,
        'parentCategoryId' => $category->cat_ID,
        'parentCategoryUrl' => get_category_link($category),
        'countArticles' => count($items),
    ]
);
get_footer();
