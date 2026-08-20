<?php
if (!defined('ABSPATH')) exit;

/**
 * Small admin-only OAuth diagnostic panel.
 * Shows the exact public values used by the plugin without exposing secrets.
 */
class DT_OAuth_Diagnostics {
    public static function register(): void {
        add_action('admin_notices', [__CLASS__, 'render']);
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) return;
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($page !== 'decka-typer-settings') return;

        $settings = DT_DB::settings();
        $clientId = trim((string) ($settings['google_client_id'] ?? ''));
        $redirect = DT_OAuth::callback_url('google');

        echo '<div class="notice notice-info" style="padding:14px 16px">';
        echo '<p style="margin:0 0 8px"><strong>Diagnostyka Google OAuth</strong></p>';
        echo '<p style="margin:4px 0">Redirect URI faktycznie wysyłany do Google: <code>' . esc_html($redirect) . '</code></p>';
        echo '<p style="margin:4px 0">Client ID faktycznie używany przez wtyczkę: <code>' . esc_html($clientId !== '' ? $clientId : 'BRAK') . '</code></p>';
        echo '<p style="margin:8px 0 0">W Google Cloud edytuj dokładnie klienta OAuth o powyższym <strong>Client ID</strong>. Typ klienta powinien być ustawiony jako <strong>Web application</strong>.</p>';
        echo '</div>';
    }
}
