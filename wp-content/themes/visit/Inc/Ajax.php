<?php

declare(strict_types=1);

namespace VisitMarche\ThemeWp\Inc;

use VisitMarche\ThemeWp\Lib\CookieHelper;
use VisitMarche\ThemeWp\Repository\WpRepository;

class Ajax
{
    public function __construct()
    {
        add_action('wp_ajax_action_add_offer', fn() => $this->actionAddOffer());
        add_action('wp_ajax_action_delete_offer', fn() => $this->actionDeleteOffer());
        /**
         * Update cookie preferences
         */
        add_action('wp_enqueue_scripts', [$this, 'setCookieScript']);
        // Handle the AJAX request
        add_action('wp_ajax_set_cookie_action', [$this, 'setCookieHandler']); // For logged-in users
        add_action('wp_ajax_nopriv_set_cookie_action', [$this, 'setCookieHandler']); // For non-logged users
        /**
         * Newsletter subscription
         */
        add_action('wp_ajax_newsletter_subscribe', [$this, 'newsletterSubscribeHandler']);
        add_action('wp_ajax_nopriv_newsletter_subscribe', [$this, 'newsletterSubscribeHandler']);
    }

    private function actionAddOffer(): void
    {
        $this->checkPermission();

        $categoryId = (int)($_POST['categoryId'] ?? 0);
        $codeCgt = sanitize_text_field($_POST['codeCgt'] ?? '');
        $codesCgt = [];

        if ($categoryId > 0 && $codeCgt !== '') {
            $codesCgt = WpRepository::getMetaPivotCodesCgtOffers($categoryId);
            if (!in_array($codeCgt, $codesCgt, true)) {
                $codesCgt[] = $codeCgt;
                update_term_meta($categoryId, WpRepository::PIVOT_REFOFFERS, $codesCgt);
            }
        }

        wp_send_json($codesCgt);
    }

    private function actionDeleteOffer(): void
    {
        $this->checkPermission();

        $categoryId = (int)($_POST['categoryId'] ?? 0);
        $codeCgt = sanitize_text_field($_POST['codeCgt'] ?? '');
        $codesCgt = [];

        if ($categoryId > 0 && $codeCgt !== '') {
            $codesCgt = WpRepository::getMetaPivotCodesCgtOffers($categoryId);
            $key = array_search($codeCgt, $codesCgt, true);
            if ($key !== false) {
                unset($codesCgt[$key]);
                $codesCgt = array_values($codesCgt);
                update_term_meta($categoryId, WpRepository::PIVOT_REFOFFERS, $codesCgt);
            }
        }

        wp_send_json($codesCgt);
    }

    private function checkPermission(): void
    {
        if (!check_ajax_referer('pivot_offers_nonce', '_ajax_nonce', false)) {
            wp_send_json_error('Invalid nonce', 403);
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions', 403);
        }
    }

    // Localize a script to pass Ajax URL and nonce
    public function setCookieScript(): void
    {
        $url = $_ENV['WP_URL_HOME'].'/wp-admin/admin-ajax.php';

        wp_localize_script('visit-alpine-js', 'wpData', array(
            'ajaxUrl' => $url,
            'cookieNonce' => wp_create_nonce('set_cookie_nonce'),
            'newsletterNonce' => wp_create_nonce('newsletter_nonce'),
        ));
    }

    public function newsletterSubscribeHandler(): void
    {
        check_ajax_referer('newsletter_nonce', 'nonce');

        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';

        if (!is_email($email)) {
            wp_send_json_error(['message' => 'Adresse email invalide.'], 400);
        }

        $admin_email = get_option('admin_email');
        $subject = 'Nouvelle inscription newsletter : '.$email;
        $body = sprintf("Nouvelle demande d'inscription à la newsletter.\n\nEmail : %s\nDate : %s", $email, wp_date('d/m/Y H:i'));

        wp_mail($admin_email, $subject, $body);

        wp_send_json_success(['message' => 'Inscription enregistrée.']);
    }

    public function setCookieHandler(): void
    {
        check_ajax_referer('set_cookie_nonce', 'nonce');

        $essential = true;
        $statistics = isset($_POST['statistics']) ? filter_var($_POST['statistics'], FILTER_VALIDATE_BOOLEAN) : false;
        $encapsulated = isset($_POST['encapsulated']) ? filter_var(
            $_POST['encapsulated'],
            FILTER_VALIDATE_BOOLEAN
        ) : false;

        $preferences = [
            'essential' => $essential,
            'statistics' => $statistics,
            'encapsulated' => $encapsulated,
        ];

        // Save all preferences at once
        CookieHelper::saveAll($preferences);

        wp_send_json_success([
            'message' => 'Cookie preferences saved',
            'preferences' => $preferences,
        ]);
    }
}
