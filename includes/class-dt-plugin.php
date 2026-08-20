<?php
if (!defined('ABSPATH')) exit;

class DT_Plugin {
    private static ?DT_Plugin $instance = null;

    public static function instance(): DT_Plugin {
        return self::$instance ??= new self();
    }

    public function boot(): void {
        // Run schema/data migrations immediately after a plugin update, also on frontend requests.
        if ((string) get_option('dt_db_version', '') !== DT_VERSION) {
            DT_DB::activate();
        }
        DT_DB::close_expired_rounds();
        DT_Sync::register();
        DT_OAuth::register();
        DT_REST::register();
        DT_Admin::register();
        DT_Frontend::register();
        DT_Updater::register();
        add_filter('plugin_action_links_' . plugin_basename(DT_FILE), [$this, 'links']);
    }

    public function links(array $links): array {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=decka-typer')) . '">Pulpit TypujKosza.pl</a>');
        return $links;
    }
}
