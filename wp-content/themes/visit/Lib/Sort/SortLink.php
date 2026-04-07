<?php

namespace VisitMarche\ThemeWp\Lib\Sort;

use VisitMarche\ThemeWp\Inc\CategorySortMetaData;

class SortLink
{
    public static function sortUrl(int $wpCategoryId): ?string
    {
        if (!current_user_can('edit_posts')) {
            return null;
        }

        if (CategorySortMetaData::getSortMethod($wpCategoryId) !== 'manual') {
            return null;
        }

        return admin_url('admin.php?page=visit_sort_articles&catId='.$wpCategoryId);
    }
}
