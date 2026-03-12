<?php

declare(strict_types=1);

namespace VisitMarche\ThemeWp\Inc;

use VisitMarche\ThemeWp\Repository\PivotRepository;
use VisitMarche\ThemeWp\Repository\WpRepository;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;

class ApiRoutes
{
    public function __construct()
    {
        add_action('rest_api_init', fn() => $this->registerRoutes());
    }

    private function registerRoutes(): void
    {
        /**
         * Use id admin interface
         */
        register_rest_route(
            'pivot',
            'find-offers-by-name/(?P<name>.+)',
            [
                'methods' => 'GET',
                'callback' => fn(WP_REST_Request $request) => $this->findOffersByName($request),
                'permission_callback' => fn() => is_user_logged_in(),
            ],
        );

        /**
         * Use in AdminPage and AdminBar
         */
        register_rest_route(
            'pivot',
            'category_offers/(?P<categoryId>\d+)',
            [
                'methods' => 'GET',
                'callback' => fn(WP_REST_Request $request) => $this->getCategoryOffers($request),
                'permission_callback' => fn() => is_user_logged_in(),
            ],
        );

        /**
         * Use with category's filters
         */
        register_rest_route(
            'pivot',
            'category_items/(?P<categoryId>\d+)',
            [
                'methods' => 'GET',
                'callback' => fn(WP_REST_Request $request) => $this->getCategoryItems($request),
                'permission_callback' => '__return_true',
            ],
        );
    }

    private function findOffersByName(WP_REST_Request $request): WP_Error|WP_REST_Response|WP_HTTP_Response
    {
        $name = urldecode((string)$request->get_param('name'));
        $pivotRepository = new PivotRepository();

        try {
            $offres = $pivotRepository->findShortsByNameOrCode($name);
        } catch (\Exception $e) {
            return rest_ensure_response(['error' => $e->getMessage()]);
        }

        return rest_ensure_response($offres);
    }

    private function getCategoryItems(WP_REST_Request $request): WP_Error|WP_REST_Response|WP_HTTP_Response
    {
        $categoryId = (int)$request->get_param('categoryId');
        if ($categoryId < 1) {
            return new WP_Error(400, 'Missing param categoryId');
        }

        $wpRepository = new WpRepository();

        try {
            $items = $wpRepository->findArticlesAndOffersByWpCategory($categoryId);
        } catch (\Exception $e) {
            return rest_ensure_response(['error' => $e->getMessage()]);
        }

        $data = array_map(fn($item) => $item->toArray(), $items);

        return rest_ensure_response($data);
    }

    private function getCategoryOffers(WP_REST_Request $request): WP_Error|WP_REST_Response|WP_HTTP_Response
    {
        $categoryId = (int)$request->get_param('categoryId');
        if ($categoryId < 1) {
            return new WP_Error(400, 'Missing param categoryId');
        }

        $codesCgt = WpRepository::getMetaPivotCodesCgtOffers($categoryId);
        $pivotRepository = new PivotRepository();

        try {
            $offers = $pivotRepository->findOffersShortByCodesCgt($codesCgt);
        } catch (\Exception $e) {
            $offers = [];
        }

        return rest_ensure_response($offers);
    }
}
