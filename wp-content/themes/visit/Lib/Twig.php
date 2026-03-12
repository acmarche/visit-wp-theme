<?php

namespace VisitMarche\ThemeWp\Lib;

use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Extension\DebugExtension;
use Twig\Extra\Intl\IntlExtension;
use Twig\Extra\String\StringExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use VisitMarche\ThemeWp\Inc\LanguageRouter;
use VisitMarche\ThemeWp\Inc\RouterPivot;
use WP;

class Twig
{
    public static function loadTwig(?string $path = null): Environment
    {
        if (!$path) {
            $path = get_template_directory().'/templates';
        }

        $loader = new FilesystemLoader($path);

        try {
            $loader->addPath($path, 'Visit');
        } catch (LoaderError $e) {

        }

        $cache = $_ENV['APP_CACHE_DIR'] ?? self::getPathCache('twig');

        $twig = new Environment($loader, [
            'strict_variables' => WP_DEBUG,
            'debug' => WP_DEBUG,
            'cache' => WP_DEBUG ? false : $cache,
            'auto_reload' => true,
            'optimizations' => 0,
            'charset' => 'UTF-8',
        ]);

        if (WP_DEBUG) {
            $twig->enableDebug();
            $twig->addExtension(new DebugExtension());
        }

        $locale = LocaleHelper::getSelectedLanguage();
        locale_set_default($locale . '-' . strtoupper($locale));
        $translator = LocaleHelper::iniTranslator();
        $twig->addExtension(new TranslationExtension($translator));
        $twig->addGlobal('locale', $locale);
        $twig->addGlobal('WP_DEBUG', WP_DEBUG);
        $twig->addFunction(self::currentUrl());
        $twig->addFunction(self::templateUri());
        $twig->addFunction(self::langUrl());
        $twig->addFunction(self::langCurrentPath());
        $twig->addFilter(self::getRouteOfferToPivotSite());
        $twig->addFilter(self::getRouteOfferToSite());
        $twig->addExtension(new StringExtension());
        $twig->addExtension(new IntlExtension());
        $twig->addFunction(self::cookieIsAuthorizedByName());
        $twig->addFunction(self::cookieHasSetPreferences());
        $twig->addFilter(self::removeHtml());
        $twig->addGlobal('template_directory', get_template_directory());

        return $twig;
    }

    public static function renderErrorPage(\Exception $exception): void
    {
        try {
            status_header(500);
            echo self::loadTwig()->render('@Visit/error/_error.html.twig', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        } catch (LoaderError|RuntimeError|SyntaxError $e) {
            echo $e->getMessage();
        }
    }

    public static function renderNotFoundPage(string $message): void
    {
        try {
            status_header(404);
            echo self::loadTwig()->render('@Visit/error/_not_found.html.twig', [
                'message' => $message,
            ]);

            return;
        } catch (LoaderError|RuntimeError|SyntaxError $e) {
            echo $e->getMessage();
        }
    }

    private static function templateUri(): TwigFunction
    {
        return new TwigFunction(
            'template_uri',
            fn(): string => get_template_directory_uri()
        );
    }

    private static function langUrl(): TwigFunction
    {
        return new TwigFunction(
            'lang_url',
            fn(string $path): string => LanguageRouter::url($path)
        );
    }

    private static function langCurrentPath(): TwigFunction
    {
        return new TwigFunction(
            'lang_current_path',
            fn(): string => LanguageRouter::currentPath()
        );
    }

    /**
     * For sharing pages
     */
    private static function currentUrl(): TwigFunction
    {
        /**
         * @var WP $wp
         */
        global $wp;

        $url = LanguageRouter::prefixUrl(home_url($wp->request));

        return new TwigFunction(
            'currentUrl',
            function () use ($url): string {
                return $url;
            }
        );
    }

    private static function getPathCache(string $folder): string
    {
        return ABSPATH.'var/cache/'.$folder;
    }

    public static function renderPage(string $path, array $params = []): void
    {
        try {
            echo self::loadTwig()->render($path, $params);
        } catch (LoaderError|RuntimeError|SyntaxError $e) {
            self::renderErrorPage($e);
        }
    }

    private static function removeHtml(): TwigFilter
    {
        return new TwigFilter(
            'remove_html',
            function (?string $text): ?string {
                if (!$text) {
                    return $text;
                }

                return strip_tags($text);
            },
            [
                'is_safe' => ['html'],
            ]
        );
    }

    private static function getRouteOfferToPivotSite(): TwigFilter
    {
        return new TwigFilter(
            'routeOfferToPivotSite',
            function (string $codeCgt): string {
                return RouterPivot::getRouteOfferToPivotSite($codeCgt);
            }
        );
    }

    private static function getRouteOfferToSite(): TwigFilter
    {
        return new TwigFilter(
            'routeOfferToSite',
            function (string $codeCgt): string {
                return RouterPivot::getOfferUrl($codeCgt);
            }
        );
    }

    public static function cookieIsAuthorizedByName(): TwigFunction
    {
        return new TwigFunction(
            'cookieIsAuthorizedByName',
            function (string $name): bool {
                return CookieHelper::isAuthorizedByName($name);
            }
        );
    }

    public static function cookieHasSetPreferences(): TwigFunction
    {
        return new TwigFunction(
            'cookieHasSetPreferences',
            function (): bool {
                return CookieHelper::hasSetPreferences();
            }
        );
    }
}
