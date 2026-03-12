<?php

declare(strict_types=1);

namespace VisitMarche\ThemeWp\Repository;

use wpdb;

class TranslationRepository
{
    private const string TABLE_NAME = 'visit_translations';
    private const string DB_VERSION = '1.0';
    private const string DB_VERSION_OPTION = 'visit_translation_db_version';

    private readonly string $table;

    public function __construct(
        private readonly wpdb $db,
    ) {
        $this->table = $this->db->prefix . self::TABLE_NAME;
    }

    public static function createTable(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            locale varchar(5) NOT NULL,
            source_text_hash varchar(32) NOT NULL,
            source_text longtext NOT NULL,
            translated_text longtext NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY locale_hash (locale, source_text_hash)
        ) $charsetCollate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    public static function needsUpgrade(): bool
    {
        return get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION;
    }

    public function findTranslation(string $text, string $locale): ?string
    {
        $hash = md5($text);

        $result = $this->db->get_var(
            $this->db->prepare(
                "SELECT translated_text FROM {$this->table} WHERE locale = %s AND source_text_hash = %s",
                $locale,
                $hash,
            ),
        );

        return is_string($result) ? $result : null;
    }

    public function saveTranslation(string $text, string $translatedText, string $locale): void
    {
        $hash = md5($text);

        $this->db->replace(
            $this->table,
            [
                'locale' => $locale,
                'source_text_hash' => $hash,
                'source_text' => $text,
                'translated_text' => $translatedText,
                'updated_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s'],
        );
    }

    public function deleteTranslation(string $text, string $locale): void
    {
        $hash = md5($text);

        $this->db->delete(
            $this->table,
            [
                'locale' => $locale,
                'source_text_hash' => $hash,
            ],
            ['%s', '%s'],
        );
    }

    public function truncate(): void
    {
        $this->db->query("TRUNCATE TABLE {$this->table}");
    }
}
