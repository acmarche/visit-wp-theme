<?php

namespace VisitMarche\ThemeWp\Inc;

use VisitMarche\ThemeWp\Dto\CommonItem;
use VisitMarche\ThemeWp\Repository\WpRepository;
use WP;

/**
 * Ajouts des routes pour les offres
 * https://roots.io/routing-wp-requests/
 * https://developer.wordpress.org/reference/functions/add_rewrite_rule/#user-contributed-notes
 * Class Router.
 */
class RouterPivot
{
    public const PARAM_OFFRE = 'codeoffre';
    public const OFFRE_URL = 'offre';
    public const PARAM_FILTRE = 'filtre';
    public const PARAM_FILTRE_TYPE = 'filtretype';
    public const PARAM_CLASSIFICATION_URN = 'classification_urn';

    public function __construct()
    {
        $this->addOfferRoute();
        $this->addClassificationRoute();
        //flush_rewrite_rules();
    }

    public static function getUrlSite(): string
    {
        return LanguageRouter::prefixUrl(home_url());
    }

    /**
     * For sharing button
     * @return string
     */
    public static function getCurrentUrl(): string
    {
        /* @var Wp $wp */
        global $wp;

        return LanguageRouter::prefixUrl(home_url($wp->request));
    }

    public static function getOfferUrl(string $codeCgt, int $categoryId = 0): string
    {
        if ($categoryId == 0) {
            $categoryId = WpRepository::findCategoryIdByCodeCgt($codeCgt);
        }

        return get_category_link(get_category($categoryId)).self::OFFRE_URL.'/'.$codeCgt;
    }

    public static function getRouteOfferToPivotSite(string $codeCgt): string
    {
        return $_ENV['PIVOT_GEST_URI'].'/detail.xhtml?codeCgt='.$codeCgt.'&state=EDIT';
    }

    public static function getUrlByUrn(string $urn): string
    {
        return LanguageRouter::url('classification/'.rawurlencode($urn));
    }

    public function addClassificationRoute(): void
    {
        add_action(
            'init',
            function () {
                add_rewrite_rule(
                    '^classification/([^/]+)[/]?$',
                    'index.php?'.self::PARAM_CLASSIFICATION_URN.'=$matches[1]',
                    'top'
                );
            }
        );

        add_filter(
            'query_vars',
            function ($query_vars) {
                $query_vars[] = self::PARAM_CLASSIFICATION_URN;

                return $query_vars;
            }
        );

        add_action(
            'template_include',
            function ($template) {
                global $wp_query;
                if (is_admin() || !$wp_query->is_main_query()) {
                    return $template;
                }
                if (false === get_query_var(self::PARAM_CLASSIFICATION_URN) ||
                    '' === get_query_var(self::PARAM_CLASSIFICATION_URN)) {
                    return $template;
                }

                return get_template_directory().'/classification-offers.php';
            }
        );
    }

    public function addOfferRoute(): void
    {
        //Setup a rule
        add_action(
            'init',
            function () {
                $taxonomy = get_taxonomy('category');
                $categoryBase = $taxonomy->rewrite['slug'];
                //^= depart, $ fin string, + one or more, * zero or more, ? zero or one, () capture
                // [^/]* => veut dire tout sauf /
                //https://regex101.com/r/pnR7x3/1
                //https://stackoverflow.com/questions/67060063/im-trying-to-capture-data-in-a-web-url-with-regex
                add_rewrite_rule(
                    '^'.$categoryBase.'/(?:([a-zA-Z0-9_-]+)/){1,3}offre/([a-zA-Z0-9-]+)[/]?$',
                    //'^'.$categoryBase.'/(?:([a-zA-Z0-9_-]+)/){1,3}offre/(\d+)/?$',
                    'index.php?category_name=$matches[1]&'.self::PARAM_OFFRE.'=$matches[2]',
                    'top'
                );
            }
        );
        //Whitelist the query param
        add_filter(
            'query_vars',
            function ($query_vars) {
                $query_vars[] = self::PARAM_OFFRE;

                return $query_vars;
            }
        );
        //Add a handler to send it off to a template file
        add_action(
            'template_include',
            function ($template) {
                global $wp_query;
                if (is_admin() || !$wp_query->is_main_query()) {
                    return $template;
                }
                if (false === get_query_var(self::PARAM_OFFRE) ||
                    '' === get_query_var(self::PARAM_OFFRE)) {
                    return $template;
                }

                return get_template_directory().'/single-offer.php';
            }
        );
    }

}
