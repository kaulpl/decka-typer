<?php
if (!defined('ABSPATH')) exit;

class DT_Marketing {
    public static function register(): void {
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets'], 120);
        add_action('wp_head', [__CLASS__, 'seo_head'], 32);
        add_action('wp_footer', [__CLASS__, 'render'], 10);
    }

    private static function active(): bool {
        return class_exists('DT_Frontend') && DT_Frontend::is_typer_page() && !is_user_logged_in();
    }

    public static function assets(): void {
        if (!self::active()) return;
        wp_enqueue_style('tk-marketing', DT_URL . 'assets/css/marketing.css', ['tk-header'], DT_VERSION);
    }

    public static function seo_head(): void {
        if (!self::active()) return;

        $title = 'TypujKosza.pl — darmowy typer koszykarski i ranking kibiców';
        $description = 'Typuj mecze koszykówki, zdobywaj punkty i rywalizuj w rankingach z kibicami z całej Polski. TypujKosza.pl to bezpłatny typer koszykarski dla fanów basketu.';
        $url = home_url('/');
        $image = class_exists('DT_Brand') ? DT_Brand::logo_horizontal_url() : '';

        echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
        echo '<meta property="og:type" content="website">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
        if ($image) echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'WebApplication',
            'name' => 'TypujKosza.pl',
            'url' => $url,
            'description' => $description,
            'applicationCategory' => 'EntertainmentApplication',
            'operatingSystem' => 'Web',
            'isAccessibleForFree' => true,
            'offers' => [
                '@type' => 'Offer',
                'price' => '0',
                'priceCurrency' => 'PLN',
            ],
        ];
        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }

    public static function render(): void {
        if (!self::active()) return;
        ?>
        <section class="tk-marketing" aria-labelledby="tk-marketing-title">
            <div class="tk-marketing-inner">
                <header class="tk-marketing-lead">
                    <span class="tk-marketing-kicker">TYPUJ KOSZYKÓWKĘ. GRAJ Z KIBICAMI.</span>
                    <h2 id="tk-marketing-title">Koszykarski typer, w którym liczy się wiedza o baskecie</h2>
                    <p>Wybieraj zwycięzców spotkań, zdobywaj punkty i sprawdzaj swoje miejsce wśród kibiców z całej Polski. TypujKosza.pl to bezpłatna rywalizacja dla fanów koszykówki — bez stawek, bez zakładów, po prostu sportowa zabawa i emocje każdej kolejki.</p>
                </header>

                <div class="tk-value-grid" aria-label="Najważniejsze zalety TypujKosza.pl">
                    <article><strong>0 zł</strong><span>udział jest całkowicie bezpłatny</span></article>
                    <article><strong>1 klik</strong><span>wystarczy wskazać zwycięzcę meczu</span></article>
                    <article><strong>Ranking</strong><span>porównuj wyniki z innymi kibicami</span></article>
                    <article><strong>BONUS</strong><span>wybrane mecze mogą dać więcej punktów</span></article>
                </div>

                <section class="tk-showcase" aria-labelledby="tk-showcase-title">
                    <div class="tk-section-copy">
                        <span>PRZYKŁADOWY WIDOK SYSTEMU</span>
                        <h2 id="tk-showcase-title">Proste typowanie, czytelny ranking</h2>
                        <p>Interfejs został zbudowany tak, żeby można było szybko przejść przez całą kolejkę, zapisać kupon i od razu wiedzieć, jak wygląda Twoja sytuacja w rankingu.</p>
                    </div>

                    <div class="tk-shot-grid">
                        <article class="tk-app-shot tk-picks-shot" aria-label="Przykładowy ekran typowania">
                            <div class="tk-shot-chrome"><i></i><i></i><i></i><span>TypujKosza.pl / Typuj</span></div>
                            <div class="tk-shot-body">
                                <div class="tk-shot-heading"><div><small>KOLEJKA</small><strong>3. kolejka</strong></div><span>OTWARTA</span></div>
                                <div class="tk-demo-match is-favorite">
                                    <div class="tk-demo-badge">ULUBIONA DRUŻYNA</div>
                                    <div class="tk-demo-team selected"><b>DP</b><span>Decka Pelplin</span><small>TWÓJ TYP</small></div>
                                    <em>VS</em>
                                    <div class="tk-demo-team rejected"><b>NI</b><span>Noteć Inowrocław</span><small>RYWAL</small></div>
                                </div>
                                <div class="tk-demo-match is-bonus">
                                    <div class="tk-demo-bonus">★ BONUS +1 PKT</div>
                                    <div class="tk-demo-team"><b>ST</b><span>SKS Starogard</span><small>WYBIERZ</small></div>
                                    <em>VS</em>
                                    <div class="tk-demo-team"><b>GT</b><span>GKS Tychy</span><small>WYBIERZ</small></div>
                                </div>
                                <div class="tk-demo-save"><span>2 z 2 typów wybrane</span><strong>Zapisz typy</strong></div>
                            </div>
                        </article>

                        <article class="tk-app-shot tk-ranking-shot" aria-label="Przykładowy ekran rankingu">
                            <div class="tk-shot-chrome"><i></i><i></i><i></i><span>TypujKosza.pl / Ranking</span></div>
                            <div class="tk-shot-body">
                                <div class="tk-shot-heading"><div><small>KLASYFIKACJA</small><strong>Ranking sezonu</strong></div><span>2026/27</span></div>
                                <div class="tk-demo-rank-head"><span>Kibic</span><span>Punkty</span><span>Trafione</span><span>Skut.</span></div>
                                <div class="tk-demo-rank is-first"><strong>🥇 BasketFan</strong><span>28</span><span>24/30</span><span>80%</span></div>
                                <div class="tk-demo-rank"><strong>🥈 TrojkaZaTrzy</strong><span>26</span><span>23/30</span><span>77%</span></div>
                                <div class="tk-demo-rank is-you"><strong>🥉 Ty</strong><span>25</span><span>22/30</span><span>73%</span></div>
                                <div class="tk-demo-rank"><strong>4. KociewieBasket</strong><span>24</span><span>21/30</span><span>70%</span></div>
                                <div class="tk-demo-note">Przykładowe dane prezentacyjne</div>
                            </div>
                        </article>
                    </div>
                </section>

                <section class="tk-feature-section" aria-labelledby="tk-features-title">
                    <div class="tk-section-copy is-centered">
                        <span>DLACZEGO WARTO GRAĆ?</span>
                        <h2 id="tk-features-title">Każda kolejka to nowa szansa na punkty</h2>
                    </div>
                    <div class="tk-feature-grid">
                        <article><div class="tk-feature-icon">✓</div><h3>Typuj całą kolejkę</h3><p>Wskaż zwycięzcę każdego meczu i zapisz jeden kompletny kupon. Bez zgadywania dokładnych wyników.</p></article>
                        <article><div class="tk-feature-icon">★</div><h3>Poluj na BONUS</h3><p>Wybrane spotkania mogą być oznaczone jako BONUS i dawać dodatkowe punkty za prawidłowy typ.</p></article>
                        <article><div class="tk-feature-icon">🏆</div><h3>Walcz o ranking</h3><p>Sprawdzaj ranking kolejki, sezonu i klasyfikację wszechczasów. Porównuj skuteczność z innymi fanami basketu.</p></article>
                        <article><div class="tk-feature-icon">♥</div><h3>Wybierz swoją drużynę</h3><p>Ustaw ulubiony klub, a jego spotkania będą od razu wyróżnione w Twoim widoku typowania.</p></article>
                    </div>
                </section>

                <section class="tk-how" aria-labelledby="tk-how-title">
                    <div class="tk-section-copy">
                        <span>JAK TO DZIAŁA?</span>
                        <h2 id="tk-how-title">Dołącz w kilka chwil</h2>
                    </div>
                    <ol>
                        <li><b>1</b><div><strong>Zaloguj się</strong><p>Użyj konta Google lub Facebook i utwórz swój profil kibica.</p></div></li>
                        <li><b>2</b><div><strong>Wybierz zwycięzców</strong><p>Przejdź przez mecze otwartej kolejki i wskaż drużyny, które Twoim zdaniem wygrają.</p></div></li>
                        <li><b>3</b><div><strong>Zapisz kupon</strong><p>Po zatwierdzeniu czekasz na wyniki — kupon jest zamknięty i nie można go już zmieniać.</p></div></li>
                        <li><b>4</b><div><strong>Zdobywaj punkty</strong><p>Po rozstrzygnięciu spotkań system automatycznie nalicza punkty i aktualizuje ranking.</p></div></li>
                    </ol>
                </section>

                <section class="tk-seo-copy" aria-labelledby="tk-seo-title">
                    <div>
                        <span>KOSZYKÓWKA + RYWALIZACJA</span>
                        <h2 id="tk-seo-title">Darmowy typer koszykarski dla kibiców z całej Polski</h2>
                    </div>
                    <div class="tk-seo-columns">
                        <p>TypujKosza.pl powstał dla osób, które śledzą polską koszykówkę, analizują formę drużyn i lubią porównywać swoją wiedzę z innymi. Typowanie meczów koszykówki jest szybkie: wybierasz zwycięzców, zapisujesz kupon i czekasz na rozstrzygnięcia parkietu.</p>
                        <p>Nie potrzebujesz żadnej wpłaty ani stawki. To społecznościowy typer koszykarski nastawiony na zabawę, sportowe emocje i ranking kibiców. Z czasem serwis może obejmować kolejne ligi, sezony i dodatkowe wyzwania dla społeczności basketu.</p>
                    </div>
                </section>

                <div class="tk-marketing-cta">
                    <div><strong>Masz swoje typy na najbliższą kolejkę?</strong><span>Zaloguj się powyżej i sprawdź, jak wypadasz na tle innych kibiców.</span></div>
                    <a href="#decka-typer">Przejdź do logowania ↑</a>
                </div>
            </div>
        </section>
        <?php
    }
}
