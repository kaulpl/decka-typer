<?php
if (!defined('ABSPATH')) exit;

class DT_Logger {
    public static function log(string $event, string $message, array $context = [], string $level = 'info', ?int $user_id = null): void {
        global $wpdb;
        $wpdb->insert(DT_DB::table('logs'), [
            'level' => sanitize_key($level),
            'event' => sanitize_key($event),
            'message' => wp_strip_all_tags($message),
            'context' => $context ? wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'user_id' => $user_id ?: (get_current_user_id() ?: null),
            'created_at' => current_time('mysql'),
        ], ['%s','%s','%s','%s','%d','%s']);
    }
}
