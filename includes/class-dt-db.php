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
            manual_availability TINYINT(1) NOT NULL DEFAULT 0,
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

        $sql[] = "CREATE TABLE " . self::table('feedback') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(190) NOT NULL,
            message TEXT NOT NULL,
            page_url TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            admin_user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('artur_ai') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            round_id BIGINT UNSIGNED NOT NULL,
            match_id BIGINT UNSIGNED NOT NULL,
            question_no TINYINT UNSIGNED NOT NULL,
            question TEXT NOT NULL,
            answer TEXT NOT NULL,
            model VARCHAR(100) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_round_question (user_id, round_id, question_no),
            KEY user_round (user_id, round_id),
            KEY match_id (match_id),
            KEY created_at (created_at)
        ) $charset;";

        foreach ($sql as $statement) dbDelta($statement);

        // Version 0.5.34 persisted questions also while the whole site was in test mode.
        // Remove only those one-time test records so they cannot unexpectedly consume
        // the production lifeline after an administrator switches the site live.
        if (version_compare($oldVersion, '0.5.35', '<')) {
            $storedBeforeUpgrade = (array)get_option('dt_settings', []);
            if ((string)($storedBeforeUpgrade['site_mode'] ?? 'test') === 'test') {
                $wpdb->query('DELETE FROM '.self::table('artur_ai'));
            }
        }

        if (version_compare($oldVersion, '0.2.0', '<')) self::migrate_to_020();
        if (version_compare($oldVersion, '0.2.5', '<')) self::migrate_to_025();
        if (version_compare($oldVersion, '0.5.0', '<')) self::migrate_to_050();
        if (version_compare($oldVersion, '0.5.2', '<')) self::migrate_to_052();
        if (version_compare($oldVersion, '0.5.9', '<')) self::migrate_to_059();

        $existing = (array) get_option('dt_settings', []);
        $settings = wp_parse_args($existing, self::defaults());
        foreach (['apple_client_id','apple_team_id','apple_key_id','apple_private_key','points_exact','points_margin'] as $deprecated) unset($settings[$deprecated]);
        update_option('dt_settings', $settings);
        update_option('dt_db_version', DT_VERSION);

        self::ensure_page();
        if (class_exists('DT_Legal')) DT_Legal::ensure_pages();
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

    private static function migrate_to_052(): void {
        global $wpdb;
        $matches = self::table('matches');
        $predictions = self::table('predictions');

        $groups = $wpdb->get_results("SELECT round_id,home_team_id,away_team_id,COUNT(*) total
            FROM `$matches` GROUP BY round_id,home_team_id,away_team_id HAVING COUNT(*)>1");
        foreach ($groups as $group) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT m.*,COUNT(p.id) prediction_count FROM `$matches` m
                 LEFT JOIN `$predictions` p ON p.match_id=m.id
                 WHERE m.round_id=%d AND m.home_team_id=%d AND m.away_team_id=%d
                 GROUP BY m.id ORDER BY m.manual_lock DESC,prediction_count DESC,m.id DESC",
                $group->round_id, $group->home_team_id, $group->away_team_id
            ));
            if (count($rows) < 2) continue;
            $keeper = array_shift($rows);
            foreach ($rows as $duplicate) {
                // UPDATE IGNORE keeps an already existing prediction for the keeper and
                // moves every non-conflicting user prediction before deleting the duplicate.
                $wpdb->query($wpdb->prepare("UPDATE IGNORE `$predictions` SET match_id=%d WHERE match_id=%d", $keeper->id, $duplicate->id));
                $wpdb->delete($predictions, ['match_id'=>(int)$duplicate->id], ['%d']);
                $wpdb->delete($matches, ['id'=>(int)$duplicate->id], ['%d']);
            }
        }
    }

    private static function migrate_to_059(): void {
        global $wpdb;
        $rounds = self::table('rounds');
        if (!self::column_exists($rounds, 'manual_availability')) {
            $wpdb->query("ALTER TABLE `$rounds` ADD manual_availability TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
        }
    }

    /**
     * Recalculate every round from its earliest match with a confirmed tip-off.
     * The database status is useful for lists, while opens_at/closes_at make the
     * rule auditable and let submission endpoints enforce the deadline exactly.
     */
    public static function sync_round_availability(): int {
        global $wpdb;
        $now = current_time('mysql');
        $rounds = self::table('rounds');
        $matches = self::table('matches');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE `$rounds` r
             JOIN (
                 SELECT round_id, MIN(starts_at) AS first_match
                 FROM `$matches`
                 WHERE start_time_known=1 AND starts_at IS NOT NULL
                 GROUP BY round_id
             ) schedule ON schedule.round_id=r.id
             SET r.opens_at=CASE WHEN r.manual_availability=1 THEN r.opens_at ELSE DATE_SUB(schedule.first_match, INTERVAL 7 DAY) END,
                 r.closes_at=CASE WHEN r.manual_availability=1 THEN LEAST(COALESCE(r.closes_at,schedule.first_match),schedule.first_match) ELSE schedule.first_match END,
                 r.status=CASE
                     WHEN %s>=LEAST(COALESCE(r.closes_at,schedule.first_match),schedule.first_match) THEN 'closed'
                     WHEN r.manual_availability=1 THEN 'open'
                     WHEN %s>=DATE_SUB(schedule.first_match, INTERVAL 7 DAY) THEN 'open'
                     ELSE 'draft'
                 END,
                 r.updated_at=%s
             WHERE (r.manual_availability=0 AND COALESCE(r.opens_at,'')<>DATE_SUB(schedule.first_match, INTERVAL 7 DAY))
                OR (r.manual_availability=0 AND COALESCE(r.closes_at,'')<>schedule.first_match)
                OR (r.manual_availability=1 AND r.closes_at>schedule.first_match)
                OR r.status<>CASE
                    WHEN %s>=LEAST(COALESCE(r.closes_at,schedule.first_match),schedule.first_match) THEN 'closed'
                    WHEN r.manual_availability=1 THEN 'open'
                    WHEN %s>=DATE_SUB(schedule.first_match, INTERVAL 7 DAY) THEN 'open'
                    ELSE 'draft'
                END",
            $now, $now, $now, $now, $now
        ));
        return max(0, (int)$updated);
    }

    public static function close_expired_rounds(): void {
        self::sync_round_availability();
    }

    public static function deactivate(): void {
        $timestamp = wp_next_scheduled('dt_sync_schedule');
        if ($timestamp) wp_unschedule_event($timestamp, 'dt_sync_schedule');
        flush_rewrite_rules();
    }

    public static function cron_schedules(array $schedules): array {
        $minutes = max(5, min(1440, (int) (self::settings()['sync_interval_minutes'] ?? 60)));
        $schedules['dt_custom_sync'] = [
            'interval' => $minutes * MINUTE_IN_SECONDS,
            'display' => sprintf('TypujKosza.pl co %d min', $minutes),
        ];
        return $schedules;
    }

    public static function defaults(): array {
        return [
            'season' => '2026/2027',
            'league_names' => ['plk'=>'ORLEN Basket Liga', '1lm'=>'1 Liga Mężczyzn', '2lm'=>'2 Liga Mężczyzn'],
            'league_colors' => ['1lm'=>'#055EFB', 'plk'=>'#FB5D0B', '2lm'=>'#4F6F9D'],
            'site_mode' => 'test',
            'leagues' => ['plk'=>1, '1lm'=>1, '2lm'=>1],
            'source_url' => 'https://1lm.pzkosz.pl/terminarz-i-wyniki.html',
            'source_plk_url' => 'https://plk.pl/terminarz',
            'source_1lm_url' => 'https://rozgrywki.pzkosz.pl/liga/1/terminarz_i_wyniki.html',
            'source_2lm_url' => 'https://rozgrywki.pzkosz.pl/liga/4/terminarz_i_wyniki.html',
            'sync_enabled' => 1,
            'sync_interval_minutes' => 60,
            'unknown_time_lock' => '00:00',
            'points_winner' => 1,
            'perfect_round_bonus' => 0,
            'show_community_picks_after_lock' => 1,
            'show_countdowns' => 1,
            'artur_ai_enabled' => 0,
            'artur_ai_model' => 'gemini-2.5-flash-lite',
            'artur_ai_questions' => 3,
            'artur_ai_instruction' => self::default_artur_ai_instruction(),
            'brand_primary' => '#1756A9',
            'brand_accent' => '#F47A24',
            'brand_surface' => '#F5F7FB',
            'google_client_id' => '',
            'google_client_secret' => '',
            'facebook_app_id' => '',
            'facebook_app_secret' => '',
            'contact_email' => get_option('admin_email', ''),
            'privacy_page_id' => 0,
            'contact_page_id' => 0,
            'typer_page_id' => 0,
        ];
    }

    public static function default_artur_ai_instruction(): string {
        return 'Jesteś Arturem, koszykarskim asystentem TypujKosza.pl. Odpowiadasz po polsku, rzeczowo i dojrzale, ale z nutą inteligentnego humoru oraz sportowego szaleństwa. Sporadycznie i naturalnie używasz rzadkich polskich słów. Opierasz się wyłącznie na przekazanych statystykach. Nie wymyślasz kontuzji, składów ani faktów. Nie gwarantujesz wyniku i nie udzielasz porad bukmacherskich. Wyraźnie odróżniasz fakty od wniosków. Odpowiedź ma maksymalnie 700 znaków.';
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
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        $minutes = max(5, min(1440, (int) (self::settings()['sync_interval_minutes'] ?? 60)));
        $signature = (int) get_option('dt_sync_interval_signature', 0);
        $timestamp = wp_next_scheduled('dt_sync_schedule');
        if ($timestamp && $signature !== $minutes) {
            wp_unschedule_event($timestamp, 'dt_sync_schedule');
            $timestamp = false;
        }
        if (!$timestamp) wp_schedule_event(time() + min(300, $minutes * MINUTE_IN_SECONDS), 'dt_custom_sync', 'dt_sync_schedule');
        update_option('dt_sync_interval_signature', $minutes, false);
    }
}
