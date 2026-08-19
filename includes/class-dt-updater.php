<?php
if (!defined('ABSPATH')) exit;

class DT_Updater {
    private const REPOSITORY = 'kaulpl/decka-typer';
    private const VERSION_URL = 'https://raw.githubusercontent.com/kaulpl/decka-typer/main/VERSION';
    private const UPDATE_URI = 'https://github.com/kaulpl/decka-typer';
    private const CACHE_KEY = 'dt_github_latest_version';
    private const LEGACY_CACHE_KEY = 'dt_github_latest_release';
    private const CHECK_NONCE = 'dt_check_updates';

    public static function register(): void {
        add_filter('update_plugins_github.com', [__CLASS__, 'check_update'], 10, 4);
        add_action('upgrader_process_complete', [__CLASS__, 'clear_cache_after_upgrade'], 10, 2);
        add_action('admin_post_dt_check_updates', [__CLASS__, 'handle_manual_check']);
        add_action('admin_notices', [__CLASS__, 'dashboard_update_panel']);
    }

    public static function check_update($update, array $plugin_data, string $plugin_file, array $locales) {
        if ($plugin_file !== plugin_basename(DT_FILE)) return $update;
        $status = self::status(false);
        if (empty($status['update_available']) || empty($status['package'])) return false;
        return [
            'id'=>self::UPDATE_URI,'slug'=>'decka-typer','version'=>$status['latest_version'],
            'new_version'=>$status['latest_version'],'url'=>$status['release_url'] ?: self::UPDATE_URI,
            'package'=>$status['package'],'requires'=>'6.5','requires_php'=>'8.0','tested'=>get_bloginfo('version'),
        ];
    }

    public static function status(bool $force = false): array {
        $fetched = self::latest_version($force);
        $version = $fetched['version'];
        $status = [
            'current_version'=>DT_VERSION,'latest_version'=>$version ?: DT_VERSION,
            'update_available'=>false,'package_ready'=>false,'package'=>null,
            'release_url'=>self::UPDATE_URI . '/releases','checked_at'=>$fetched['checked_at'],
            'error'=>$fetched['error'],
        ];
        if (!$version || !self::valid_version($version)) return $status;
        $status['release_url'] = self::release_url($version);
        if (version_compare($version, DT_VERSION, '>')) {
            $status['package'] = self::package_url($version);
            $status['package_ready'] = true;
            $status['update_available'] = true;
        }
        return $status;
    }

    public static function update_url(?string $version = null): string {
        $pluginFile = plugin_basename(DT_FILE);
        return wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($pluginFile)), 'upgrade-plugin_' . $pluginFile);
    }

    public static function check_url(): string {
        return wp_nonce_url(admin_url('admin-post.php?action=dt_check_updates'), self::CHECK_NONCE);
    }

    public static function handle_manual_check(): void {
        if (!current_user_can('update_plugins')) wp_die('Brak uprawnień do aktualizacji wtyczek.');
        check_admin_referer(self::CHECK_NONCE);
        self::clear_cache();
        delete_site_transient('update_plugins');
        wp_clean_plugins_cache(true);
        wp_update_plugins();
        $status = self::status(true);
        if (!empty($status['update_available'])) {
            $message = 'Dostępna jest wersja ' . $status['latest_version'] . '. Możesz ją teraz zainstalować.'; $type = 'success';
        } elseif (!empty($status['error'])) {
            $message = 'Sprawdzenie aktualizacji: ' . $status['error']; $type = 'error';
        } else {
            $message = 'Decka Typer jest aktualny. Zainstalowana wersja: ' . DT_VERSION . '.'; $type = 'success';
        }
        wp_safe_redirect(add_query_arg(['page'=>'decka-typer','dt_notice'=>$message,'dt_type'=>$type], admin_url('admin.php')));
        exit;
    }

    public static function dashboard_update_panel(): void {
        if (!current_user_can('update_plugins')) return;
        if (empty($_GET['page']) || sanitize_key((string) $_GET['page']) !== 'decka-typer') return;
        $status = self::status(false);
        $available = !empty($status['update_available']);
        $error = (string) ($status['error'] ?? '');
        $checked = (string) ($status['checked_at'] ?? '');
        echo '<div class="dt-update-panel ' . ($available ? 'is-update' : '') . '">';
        echo '<div class="dt-update-icon"><span class="dashicons dashicons-update-alt"></span></div><div class="dt-update-copy"><span class="dt-eyebrow">AKTUALIZACJE</span>';
        if ($available) {
            echo '<h2>Dostępna wersja ' . esc_html($status['latest_version']) . '</h2><p>Zainstalowana: <strong>' . esc_html(DT_VERSION) . '</strong>. Pakiet aktualizacji jest gotowy do pobrania z GitHub Release.</p>';
        } elseif ($error) {
            echo '<h2>Nie udało się sprawdzić wersji</h2><p>' . esc_html($error) . '</p>';
        } else {
            echo '<h2>Decka Typer jest aktualny</h2><p>Zainstalowana wersja: <strong>' . esc_html(DT_VERSION) . '</strong>' . ($checked ? ' · sprawdzono ' . esc_html(self::format_checked_at($checked)) : '') . '.</p>';
        }
        echo '</div><div class="dt-update-actions">';
        if ($available) {
            echo '<a class="button button-primary dt-button dt-update-button" href="' . esc_url(self::update_url($status['latest_version'])) . '"><span class="dashicons dashicons-update"></span> Aktualizuj do wersji ' . esc_html($status['latest_version']) . '</a>';
            echo '<a class="button dt-update-secondary" href="' . esc_url(self::check_url()) . '">Sprawdź ponownie</a>';
        } else {
            echo '<a class="button button-primary dt-button dt-update-button" href="' . esc_url(self::check_url()) . '"><span class="dashicons dashicons-update-alt"></span> Sprawdź aktualizacje</a>';
        }
        echo '</div></div>';
    }

    private static function latest_version(bool $force): array {
        if (!$force) {
            $cached = get_site_transient(self::CACHE_KEY);
            if (is_array($cached) && array_key_exists('version', $cached)) return $cached;
        }
        $url = self::VERSION_URL;
        if ($force) $url = add_query_arg('dt_check', (string) time(), $url);
        $response = wp_remote_get($url, [
            'timeout'=>10,'redirection'=>3,
            'headers'=>['Accept'=>'text/plain','Cache-Control'=>$force ? 'no-cache' : 'max-age=0','User-Agent'=>'Decka-Typer/' . DT_VERSION . '; ' . home_url('/')],
        ]);
        $checkedAt = current_time('mysql');
        if (is_wp_error($response)) {
            $result=['version'=>null,'checked_at'=>$checkedAt,'error'=>'Nie udało się połączyć z serwerem wersji: ' . $response->get_error_message()];
            set_site_transient(self::CACHE_KEY,$result,3*MINUTE_IN_SECONDS); return $result;
        }
        $code=(int)wp_remote_retrieve_response_code($response);
        if ($code!==200) {
            $result=['version'=>null,'checked_at'=>$checkedAt,'error'=>'Serwer wersji zwrócił HTTP ' . $code . '.'];
            set_site_transient(self::CACHE_KEY,$result,3*MINUTE_IN_SECONDS); return $result;
        }
        $version=trim((string)wp_remote_retrieve_body($response));
        if (!self::valid_version($version)) {
            $result=['version'=>null,'checked_at'=>$checkedAt,'error'=>'Serwer zwrócił nieprawidłowy numer wersji.'];
            set_site_transient(self::CACHE_KEY,$result,3*MINUTE_IN_SECONDS); return $result;
        }
        $result=['version'=>$version,'checked_at'=>$checkedAt,'error'=>''];
        set_site_transient(self::CACHE_KEY,$result,15*MINUTE_IN_SECONDS); return $result;
    }

    private static function package_url(string $version): string {
        return 'https://github.com/' . self::REPOSITORY . '/releases/download/v' . rawurlencode($version) . '/decka-typer-' . rawurlencode($version) . '.zip';
    }
    private static function release_url(string $version): string {
        return 'https://github.com/' . self::REPOSITORY . '/releases/tag/v' . rawurlencode($version);
    }
    private static function valid_version(string $version): bool {
        return (bool) preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version);
    }
    private static function format_checked_at(string $value): string {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,wp_timezone());
        return $date ? $date->format('d.m.Y H:i') : $value;
    }
    public static function clear_cache(): void {
        delete_site_transient(self::CACHE_KEY); delete_site_transient(self::LEGACY_CACHE_KEY);
    }
    public static function clear_cache_after_upgrade($upgrader, array $options): void {
        if (($options['action']??'')!=='update' || ($options['type']??'')!=='plugin') return;
        $plugins=(array)($options['plugins']??[]);
        if (($options['plugin']??'')===plugin_basename(DT_FILE) || in_array(plugin_basename(DT_FILE),$plugins,true)) self::clear_cache();
    }
}
