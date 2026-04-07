<?php

namespace VisitMarche\ThemeWp;

use AcMarche\PivotAi\Api\PivotClient;
use VisitMarche\ThemeWp\Inc\AssetsLoader;
use VisitMarche\ThemeWp\Lib\Di;
use VisitMarche\ThemeWp\Lib\Twig;
use VisitMarche\ThemeWp\Repository\WpRepository;

wp_enqueue_style('leaflet-css', AssetsLoader::leaflet_css);
wp_enqueue_script('leaflet-js', AssetsLoader::leaflet_js);
wp_enqueue_script(
    'leaflet-gpx',
    'https://cdn.jsdelivr.net/npm/leaflet-gpx@2.1.2/gpx.min.js',
    ['leaflet-js'],
);

get_header();

$categoryId = 11;
$wpRepository = new WpRepository();
$pivotClient = Di::getInstance()->get(PivotClient::class);
$offerResponse = $pivotClient->fetchOffersByCriteria();

$categoryIds = [$categoryId];
foreach ($wpRepository->getChildrenOfCategory($categoryId) as $child) {
    $categoryIds[] = $child->term_id;
}

$allCodesCgt = [];
foreach ($categoryIds as $catId) {
    $codesCgt = WpRepository::getMetaPivotCodesCgtOffers($catId);
    array_push($allCodesCgt, ...$codesCgt);
}
$allCodesCgt = array_unique($allCodesCgt);

$markers = [];
foreach ($offerResponse->getOffers() as $offer) {
    if (!in_array($offer->codeCgt, $allCodesCgt, true)) {
        continue;
    }

    $lat = $offer->latitude();
    $lng = $offer->longitude();
    if (!$lat || !$lng) {
        continue;
    }

    $gpxFiles = $offer->getGpxFiles();
    $gpxUrls = array_values(array_map(fn($gpx) => $gpx->url, $gpxFiles));

    $markers[] = [
        'codeCgt' => $offer->codeCgt,
        'name' => $offer->nom ?? '',
        'lat' => $lat,
        'lng' => $lng,
        'url' => Inc\RouterPivot::getOfferUrl($offer->codeCgt),
        'image' => $offer->getDefaultImage()?->url,
        'excerpt' => $offer->getShortDescription() ?? '',
        'gpx' => $gpxUrls,
        'distance' => $offer->distance,
    ];
}

Twig::renderPage(
    '@Visit/map/carte-balades.html.twig',
    [
        'markers' => $markers,
        'markersJson' => json_encode($markers, JSON_THROW_ON_ERROR),
    ]
);

get_footer();
