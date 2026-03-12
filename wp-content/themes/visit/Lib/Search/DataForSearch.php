<?php

namespace VisitMarche\ThemeWp\Lib\Search;


use VisitMarche\ThemeWp\Dto\CommonItem;
use VisitMarche\ThemeWp\Dto\Tag;
use VisitMarche\ThemeWp\Enums\LanguageEnum;
use VisitMarche\ThemeWp\Lib\OpenAi;
use VisitMarche\ThemeWp\Repository\PivotRepository;
use VisitMarche\ThemeWp\Repository\WpRepository;

class DataForSearch
{
    private WpRepository $wpRepository;
    private PivotRepository $pivotRepository;

    public function __construct()
    {
        $this->wpRepository = new WpRepository();
        $this->pivotRepository = new PivotRepository();
    }

    /**
     * @param int|null $categoryId
     * @return array<int,CommonItem>
     */
    public function getPosts(?int $categoryId = null): array
    {
        $args = array(
            'numberposts' => 5000,
            'orderby' => 'post_title',
            'order' => 'ASC',
            'post_status' => 'publish',
            'suppress_filters' => true,
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );

        if ($categoryId) {
            $args['category'] = $categoryId;
        }

        $posts = get_posts($args);
        $data = [];

        foreach ($posts as $post) {
            $data[] = CommonItem::createFromPost($post);
        }

        // Free memory
        unset($posts);

        return $data;
    }

    /**
     * @return array<int,CommonItem>
     */
    public function getCategories(): array
    {
        $args = array(
            'type' => 'post',
            'child_of' => 0,
            'parent' => '',
            'orderby' => 'name',
            'order' => 'ASC',
            'hide_empty' => 0,
            'hierarchical' => 1,
            'exclude' => '',
            'include' => '',
            'number' => '',
            'taxonomy' => 'category',
            'pad_counts' => false,
        );

        $categories = get_categories($args);
        $data = [];

        foreach ($categories as $category) {
            if ($category->description) {
                $category->description = Cleaner::cleandata($category->description);
            }

            // Use lightweight method - only titles and excerpts, limited to 100 posts
            $content = $category->description ?? '';

            try {
                $offers = $this->wpRepository->findArticlesAndOffersByWpCategory($category->cat_ID);
            } catch (\Exception $e) {
                $offers = [];
            }
            foreach ($offers as $offer) {
                $content .= ' '.$offer->name;
                $content .= ' '.$offer->type;
                $content .= ' '.$offer->excerpt;
            }

            $tags = [];
            if ($category->parent > 0) {
                $parent = get_category($category->parent);
                if ($parent instanceof \WP_Term) {
                    $tags[] = Tag::createFromCategory($parent);
                }
            }

            $data[] = CommonItem::createFromCategory($category, $content, $tags);

            // Free memory after each category
            unset($content, $children, $tags);
        }

        unset($categories);

        return $data;
    }

    /**
     * @param int|null $categoryId
     * @return array<int,CommonItem>
     */
    public function getOffers(?int $categoryId = null, bool $translate = false): array
    {
        $offers = $this->pivotRepository->getAllOffers();
        $data = [];

        $translator = $translate ? OpenAi::create() : null;
        $languages = [
            'en' => LanguageEnum::ENGLISH,
            'nl' => LanguageEnum::DUTCH,
            'de' => LanguageEnum::GERMAN,
        ];

        foreach ($offers as $offer) {
            $item = CommonItem::createFromOffer($offer);

            if ($translator) {
                foreach ($languages as $code => $language) {
                    $item->{'name_' . $code} = $translator->translate($item->name, $language);
                    if ($item->excerpt) {
                        $item->{'excerpt_' . $code} = $translator->translate($item->excerpt, $language);
                    }
                }
            }

            $data[] = $item;
        }

        // Free memory
        unset($offers);

        return $data;
    }

}
