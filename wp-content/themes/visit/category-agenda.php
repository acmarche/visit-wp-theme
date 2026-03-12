<?php

namespace VisitMarche\TheWo;

use VisitMarche\ThemeWp\Dto\CommonItem;
use VisitMarche\ThemeWp\Enums\LanguageEnum;
use VisitMarche\ThemeWp\Inc\RouterPivot;
use VisitMarche\ThemeWp\Lib\LocaleHelper;
use VisitMarche\ThemeWp\Lib\OpenAi;
use VisitMarche\ThemeWp\Lib\Twig;
use VisitMarche\ThemeWp\Repository\PivotRepository;

get_header();

$cat_ID = get_queried_object_id();
$category = get_category($cat_ID);

$pivotRepository = new PivotRepository();
$events = $pivotRepository->loadEvents();

$locale = LocaleHelper::getSelectedLanguage();
$image = get_template_directory_uri().'/assets/tartine/bg_events.png';
$filters = [];
foreach ($events as $event) {
    foreach ($event->eventCategories as $urn => $specification) {
        if (!isset($filters[$urn])) {
            $filters[$urn] = [
                'urn' => $urn,
                'name' => $specification->getLabelByLang($locale) ?? $urn,
                'url' => RouterPivot::getUrlByUrn($urn),
            ];
        }
    }
}
$filters = array_values($filters);

$eventsData = [];
foreach ($events as $event) {
    $eventsData[] = CommonItem::createFromOffer($event);
}

$categoryName = $category->name;
$translator = OpenAi::create();
if ($locale !== 'fr' && ($language = LanguageEnum::tryFrom($locale))) {
    $categoryName = $translator->translate($category->name, $language);
    foreach ($eventsData as $offer) {
        $offer->name = $translator->translate($offer->name, $language);
        if ($offer->excerpt) {
            $offer->excerpt = $translator->translate($offer->excerpt, $language);
        }
    }

    foreach ($filters as $filter) {
        $filter['name'] = $translator->translate($filter['name'], $language);
    }
}

Twig::renderPage(
    '@Visit/agenda.html.twig',
    [
        'events' => $events,
        'eventsJson' => json_encode($eventsData, JSON_THROW_ON_ERROR),
        'name' => $categoryName,
        'nameBack' => '',
        'image' => $image,
        'filters' => $filters,
        'parentCategoryUrl' => get_category_link($category),
        'icon' => null,
    ]
);
get_footer();
