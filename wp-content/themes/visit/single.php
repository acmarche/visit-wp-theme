<?php

namespace VisitMarche\ThemeWp;

use VisitMarche\ThemeWp\Dto\CommonItem;
use VisitMarche\ThemeWp\Enums\LanguageEnum;
use VisitMarche\ThemeWp\Lib\LocaleHelper;
use VisitMarche\ThemeWp\Lib\OpenAi;
use VisitMarche\ThemeWp\Lib\Twig;
use VisitMarche\ThemeWp\Repository\PivotRepository;

get_header();

$post = get_post();

$image = null;
$image_srcset = null;
$image_sizes = null;
$locale = LocaleHelper::getSelectedLanguage();
$translator = OpenAi::create();

if (has_post_thumbnail()) {
    $attachment_id = get_post_thumbnail_id();
    $image = wp_get_attachment_image_url($attachment_id, 'hero-header');
    $image_srcset = wp_get_attachment_image_srcset($attachment_id, 'hero-header');
    $image_sizes = wp_get_attachment_image_sizes($attachment_id, 'hero-header');
}

$tags = CommonItem::populateTagsForPost($post);

if (!$currentCategory = get_category_by_slug(get_query_var('category_name'))) {
    $currentCategory = get_category(1);
}
$categoryName = $currentCategory->name;
$excerpt = $post->post_excerpt;

$content = get_the_content(null, null, $post);
$content = apply_filters('the_content', $content);
$content = str_replace(']]>', ']]&gt;', $content);

$pivotRepository = new PivotRepository();
$events = $pivotRepository->loadEvents(skip: true);
$events = array_slice($events, 0, 3);

if ($locale !== 'fr' && ($language = LanguageEnum::tryFrom($locale))) {
    foreach ($events as $offer) {
        $offer->nom = $translator->translate($offer->nom, $language);
    }
    foreach ($tags as $tag) {
        $tag->name = $translator->translate($tag->name, $language);
    }
    $categoryName = $translator->translate($categoryName, $language);
    $content = $translator->translate($content, $language);
    if ($excerpt) {
        $excerpt = $translator->translate($excerpt, $language);
    }
}

$returnUrl = get_category_link($currentCategory);

Twig::renderPage(
    '@Visit/article.html.twig',
    [
        'post' => $post,
        'name' => $post->post_title,
        'content' => $content,
        'returnName' => $categoryName,
        'returnUrl' => $returnUrl,
        'image' => $image,
        'thumbnail' => $image,
        'thumbnail_srcset' => $image_srcset,
        'thumbnail_sizes' => $image_sizes,
        'icon' => null,
        'excerpt' => $excerpt,
        'tags' => $tags,
        'events' => $events,
    ]
);
get_footer();
