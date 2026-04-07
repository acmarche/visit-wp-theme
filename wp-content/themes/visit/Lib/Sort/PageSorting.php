<?php

namespace VisitMarche\ThemeWp\Lib\Sort;

use VisitMarche\ThemeWp\Lib\Twig;
use VisitMarche\ThemeWp\Repository\WpRepository;

class PageSorting
{
    static function loadPages(int $wpCategoryId): void
    {
        $position = 61;
        add_menu_page(
            'Tri des articles',
            'Tri',
            'edit_posts',
            'acmarche_trie',
            function () {
                PageSorting::pageIndex();
            },
            'dashicons-sort',
            $position
        );
        add_submenu_page(
            'acmarche_trie',
            'Trie des articles',
            'Tri des articles',
            'edit_posts',
            'ac_marche_tri_articles',
            function () use ($wpCategoryId) {
                PageSorting::renderPage($wpCategoryId);
            },
        );
    }

    static function pageIndex(): void
    {
        Twig::renderPage(
            '@AcMarche/sort/menu.html.twig',
            [
                'url' => admin_url('/admin.php?page=ac_marche_tri_articles')
            ]
        );
    }

    static function renderPage(int $wpCategoryId): void
    {
        $wpRepository = new WpRepository();
        $articles = $wpRepository->findArticlesAndOffersByWpCategory($wpCategoryId);

        Twig::renderPage(
            '@AcMarche/sort/tri_articles.html.twig',
            [
                'articles' => $articles,
            ]
        );
    }
}
