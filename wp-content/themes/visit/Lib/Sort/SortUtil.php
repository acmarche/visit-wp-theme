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
                    if ($postA->position == $postB->position) {
                        return 0;
                    }

                    return ($postA->position < $postB->position) ? -1 : 1;
                }
            }
        );

        return $posts;
    }
}
