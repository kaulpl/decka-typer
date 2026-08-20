<?php
if (!defined('ABSPATH')) exit;

class DT_Brand {
    public const NAME = 'TypujKosza.pl';
    public const TAGLINE = 'Typuj mecze. Zdobywaj punkty. Rywalizuj w rankingu.';
    public const PRIMARY = '#055EFB';
    public const ACCENT = '#FB5D0B';
    public const SURFACE = '#F4F7FB';
    public const NAVY = '#07162F';

    public static function register(): void {
        add_action('init', [__CLASS__, 'migrate_default_colors'], 20);
        add_action('wp_enqueue_scripts', [__CLASS__, 'frontend_assets'], 100);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets'], 100);
        add_action('wp_head', [__CLASS__, 'head_meta'], 30);
        add_action('wp_footer', [__CLASS__, 'footer'], 30);
        add_action('admin_menu', [__CLASS__, 'rename_admin_menu'], 100);
        add_filter('pre_get_document_title', [__CLASS__, 'document_title']);
        add_filter('admin_title', [__CLASS__, 'admin_title'], 10, 2);
    }

    public static function logo_horizontal_url(): string {
        return DT_URL . 'assets/img/typujkosza-logo-horizontal.png';
    }

    public static function logo_stacked_url(): string {
        return DT_URL . 'assets/img/typujkosza-logo-stacked.png';
    }

    public static function mark_url(): string {
        return DT_URL . 'assets/img/typujkosza-mark.png';
    }

    public static function migrate_default_colors(): void {
        $stored = (array) get_option('dt_settings', []);
        if (!$stored) return;

        $map = [
            'brand_primary' => ['#1756a9', self::PRIMARY],
            'brand_accent' => ['#f47a24', self::ACCENT],
            'brand_surface' => ['#f5f7fb', self::SURFACE],
        ];
        $changed = false;
        foreach ($map as $key => [$old, $new]) {
            if (!array_key_exists($key, $stored)) continue;
            $current = strtolower(trim((string) $stored[$key]));
            if ($current === $old) {
                $stored[$key] = $new;
                $changed = true;
            }
        }
        if ($changed) update_option('dt_settings', $stored);
    }

    public static function frontend_assets(): void {
        if (!class_exists('DT_Frontend') || !DT_Frontend::is_typer_page()) return;

        wp_enqueue_style('tk-brand', DT_URL . 'assets/css/brand.css', ['dt-front'], DT_VERSION);
        wp_enqueue_script('tk-brand', DT_URL . 'assets/js/brand.js', ['dt-front'], DT_VERSION, true);
        wp_localize_script('tk-brand', 'TypujKoszaBrand', [
            'name' => self::NAME,
            'tagline' => self::TAGLINE,
            'logoHorizontal' => self::logo_horizontal_url(),
            'logoStacked' => self::logo_stacked_url(),
            'mark' => self::mark_url(),
        ]);
    }

    public static function admin_assets(string $hook): void {
        if (strpos($hook, 'decka-typer') === false) return;
        wp_enqueue_style('tk-brand-admin', DT_URL . 'assets/css/brand-admin.css', ['dt-admin'], DT_VERSION);
        wp_enqueue_script('tk-brand-admin', DT_URL . 'assets/js/brand-admin.js', ['dt-admin'], DT_VERSION, true);
        wp_localize_script('tk-brand-admin', 'TypujKoszaAdminBrand', [
            'logoHorizontal' => self::logo_horizontal_url(),
            'mark' => self::mark_url(),
        ]);
    }

    public static function head_meta(): void {
        if (!class_exists('DT_Frontend') || !DT_Frontend::is_typer_page()) return;
        echo '<meta name="theme-color" content="' . esc_attr(self::NAVY) . '">\n';
        echo '<link rel="icon" type="image/png" href="' . esc_url(self::mark_url()) . '">\n';
    }

    public static function document_title(string $title): string {
        if (!class_exists('DT_Frontend') || !DT_Frontend::is_typer_page()) return $title;
        return self::NAME . ' — ' . self::TAGLINE;
    }

    public static function footer(): void {
        if (!class_exists('DT_Frontend') || !DT_Frontend::is_typer_page()) return;
        echo '<footer class="tk-footer" aria-label="Informacje o serwisie"><div class="tk-footer-inner">';
        echo '<div class="tk-footer-brand"><img src="' . esc_url(self::logo_horizontal_url()) . '" alt="' . esc_attr(self::NAME) . '"></div>';
        echo '<p><strong>' . esc_html(self::NAME) . '</strong> to bezpłatna zabawa dla kibiców koszykówki. Serwis nie służy do zawierania zakładów ani przyjmowania stawek pieniężnych; udział jest bezpłatny. Typy, punkty i rankingi mają wyłącznie charakter rozrywkowy i społecznościowy.</p>';
        echo '<small>&copy; ' . esc_html(wp_date('Y')) . ' ' . esc_html(self::NAME) . '</small>';
        echo '</div></footer>';
    }

    public static function rename_admin_menu(): void {
        global $menu;
        foreach ((array) $menu as &$item) {
            if (($item[2] ?? '') === 'decka-typer') {
                $item[0] = self::NAME;
                break;
            }
        }
        unset($item);
    }

    public static function admin_title(string $admin_title, string $title): string {
        return str_replace('Decka Typer', self::NAME, $admin_title);
    }
}
