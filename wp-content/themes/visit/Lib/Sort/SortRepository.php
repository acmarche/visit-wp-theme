<?php

namespace VisitMarche\ThemeWp\Lib\Sort;

use VisitMarche\ThemeWp\Dto\CommonItem;

class SortRepository
{
    private const string TABLE = 'wp_acposts_order';

    /**
     * @param CommonItem[] $items
     * @return CommonItem[]
     */
    public static function applySortOrder(int $catId, array $items): array
    {
        $positions = self::getPositions($catId);

        foreach ($items as $item) {
            $item->sortPosition = $positions[$item->id] ?? PHP_INT_MAX;
        }

        usort($items, fn(CommonItem $a, CommonItem $b) => $a->sortPosition <=> $b->sortPosition);

        return $items;
    }

    /**
     * @return array<string, int> post_id => position
     */
    public static function getPositions(int $catId): array
    {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, position FROM ".self::TABLE." WHERE cat_id = %d ORDER BY position",
                $catId
            )
        );

        if (!$results) {
            return [];
        }

        $positions = [];
        foreach ($results as $row) {
            $positions[$row->post_id] = (int)$row->position;
        }

        return $positions;
    }

    /**
     * @param string[] $order Array of item IDs in desired order
     */
    public static function saveOrder(int $catId, array $order): void
    {
        global $wpdb;

        foreach ($order as $position => $postId) {
            $postId = sanitize_text_field($postId);

            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM ".self::TABLE." WHERE cat_id = %d AND post_id = %s",
                    $catId,
                    $postId
                )
            );

            if ($exists) {
                $wpdb->update(
                    self::TABLE,
                    ['position' => $position],
                    ['cat_id' => $catId, 'post_id' => $postId],
                    ['%d'],
                    ['%d', '%s']
                );
            } else {
                $wpdb->insert(
                    self::TABLE,
                    [
                        'cat_id' => $catId,
                        'post_id' => $postId,
                        'position' => $position,
                    ],
                    ['%d', '%s', '%d']
                );
            }
        }
    }
}
