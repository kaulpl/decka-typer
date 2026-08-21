<?php
if (!defined('ABSPATH')) exit;

class DT_Avatar {
    private const OPTION = 'dt_avatar_messages';
    private const MESSAGE_COUNT = 15;

    public static function defaults(): array {
        return [
            'welcome' => ['image'=>'01-powitanie.png','label'=>'Powitanie','texts'=>[
                'No, jesteś! Piłka czeka, typy same się nie zrobią.', 'Witaj na parkiecie. Sprawdźmy, czy dziś masz rękę do typów.', 'Artur melduje gotowość. Ty wybierasz, ja trzymam kciuki.', 'Dzień dobry, selekcjonerze wyników. Kolejka już czeka.', 'Rozgrzewka skończona. Czas ustawić swój zwycięski skład typów.', 'Wchodzisz z ławki czy od razu rzucasz za trzy? Typujmy!', 'Miło Cię widzieć. Oby dziś każdy typ trafił w obręcz.', 'Parkiet gotowy, terminarz gotowy, Artur gotowy. A Ty?', 'Zaczynamy kolejną koszykarską przygodę. Bez fauli w typowaniu!', 'Witaj! Intuicja już rozgrzana, więc zerknijmy na mecze.', 'Cześć! Dzisiaj typujemy głową, sercem i odrobiną szczęścia.', 'Nowa wizyta, nowe szanse. Tablica wyników jeszcze wszystko przyjmie.', 'Artur przy piłce. Podaj swoje typy, zanim zabrzmi gwizdek.', 'Hej! Liderzy nie śpią, ale Ty możesz ich jeszcze dogonić.', 'Witamy w strefie typowania. Tutaj każdy punkt ma znaczenie.',
            ]],
            'thinking' => ['image'=>'02-myslenie.png','label'=>'Myślenie','texts'=>[
                'Spokojnie. Nawet trener czasem patrzy w tablicę trochę dłużej.', 'Trudny wybór? Dogrywki w głowie są całkowicie dozwolone.', 'Serce mówi jedno, tabela drugie. Klasyczny mecz na styku.', 'Analiza trwa. Artur właśnie sprawdza kierunek wiatru na hali.', 'Nie spiesz się, ale pamiętaj: zegar akcji już tyka.', 'Forma, skład, intuicja… a czasem wygrywa po prostu odważniejszy typ.', 'To dobry moment na trenerską minę i jeszcze jedno spojrzenie.', 'Myślisz? Świetnie. Losowanie wyniku zostawmy innym.', 'Każdy wielki typ zaczyna się od chwili zawahania.', 'Statystyki na stół, emocje na bok. Przynajmniej na minutę.', 'Artur analizuje. Procesor koszykarski pracuje na pełnych obrotach.', 'Ten mecz pachnie niespodzianką. Tylko z której strony?', 'Rzut za trzy czy bezpieczne dwa? Decyzja należy do Ciebie.', 'Cisza na ławce. Trwa układanie zwycięskiej strategii.', 'Daj sobie chwilę. Dobry typ nie zawsze wpada od tablicy.',
            ]],
            'saved' => ['image'=>'03-typowanie-zapisany.png','label'=>'Kupon zapisany','texts'=>[
                'Kupon zapisany. Teraz pozostaje udawać, że wszystko było przemyślane.', 'Gotowe! Typy bezpiecznie siedzą już na ławce rezerwowych.', 'Kupon przyjęty. Teraz piłka jest po stronie zawodników.', 'Zapisane! Możesz odetchnąć, zegar akcji zatrzymany.', 'Typy w systemie. Artur potwierdza: żadnego błędu kroków.', 'Kupon zamknięty w szatni. Czekamy na pierwszy gwizdek.', 'Dobra robota. Teraz nie zmieniaj zdania co pięć minut.', 'Zapis zakończony sukcesem. Oby podobnie skończyła się kolejka.', 'Typy poleciały celnie do bazy. Teraz czas na parkiet.', 'Kupon gotowy. Pewność siebie: poziom rzut z połowy boiska.', 'Mamy to! Artur już liczy przyszłe punkty.', 'Wszystko zapisane. Teraz pozostaje spokojnie kibicować. Podobno się da.', 'Kupon zaakceptowany. Sędziowie nie zgłosili uwag.', 'Typowanie zakończone. Piłka w grze, emocje w pakiecie.', 'Zapisane jak akcja na tablicy trenera. Powodzenia!',
            ]],
            'perfect' => ['image'=>'04-idealny-typowanie.png','label'=>'Idealny kupon','texts'=>[
                'Komplet trafień! Możesz przez chwilę mówić, że znasz się najlepiej.', 'Perfekcyjna kolejka! Artur wstaje z ławki i bije brawo.', 'Same trafienia. Czy ktoś zamawiał koszykarskiego jasnowidza?', 'Idealny kupon! Siatka nawet nie drgnęła.', 'Bez pudła. To już nie intuicja, to koszykarska telepatia.', 'Komplet punktów! Zachowaj ten kupon do rodzinnego archiwum.', 'Czysta perfekcja. Trener miesiąca może być tylko jeden.', 'Wszystko weszło! Nawet Artur sprawdzał wynik dwa razy.', 'Idealnie rozegrana kolejka. Żadnej straty, same punkty.', 'Kupon marzenie! Taki występ zasługuje na owację na stojąco.', 'Sto procent celności. Obręcz była dziś szeroka jak ocean.', 'Perfekcyjny wynik! Ranking właśnie poczuł Twoją obecność.', 'Ani jednego pudła. NBA dzwoniło, ale Artur nie odebrał.', 'Mistrzowska kolejka! Konfetti może nie ma, ale punkty są prawdziwe.', 'Komplet trafień. Prosimy zachować skromność do następnej kolejki.',
            ]],
            'warning' => ['image'=>'05-ostrzezenie.png','label'=>'Niepełny kupon','texts'=>[
                'Halo, zostały puste mecze. Piłka nie wybierze za Ciebie.', 'Timeout! Nie wszystkie spotkania mają jeszcze swój typ.', 'Na parkiecie brakuje zawodnika. Uzupełnij cały kupon.', 'Zegar tyka, a kilka typów nadal siedzi na ławce.', 'Nie oddawaj pustego rzutu. Wybierz zwycięzców wszystkich meczów.', 'Artur widzi wolne pola. Sędzia też by je zauważył.', 'Kupon niekompletny. Jeszcze chwila koncentracji i będzie gotowy.', 'Brakuje decyzji. W typowaniu nie ma remisu z samym sobą.', 'Sprawdź skład kuponu — kilka meczów nie dostało powołania.', 'Uwaga, niedokończona akcja! Wskaż pozostałych zwycięzców.', 'Nie zostawiaj punktów na ławce. Uzupełnij brakujące typy.', 'Jeszcze nie koniec. Artur naliczył puste pozycje.', 'Kupon prosi o dokończenie. Nie każ mu czekać do syreny.', 'Brakuje kilku rzutów. Celuj i wybieraj dalej.', 'Kontrola techniczna: kupon nie jest jeszcze kompletny.',
            ]],
            'closed' => ['image'=>'06-kolejka-zamknieta.png','label'=>'Kolejka zamknięta','texts'=>[
                'Koniec typowania. Gwizdek był, reklamacji nie przyjmuję.', 'Syrena zabrzmiała. Ta kolejka jest już zamknięta.', 'Zegar pokazuje zero. Teraz oglądamy i liczymy punkty.', 'Typy zamknięte. Koszykarze już grają.', 'Za późno na zmianę taktyki. Kolejka ruszyła.', 'Drzwi szatni zamknięte. Kuponu już nie poprawimy.', 'Pierwszy gwizdek za nami. Czas zaufać swoim wyborom.', 'Koniec czasu! Nawet Artur nie dostanie dodatkowej sekundy.', 'Kolejka zamknięta. Teraz każdy typ wygląda na genialny. Do wyniku.', 'Typowanie zakończone. Emocje dopiero się zaczynają.', 'Sędzia zabrał piłkę. Zmiany w kuponie są już niemożliwe.', 'Po syrenie się nie rzuca. Widzimy się przy następnej kolejce.', 'Ta akcja jest zakończona. Sprawdź inną otwartą kolejkę.', 'Tablica zamknięta, punkty czekają na rozstrzygnięcie.', 'Kolejka wystartowała. Teraz kibicujemy bez poprawiania historii.',
            ]],
            'missed' => ['image'=>'07-nietrafiony-typ.png','label'=>'Nietrafiony typ','texts'=>[
                'Nie weszło. Koszykówka bywa złośliwa, ale następna kolejka już czeka.', 'Pudło. Nawet najlepsi czasem trafiają tylko w obręcz.', 'Ten typ zrobił efektowny airball. Gramy dalej.', 'Nie tym razem. Tabela właśnie pokazała język intuicji.', 'Typ nie trafił, ale sezon jest długi. Głowa do góry.', 'Obręcz wypluła ten wybór. Następny może wpaść czysto.', 'Ups. Niespodzianka miała dziś inne plany.', 'Punktów brak, doświadczenie dopisane. To też jakaś statystyka.', 'Nie siadło. Artur zaleca krótki timeout i powrót do gry.', 'Ten mecz uciekł spod kontroli szybciej niż kontra dwa na jeden.', 'Typ nietrafiony. Bez paniki, nawet trenerzy mylą tablice.', 'Tym razem ławka rywali świętuje. My szykujemy rewanż.', 'Piłka zatańczyła na obręczy i wyszła. Klasyka.', 'Jedno pudło nie przegrywa sezonu. Następna akcja!', 'Nie wyszło, ale przynajmniej było odważnie. Prawda?',
            ]],
            'bonus' => ['image'=>'08-mecz-bonus.png','label'=>'Mecz BONUS','texts'=>[
                'BONUS na parkiecie. Tu odwaga może ważyć trochę więcej.', 'Mecz BONUS wybrany. Podwajamy emocje, nie ciśnienie.', 'To Twój specjalny rzut. Oby wpadł bez dotykania obręczy.', 'BONUS zaznaczony. Artur zakłada okulary do liczenia punktów.', 'Grasz va banque? Koszykarsko mówimy: rzut za trzy!', 'Ten mecz dostał gwiazdkę. Teraz musi tylko spełnić oczekiwania.', 'BONUS aktywny. Ryzyko większe, satysfakcja też.', 'Wybrano mecz specjalny. Niech moc tablicy będzie z Tobą.', 'Tu ważą się dodatkowe punkty. Bez presji, oczywiście.', 'Mecz BONUS gotowy. Artur już odpala syrenę alarmową.', 'Odważny wybór! Tak powstają piękne historie albo świetne anegdoty.', 'Podkręcamy stawkę. Ten typ gra dziś pierwsze skrzypce.', 'BONUS na boisku. Teraz liczy się celność i chłodna głowa.', 'Specjalny wybór zapisany. Oby tabela nagrodziła odwagę.', 'Jeden mecz, dodatkowe emocje. Artur mówi: warto było zaryzykować.',
            ]],
        ];
    }

    public static function messages(): array {
        $saved = (array) get_option(self::OPTION, []);
        $out = self::defaults();
        foreach ($out as $key => &$item) {
            $texts = $item['texts'];
            $custom = $saved[$key] ?? [];
            if (is_string($custom)) $custom = [$custom];
            if (is_array($custom)) {
                for ($i = 0; $i < self::MESSAGE_COUNT; $i++) {
                    $value = sanitize_text_field((string)($custom[$i] ?? ''));
                    if ($value !== '') $texts[$i] = $value;
                }
            }
            $item['texts'] = array_values($texts);
            $item['text'] = $item['texts'][0];
            $item['url'] = DT_URL . 'assets/img/artur-bot/' . $item['image'];
        }
        unset($item);
        return $out;
    }

    public static function save(array $input): void {
        $clean = [];
        foreach (self::defaults() as $key => $item) {
            $values = $input[$key] ?? [];
            if (!is_array($values)) $values = [$values];
            $clean[$key] = [];
            for ($i = 0; $i < self::MESSAGE_COUNT; $i++) {
                $value = sanitize_text_field(wp_unslash((string)($values[$i] ?? '')));
                $clean[$key][$i] = $value !== '' ? $value : $item['texts'][$i];
            }
        }
        update_option(self::OPTION, $clean, false);
    }
}
