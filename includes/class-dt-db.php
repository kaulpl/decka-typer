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
        $oldVersion = (string) get_option('dt_db_version', '0.0.0');

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
            league_key VARCHAR(20) NOT NULL DEFAULT '1lm',
            group_key VARCHAR(40) NOT NULL DEFAULT '',
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
            UNIQUE KEY league_season_group_round (league_key, season, group_key, round_no),
            KEY league_season (league_key, season),
            KEY status (status),
            KEY closes_at (closes_at)
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

        // User prediction = selected winner only. Real match scores live in dt_matches.
        // Keep selected_team_id nullable in dbDelta so upgrades from legacy rows are safe;
        // migrate_to_025 removes incomplete legacy rows and makes the column NOT NULL.
        $sql[] = "CREATE TABLE " . self::table('predictions') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            match_id BIGINT UNSIGNED NOT NULL,
            selected_team_id BIGINT UNSIGNED NULL,
            points DECIMAL(8,2) NOT NULL DEFAULT 0,
            scoring_code VARCHAR(40) NULL,
            submitted_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_match (user_id, match_id),
            KEY match_id (match_id),
            KEY selected_team_id (selected_team_id),
            KEY points (points)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('round_submissions') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            round_id BIGINT UNSIGNED NOT NULL,
            prediction_count INT NOT NULL DEFAULT 0,
            submitted_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_round (user_id, round_id),
            KEY round_id (round_id),
            KEY submitted_at (submitted_at)
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

        if (version_compare($oldVersion, '0.2.0', '<')) self::migrate_to_020();
        if (version_compare($oldVersion, '0.2.5', '<')) self::migrate_to_025();
        if (version_compare($oldVersion, '0.5.0', '<')) self::migrate_to_050();

        $existing = (array) get_option('dt_settings', []);
        $settings = wp_parse_args($existing, self::defaults());
        foreach (['apple_client_id','apple_team_id','apple_key_id','apple_private_key','points_exact','points_margin'] as $deprecated) unset($settings[$deprecated]);
        update_option('dt_settings', $settings);
        update_option('dt_db_version', DT_VERSION);

        self::ensure_page();
        self::ensure_cron();
        flush_rewrite_rules();
    }

    private static function column_exists(string $table, string $column): bool {
        global $wpdb;
        $result = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$table` LIKE %s", $column));
        return !empty($result);
    }

    private static function migrate_to_020(): void {
        global $wpdb;
        $pred = self::table('predictions');
        $mat = self::table('matches');
        $rnd = self::table('rounds');
        $sub = self::table('round_submissions');

        // One-time conversion for installations that genuinely came from the old score-prediction model.
        if (self::column_exists($pred, 'home_score') && self::column_exists($pred, 'away_score')) {
            $wpdb->query("UPDATE $pred p JOIN $mat m ON m.id=p.match_id
                SET p.selected_team_id = CASE
                    WHEN p.home_score > p.away_score THEN m.home_team_id
                    WHEN p.away_score > p.home_score THEN m.away_team_id
                    ELSE NULL END
                WHERE p.selected_team_id IS NULL AND p.home_score IS NOT NULL AND p.away_score IS NOT NULL");
        }

        if (class_exists('DT_Scoring')) {
            $finished = $wpdb->get_col("SELECT id FROM $mat WHERE score_home IS NOT NULL AND score_away IS NOT NULL");
            foreach ($finished as $matchId) DT_Scoring::recalc_match((int) $matchId);
        }

        $wpdb->query("UPDATE $rnd SET status='draft', opens_at=NULL, closes_at=NULL WHERE status='published'");
        $wpdb->query("INSERT IGNORE INTO $sub (user_id, round_id, prediction_count, submitted_at)
            SELECT p.user_id, m.round_id, COUNT(*), MIN(p.submitted_at)
            FROM $pred p JOIN $mat m ON m.id=p.match_id
            WHERE p.selected_team_id IS NOT NULL
            GROUP BY p.user_id, m.round_id
            HAVING COUNT(*) = (SELECT COUNT(*) FROM $mat mm WHERE mm.round_id=m.round_id)");
    }

    private static function migrate_to_025(): void {
        global $wpdb;
        $pred = self::table('predictions');

        // Winner-only model: legacy predicted-score columns are removed permanently.
        // Actual basketball results remain only in dt_matches.score_home / score_away.
        foreach (['home_score', 'away_score'] as $column) {
            if (self::column_exists($pred, $column)) $wpdb->query("ALTER TABLE `$pred` DROP COLUMN `$column`");
        }

        $wpdb->query("DELETE FROM `$pred` WHERE selected_team_id IS NULL");
        $wpdb->query("ALTER TABLE `$pred` MODIFY selected_team_id BIGINT UNSIGNED NOT NULL");
    }

    private static function migrate_to_050(): void {
        global $wpdb;
        $rounds = self::table('rounds');
        if (self::column_exists($rounds, 'league_key')) {
            $wpdb->query("UPDATE `$rounds` SET league_key='1lm' WHERE league_key='' OR league_key IS NULL");
            $wpdb->query("ALTER TABLE `$rounds` DROP INDEX season_round");
        }
    }

    public static function close_expired_rounds(): void {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table('rounds') . " SET status='closed', updated_at=%s WHERE status='open' AND closes_at IS NOT NULL AND closes_at<=%s",
            $now, $now
        ));
    }

    public static function deactivate(): void {
        $timestamp = wp_next_scheduled('dt_sync_schedule');
        if ($timestamp) wp_unschedule_event($timestamp, 'dt_sync_schedule');
        flush_rewrite_rules();
    }

    public static function defaults(): array {
        return [
            'season' => '2026/2027',
            'league_name' => 'PEKAO S.A. 1 LIGA',
            'site_mode' => 'test',
            'leagues' => ['plk'=>1, '1lm'=>1, '2lm'=>1],
            'source_url' => 'https://1lm.pzkosz.pl/terminarz-i-wyniki.html',
            'source_plk_url' => 'https://plk.pl/terminarz',
            'source_1lm_url' => 'https://rozgrywki.pzkosz.pl/liga/1/terminarz_i_wyniki.html',
            'source_2lm_url' => 'https://rozgrywki.pzkosz.pl/liga/4/terminarz_i_wyniki.html',
            'sync_enabled' => 1,
            'sync_interval' => 'hourly',
            'unknown_time_lock' => '00:00',
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
        if (!wp_next_scheduled('dt_sync_schedule')) wp_schedule_event(time() + 300, 'hourly', 'dt_sync_schedule');
    }
}
