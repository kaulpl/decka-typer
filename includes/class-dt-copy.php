<?php
if (!defined('ABSPATH')) exit;

/**
 * User-facing terminology and presentation compatibility layer.
 *
 * The plugin historically used the word "kupon" in many places. TypujKosza.pl
 * now consistently describes a saved round as "typowanie". Server-rendered HTML
 * is normalized here, while a tiny frontend helper handles labels inserted later
 * by JavaScript or AJAX responses.
 */
class DT_Copy {
    private static bool $frontBufferStarted = false;
    private static bool $adminBufferStarted = false;

    public static function register(): void {
        add_action('template_redirect', [__CLASS__, 'start_frontend_buffer'], 2);
        add_action('admin_init', [__CLASS__, 'start_admin_buffer'], 1);
        add_action('wp_enqueue_scripts', [__CLASS__, 'frontend_assets'], 150);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets'], 150);
    }

    public static function frontend_assets(): void {
        if (!class_exists('DT_Frontend') || !DT_Frontend::is_typer_page()) return;
        wp_enqueue_script('tk-copy', DT_URL . 'assets/js/copy.js', [], DT_VERSION, true);
        if (!is_user_logged_in()) {
            wp_enqueue_style('tk-marketing-tune', DT_URL . 'assets/css/marketing-tune.css', ['tk-marketing'], DT_VERSION);
        }
    }

    public static function admin_assets(string $hook): void {
        if (strpos($hook, 'decka-typer') === false) return;
        wp_enqueue_script('tk-copy-admin', DT_URL . 'assets/js/copy.js', [], DT_VERSION, true);
    }

    public static function start_frontend_buffer(): void {
        if (self::$frontBufferStarted || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;
        if (!class_exists('DT_Frontend') || !DT_Frontend::is_typer_page()) return;
        self::$frontBufferStarted = true;
        ob_start([__CLASS__, 'rewrite_output']);
    }

    public static function start_admin_buffer(): void {
        if (self::$adminBufferStarted || wp_doing_ajax()) return;
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($page === '' || strpos($page, 'decka-typer') !== 0) return;
        self::$adminBufferStarted = true;
        ob_start([__CLASS__, 'rewrite_output']);
    }

    public static function rewrite_output(string $html): string {
        return strtr($html, self::replacements());
    }

    private static function replacements(): array {
        return [
            'Czas na pierwszy kupon' => 'Czas na pierwsze typowanie',
            'czas na pierwszy kupon' => 'czas na pierwsze typowanie',
            'zapisz jeden kompletny kupon' => 'zapisz kompletne typowanie',
            'Zapisz jeden kompletny kupon' => 'Zapisz kompletne typowanie',
            'zapisz jeden kupon' => 'zapisz swoje typowanie',
            'Zapisz jeden kupon' => 'Zapisz swoje typowanie',
            'jeden kompletny kupon' => 'kompletne typowanie',
            'Jeden kompletny kupon' => 'Kompletne typowanie',
            'Jeden kupon' => 'Jedno typowanie',
            'jeden kupon' => 'jedno typowanie',
            'Ten kupon został już zapisany i nie można go edytować.' => 'To typowanie zostało już zapisane i nie można go edytować.',
            'ten kupon został już zapisany i nie można go edytować.' => 'to typowanie zostało już zapisane i nie można go edytować.',
            'Kupon zapisany' => 'Typowanie zapisane',
            'kupon zapisany' => 'typowanie zapisane',
            'Zapisz kupon' => 'Zapisz typowanie',
            'zapisz kupon' => 'zapisz typowanie',
            'Tak, zapisz kupon' => 'Tak, zapisz typowanie',
            'Po zapisaniu kuponu' => 'Po zapisaniu typowania',
            'po zapisaniu kuponu' => 'po zapisaniu typowania',
            'Po zatwierdzeniu kuponu' => 'Po zatwierdzeniu typowania',
            'po zatwierdzeniu kuponu' => 'po zatwierdzeniu typowania',
            'kupon jest zamknięty' => 'typowanie jest zamknięte',
            'Kupon jest zamknięty' => 'Typowanie jest zamknięte',
            'Edycja kuponu' => 'Edycja typowania',
            'edycja kuponu' => 'edycja typowania',
            'zamknięcia kuponów' => 'zamknięcia typowania',
            'Zamknięcia kuponów' => 'Zamknięcia typowania',
            'Kupony są nieedytowalne' => 'Typowania są nieedytowalne',
            'kupony są nieedytowalne' => 'typowania są nieedytowalne',
            'nieedytowalny kupon' => 'nieedytowalne typowanie',
            'Nieedytowalny kupon' => 'Nieedytowalne typowanie',
            'podczas zapisu kuponu' => 'podczas zapisu typowania',
            'Podczas zapisu kuponu' => 'Podczas zapisu typowania',
            'dane kuponu' => 'dane typowania',
            'Dane kuponu' => 'Dane typowania',
            'blokady kuponu' => 'blokady typowania',
            'Blokady kuponu' => 'Blokady typowania',
            'zatwierdzić kuponu' => 'zatwierdzić typowania',
            'Zatwierdzić kuponu' => 'Zatwierdzić typowania',
            'zapisać kuponu' => 'zapisać typowania',
            'Zapisać kuponu' => 'Zapisać typowania',
            'Kuponów' => 'Typowań',
            'kuponów' => 'typowań',
            'Kupony' => 'Typowania',
            'kupony' => 'typowania',
            'Kuponu' => 'Typowania',
            'kuponu' => 'typowania',
            'Kuponem' => 'Typowaniem',
            'kuponem' => 'typowaniem',
            'Kuponie' => 'Typowaniu',
            'kuponie' => 'typowaniu',
            'Kupon' => 'Typowanie',
            'kupon' => 'typowanie',
        ];
    }
}
