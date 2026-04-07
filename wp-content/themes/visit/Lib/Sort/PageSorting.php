<?php

namespace VisitMarche\ThemeWp\Lib\Sort;

use VisitMarche\ThemeWp\Lib\Twig;
use VisitMarche\ThemeWp\Repository\WpRepository;

class PageSorting
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'registerPages']);
        add_action('wp_ajax_visit_sort_save', [$this, 'handleSaveOrder']);
    }

    public function registerPages(): void
    {
        add_submenu_page(
            '',
            'Tri des articles',
            'Tri des articles',
            'edit_posts',
            'visit_sort_articles',
            [$this, 'renderPage'],
        );
    }

    public function renderPage(): void
    {
        global $title;
        $title = 'Tri des articles';

        $catId = (int)($_GET['catId'] ?? 0);
        if ($catId < 1) {
            Twig::renderPage('@Visit/admin/error.html.twig', [
                'message' => 'Vous devez passer par une catégorie pour accéder à cette page',
            ]);

            return;
        }

        $category = get_category($catId);
        if (!$category || is_wp_error($category)) {
            Twig::renderPage('@Visit/admin/error.html.twig', [
                'message' => 'Catégorie introuvable',
            ]);

            return;
        }

        $wpRepository = new WpRepository();
        $items = $wpRepository->findArticlesAndOffersByWpCategory($catId);

        $sortedItems = SortRepository::applySortOrder($catId, $items);

        wp_enqueue_script('jquery-ui-sortable');

        Twig::renderPage(
            '@Visit/admin/sort/tri_articles.html.twig',
            [
                'category' => $category,
                'items' => $sortedItems,
                'categoryUrl' => get_category_link($category),
                'nonce' => wp_create_nonce('visit_sort_nonce'),
            ]
        );
    }

    public function handleSaveOrder(): void
    {
        if (!check_ajax_referer('visit_sort_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce', 403);
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions', 403);
        }

        $catId = (int)($_POST['cat_id'] ?? 0);
        $order = $_POST['order'] ?? [];

        if ($catId < 1 || !is_array($order)) {
            wp_send_json_error('Invalid data', 400);
        }

        SortRepository::saveOrder($catId, $order);

        wp_send_json_success();
    }
}
