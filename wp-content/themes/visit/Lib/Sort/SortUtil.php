<?php

namespace VisitMarche\ThemeWp\Lib\Sort;

use WP_Post;

class SortUtil
{
    /**
     * @param WP_Post[] $posts
     *
     * @return WP_Post[]
     */
    public static function sortByPosition(array $posts): array
    {
        usort(
            $posts,
            function ($postA, $postB) {
                {
                    if ($postA->order == $postB->order) {
                        return 0;
                    }

                    return ($postA->order < $postB->order) ? -1 : 1;
                }
            }
        );

        return $posts;
    }
}
