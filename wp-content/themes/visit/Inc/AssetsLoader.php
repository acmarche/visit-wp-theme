<?php

namespace VisitMarche\ThemeWp\Inc;

class AssetsLoader
{
    const leaflet_js = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    const leaflet_css = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    const swiper_js = 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js';
    const swiper_css = 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css';

    public function __construct()
    {
        //Renvoyer la valeur « true » charge les ressources de base du bloc uniquement lorsqu’elles sont affichées.
        add_filter("should_load_separate_core_block_assets", "__return_true");
        add_action('wp_enqueue_scripts', [$this, 'remove_unnecessary_core_styles'], 9999);
        add_action('wp_enqueue_scripts', fn() => $this->mainAssets());
        add_filter('script_loader_tag', [$this, 'add_defer_attribute'], 10, 2);
    }

    public function mainAssets(): void
    {
        wp_enqueue_style(
            'theme-visit-style',
            get_template_directory_uri().'/style.css'
        );

        wp_enqueue_style(
            'visitmarche-css',
            get_template_directory_uri().'/assets/css/visit.css',
        );

        wp_enqueue_style(
            'tabler-icons-css',
            'https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css',
        );

        wp_enqueue_style(
            'swiper-css',
            self::swiper_css,
        );

        wp_enqueue_script(
            'swiper-js',
            self::swiper_js,
            [],
            false,
            false
        );

        // Header navigation component (must load before Alpine.js)
        wp_enqueue_script(
            'visit-header-nav',
            get_template_directory_uri().'/assets/js/header-search.js',
            [],
            wp_get_theme()->get('Version')
        );

        wp_enqueue_script(
            'visit-category-filter',
            get_template_directory_uri().'/assets/js/category-filter.js',
            [],
            false,
            false
        );

        wp_enqueue_script(
            'visit-alpine-js',
            '//unpkg.com/alpinejs',
            ['visit-category-filter'],
            false,
            false
        );
    }

    function add_defer_attribute($tag, $handle): string
    {
        // Add defer to Alpine.js and component scripts
        if (in_array($handle, ['visit-category-filter', 'visit-alpine-js'])) {
            return str_replace(' src', ' defer src', $tag);
        }

        return $tag;
    }

    function remove_unnecessary_core_styles(): void
    {
        // Remove Classic Theme Styles (Often redundant/opinionated CSS)
        wp_dequeue_style('classic-theme-styles');

        // Remove Block Library Theme Styles (Often redundant/opinionated CSS)
        wp_dequeue_style('wp-block-library-theme');

        // Remove Duotone SVG filters (Large inline SVG definitions for image effects)
        remove_action('wp_body_open', 'wp_global_styles_render_svg_filters');

        // Charge le CSS de base des blocs Gutenberg (indispensable pour la structure)
        //wp_enqueue_style('wp-block-library');
        // OPTIONNEL : Supprime le CSS inline de Global Styles (duotone, etc.) si vous n'en voulez pas
        // wp_dequeue_style( 'global-styles' );
        // DO NOT remove 'wp-block-library' (Needed for basic block structure/layouts)
        // DO NOT remove 'global-styles' (Needed for colors, typography, and layout settings)
    }
}
