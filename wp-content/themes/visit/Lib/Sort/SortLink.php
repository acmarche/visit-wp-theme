<?php

namespace VisitMarche\ThemeWp\Lib\Sort;

use VisitMarche\ThemeWp\Lib\Twig;

class SortLink
{
    public static function linkSortArticles(): ?string
    {
        if (current_user_can('edit_posts')) {
            $url = admin_url('/admin.php?page=ac_marche_tri_news');
            $twig = Twig::LoadTwig();

            return $twig->render('@Visit/admin/sort/_link_tri_news.html.twig', ['url' => $url]);
        }

        return null;
    }
}
