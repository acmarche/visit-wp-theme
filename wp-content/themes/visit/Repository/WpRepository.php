<?php

namespace VisitMarche\ThemeWp\Repository;

use AcMarche\PivotAi\Api\PivotClient;
use VisitMarche\ThemeWp\Dto\CommonItem;
use VisitMarche\ThemeWp\Inc\Theme;
use VisitMarche\ThemeWp\Lib\Di;
use WP_Post;
use WP_Query;
use WP_Term;

class WpRepository
{
    public const PIVOT_REFOFFERS = 'pivot_ref_offers';

    public static function findCategoryIdByCodeCgt(string $codeCgt): int
    {
        $terms = get_terms([
            'taxonomy' => 'category',
            'hide_empty' => false,
            'meta_key' => self::PIVOT_REFOFFERS,
        ]);

        if (is_wp_error($terms)) {
            return Theme::CATEGORY_NOT_CATEGORIZED;
        }

        foreach ($terms as $term) {
            $codes = get_term_meta($term->term_id, self::PIVOT_REFOFFERS, true);
            if (is_array($codes) && in_array($codeCgt, $codes, true)) {
                return $term->term_id;
            }
        }

        return Theme::CATEGORY_NOT_CATEGORIZED;
    }

    /**
     * @return string[]
     */
    public static function getMetaPivotCodesCgtOffers(int $categoryId): array
    {
        $offers = get_term_meta($categoryId, self::PIVOT_REFOFFERS, true);
        if (!is_array($offers)) {
            return [];
        }

        return $offers;
    }

    /**
     * @param int $cat_ID
     * @return WP_Term[]
     */
    public function getChildrenOfCategory(int $cat_ID): array
    {
        $args = [
            'parent' => $cat_ID,//Get direct children only
            'hide_empty' => false,
        ];
        $children = get_categories($args);
        array_map(
            function ($category) {
                $category->url = get_category_link($category->term_id);
                $category->id = $category->term_id;
            },
            $children,
        );

        return $children;
    }

    /**
     * @return CommonItem[]
     */
    public function findArticlesAndOffersByWpCategory(int $cat_ID, bool $includeChildren = false): array
    {
        $categoryIds = [$cat_ID];

        if ($includeChildren) {
            foreach ($this->getChildrenOfCategory($cat_ID) as $child) {
                $categoryIds[] = $child->term_id;
            }
        }

        $items = [];
        $allCodesCgt = [];
        $seenIds = [];

        foreach ($categoryIds as $categoryId) {
            foreach ($this->findArticlesByCategory($categoryId) as $post) {
                $item = CommonItem::createFromPost($post);
                if (!isset($seenIds[$item->id])) {
                    $seenIds[$item->id] = true;
                    $items[] = $item;
                }
            }

            $codesCgt = WpRepository::getMetaPivotCodesCgtOffers($categoryId);
            array_push($allCodesCgt, ...$codesCgt);
        }

        $allCodesCgt = array_unique($allCodesCgt);

        if ($allCodesCgt !== []) {
            $pivotClient = Di::getInstance()->get(PivotClient::class);
            $offerResponse = $pivotClient->fetchOffersByCriteria();

            foreach ($offerResponse->getOffers() as $offer) {
                if (in_array($offer->codeCgt, $allCodesCgt, true) && !isset($seenIds[$offer->codeCgt])) {
                    $seenIds[$offer->codeCgt] = true;
                    $items[] = CommonItem::createFromOffer($offer);
                }
            }
        }

        usort($items, function (CommonItem $a, CommonItem $b) {
            $order = $a->type->sortOrder() <=> $b->type->sortOrder();

            return $order !== 0 ? $order : strcasecmp($a->name, $b->name);
        });

        return $items;
    }

    /**
     * @param int $catId
     * @return array<int, WP_Post>
     */
    public function findArticlesByCategory(int $catId): array
    {
        $args = [
            'cat' => $catId,
            'numberposts' => 5000,
            'orderby' => 'post_title',
            'order' => 'ASC',
            'post_status' => 'publish',
        ];

        $query = new WP_Query($args);

        return $query->posts;
    }

    public function getIntro(): string
    {
        $intro = '<p>Intro vide</p>';
        $introId = apply_filters('wpml_object_id', Theme::PAGE_INTRO, 'page', true);
        $pageIntro = get_post($introId);

        if ($pageIntro) {
            $intro = get_the_content(null, false, $pageIntro);
            $intro = apply_filters('the_content', $intro);
            $intro = str_replace(']]>', ']]&gt;', $intro);
            $intro = str_replace('<p>', '', $intro);
            $intro = str_replace('</p>', '', $intro);
        }

        return $intro;
    }

    /**
     * @return array<int,CommonItem>
     */
    public function getIdeas(): array
    {
        $ideas = [];
        if ($term = get_category_by_slug('en-famille')) {
            $ideas[] = $this->addIdea($term, 'Famille.jpg');
        }
        if ($term = get_category_by_slug('en-solo-ou-duo')) {
            $ideas[] = $this->addIdea($term, 'Duo-WBT.jpg');
        }
        if ($term = get_category_by_slug('entre-amis')) {
            $ideas[] = $this->addIdea($term, 'Friends.jpg');
        }
        if ($term = get_category_by_slug('en-groupe')) {
            $ideas[] = $this->addIdea($term, 'Groupe.jpg');
        }
        if ($term = get_category_by_slug('personnes-porteuses-dun-handicap')) {
            $ideas[] = $this->addIdea($term, 'PMR.jpg');
        }
        if ($term = get_category_by_slug('tourisme-participatif-2')) {
            $ideas[] = $this->addIdea($term, 'Tourismeparticipatif.jpg');
        }

        return $ideas;
    }

    /**
     * @param WP_Term $term
     * @param string $imageName
     * @return CommonItem
     */
    private function addIdea(\WP_Term $term, string $imageName): CommonItem
    {
        $item = CommonItem::createFromCategory($term);
        $item->icon = $imageName;

        return $item;
    }
}
