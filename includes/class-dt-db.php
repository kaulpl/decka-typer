<?php
if (!defined('ABSPATH')) exit;

class DT_DB {
    public static function table(string $name): string {
        global $wpdb;
        return $wpdb->prefix . 'dt_' . $name;
    }

    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $sql = [];
        $sql[] = "CREATE TABLE " . self::table('teams') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            external_id VARCHAR(120) NULL,
            name VARCHAR(190) NOT NULL,
            short_name VARCHAR(100) NULL,
            slug VARCHAR(190) NOT NULL,
            logo_url TEXT NULL,
            source_url TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY external_id (external_id)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('rounds') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            season VARCHAR(20) NOT NULL,
            round_no INT NOT NULL,
            title VARCHAR(190) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            opens_at DATETIME NULL,
            closes_at DATETIME NULL,
            source VARCHAR(30) NOT NULL DEFAULT '1lm',
            external_key VARCHAR(190) NULL,
            last_synced_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY season_round (season, round_no),
            KEY status (status)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('matches') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            round_id BIGINT UNSIGNED NOT NULL,
            external_key VARCHAR(190) NULL,
            source_url TEXT NULL,
            home_team_id BIGINT UNSIGNED NOT NULL,
            away_team_id BIGINT UNSIGNED NOT NULL,
            starts_at DATETIME NULL,
            start_time_known TINYINT(1) NOT NULL DEFAULT 1,
            score_home SMALLINT NULL,
            score_away SMALLINT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'scheduled',
            manual_lock TINYINT(1) NOT NULL DEFAULT 0,
            featured TINYINT(1) NOT NULL DEFAULT 0,
            source_hash CHAR(40) NULL,
            last_synced_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY external_key (external_key),
            KEY round_id (round_id),
            KEY starts_at (starts_at),
            KEY manual_lock (manual_lock)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('predictions') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            match_id BIGINT UNSIGNED NOT NULL,
            home_score SMALLINT NOT NULL,
            away_score SMALLINT NOT NULL,
            points DECIMAL(8,2) NOT NULL DEFAULT 0,
            scoring_code VARCHAR(40) NULL,
            submitted_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_match (user_id, match_id),
            KEY match_id (match_id),
            KEY points (points)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('social_accounts') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(30) NOT NULL,
            provider_user_id VARCHAR(190) NOT NULL,
            email VARCHAR(190) NULL,
            created_at DATETIME NOT NULL,
            last_login_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY provider_user (provider, provider_user_id),
            KEY user_id (user_id)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('point_adjustments') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            season VARCHAR(20) NOT NULL,
            points DECIMAL(8,2) NOT NULL,
            reason VARCHAR(255) NOT NULL,
            admin_user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY season (season)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('logs') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            event VARCHAR(80) NOT NULL,
            message TEXT NOT NULL,
            context LONGTEXT NULL,
            user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY event (event),
            KEY created_at (created_at)
        ) $charset;";

        foreach ($sql as $statement) dbDelta($statement);

        add_option('dt_settings', self::defaults());
        update_option('dt_db_version', DT_VERSION);

        self::ensure_page();
        self::ensure_cron();
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        $timestamp = wp_next_scheduled('dt_sync_schedule');
        if ($timestamp) wp_unschedule_event($timestamp, 'dt_sync_schedule');
        flush_rewrite_rules();
    }

    public static function defaults(): array {
        return [
            'season' => '2026/2027',
            'source_url' => 'https://1lm.pzkosz.pl/terminarz-i-wyniki.html',
            'sync_enabled' => 1,
            'sync_interval' => 'hourly',
            'unknown_time_lock' => '00:00',
            'points_exact' => 5,
            'points_margin' => 3,
            'points_winner' => 1,
            'perfect_round_bonus' => 0,
            'show_community_picks_after_lock' => 1,
            'brand_primary' => '#1756A9',
            'brand_accent' => '#F47A24',
            'brand_surface' => '#F5F7FB',
            'google_client_id' => '',
            'google_client_secret' => '',
            'facebook_app_id' => '',
            'facebook_app_secret' => '',
            'apple_client_id' => '',
            'apple_team_id' => '',
            'apple_key_id' => '',
            'apple_private_key' => '',
            'typer_page_id' => 0,
        ];
    }

    public static function settings(): array {
        return wp_parse_args((array) get_option('dt_settings', []), self::defaults());
    }

    public static function ensure_page(): int {
        $settings = self::settings();
        if (!empty($settings['typer_page_id']) && get_post((int) $settings['typer_page_id'])) return (int) $settings['typer_page_id'];

        $page = get_page_by_path('typer');
        if ($page) {
            $id = (int) $page->ID;
        } else {
            $id = (int) wp_insert_post([
                'post_title' => 'Typer',
                'post_name' => 'typer',
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_content' => '[decka_typer]',
            ]);
        }
        if ($id > 0) {
            $settings['typer_page_id'] = $id;
            update_option('dt_settings', $settings);
        }
        return $id;
    }

    public static function ensure_cron(): void {
        if (!wp_next_scheduled('dt_sync_schedule')) {
            wp_schedule_event(time() + 300, 'hourly', 'dt_sync_schedule');
        }
    }
}
