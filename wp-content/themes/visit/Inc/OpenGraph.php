<?php

namespace VisitMarche\ThemeWp\Inc;

use VisitMarche\ThemeWp\Repository\PivotRepository;
use WP;
use WP_Post;

class OpenGraph
{
    private string $siteName;
    private string $locale = 'fr_BE';

    public function __construct()
    {
        $this->siteName = get_bloginfo('name');
        add_action('wp_head', [$this, 'renderOpenGraphTags']);
    }

    public function renderOpenGraphTags(): void
    {
        $data = $this->getPageData();

        echo "\n<!-- Open Graph / Facebook -->\n";
        echo '<meta property="og:type" content="'.esc_attr($data['type']).'" />'."\n";
        echo '<meta property="og:url" content="'.esc_url($data['url']).'" />'."\n";
        echo '<meta property="og:title" content="'.esc_attr($data['title']).'" />'."\n";
        echo '<meta property="og:site_name" content="'.esc_attr($this->siteName).'" />'."\n";
        echo '<meta property="og:locale" content="'.esc_attr($this->locale).'" />'."\n";

        if (!empty($data['description'])) {
            echo '<meta property="og:description" content="'.esc_attr($data['description']).'" />'."\n";
        }

        if (!empty($data['image'])) {
            echo '<meta property="og:image" content="'.esc_url($data['image']).'" />'."\n";
        }

        if (!empty($data['published_time'])) {
            echo '<meta property="article:published_time" content="'.esc_attr($data['published_time']).'" />'."\n";
        }

        if (!empty($data['modified_time'])) {
            echo '<meta property="article:modified_time" content="'.esc_attr($data['modified_time']).'" />'."\n";
        }

        echo "\n<!-- Twitter -->\n";
        echo '<meta name="twitter:card" content="summary_large_image" />'."\n";
        echo '<meta name="twitter:url" content="'.esc_url($data['url']).'" />'."\n";
        echo '<meta name="twitter:title" content="'.esc_attr($data['title']).'" />'."\n";

        if (!empty($data['description'])) {
            echo '<meta name="twitter:description" content="'.esc_attr($data['description']).'" />'."\n";
        }

        if (!empty($data['image'])) {
            echo '<meta name="twitter:image" content="'.esc_url($data['image']).'" />'."\n";
        }
    }

    private function getPageData(): array
    {
        $data = [
            'type' => 'website',
            'url' => $this->getCurrentUrl(),
            'title' => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'image' => '',
            'published_time' => '',
            'modified_time' => '',
        ];

        if (is_front_page() || is_home()) {
            return $this->getHomePageData($data);
        }

        if (is_singular()) {
            return $this->getSinglePostData($data);
        }

        if (is_category()) {
            return $this->getCategoryData($data);
        }

        $codeCgt = get_query_var(RouterPivot::PARAM_OFFRE);
        if ($codeCgt) {
            return $this->getOfferData($data, $codeCgt);
        }

        return $data;
    }

    private function getHomePageData(array $data): array
    {
        $data['title'] = get_bloginfo('name').' | Visit Marche-en-Famenne';
        $data['description'] = get_bloginfo('description');

        return $data;
    }

    private function getSinglePostData(array $data): array
    {
        global $post;

        $data['type'] = 'article';
        $data['title'] = get_the_title();
        $data['description'] = $this->getPostDescription($post);
        $data['published_time'] = get_the_date('c');
        $data['modified_time'] = get_the_modified_date('c');

        if (has_post_thumbnail()) {
            $data['image'] = get_the_post_thumbnail_url($post, 'original');
        }

        return $data;
    }

    private function getCategoryData(array $data): array
    {
        $category = get_queried_object();

        if ($category) {
            $data['title'] = $category->name.' | '.$this->siteName;

            if (!empty($category->description)) {
                $data['description'] = wp_strip_all_tags($category->description);
            }
        }

        return $data;
    }

    private function getOfferData(array $data, string $codeCgt): array
    {
        try {
            $pivotRepository = new PivotRepository();
            $offer = $pivotRepository->loadOffer($codeCgt);

            if ($offer) {
                $data['type'] = 'article';
                $data['title'] = $offer->nom.' | '.$offer->typeOffre?->getLabelByLang('fr').' | ';

                if (!empty($offer->description)) {
                    $data['description'] = wp_strip_all_tags($offer->description?->get('fr'));
                }

                $image = $offer->getDefaultImage();
                if ($image) {
                    $data['image'] = $image->url;
                }

                if (!empty($offer->dateCreation)) {
                    $data['published_time'] = $offer->dateCreation;
                }

                if (!empty($offer->dateModification)) {
                    $data['modified_time'] = $offer->dateModification;
                }
            }
        } catch (\Exception) {
            // Keep default data on error
        }

        return $data;
    }

    private function getPostDescription(WP_Post $post): string
    {
        if (!empty($post->post_excerpt)) {
            return wp_strip_all_tags($post->post_excerpt);
        }

        $content = wp_strip_all_tags($post->post_content);
        if (strlen($content) > 160) {
            $content = substr($content, 0, 157).'...';
        }

        return $content;
    }

    private function getCurrentUrl(): string
    {
        /**
         * @var WP $wp
         */
        global $wp;

        return LanguageRouter::prefixUrl(home_url($wp->request));
    }
}
