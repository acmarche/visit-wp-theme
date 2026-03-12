<?php

namespace VisitMarche\ThemeWp;

use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use VisitMarche\ThemeWp\Lib\LocaleHelper;
use VisitMarche\ThemeWp\Lib\Search\MeiliSearch;
use VisitMarche\ThemeWp\Lib\Twig;

get_header();

$searcher = new MeiliSearch();
$searcher->initClientAndIndex();

$keyword = get_search_query();
$locale = LocaleHelper::getSelectedLanguage();

try {
    $searching = $searcher->doSearch($keyword, locale: $locale);
    $hits = $searching->getHits();
    $count = $searching->count();

    if ($locale !== 'fr' && in_array($locale, ['en', 'nl', 'de'])) {
        foreach ($hits as &$hit) {
            $nameField = 'name_' . $locale;
            $excerptField = 'excerpt_' . $locale;
            if (!empty($hit[$nameField])) {
                $hit['name'] = $hit[$nameField];
            }
            if (!empty($hit[$excerptField])) {
                $hit['excerpt'] = $hit[$excerptField];
            }
        }
        unset($hit);
    }
} catch (\Exception $e) {
    Twig::renderErrorPage($e);

    get_footer();

    return;
}

$twig = Twig::loadTwig();
$thumbnail = get_template_directory_uri().'/assets/images/bg-search.jpeg';
$paths = [];

try {
    echo $twig->render('@Visit/search.html.twig', [
        'hits' => $hits,
        'name' => 'Rechercher',
        'count' => $count,
        'keyword' => $keyword,
        'returnUrl' => null,
        'returnName' => null,
        'image' => $thumbnail,
        'thumbnail' => $thumbnail,
        'thumbnail_srcset' => null,
        'thumbnail_sizes' => null,
        'paths' => $paths,
        'title' => 'Rechercher',
    ]);
} catch (LoaderError|RuntimeError|SyntaxError $e) {
    Twig::renderErrorPage($e);
}

get_footer();
