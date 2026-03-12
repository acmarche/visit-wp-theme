<?php

namespace VisitMarche\ThemeWp\Lib\Sort;

use VisitMarche\ThemeWp\Lib\Twig;
use VisitMarche\ThemeWp\Repository\WpRepository;

class PageSorting
{
    static function loadPages(): void
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
            'Trie des news',
            'Tri des news',
            'edit_posts',
            'ac_marche_tri_news',
            function () {
                PageSorting::renderPageNews();
            },
        );
    }

    static function pageIndex(): void
    {
        $urlNews = admin_url('/admin.php?page=ac_marche_tri_news');
        Twig::renderPage(
            '@AcMarche/sort/menu.html.twig',
            [
                'urlNews' => $urlNews,
            ]
        );
    }

    static function renderPageNews(): void
    {
        $wpRepository = new WpRepository();
        $news = $wpRepository->getNews(30);

        Twig::renderPage(
            '@AcMarche/sort/tri_news.html.twig',
            [
                'news' => $news,
            ]
        );
    }
}
