# Changelog

## 0.4.20 — 2026-08-20

- ustawiono bazową wielkość tekstu landing page na minimum `12px`,
- podniesiono pomocnicze teksty landingu i ekranu logowania, które wcześniej schodziły do `11px`,
- poprawiono generowanie adresu wylogowania tak, aby parametr `_wpnonce` nie był zwracany jako HTML-owe `&amp;`,
- dodano dodatkowe zabezpieczenie po stronie JavaScript, które normalizuje starszy zakodowany adres wylogowania,
- po kliknięciu „Wyloguj się” użytkownik jest wylogowywany bez promptu WordPressa i wraca na `https://typujkosza.pl/`.

## 0.4.19 — 2026-08-20

- dodano własny endpoint bezpośredniego wylogowania użytkownika z poziomu Ustawień,
- usunięto przejście przez `wp-login.php?action=logout` i ekran potwierdzenia WordPressa,
- po wylogowaniu użytkownik wraca bezpośrednio na `https://typujkosza.pl/`,
- endpoint wylogowania jest zabezpieczony nonce.

## 0.4.18 — 2026-08-20

- webowy callback Google/Facebook został uproszczony do kanonicznej strony głównej `https://typujkosza.pl/`,
- callback jest przechwytywany po `state + code/error` zanim renderuje się publiczny frontend,
- `Redirect URI` pokazywany w panelu, wysyłany do Google/Facebook i używany podczas wymiany kodu na token jest teraz dokładnie tym samym adresem strony głównej,
- dodano panel diagnostyczny Google OAuth pokazujący dokładny Client ID używany przez wtyczkę oraz faktyczny Redirect URI,
- diagnostyka ułatwia wykrycie sytuacji, w której Redirect URI został dodany do innego klienta OAuth niż Client ID zapisany we wtyczce,
- stare REST callbacki pozostają zarejestrowane dla kompatybilności; mobilne OAuth bez zmian.

## 0.4.17 — 2026-08-20

- usunięto ogromną pustą przestrzeń pomiędzy kartą logowania a właściwą częścią landing page,
- publiczny landing nie dziedziczy już `min-height: 100vh` przeznaczonego dla zalogowanej aplikacji,
- linki i istniejące adresy z fragmentem `#decka-typer` są automatycznie czyszczone do kanonicznego adresu TypujKosza.pl,
- webowy callback OAuth Google/Facebook został uproszczony do jednego, czystego adresu bez parametrów zapytania: `https://typujkosza.pl/wp-admin/admin-post.php`,
- dostawca logowania jest odzyskiwany bezpiecznie z krótkotrwałego `state`, dzięki czemu Google nie musi porównywać wariantów URI z `action` i `provider`,
- panel administratora i faktyczne żądanie OAuth korzystają z tego samego czystego Redirect URI,
- mobilne callbacki OAuth pozostają bez zmian.

## 0.4.16 — 2026-08-20

- zmniejszono pionowe odstępy pomiędzy modułami landing page, aby strona była bardziej zwarta,
- zwiększono czytelne teksty landing page i ekranu logowania o około 2 px,
- mockupy aplikacji zachowują kompaktową skalę, aby nie psuć proporcji ekranów demonstracyjnych,
- w całym widocznym interfejsie określenie „kupon” zostało zastąpione terminologią „typowanie”,
- zmiana obejmuje frontend, panel administratora, teksty renderowane po stronie serwera oraz komunikaty dokładane dynamicznie przez JavaScript/AJAX,
- dodano naturalne warianty językowe, m.in. „Twoje typowanie”, „Typowanie zapisane”, „Typowania” i „Czas na pierwsze typowanie”,
- brak zmian w modelu danych — wewnętrzne nazwy tabel i API pozostają kompatybilne.

## 0.4.15 — 2026-08-20

- webowe callbacki OAuth Google i Facebook zostały przeniesione z `/wp-json/...` na standardowy endpoint WordPress `wp-admin/admin-post.php`,
- zmiana usuwa zależność logowania WWW od reguł REST API i permalinków serwera,
- panel administratora pokazuje teraz dokładnie ten sam `Redirect URI`, który jest wysyłany do Google/Facebook podczas autoryzacji i wymiany kodu na token,
- nowe callbacki są kanonicznie osadzone na `https://typujkosza.pl/`,
- stare endpointy REST OAuth pozostają zarejestrowane dla kompatybilności, ale nowe logowania WWW ich nie używają,
- mobilne endpointy OAuth pozostają bez zmian.

## 0.4.14 — 2026-08-20

- dodano centralny adres kanoniczny aplikacji: `https://typujkosza.pl/`,
- wejście na publiczny Typer przez inną domenę lub wariant `www` jest automatycznie przekierowywane na `https://typujkosza.pl/`,
- stare przekierowania na `/typer` oraz powroty OAuth do lokalnej strony głównej są normalizowane do głównego adresu TypujKosza.pl,
- zachowywane są parametry przekierowania, m.in. `dt_login` i `dt_login_error`,
- callbacki Google i Facebook wyświetlane w panelu oraz używane podczas logowania są wymuszane na domenie `typujkosza.pl`,
- kanoniczne callbacki obejmują także mobilne OAuth i mobilny web-login,
- dodano znacznik `<link rel="canonical" href="https://typujkosza.pl/">` na stronie głównej,
- nie zmieniamy automatycznie opcji `siteurl` WordPressa, dzięki czemu panel administratora i pliki instalacji pozostają bezpieczne.

## 0.4.13 — 2026-08-20

- dodano standardowy odstęp pod nagłówkiem i 3-kolorowym paskiem identyfikacji,
- ekran dla niezalogowanych został rozszerzony o pełny landing page TypujKosza.pl,
- dodano treści SEO opisujące darmowy typer koszykarski, rywalizację kibiców, ranking oraz sposób działania serwisu,
- dodano meta description, Open Graph, Twitter Card oraz dane strukturalne `WebApplication` z informacją o bezpłatnym dostępie,
- dodano wizualne, responsywne mockupy ekranów „Typuj” i „Ranking” z przykładowymi danymi prezentacyjnymi,
- dodano sekcje korzyści, funkcji, czterech kroków rozpoczęcia gry oraz CTA do logowania,
- nowe materiały marketingowe są renderowane po stronie PHP tylko dla niezalogowanych użytkowników, dzięki czemu treść jest dostępna dla wyszukiwarek,
- brak zmian w typach, punktacji i bazie danych.

## 0.4.12 — 2026-08-20

- całkowicie przebudowano nagłówek publicznego Typera pod markę TypujKosza.pl,
- szeroki logotyp PNG jest teraz wyświetlany bezpośrednio jako główny element hero,
- pod logotypem pokazujemy hasło „Typuj mecze. Zdobywaj punkty. Rywalizuj w rankingu.”,
- usunięto z prawej strony nagłówka nazwę ligi,
- nagłówek ma nowy, jasny layout z akcentami granat/niebieski/pomarańczowy,
- poprawiono błędne wypisywanie dosłownych znaków `\n\n` na górze strony,
- brak zmian w typach, rankingach, punktacji i bazie danych.

## 0.4.11 — 2026-08-20

- branding TypujKosza.pl korzysta teraz z oryginalnych plików PNG wgranych bezpośrednio do repozytorium,
- nagłówek, stopka, favicon i panel administratora zostały przepięte z plików `.webp` na `.png`,
- poprawiono typ MIME favicon na `image/png`,
- zmiana eliminuje problem niewyświetlających się logotypów po instalacji wydania 0.4.10.

## 0.4.10 — 2026-08-20

- publiczna marka aplikacji została zmieniona z Decka Typer na **TypujKosza.pl**,
- dodano oficjalne logo poziome, pionowe i sygnet bezpośrednio do paczki wtyczki,
- nagłówek frontendu otrzymał branding TypujKosza.pl oraz hasło „Typuj mecze. Zdobywaj punkty. Rywalizuj w rankingu.”,
- interfejs otrzymał paletę granat `#07162F`, niebieski `#055EFB`, pomarańczowy `#FB5D0B` i jasne tło `#F4F7FB`,
- wcześniejsze domyślne kolory są automatycznie migrowane do nowej identyfikacji bez nadpisywania własnych kolorów administratora,
- dodano prostą stopkę z małym logotypem i informacją o bezpłatnym, rozrywkowym i społecznościowym charakterze typowania,
- z publicznego ekranu logowania usunięto legacy opcję logowania kontem WordPress,
- odświeżono branding panelu administratora, menu, tytuł dokumentu i favicon,
- zaktualizowano nazwę wtyczki, autora, dokumentację oraz numer wersji.

## 0.4.9 — 2026-08-20

- Typer jest renderowany bezpośrednio jako strona główna WordPressa (`/`),
- frontend nie zależy od osobnej strony `/typer`,
- stary adres `/typer` i legacy redirecty logowania są kierowane na stronę główną.

## 0.4.8 — 2026-08-20

- usunięto automatyczne wyróżnianie meczów Decki Pelplin,
- szarfa „ULUBIONA DRUŻYNA” trafiła do lewego dolnego rogu,
- mecz ulubionej drużyny otrzymuje niebieską ramkę przed rozstrzygnięciem.

## 0.4.7 — 2026-08-20

- użytkownik może wybrać ulubioną drużynę,
- mecze ulubionej drużyny są personalizowane,
- zwykłe konta Typera korzystają z trwałej sesji logowania do świadomego wylogowania lub usunięcia cookies.

## 0.4.6 — 2026-08-20

- uproszczono layout rankingu i przeniesiono medale przed nazwy użytkowników,
- wyśrodkowano kolumny punktów, trafień, skuteczności i BONUS,
- w selektorach kolejek ukryto kolejki pozostające w szkicu.

## 0.4.5 — 2026-08-20

- ranking obsługuje zakresy wszechczasów, sezonu i kolejki,
- dodano kolumnę BONUS oraz medale TOP 3,
- mecz BONUS jest oznaczany także w historii „Moje typy”.

## 0.4.4 — 2026-08-20

- zamknięte kolejki są dostępne do podglądu także bez wcześniejszego typowania,
- dodano jeden konfigurowalny mecz BONUS na kolejkę i dodatkową punktację.

## 0.4.3 — 2026-08-20

- przyspieszono przełączanie kolejek przez cache kontekstu, równoległe pobieranie i prefetch sąsiednich kolejek,
- frontend nie czeka na synchroniczne połączenie z zewnętrzną tabelą 1LM.

## 0.4.2 — 2026-08-20

- zamknięte kolejki pozostają dostępne do podglądu,
- forma pięciu ostatnich spotkań jest liczona historycznie względem zamknięcia oglądanej kolejki.

## 0.4.1 — 2026-08-20

- dodano ustawienia użytkownika, publiczną nazwę rankingową, dane konta i link do zmiany hasła.

## 0.4.0 — 2026-08-20

- dodano backend sesji/tokenów dla aplikacji iOS oraz mobilny bridge do sesji WordPress.

## Starsze wersje

Pełna historia zmian wersji `0.1.x–0.3.x` pozostaje dostępna w historii Git repozytorium.
