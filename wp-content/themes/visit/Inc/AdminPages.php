<?php

declare(strict_types=1);

namespace VisitMarche\ThemeWp\Inc;

use VisitMarche\ThemeWp\Lib\Twig;
use VisitMarche\ThemeWp\Repository\WpRepository;

class AdminPages
{
    public function __construct()
    {
        add_action('admin_menu', fn() => $this->addMenuItems());
    }

    private function addMenuItems(): void
    {
        add_menu_page(
            'pivot_home',
            'Pivot',
            'edit_posts',
            'pivot_home',
            fn() => $this->homepageRender(),
        );
        add_submenu_page(
            'pivot_home',
            'Offres sur une catégorie',
            'Offres sur une catégorie',
            'edit_posts',
            'category_offers',
            fn() => $this->categoryOffersRender(),
        );
    }

    private function homepageRender(): void
    {
        Twig::renderPage('@Visit/admin/home.html.twig');
    }

    private function categoryOffersRender(): void
    {
        $catID = (int)($_GET['catID'] ?? 0);
        if ($catID < 1) {
            Twig::renderPage('@Visit/admin/error.html.twig', [
                'message' => 'Vous devez passer par une catégorie pour accéder à cette page',
            ]);

            return;
        }

        $category = get_category($catID);
        if (!$category || is_wp_error($category)) {
            Twig::renderPage('@Visit/admin/error.html.twig', [
                'message' => 'Catégorie introuvable',
            ]);

            return;
        }

        $categoryUrl = get_category_link($category);

        $wpRepository = new WpRepository();
        $children = $wpRepository->getChildrenOfCategory($catID);

        wp_enqueue_script(
            'visit-alpine-admin',
            'https://unpkg.com/alpinejs@3/dist/cdn.min.js',
            [],
            null,
            ['in_footer' => false],
        );

        $handle = 'visit-alpine-admin';
        wp_localize_script($handle, 'pivotOffers', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => esc_url_raw(rest_url('pivot')),
            'nonce' => wp_create_nonce('pivot_offers_nonce'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'catId' => $catID,
        ]);

        Twig::renderPage('@Visit/admin/category_offers.html.twig', [
            'category' => $category,
            'categoryUrl' => $categoryUrl,
            'catId' => $catID,
            'children' => $children,
        ]);
    }
}
