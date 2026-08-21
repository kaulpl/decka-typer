<?php
if (!defined('ABSPATH')) exit;

class DT_Brand {
    public const NAME = 'TypujKosza.pl';
    public const TAGLINE = 'Typuj mecze. Zdobywaj punkty. Rywalizuj w rankingu.';
    public const PRIMARY = '#055EFB';
    public const ACCENT = '#FB5D0B';
    public const SURFACE = '#F4F7FB';
    public const NAVY = '#07162F';
    public const SEO_TITLE = 'TypujKosza.pl – darmowy typer meczów koszykówki online';
    public const SEO_DESCRIPTION = 'Typuj wyniki PLK, 1 Ligi i 2 Ligi, zdobywaj punkty i rywalizuj z kibicami koszykówki w bezpłatnym typerze.';
    public const FACEBOOK_URL = 'https://www.facebook.com/TypujKosza';

    public static function register(): void {
        add_action('init', [__CLASS__, 'migrate_default_colors'], 20);
        add_action('wp_enqueue_scripts', [__CLASS__, 'frontend_assets'], 100);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets'], 100);
        add_action('wp_head', [__CLASS__, 'head_meta'], 30);
        add_action('template_redirect', [__CLASS__, 'serve_seo_files'], -5);
        add_filter('robots_txt', [__CLASS__, 'robots_txt'], 20, 2);
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
        wp_enqueue_style('tk-header', DT_URL . 'assets/css/header.css', ['tk-brand'], DT_VERSION);
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
        $url = class_exists('DT_Canonical') ? DT_Canonical::URL : home_url('/');
        $image = self::logo_stacked_url();
        echo '<meta name="theme-color" content="' . esc_attr(self::NAVY) . '">' . "\n";
        echo '<link rel="icon" type="image/png" href="' . esc_url(self::mark_url()) . '">' . "\n";
        echo '<meta name="description" content="'.esc_attr(self::SEO_DESCRIPTION).'">' . "\n";
        echo '<link rel="alternate" hreflang="pl-PL" href="'.esc_url($url).'">' . "\n";
        echo '<link rel="alternate" hreflang="x-default" href="'.esc_url($url).'">' . "\n";
        echo '<link rel="sitemap" type="application/xml" href="'.esc_url(home_url('/sitemap.xml')).'">' . "\n";
        echo '<meta property="og:type" content="website">' . "\n";
        echo '<meta property="og:locale" content="pl_PL">' . "\n";
        echo '<meta property="og:site_name" content="'.esc_attr(self::NAME).'">' . "\n";
        echo '<meta property="og:title" content="'.esc_attr(self::SEO_TITLE).'">' . "\n";
        echo '<meta property="og:description" content="'.esc_attr(self::SEO_DESCRIPTION).'">' . "\n";
        echo '<meta property="og:url" content="'.esc_url($url).'">' . "\n";
        echo '<meta property="og:image" content="'.esc_url($image).'">' . "\n";
        echo '<meta property="og:image:secure_url" content="'.esc_url($image).'">' . "\n";
        echo '<meta property="og:image:type" content="image/png">' . "\n";
        echo '<meta property="og:image:width" content="963">' . "\n";
        echo '<meta property="og:image:height" content="445">' . "\n";
        echo '<meta property="og:image:alt" content="TypujKosza.pl – typer meczów koszykówki">' . "\n";
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        echo '<meta name="twitter:title" content="'.esc_attr(self::SEO_TITLE).'">' . "\n";
        echo '<meta name="twitter:description" content="'.esc_attr(self::SEO_DESCRIPTION).'">' . "\n";
        echo '<meta name="twitter:image" content="'.esc_url($image).'">' . "\n";
        echo '<script type="application/ld+json">'.wp_json_encode([
            '@context'=>'https://schema.org','@type'=>'WebSite','name'=>self::NAME,'url'=>$url,
            'description'=>self::SEO_DESCRIPTION,'inLanguage'=>'pl-PL','sameAs'=>[self::FACEBOOK_URL],
            'publisher'=>['@type'=>'Organization','name'=>self::NAME,'url'=>$url,'logo'=>['@type'=>'ImageObject','url'=>$image]],
        ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).'</script>' . "\n";
    }

    public static function document_title(string $title): string {
        if (!class_exists('DT_Frontend') || !DT_Frontend::is_typer_page()) return $title;
        return self::SEO_TITLE;
    }

    public static function footer(): void {
        $isTyper = class_exists('DT_Frontend') && DT_Frontend::is_typer_page();
        $isLegal = class_exists('DT_Legal') && DT_Legal::is_public_page();
        if (!$isTyper && !$isLegal) return;
        echo '<footer class="tk-footer" aria-label="Informacje o serwisie"><div class="tk-footer-inner">';
        echo '<div class="tk-footer-brand"><a href="' . esc_url(home_url('/')) . '" aria-label="TypujKosza.pl — strona główna"><img src="' . esc_url(self::logo_horizontal_url()) . '" alt="' . esc_attr(self::NAME) . '"></a></div>';
        echo '<p><strong>' . esc_html(self::NAME) . '</strong> to bezpłatna zabawa dla kibiców koszykówki. Serwis nie służy do zawierania zakładów ani przyjmowania stawek pieniężnych; udział jest bezpłatny. Typy, punkty i rankingi mają wyłącznie charakter rozrywkowy i społecznościowy.</p>';
        echo '<nav class="tk-footer-links" aria-label="Dokumenty i kontakt"><a href="'.esc_url(DT_Legal::privacy_url()).'">Polityka prywatności</a><a href="'.esc_url(DT_Legal::contact_url()).'">Kontakt</a><a class="tk-facebook-link" href="'.esc_url(self::FACEBOOK_URL).'" target="_blank" rel="noopener noreferrer" aria-label="TypujKosza.pl na Facebooku"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06C2 17.08 5.66 21.25 10.44 22v-7.03H7.9v-2.91h2.54V9.85c0-2.52 1.5-3.91 3.78-3.91 1.09 0 2.23.2 2.23.2v2.46H15.2c-1.24 0-1.63.77-1.63 1.56v1.9h2.77l-.44 2.91h-2.33V22C18.34 21.25 22 17.08 22 12.06Z"/></svg><span>Facebook</span></a></nav>';
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

    public static function robots_txt(string $output, bool $public): string {
        if (!$public) return $output;
        return "User-agent: *\nAllow: /\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\nSitemap: ".home_url('/sitemap.xml')."\n";
    }

    public static function serve_seo_files(): void {
        if (is_admin() || wp_doing_ajax()) return;
        $path = isset($_SERVER['REQUEST_URI']) ? (string)wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : '';
        if (untrailingslashit($path) !== '/sitemap.xml') return;
        $urls = [home_url('/')];
        if (class_exists('DT_Legal')) $urls = array_merge($urls, [DT_Legal::privacy_url(), DT_Legal::contact_url()]);
        $urls = array_values(array_unique(array_filter($urls)));
        nocache_headers();
        header('Content-Type: application/xml; charset=UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) echo '<url><loc>'.esc_url($url).'</loc><changefreq>'.($url === home_url('/') ? 'daily' : 'monthly').'</changefreq><priority>'.($url === home_url('/') ? '1.0' : '0.5').'</priority></url>';
        echo '</urlset>';
        exit;
    }
}
