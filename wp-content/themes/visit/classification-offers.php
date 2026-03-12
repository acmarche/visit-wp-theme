<?php

namespace VisitMarche\ThemeWp;

use VisitMarche\ThemeWp\Dto\CommonItem;
use VisitMarche\ThemeWp\Inc\RouterPivot;
use VisitMarche\ThemeWp\Lib\Twig;
use VisitMarche\ThemeWp\Repository\PivotRepository;

get_header();

$urn = urldecode(get_query_var(RouterPivot::PARAM_CLASSIFICATION_URN));

if ($urn === '') {
    Twig::renderNotFoundPage('Classification introuvable.');
    get_footer();

    return;
}

$pivotRepository = new PivotRepository();

try {
    $offers = $pivotRepository->loadOffersByClassificationUrn($urn);
} catch (\Exception $e) {
    Twig::renderErrorPage($e);
    get_footer();

    return;
}

$label = null;
foreach ($offers as $offer) {
    foreach ($offer->getClassificationLabels() as $cl) {
        if ($cl->urn === $urn) {
            $label = $cl->label;
            break 2;
        }
    }
}

if ($label === null) {
    $label = $urn;
}

$items = [];
foreach ($offers as $offer) {
    $item = CommonItem::createFromOffer($offer);
    $item->url = RouterPivot::getOfferUrl($offer->codeCgt);
    $items[] = $item;
}

try {
    $offersJson = json_encode(array_map(fn($item) => $item->toArray(), $items), JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    $offersJson = null;
}

Twig::renderPage(
    '@Visit/category.html.twig',
    [
        'name' => $label,
        'image' => null,
        'returnName' => '',
        'returnUrl' => '',
        'excerpt' => null,
        'icon' => null,
        'video' => null,
        'color' => null,
        'children' => [],
        'filters' => [],
        'offersJson' => $offersJson,
        'parentCategoryId' => 0,
        'parentCategoryUrl' => '/',
        'countArticles' => count($offers),
    ]
);
get_footer();
