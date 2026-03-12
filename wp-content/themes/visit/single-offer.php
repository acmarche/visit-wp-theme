<?php

namespace VisitMarche\ThemeWp;

use AcMarche\PivotAi\Enums\ContentLevel;
use AcMarche\PivotAi\Enums\TypeOffreEnum;
use VisitMarche\ThemeWp\Dto\CommonItem;
use VisitMarche\ThemeWp\Enums\LanguageEnum;
use VisitMarche\ThemeWp\Inc\RouterPivot;
use VisitMarche\ThemeWp\Lib\LocaleHelper;
use VisitMarche\ThemeWp\Lib\OpenAi;
use VisitMarche\ThemeWp\Lib\Twig;
use VisitMarche\ThemeWp\Repository\PivotRepository;

get_header();

$codeCgt = get_query_var(RouterPivot::PARAM_OFFRE);

if (!str_contains($codeCgt, "-")) {
    Twig::renderNotFoundPage('Offre introuvable.');
    get_footer();

    return;
}

$pivotRepository = new PivotRepository();

try {
    $offer = $pivotRepository->loadOffer($codeCgt, ContentLevel::Full);
} catch (\Exception $e) {
    Twig::renderErrorPage($e);
    get_footer();

    return;
}

if (!$offer) {
    Twig::renderNotFoundPage('Offre introuvable.');
    get_footer();

    return;
}

$locale = LocaleHelper::getSelectedLanguage();
$name = $offer->name();
$description = $offer->getDescription();

if (!$currentCategory = get_category_by_slug(get_query_var('category_name'))) {
    $currentCategory = get_category(1);
}

$returnName = $currentCategory->name;
$returnUrl = get_category_link($currentCategory);

$events = $pivotRepository->loadEvents(skip: true);
$events = array_slice($events, 0, 3);

$tags = CommonItem::populateTagsForOffer($offer);

if ($locale !== 'fr' && ($language = LanguageEnum::tryFrom($locale))) {
    $translator = OpenAi::create();
    if ($offer->typeOffre->idTypeOffre === TypeOffreEnum::EVENT->value) {
        $name = $translator->translate($name, $language);
    }
    if ($description) {
        $description = $translator->translate($description, $language);
    }
    foreach ($events as $event) {
        $event->nom = $translator->translate($event->nom, $language);
    }
    foreach ($tags as $tag) {
        $tag->name = $translator->translate($tag->name, $language);
    }
    $returnName = $translator->translate($returnName, $language);
}

$latitude = $offer->latitude();
$longitude = $offer->longitude();
if ($latitude && $longitude) {
    //AssetsLoader::enqueueLeaflet();
}

Twig::renderPage(
    '@Visit/offer.html.twig',
    [
        'offer' => $offer,
        'name' => $name,
        'description' => $description,
        'returnName' => $returnName,
        'returnUrl' => $returnUrl,
        'image' => $offer->getDefaultImage()->url ?? get_template_directory_uri().Dto\CommonItem::PLACEHOLDER_IMAGE,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'excerpt' => null,
        'tags' => $tags,
        'icon' => null,
        'events' => $events,
        'specs' => [],
        'locations' => [],
    ]
);
get_footer();
