<?php

namespace VisitMarche\ThemeWp\Repository;


use VisitMarche\ThemeWp\Dto\CommonItem;
use VisitMarche\ThemeWp\Enums\CommonItemTypeEnum;
use VisitMarche\ThemeWp\Enums\IconeEnum;
use VisitMarche\ThemeWp\Enums\LanguageEnum;
use VisitMarche\ThemeWp\Lib\OpenAi;
use WP_Post;
use WP_Term;

class MenuRepository
{
    const MENU_NAME = 'menu-top';

    /**
     * @return array<int, array{parent: CommonItem, children: CommonItem[]}>
     */
    public function getMenuTop(string $locale): array
    {
        $menu = wp_get_nav_menu_object(self::MENU_NAME);
        if (!$menu) {
            return [];
        }

        $menuItems = wp_get_nav_menu_items($menu);
        if (!$menuItems) {
            return [];
        }

        $items = [];
        $childrenByParent = [];

        foreach ($menuItems as $menuItem) {
            $commonItem = $this->createCommonItemFromMenuItem($menuItem);
            if ((int)$menuItem->menu_item_parent === 0) {
                $items[$menuItem->ID] = ['parent' => $commonItem, 'children' => []];
            } else {
                $childrenByParent[(int)$menuItem->menu_item_parent][] = $commonItem;
            }
        }

        foreach ($childrenByParent as $parentId => $children) {
            if (isset($items[$parentId])) {
                $items[$parentId]['children'] = $children;
            }
        }

        $items = array_values($items);

        if ($locale !== 'fr' && ($language = LanguageEnum::tryFrom($locale))) {
            $translator = OpenAi::create();
            foreach ($items as $data) {
                $data['parent']->name = $translator->translate($data['parent']->name, $language);
                foreach ($data['children'] as $child) {
                    $child->name = $translator->translate($child->name, $language);
                }
            }
        }

        return $items;
    }

    private function createCommonItemFromMenuItem(WP_Post $menuItem): CommonItem
    {
        $objectType = $menuItem->object;
        $objectId = (int)$menuItem->object_id;

        if ($objectType === 'category') {
            $term = get_term($objectId, 'category');
            if ($term instanceof WP_Term) {
                return CommonItem::createFromCategory($term);
            }
        }

        if ($objectType === 'page' || $objectType === 'post') {
            $post = get_post($objectId);
            if ($post instanceof WP_Post) {
                return CommonItem::createFromPost($post);
            }
        }

        // Custom link or other type
        $item = new CommonItem(
            id: (string)$menuItem->ID,
            type: CommonItemTypeEnum::MENU_ITEM,
            name: $menuItem->title,
            image: CommonItem::PLACEHOLDER_IMAGE,
        );
        $item->url = $menuItem->url;

        return $item;
    }

    /**
     * @return WP_Term[]
     */
    public function getIcons(string $locale): array
    {
        $icons = [
            'arts' => get_category_by_slug('arts'),
            'balades' => get_category_by_slug('balades'),
            'fetes' => get_category_by_slug('fetes'),
            'gourmandises' => get_category_by_slug('gourmandises'),
            'patrimoine' => get_category_by_slug('patrimoine'),
        ];

        foreach ($icons as $key => $icon) {
            if ($icon instanceof WP_Term) {
                $icon->url = get_category_link($icon);
                $icon->colorHover = $this->hoverColor($key);
                $icon->imageWhite = IconeEnum::iconeWhite($icon->slug);
            }
        }

        if ($locale !== 'fr' && ($language = LanguageEnum::tryFrom($locale))) {
            $translator = OpenAi::create();
            foreach ($icons as $data) {
                $data->name = $translator->translate($data->name, $language);
            }
        }

        return $icons;
    }

    private function hoverColor(string $key): string
    {
        return match ($key) {
            'arts' => 'hover:bg-art',
            'balades' => 'hover:bg-walk',
            'fetes' => 'hover:bg-party',
            'gourmandises' => 'hover:bg-delicacy',
            default => 'hover:bg-patrimony',
        };
    }


}