<?php

namespace VisitMarche\ThemeWp\Inc;

use VisitMarche\ThemeWp\Lib\LocaleHelper;
use VisitMarche\ThemeWp\Repository\PivotRepository;
use WP_Post;

class Seo
{
    private static array $metas = [
        'title' => '',
        'keywords' => '',
        'description' => '',
    ];

    public function __construct()
    {
        add_action('wp_head', function (): void {
            $this::assignMetaInfo();
        });
    }

    public static function assignMetaInfo(bool $render = true): array
    {
        if (Theme::isHomePage()) {
            self::metaHomePage();
            if ($render) {
                self::renderMetas();
            }

            return self::$metas;
        }

        $codeCgt = get_query_var(RouterPivot::PARAM_OFFRE);
        if ($codeCgt) {
            self::metaPivotOffer($codeCgt);
            if ($render) {
                self::renderMetas();
            }
        }

        global $post;
        if ($post) {
            self::metaPost($post);
            if ($render) {
                self::renderMetas();
            }

            return self::$metas;
        }

        $cat_id = get_query_var('cat');
        if ($cat_id) {
            self::metaCategory($cat_id);
            if ($render) {
                self::renderMetas();
            }

            return self::$metas;
        }
        if ($render) {
            self::renderMetas();
        }

        return self::$metas;
    }

    public function isGoole(): void
    {
        global $is_lynx;
    }

    public static function baseTitle(string $begin): string
    {
        $base = wp_title('|', false, 'right');

        $nameSousSite = get_bloginfo('name', 'display');

        $tourisme = self::translate('page.tourisme');

        return $begin.' '.$tourisme.' '.$base.' '.$nameSousSite;
    }

    private static function renderMetas(): void
    {
        self::$metas['title'] = self::cleanString(self::$metas['title']);
        echo '<title>'.self::$metas['title'].'</title>';

        if ('' !== self::$metas['description']) {
            self::$metas['description'] = self::cleanString(self::$metas['description']);
            echo '<meta name="description" content="'.self::$metas['description'].'" />';
        }

        if ('' !== self::$metas['keywords']) {
            echo '<meta name="keywords" content="'.self::$metas['keywords'].'" />';
        }
    }

    private static function metaPivotOffer(string $codeCgt): void
    {
        $language = 'fr';
        $pivotRepository = new PivotRepository();
        try {
            $offer = $pivotRepository->loadOffer($codeCgt);
        } catch (\Exception $exception) {
            $base = self::baseTitle('');
            self::$metas['title'] = "Error 500 ".$base;

            return;
        }

        if (null !== $offer) {
            $base = self::baseTitle('');
            $label = $offer->typeOffre->getLabelByLang($language);
            self::$metas['title'] = $offer->nom.' '.$label.' '.$base;
            $description = wp_strip_all_tags($offer->description?->get('fr'));
            if ($description) {
                self::$metas['description'] = self::cleanString($description);
            }
            $keywords = array_map(
                fn($tag) => $tag->name(),
                $offer->getClassificationLabels(),
            );
            self::$metas['keywords'] = implode(',', $keywords);
            self::$metas['image'] = $offer->getDefaultImage();
            self::$metas['updated_time'] = $offer->dateModification;
            self::$metas['published_time'] = $offer->dateCreation;
            self::$metas['modified_time'] = $offer->dateModification;
        }
    }

    private static function metaHomePage(): void
    {
        $home = self::translate('homepage.name');
        self::$metas['title'] = self::baseTitle($home);
        self::$metas['description'] = get_bloginfo('description', 'display');
        self::$metas['keywords'] = 'Commune, Ville, Marche, Marche-en-Famenne, Famenne, Tourisme, Horeca, Visit';
        self::$metas['image'] = get_template_directory_uri().'/assets/tartine/patrimoine.jpg';
    }

    private static function metaCategory(int $cat_id): void
    {
        $category = get_category($cat_id);
        self::$metas['title'] = self::baseTitle('');
        self::$metas['description'] = self::cleanString($category->description);
        self::$metas['keywords'] = '';

        if ($category instanceof \WP_Term) {
            $image = CategoryMetaData::getImage($category);
            self::$metas['image'] = $image;
        }
    }

    private static function metaPost(WP_Post $post): void
    {
        self::$metas['title'] = self::baseTitle('');
        self::$metas['description'] = $post->post_excerpt;
        $tags = get_the_category($post->ID);
        self::$metas['keywords'] = implode(
            ',',
            array_map(
                fn($tag) => $tag->name,
                $tags,
            ),
        );
        $attachment_id = get_post_thumbnail_id();
        $image = wp_get_attachment_image_url($attachment_id, 'full');

        self::$metas['image'] = $image;
    }

    private static function cleanString(string $description): ?string
    {
        $description = trim(strip_tags($description));

        return preg_replace('#"#', '', $description);
    }

    private static function translate(string $text): string
    {
        return LocaleHelper::translate($text);
    }
}
