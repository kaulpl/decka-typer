<?php
if (!defined('ABSPATH')) exit;

class DT_Updater {
    private const REPOSITORY = 'kaulpl/decka-typer';
    private const API_URL = 'https://api.github.com/repos/kaulpl/decka-typer/releases/latest';
    private const UPDATE_URI = 'https://github.com/kaulpl/decka-typer';
    private const CACHE_KEY = 'dt_github_latest_release';

    public static function register(): void {
        add_filter('update_plugins_github.com', [__CLASS__, 'check_update'], 10, 4);
        add_action('upgrader_process_complete', [__CLASS__, 'clear_cache_after_upgrade'], 10, 2);
    }

    public static function check_update($update, array $plugin_data, string $plugin_file, array $locales) {
        if ($plugin_file !== plugin_basename(DT_FILE)) {
            return $update;
        }

        $release = self::latest_release();
        if (!$release || empty($release['tag_name'])) {
            return false;
        }

        $version = ltrim((string) $release['tag_name'], 'vV');
        if ($version === '' || !preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            return false;
        }

        $package = self::release_package($release, $version);
        if (!$package) {
            return false;
        }

        return [
            'id'           => self::UPDATE_URI,
            'slug'         => 'decka-typer',
            'version'      => $version,
            'new_version'  => $version,
            'url'          => (string) ($release['html_url'] ?? self::UPDATE_URI),
            'package'      => $package,
            'requires_php' => '8.0',
            'tested'       => '6.9',
            'autoupdate'   => true,
        ];
    }

    private static function latest_release(): ?array {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get(self::API_URL, [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'Decka-Typer/' . DT_VERSION . '; ' . home_url('/'),
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || !empty($data['draft']) || !empty($data['prerelease'])) {
            return null;
        }

        set_site_transient(self::CACHE_KEY, $data, 30 * MINUTE_IN_SECONDS);
        return $data;
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

    public static function clear_cache_after_upgrade($upgrader, array $options): void {
        if (($options['action'] ?? '') !== 'update' || ($options['type'] ?? '') !== 'plugin') {
            return;
        }
        $plugins = (array) ($options['plugins'] ?? []);
        if (($options['plugin'] ?? '') === plugin_basename(DT_FILE) || in_array(plugin_basename(DT_FILE), $plugins, true)) {
            delete_site_transient(self::CACHE_KEY);
        }
    }
}
