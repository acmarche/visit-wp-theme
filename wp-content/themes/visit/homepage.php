<?php

namespace VisitMarche\ThemeWp;

use VisitMarche\ThemeWp\Dto\CommonItem;
use VisitMarche\ThemeWp\Enums\LanguageEnum;
use VisitMarche\ThemeWp\Inc\Theme;
use VisitMarche\ThemeWp\Lib\LocaleHelper;
use VisitMarche\ThemeWp\Lib\OpenAi;
use VisitMarche\ThemeWp\Lib\Sort\SortLink;
use VisitMarche\ThemeWp\Lib\Twig;
use VisitMarche\ThemeWp\Repository\MenuRepository;
use VisitMarche\ThemeWp\Repository\PivotRepository;
use VisitMarche\ThemeWp\Repository\WpRepository;

get_header();

$wpRepository = new WpRepository();
$pivotRepository = new PivotRepository();
$menu = new MenuRepository();

$intro = $wpRepository->getIntro();
try {
    $ideas = $wpRepository->getIdeas();
} catch (\Exception $exception) {
    $ideas = [];
}

$locale = LocaleHelper::getSelectedLanguage();
$inspirationCat = get_category(Theme::CATEGORY_INSPIRATION);
$inspirations = [];

foreach ($wpRepository->findArticlesByCategory($inspirationCat->term_id) as $post) {
    $item = CommonItem::createFromPost($post);
    $inspirations[$item->id] = $item;
}

$urlAgenda = get_category_link(Theme::CATEGORIES_AGENDA);
$urlInspiration = get_category_link($inspirationCat);

$events = $pivotRepository->loadEvents(skip: true);
$events = array_slice($events, 0, 4);

$inspirations = array_slice($inspirations, 0, 4);
$icons = $menu->getIcons($locale);

if ($locale !== 'fr' && ($language = LanguageEnum::tryFrom($locale))) {
    $translator = OpenAi::create();
    $intro = $translator->translate($intro, $language);
    foreach ($ideas as $idea) {
        $idea->name = $translator->translate($idea->name, $language);
    }
    foreach ($events as $offer) {
        $offer->nom = $translator->translate($offer->nom, $language);
    }
    foreach ($inspirations as $inspiration) {
        $inspiration->name = $translator->translate($inspiration->name, $language);
    }
}

$imgs = [
    '01.jpg',
    '02.jpg',
    '03.jpg',
    '04.jpg',
    '05.jpg',
    '06.jpg',
    '07.jpg',
    '08.jpg',
];
$img = array_rand($imgs);
$bgImg = $imgs[$img];

$sortLink = false;
if (current_user_can('edit_post', 2)) {
    $sortLink = SortLink::linkSortArticles(2);
}

Twig::renderPage(
    '@Visit/homepage.html.twig',
    [
        'events' => $events,
        'inspirations' => $inspirations,
        'urlAgenda' => $urlAgenda,
        'urlInspiration' => $urlInspiration,
        'intro' => $intro,
        'icons' => $icons,
        'ideas' => $ideas,
        'bgimg' => $bgImg,
        'sortLink' => $sortLink,
    ]
);

get_footer();
