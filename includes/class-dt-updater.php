<?php
if (!defined('ABSPATH')) exit;

class DT_Updater {
    private const REPOSITORY = 'kaulpl/decka-typer';
    private const API_URL = 'https://api.github.com/repos/kaulpl/decka-typer/releases/latest';
    private const UPDATE_URI = 'https://github.com/kaulpl/decka-typer';
    private const CACHE_KEY = 'dt_github_latest_release';
    private const CHECK_NONCE = 'dt_check_updates';

    public static function register(): void {
        add_filter('update_plugins_github.com', [__CLASS__, 'check_update'], 10, 4);
        add_action('upgrader_process_complete', [__CLASS__, 'clear_cache_after_upgrade'], 10, 2);
        add_action('admin_post_dt_check_updates', [__CLASS__, 'handle_manual_check']);
        add_action('admin_notices', [__CLASS__, 'dashboard_update_panel']);
    }

    /** Feed WordPress' native plugin updater with the latest stable GitHub Release. */
    public static function check_update($update, array $plugin_data, string $plugin_file, array $locales) {
        if ($plugin_file !== plugin_basename(DT_FILE)) return $update;

        $status = self::status(false);
        if (empty($status['update_available']) || empty($status['package'])) return false;

        return [
            'id'           => self::UPDATE_URI,
            'slug'         => 'decka-typer',
            'version'      => $status['latest_version'],
            'new_version'  => $status['latest_version'],
            'url'          => $status['release_url'] ?: self::UPDATE_URI,
            'package'      => $status['package'],
            'requires'     => '6.5',
            'requires_php' => '8.0',
            'tested'       => get_bloginfo('version'),
        ];
    }

    /** Public updater state used by the Decka Typer dashboard. */
    public static function status(bool $force = false): array {
        $fetched = self::latest_release($force);
        $release = $fetched['release'];
        $checkedAt = $fetched['checked_at'];
        $error = $fetched['error'];

        $status = [
            'current_version' => DT_VERSION,
            'latest_version' => DT_VERSION,
            'update_available' => false,
            'package_ready' => false,
            'package' => null,
            'release_url' => self::UPDATE_URI,
            'checked_at' => $checkedAt,
            'error' => $error,
        ];

        if (!$release || empty($release['tag_name'])) return $status;

        $version = ltrim((string) $release['tag_name'], 'vV');
        if (!self::valid_version($version)) {
            $status['error'] = 'GitHub zwrócił nieprawidłowy numer wersji.';
            return $status;
        }

        $package = self::release_package($release, $version);
        $status['latest_version'] = $version;
        $status['release_url'] = esc_url_raw((string) ($release['html_url'] ?? self::UPDATE_URI));
        $status['package'] = $package;
        $status['package_ready'] = (bool) $package;
        $status['update_available'] = version_compare($version, DT_VERSION, '>') && (bool) $package;

        if (version_compare($version, DT_VERSION, '>') && !$package) {
            $status['error'] = 'Wykryto wersję ' . $version . ', ale Release nie zawiera instalacyjnego ZIP-a.';
        }

        return $status;
    }

    public static function update_url(?string $version = null): string {
        $pluginFile = plugin_basename(DT_FILE);
        return wp_nonce_url(
            self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($pluginFile)),
            'upgrade-plugin_' . $pluginFile
        );
    }

    public static function check_url(): string {
        return wp_nonce_url(
            admin_url('admin-post.php?action=dt_check_updates'),
            self::CHECK_NONCE
        );
    }

    /** Render the update control only on Decka Typer's main dashboard. */
    public static function dashboard_update_panel(): void {
        if (!is_admin() || !current_user_can('update_plugins')) return;
        if (sanitize_key($_GET['page'] ?? '') !== 'decka-typer') return;

        echo '<div class="wrap dt-admin dt-update-dashboard" style="margin-top:14px;margin-bottom:0">';
        self::dashboard_card();
        echo '</div>';
    }

    public static function dashboard_card(): void {
        if (!current_user_can('update_plugins')) return;
        $status = self::status(false);

        echo '<section class="dt-card dt-update-card" style="border-left:4px solid var(--dt-blue);display:grid;grid-template-columns:minmax(0,1fr) auto;gap:18px;align-items:center">';
        echo '<div><span class="dt-eyebrow">AKTUALIZACJE</span><h2 style="margin-bottom:8px">Decka Typer ' . esc_html($status['current_version']) . '</h2>';

        if (!empty($status['update_available'])) {
            echo '<div class="dt-update-state is-update" style="display:flex;align-items:center;gap:9px;color:#117452"><span class="dashicons dashicons-update-alt"></span><div><strong>Dostępna wersja ' . esc_html($status['latest_version']) . '</strong><div class="dt-muted">GitHub Release zawiera gotową paczkę instalacyjną.</div></div></div>';
        } elseif (!empty($status['error'])) {
            echo '<div class="dt-update-state is-error" style="display:flex;align-items:center;gap:9px;color:#b72f46"><span class="dashicons dashicons-warning"></span><div><strong>Nie udało się potwierdzić aktualizacji</strong><div class="dt-muted">' . esc_html($status['error']) . '</div></div></div>';
        } else {
            echo '<div class="dt-update-state is-current" style="display:flex;align-items:center;gap:9px;color:#117452"><span class="dashicons dashicons-yes-alt"></span><div><strong>Masz aktualną wersję</strong><div class="dt-muted">Najnowszy GitHub Release: ' . esc_html($status['latest_version']) . (!empty($status['checked_at']) ? ' · sprawdzono ' . esc_html(self::format_checked_at($status['checked_at'])) : '') . '</div></div></div>';
        }
        echo '</div>';

        if (!empty($status['update_available'])) {
            echo '<a class="button button-primary dt-button" href="' . esc_url(self::update_url($status['latest_version'])) . '"><span class="dashicons dashicons-update"></span> Aktualizuj do wersji ' . esc_html($status['latest_version']) . '</a>';
        } else {
            echo '<a class="button dt-button" href="' . esc_url(self::check_url()) . '"><span class="dashicons dashicons-search"></span> Sprawdź aktualizacje</a>';
        }
        echo '</section>';
    }

    public static function handle_manual_check(): void {
        if (!current_user_can('update_plugins')) wp_die('Brak uprawnień do aktualizacji wtyczek.');
        check_admin_referer(self::CHECK_NONCE);

        self::clear_all_update_caches();

        if (!function_exists('wp_update_plugins')) require_once ABSPATH . WPINC . '/update.php';
        wp_update_plugins();

        $status = self::status(false);
        if (!empty($status['update_available'])) {
            $message = 'Dostępna jest aktualizacja Decka Typer do wersji ' . $status['latest_version'] . '.';
            $type = 'success';
        } elseif (!empty($status['error'])) {
            $message = 'Sprawdzenie aktualizacji: ' . $status['error'];
            $type = 'error';
        } else {
            $message = 'Decka Typer ' . DT_VERSION . ' jest aktualny.';
            $type = 'success';
        }

        wp_safe_redirect(add_query_arg([
            'page'=>'decka-typer',
            'dt_notice'=>$message,
            'dt_type'=>$type,
        ], admin_url('admin.php')));
        exit;
    }

    /** @return array{release:?array,checked_at:?string,error:?string} */
    private static function latest_release(bool $force = false): array {
        if ($force) delete_site_transient(self::CACHE_KEY);

        if (!$force) {
            $cached = get_site_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                // 0.2.0 cached the release object directly. Accept it during migration.
                if (isset($cached['tag_name'])) {
                    return ['release'=>$cached, 'checked_at'=>null, 'error'=>null];
                }
                if (array_key_exists('release', $cached)) {
                    return [
                        'release'=>is_array($cached['release']) ? $cached['release'] : null,
                        'checked_at'=>isset($cached['checked_at']) ? (string) $cached['checked_at'] : null,
                        'error'=>isset($cached['error']) && $cached['error'] ? (string) $cached['error'] : null,
                    ];
                }
            }
        }

        $checkedAt = current_time('mysql');
        $response = wp_remote_get(self::API_URL, [
            'timeout' => 12,
            'redirection' => 3,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'Decka-Typer/' . DT_VERSION . '; ' . home_url('/'),
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);

        if (is_wp_error($response)) {
            return self::cache_result(null, $checkedAt, $response->get_error_message(), 5 * MINUTE_IN_SECONDS);
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $message = 'GitHub API zwróciło HTTP ' . $code . '.';
            if ($code === 403 || $code === 429) $message .= ' Możliwy limit zapytań API — spróbuj ponownie później.';
            return self::cache_result(null, $checkedAt, $message, 5 * MINUTE_IN_SECONDS);
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || !empty($data['draft']) || !empty($data['prerelease'])) {
            return self::cache_result(null, $checkedAt, 'Nie znaleziono stabilnego GitHub Release.', 5 * MINUTE_IN_SECONDS);
        }

        return self::cache_result($data, $checkedAt, null, 15 * MINUTE_IN_SECONDS);
    }

    private static function cache_result(?array $release, string $checkedAt, ?string $error, int $ttl): array {
        $payload = ['release'=>$release, 'checked_at'=>$checkedAt, 'error'=>$error];
        set_site_transient(self::CACHE_KEY, $payload, $ttl);
        return $payload;
    }

    private static function release_package(array $release, string $version): ?string {
        $expected = 'decka-typer-' . $version . '.zip';
        foreach ((array) ($release['assets'] ?? []) as $asset) {
            if (($asset['name'] ?? '') === $expected && !empty($asset['browser_download_url'])) {
                return esc_url_raw((string) $asset['browser_download_url']);
            }
        }
        return null;
    }

    private static function valid_version(string $version): bool {
        return $version !== '' && (bool) preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version);
    }

    private static function format_checked_at(string $value): string {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, wp_timezone());
        return $date ? $date->format('d.m.Y · H:i') : $value;
    }

    private static function clear_all_update_caches(): void {
        delete_site_transient(self::CACHE_KEY);
        delete_site_transient('update_plugins');
        if (function_exists('wp_clean_plugins_cache')) wp_clean_plugins_cache(false);
    }

    public static function clear_cache_after_upgrade($upgrader, array $options): void {
        if (($options['action'] ?? '') !== 'update' || ($options['type'] ?? '') !== 'plugin') return;
        $plugins = (array) ($options['plugins'] ?? []);
        if (($options['plugin'] ?? '') === plugin_basename(DT_FILE) || in_array(plugin_basename(DT_FILE), $plugins, true)) {
            self::clear_all_update_caches();
        }
    }
}
