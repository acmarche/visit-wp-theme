<?php

namespace VisitMarche\ThemeWp\Lib;

class CookieHelper
{
    const COOKIE_PREFERENCES = 'cookiePreferences';
    public static string $essential = 'essential';
    public static string $analytics = 'analytics';
    public static string $encapsulated = 'encapsulated';

    public static function getAll(): array
    {
        if (!isset($_COOKIE[self::COOKIE_PREFERENCES])) {
            return [];
        }

        $decoded = json_decode(urldecode($_COOKIE[self::COOKIE_PREFERENCES]), true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function isAuthorizedByName(string $name): bool
    {
        $preferences = self::getAll();

        return isset($preferences[$name]) && $preferences[$name] === true;
    }

    public static function hasSetPreferences(): bool
    {
        return !(count(self::getAll()) == 0);
    }

    public static function setByName(string $name, bool $value): void
    {
        $preferences = self::getAll();
        $preferences[$name] = $value;
        self::saveAll($preferences);
    }

    public static function saveAll(array $preferences): void
    {
        // Set cookie for 365 days
        $expiry = time() + (365 * 24 * 60 * 60);
        @setcookie(
            self::COOKIE_PREFERENCES,
            self::encodeData($preferences),
            $expiry,
            '/',
            '',
            true, // Secure (HTTPS only)
            false  // HttpOnly - set to false so JavaScript can read it
        );

        // Also update $_COOKIE for immediate availability in current request
        $_COOKIE[self::COOKIE_PREFERENCES] = self::encodeData($preferences);
    }

    public static function createCookie(array $data = []): void
    {
        if (!isset($_COOKIE[self::COOKIE_PREFERENCES])) {
            self::saveAll($data);
        }
    }

    private static function encodeData(array $data): string
    {
        return urlencode(json_encode($data));
    }

    public static function reset(): void
    {
        self::saveAll([]);
    }
}
