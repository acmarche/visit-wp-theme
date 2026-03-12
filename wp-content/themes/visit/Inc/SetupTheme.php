<?php

namespace VisitMarche\ThemeWp\Inc;

use VisitMarche\ThemeWp\Repository\TranslationRepository;

class SetupTheme
{
    public function __construct()
    {
        add_action('after_setup_theme', fn() => $this->setup());
        add_action('after_switch_theme', fn() => TranslationRepository::createTable());
        add_action('admin_init', fn() => $this->maybeCreateTranslationTable());
    }

    private function maybeCreateTranslationTable(): void
    {
        if (TranslationRepository::needsUpgrade()) {
            TranslationRepository::createTable();
        }
    }

    /**
     * Sets up theme defaults and registers support for various WordPress features.
     *
     * Note that this function is hooked into the after_setup_theme hook, which
     * runs before the init hook. The init hook is too late for some features, such
     * as indicating support for post thumbnails.
     */
    public function setup(): void
    {
        // Add default posts and comments RSS feed links to head.
        //add_theme_support( 'automatic-feed-links' );

        /*
         * Let WordPress manage the document title.
         * By adding theme support, we declare that this theme does not use a
         * hard-coded <title> tag in the document head, and expect WordPress to
         * provide it for us.
         */
        //   add_theme_support('title-tag');

        /*
         * Enable support for Post Thumbnails on posts and pages.
         *
         * @link https://developer.wordpress.org/themes/functionality/featured-images-post-thumbnails/
         */
        add_theme_support('post-thumbnails');
        add_image_size('slideshow', 480, 210, true);
        add_image_size('agenda_list', 435, 136, true);
        set_post_thumbnail_size(1568, 9999);

        // This theme uses wp_nav_menu() in one location.
        register_nav_menus(
            [
                'menu-top' => esc_html__('Menu top', 'marchebe'),
            ]
        );

        /*
         * Switch default core markup for search form, comment form, and comments
         * to output valid HTML5.
         */
        add_theme_support(
            'html5',
            [
                'search-form',
                'comment-form',
                'comment-list',
                'gallery',
                'caption',
                'style',
                'script',
                'navigation-widgets',
            ]
        );

        // Add theme support for selective refresh for widgets.
        add_theme_support('customize-selective-refresh-widgets');

        // Add support for Block Styles.
        // Charge les styles par défaut du coeur de WP pour les blocs (couleurs, espacements)
        // À n'utiliser que si vous voulez le look "standard" de WP en base
        add_theme_support('wp-block-styles');

        // Add support for full and wide align images.
        add_theme_support('align-wide');

        // Add support for editor styles.
        //add_theme_support( 'editor-styles' );

        // Add support for responsive embedded content.
        add_theme_support('responsive-embeds');
    }
}