<?php

declare(strict_types=1);

/**
 * GPX file downloader proxy.
 *
 * Fetches a GPX document from the Pivot API by its codeCgt
 * and serves it with Content-Disposition: attachment to force download.
 */

require_once dirname(__DIR__, 3).'/wp-load.php';

use AcMarche\PivotAi\Enums\ContentLevel;
use VisitMarche\ThemeWp\Repository\PivotRepository;

$offerCode = $_GET['offer'] ?? '';
$gpxCode = $_GET['codecgt'] ?? '';

if ($offerCode === '' || $gpxCode === '' || !preg_match('/^[A-Z0-9-]+$/i', $offerCode) || !preg_match('/^[A-Z0-9-]+$/i', $gpxCode)) {
    http_response_code(400);
    exit('Invalid parameters.');
}

$pivotRepository = new PivotRepository();
$offer = $pivotRepository->loadOffer($offerCode, ContentLevel::Full);

if ($offer === null) {
    http_response_code(404);
    exit('Offer not found.');
}

$gpxDocument = null;
foreach ($offer->getGpxFiles() as $gpx) {
    if ($gpx->codeCgt === $gpxCode) {
        $gpxDocument = $gpx;
        break;
    }
}

if ($gpxDocument === null || $gpxDocument->url === null || $gpxDocument->url === '') {
    http_response_code(404);
    exit('GPX file not found.');
}

$content = @file_get_contents($gpxDocument->url);

if ($content === false) {
    http_response_code(502);
    exit('Failed to fetch GPX file.');
}

$filename = ($gpxDocument->name ?? $gpxCode).'.gpx';
$filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

header('Content-Type: application/gpx+xml');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Content-Length: '.strlen($content));
header('Cache-Control: no-cache, must-revalidate');

echo $content;
