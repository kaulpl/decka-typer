# Changelog

## 0.5.0 — 2026-08-20

- wprowadzono fundament wieloligowy dla **PLK, 1LM i 2LM**,
- 2LM posiada oddzielny kontekst grup **A, B, C i D**,
- model kolejki został rozszerzony o `league_code` i `group_code`; unikalność kolejki obejmuje teraz ligę, sezon, grupę i numer kolejki,
- istniejące dane z wcześniejszych wersji są automatycznie migrowane jako dane 1LM,
- na stronie głównej zalogowanego użytkownika otwarte kolejki są prezentowane jako rozwijane sekcje w podziale na ligi i grupy,
- użytkownik może rozwinąć wybraną kolejkę, wybrać zwycięzców i zapisać niezależne typowanie,
- „Moje typy” pokazują zapisane typowania jako rozwijane elementy z oznaczeniem ligi, grupy i sezonu,
- ranking otrzymał zakresy **Wszechczasów / Liga / Sezon / Kolejka** oraz kompaktowe selektory ligi, grupy 2LM, sezonu i kolejki,
- rozbudowano indywidualne osiągnięcia użytkownika o miejsce, punkty, trafienia, skuteczność, liczbę typowań i perfekcyjne kolejki z filtrami wszechczasów/liga/sezon,
- przycisk „Ustawienia” w aplikacji użytkownika jest prezentowany jako **„Moje konto”**,
- panel administratora otrzymał moduł **Kolejki i ligi** oraz **Ligi i źródła** do obsługi PLK, 1LM i grup 2LM,
- stare selektory kolejek w panelu są uzupełniane o nazwę ligi, grupę i sezon, aby identyczne numery kolejek nie były mylone,
- automatyczna synchronizacja 1LM pozostaje aktywna; PLK i 2LM są gotowe do ręcznej obsługi oraz późniejszego podpięcia parserów automatycznych,
- dodano tryby serwisu **Produkcyjny / Testowy / Przerwa**,
- tryb Testowy pokazuje żółty komunikat na górze publicznej strony,
- tryb Przerwa blokuje logowanie zwykłych użytkowników, zachowując dostęp administratorów do `/wp-admin`, i pokazuje ekran „Ruszamy w sezonie 2026/2027”,
- logo TypujKosza.pl w nagłówku i stopce jest linkiem do `https://typujkosza.pl/`.

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
- stare REST callbacki pozostają zarejestrowane dla kompatybilności; mobilne OAuth bez zmian.

## 0.4.17 — 2026-08-20

- usunięto ogromną pustą przestrzeń pomiędzy kartą logowania a właściwą częścią landing page,
- publiczny landing nie dziedziczy już `min-height: 100vh` przeznaczonego dla zalogowanej aplikacji,
- linki i istniejące adresy z fragmentem `#decka-typer` są automatycznie czyszczone do kanonicznego adresu TypujKosza.pl,
- uproszczono webowy callback OAuth i ujednolicono adres pokazywany w panelu z faktycznym żądaniem.

## 0.4.16 — 2026-08-20

- zmniejszono pionowe odstępy pomiędzy modułami landing page,
- zwiększono typografię treści,
- w całym widocznym interfejsie określenie „kupon” zastąpiono terminologią „typowanie”.

## 0.4.15 — 2026-08-20

- webowe callbacki OAuth Google i Facebook przeniesiono z `/wp-json/...` na standardowy endpoint WordPress,
- usunięto zależność logowania WWW od reguł REST API i permalinków serwera.

## 0.4.14 — 2026-08-20

- dodano centralny adres kanoniczny aplikacji `https://typujkosza.pl/`,
- stare `/typer`, wariant `www` i powroty logowania są normalizowane do strony głównej.

## 0.4.13 — 2026-08-20

- dodano landing page TypujKosza.pl dla niezalogowanych użytkowników,
- dodano treści SEO, meta description, Open Graph, Twitter Card i dane strukturalne `WebApplication`,
- dodano wizualne mockupy aplikacji, sekcje korzyści i instrukcję rozpoczęcia gry.

## 0.4.12 — 2026-08-20

- przebudowano nagłówek pod markę TypujKosza.pl,
- szeroki logotyp PNG jest głównym elementem hero,
- dodano hasło „Typuj mecze. Zdobywaj punkty. Rywalizuj w rankingu.”,
- usunięto pole nazwy ligi z prawej strony nagłówka,
- poprawiono błędne wypisywanie dosłownych znaków `\n\n`.

## 0.4.11 — 2026-08-20

- branding korzysta z oryginalnych plików PNG w repozytorium,
- nagłówek, stopka, favicon i panel administratora przepięto na PNG.

## 0.4.10 — 2026-08-20

- publiczna marka aplikacji została zmieniona na **TypujKosza.pl**,
- dodano oficjalne logo, nową kolorystykę i stopkę,
- usunięto publiczny fallback logowania kontem WordPress.

## 0.4.9 — 2026-08-20

- Typer jest renderowany bezpośrednio jako strona główna WordPressa (`/`),
- stary adres `/typer` przekierowuje na stronę główną.

## 0.4.8 — 2026-08-20

- usunięto automatyczne wyróżnianie meczów Decki Pelplin,
- dodano czytelniejsze oznaczenie ulubionej drużyny.

## 0.4.7 — 2026-08-20

- użytkownik może wybrać ulubioną drużynę,
- dodano trwałą sesję zwykłego użytkownika.

## 0.4.6 — 2026-08-20

- uproszczono ranking i selektory kolejek.

## 0.4.5 — 2026-08-20

- ranking otrzymał zakresy wszechczasów, sezonu i kolejki,
- dodano kolumnę BONUS i medale TOP 3.

## 0.4.4 — 2026-08-20

- dodano konfigurowalny mecz BONUS na kolejkę,
- zamknięte kolejki można przeglądać bez wcześniejszego typowania.

## 0.4.3 — 2026-08-20

- przyspieszono przełączanie kolejek przez cache i prefetch.

## 0.4.2 — 2026-08-20

- zamknięte kolejki pozostają dostępne do podglądu,
- forma jest liczona historycznie względem oglądanej kolejki.

## 0.4.1 — 2026-08-20

- dodano ustawienia użytkownika i publiczną nazwę rankingową.

## 0.4.0 — 2026-08-20

- dodano backend sesji/tokenów dla aplikacji iOS oraz mobilny bridge do sesji WordPress.

## Starsze wersje

Pełna historia zmian wersji `0.1.x–0.3.x` pozostaje dostępna w historii Git repozytorium.
