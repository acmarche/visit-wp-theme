<?php

namespace VisitMarche\ThemeWp\Inc;

use VisitMarche\ThemeWp\Lib\Sort\SortLink;
use WP_Admin_Bar;

class AdminBar
{
    public function __construct()
    {
        add_action('admin_bar_menu', fn($wp_admin_bar) => $this->customize_my_wp_admin_bar($wp_admin_bar), 100);
    }

    public function customize_my_wp_admin_bar(WP_Admin_Bar $wp_admin_bar): void
    {
        $codeCgt = get_query_var(RouterPivot::PARAM_OFFRE);

        if ($codeCgt) {
            $wp_admin_bar->add_menu(
                [
                    'id' => 'edit-offer',
                    'title' => '<span class="ab-icon dashicons dashicons-database-view"></span> Modifier l\'offre',
                    'href' => RouterPivot::getRouteOfferToPivotSite($codeCgt),
                    'meta' => ['target' => '_blank'],
                ]
            );
        }
        if (is_category()) {
            $cat_ID = get_queried_object_id();
            $wp_admin_bar->add_menu(
                [
                    'id' => 'pivot_category_offers',
                    'title' => '<span class="ab-icon dashicons dashicons-database"></span> Offres Pivot',
                    'href' => '/wp-admin/admin.php?page=category_offers&catID='.$cat_ID,
                ]
            );
            $sortLink = SortLink::linkSortArticles($cat_ID);
            if ($sortLink) {
                $wp_admin_bar->add_menu(
                    [
                        'id' => 'pivot_sort',
                        'title' => '<span class="ab-icon dashicons dashicons-sort"></span> Trier les articles',
                        'href' => $sortLink,
                    ]
                );
            }
        }
    }
}
