# Changelog

## 0.4.0 — 2026-08-20

- dodano backend dla natywnej aplikacji iOS Decka Typer,
- logowanie mobilne Google/Facebook odbywa się przez systemowy OAuth i zwraca podpisany token aplikacji ważny 90 dni,
- token mobilny może uwierzytelniać żądania REST bez używania ciasteczek WordPress,
- dodano jednorazowy most `mobile/web-session` → `mobile/web-login`, który tworzy bezpieczną sesję WordPress wewnątrz `WKWebView`,
- aplikacja iOS może dzięki temu wyświetlać pełny istniejący `/typer` i korzystać z identycznych danych, kuponów, rankingów oraz aktualizacji frontendu jak wersja WWW,
- dodano unieważnianie tokenów mobilnych po wylogowaniu przez wersjonowanie sesji użytkownika,
- brak migracji bazy danych.

## 0.3.3 — 2026-08-20

- rozstrzygnięty mecz nie koloruje już osobno kafla zwycięskiej drużyny na zielono ani przegranej na czerwono,
- kolor zielony/czerwony pozostaje wyłącznie na zewnętrznej ramce całego meczu i oznacza trafiony albo nietrafiony typ użytkownika,
- kafel przegranej drużyny jest delikatnie wyszarzony, natomiast kafel zwycięzcy pozostaje neutralny,
- pod drużynami wyświetlane są osobne oznaczenia `ZWYCIĘZCA` i `PRZEGRANY`,
- oznaczenie `TWÓJ TYP` pozostaje niezależne od wyniku; przy poprawnym wyborze zwycięzcy pod tą samą drużyną widoczne są jednocześnie `TWÓJ TYP` i `ZWYCIĘZCA`,
- bez zmian w bazie, punktacji i mechanizmie zapisu kuponów.

## 0.3.2 — 2026-08-20

- w rozstrzygniętym meczu faktyczny zwycięzca jest zawsze wyróżniany zielonym kaflem, niezależnie od typu użytkownika,
- faktyczny przegrany otrzymuje czerwone wyróżnienie,
- zewnętrzna ramka meczu nadal pokazuje poprawność kuponu użytkownika: zielona dla trafionego typu, czerwona dla nietrafionego,
- w nietrafionym meczu użytkownik widzi jednocześnie czerwony kafel swojego błędnego wyboru oraz zielony kafel prawdziwego zwycięzcy,
- nie zmieniono danych meczu, punktacji ani mechanizmu zapisu kuponów.

## 0.3.1 — 2026-08-20

- naprawiono błędne wyświetlanie wyników w zamkniętych kuponach, gdzie liczba punktów za typ mogła dokleić się do wyniku meczu, np. `20:25 + 0 pkt` było prezentowane jako `20:250`,
- wynik meczu jest teraz odczytywany wyłącznie z elementu zawierającego faktyczny wynik spotkania, a nie z całego tekstu wiersza,
- dzięki poprawnemu wynikowi ponownie prawidłowo wyliczane jest, czy typ użytkownika był trafiony, a zielona/czerwona ramka meczu odpowiada rzeczywistemu rozstrzygnięciu.

## 0.3.0 — 2026-08-20

- niezapisane wybory na ekranie „Typuj” są traktowane wyłącznie jako stan tymczasowy: przeładowanie strony lub zmiana kolejki odrzuca je bez ostrzeżenia i bez zapisu,
- ranking otrzymał dwie nowe kolumny: `trafione / typowane` oraz skuteczność wyrażoną procentowo,
- pod nazwą każdej drużyny na ekranie „Typuj” wyświetlana jest forma z 5 ostatnich zakończonych meczów,
- forma używa pięciu kropek ułożonych od najstarszego meczu po lewej do najnowszego po prawej; zielony oznacza zwycięstwo, czerwony porażkę, a szary brak danych,
- w kolorowych kropkach wyświetlane są miniatury logotypów rywali z lokalnego pakietu logotypów 1LM,
- w lewym górnym rogu kafla drużyny wyświetlane jest aktualne miejsce `#[N]` pobierane z oficjalnej tabeli `1lm.pzkosz.pl/tabele.html`,
- tabela 1LM jest cache'owana i odświeżana po synchronizacji terminarza oraz okresowo przy wejściu do Typera,
- po rozliczeniu zamkniętego meczu wynik jest prezentowany dużą czcionką osobno pod właściwą drużyną,
- cały moduł rozliczonego meczu otrzymuje zieloną ramkę przy trafionym typie i czerwoną przy nietrafionym,
- nietrafiony wybrany kafel drużyny również przechodzi w czerwony stan, żeby nie mylić samego wyboru z poprawnym wynikiem,
- nowe elementy mają responsywny układ mobilny.

## 0.2.6 — 2026-08-19

- zakładka „Moje typy” została przebudowana na rozwijalną listę kuponów — jeden kupon odpowiada jednej kolejce,
- w nagłówku każdego kuponu wyświetlana jest nazwa ligi pobierana z ustawień oraz numer kolejki,
- nagłówek kuponu pokazuje liczbę rozliczonych/trafionych spotkań i sumę punktów,
- po rozliczeniu meczu trafiony typ jest wyróżniany na zielono, a nietrafiony na czerwono,
- przy każdym meczu mocniej wyróżniono blok „TWÓJ TYP” z nazwą wybranej drużyny,
- ostatnia kolumna pokazuje `+X pkt` dla trafionego typu, `0` dla nietrafionego i `—` dla meczu oczekującego na wynik,
- najnowszy kupon jest domyślnie rozwinięty, starsze można rozwijać i zwijać,
- widok jest responsywny również na telefonach.

## 0.2.5 — 2026-08-19

- usunięto z modelu typów użytkownika stare pola `home_score` i `away_score`; użytkownik zapisuje wyłącznie identyfikator wybranej drużyny,
- dodana migracja bazy usuwa legacy kolumny `home_score` / `away_score` z tabeli `dt_predictions` i pozostawia faktyczny wynik wyłącznie w tabeli meczów,
- naprawiony błąd zapisu `Column 'home_score' cannot be null` występujący na bazach utworzonych przez starsze wersje wtyczki,
- zapis kuponu AJAX wysyła prosty, kompaktowy payload `round_id` + `match_id:team_id` zamiast osadzonego JSON-a w formularzu,
- cały handler zapisu jest objęty ochroną `try/catch(Throwable)` oraz awaryjną obsługą błędów krytycznych PHP,
- w przypadku błędu krytycznego odpowiedź jest czyszczana i zwracana jako JSON z krótkim identyfikatorem `DT-XXXXXXXX`, zamiast surowej strony HTTP 500,
- typy są zapisywane najpierw, a blokada nieedytowalnego kuponu powstaje dopiero po poprawnym zapisaniu wszystkich meczów,
- częściowy zapis po przerwanym żądaniu pozostaje możliwy do ponowienia i nie blokuje kolejki użytkownikowi,
- błędy zapisu typu lub blokady kuponu trafiają do Historii Typera, ale sam mechanizm logowania nie może już przerwać odpowiedzi,
- frontend pokazuje również krótki fragment niepoprawnej odpowiedzi serwera, jeśli hosting mimo zabezpieczeń zwróci HTML zamiast JSON.

## 0.2.4 — 2026-08-19

- zapis kuponu na `/typer` został przeniesiony z POST REST do uwierzytelnionego `admin-ajax.php`, aby wyeliminować odpowiedzi HTML/fatal błędnie interpretowane przez frontend jako „Błąd odpowiedzi serwera”,
- dodany osobny nonce dla zapisu kuponu AJAX,
- REST POST `/submission` pozostaje jako kompatybilny fallback, ale standardowy frontend korzysta z AJAX,
- oba transporty korzystają z tej samej walidacji i jednej transakcji zapisu, więc nadal obowiązuje jeden nieedytowalny kupon na kolejkę,
- dodany transport bridge, który przechwytuje wyłącznie zapis `/submission` i nie zmienia pozostałych odczytów REST,
- jeśli hosting zwróci niepoprawną odpowiedź zamiast JSON, użytkownik dostaje teraz jednoznaczny komunikat diagnostyczny zamiast ogólnego „Błąd odpowiedzi serwera”,
- błędy zapisu nadal trafiają do Historii Typera wraz z informacją, czy zapis szedł przez AJAX czy REST.

## 0.2.3 — 2026-08-19

- naprawiony zapis nieedytowalnego kuponu kolejki przez osobny, utwardzony endpoint REST,
- zapis korzysta z jednoznacznych zapytań SQL w transakcji i zapisuje szczegóły błędu do Historii Typera zamiast zwracać nieczytelną odpowiedź serwera,
- dodana kontrola obecności wymaganych tabel bazy danych przed zapisem kuponu,
- fizycznie dołączono do paczki wtyczki 16 lokalnych logotypów drużyn 1LM,
- lokalne logotypy są ponownie przypisywane do drużyn po aktualizacji do 0.2.3 i są dostępne również bez wizyty w panelu administratora,
- z ekranu logowania `/typer` usunięto opcję „Zaloguj kontem strony” oraz separator prowadzący do logowania WordPress,
- usunięto techniczne pliki pomocnicze pozostawione po wcześniejszym procesie publikacji.

## 0.2.2 — 2026-08-19

- mechanizm sprawdzania aktualizacji nie korzysta już z limitowanego endpointu GitHub REST API, dzięki czemu eliminuje komunikaty HTTP 403 wynikające z limitu anonimowych zapytań,
- najnowsza wersja jest sprawdzana przez lekki plik `VERSION` z `raw.githubusercontent.com`, a paczka aktualizacji pobierana jest bezpośrednio z GitHub Release,
- ręczne „Sprawdź aktualizacje” czyści również stary cache updatera z wersji 0.2.1,
- poprawiony kontrast niebieskich przycisków w panelu Decka Typer — biały tekst, ikony i stany hover/focus,
- dodany lokalny pakiet 16 logotypów klubów 1LM przesłanych przez administratora,
- logotypy są parowane po znormalizowanej nazwie klubu, odpornej na większość zmian sponsorskich w nazwach,
- lokalne logotypy zastępują adresy obrazów z zewnętrznego źródła zarówno w REST API Typera, jak i w bazie drużyn.

## 0.2.1 — 2026-08-19

- poprawiony mechanizm wykrywania aktualizacji z GitHub Releases,
- dodany panel aktualizacji na Pulpicie Decka Typer,
- przycisk „Sprawdź aktualizacje” wymusza ponowne sprawdzenie GitHub i WordPress,
- po wykryciu nowszej wersji przycisk zmienia się w „Aktualizuj do wersji X.Y.Z”,
- workflow GitHub Release potrafi naprawić istniejący, niekompletny Release i zweryfikować ZIP oraz SHA-256.

## 0.2.0 — 2026-08-19

- uproszczenie typowania: użytkownik wskazuje wyłącznie zwycięzcę meczu,
- nowy wizualny wybór drużyny: wybrana drużyna na zielono, przeciwna na czerwono,
- jeden nieedytowalny kupon na kolejkę; zapis całej kolejki jest atomowy i nie może zostać później zmieniony,
- administrator jawnie otwiera kolejkę i ustawia termin zamknięcia typowania,
- przyszłe kolejki w szkicu są ukryte przed użytkownikami; widoczne są kolejki otwarte oraz rozpoczęte/zamknięte,
- poprawione pobieranie czasu z 1LM: pierwszeństwo ma oficjalna godzina meczu, a nie wcześniejsza godzina transmisji Emocje TV,
- poprawione wyświetlanie lokalnych godzin w panelu administratora bez podwójnej konwersji strefy czasowej,
- pełne nazwy drużyn na `/typer`,
- mocniejsze obramowanie całego modułu meczu Decki Pelplin,
- samodzielny szablon `/typer` bez nagłówka, stopki i tytułu strony z motywu WordPress,
- nazwa ligi w niebieskim nagłówku przeniesiona do ustawień,
- usunięte logowanie Apple ID; pozostają Google, Facebook i konto WordPress,
- migracja typów z `0.1.0` do wyboru zwycięzcy oraz ochrona starych kuponów przed ponowną edycją,
- automatyczne zamykanie kolejki po osiągnięciu ustawionego terminu.

## 0.1.0 — 2026-08-19

- pierwsza kompletna wersja instalacyjna,
- frontend `/typer`,
- panel administracyjny,
- import terminarza i wyników 1LM,
- ochrona ręcznych zmian przed synchronizacją,
- punktacja i rankingi,
- logowanie Google/Facebook/Apple ID,
- statystyki, historia i korekty punktów,
- responsywny nowoczesny interfejs,
- własny mechanizm aktualizacji WordPress z GitHub Releases,
- `Update URI` dla repozytorium `kaulpl/decka-typer`,
- automatyczne tworzenie tagu, GitHub Release, instalacyjnego ZIP i sumy SHA-256 po stabilnym merge do `main`.
