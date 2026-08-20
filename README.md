# TypujKosza.pl

**Typuj mecze. Zdobywaj punkty. Rywalizuj w rankingu.**

Wtyczka WordPress do prowadzenia bezpłatnego koszykarskiego typera dla kibiców.

## Wersja

Aktualna wersja: **0.5.0**

## Rozgrywki

Od wersji `0.5.0` model danych i frontend są przygotowane do równoległej obsługi:

- **PLK** — Polska Liga Koszykówki,
- **1LM** — 1 Liga Mężczyzn,
- **2LM** — 2 Liga Mężczyzn, z oddzielnym kontekstem grup **A, B, C i D**.

Istniejące dane z wcześniejszych wersji są podczas migracji automatycznie przypisywane do 1LM. Kolejka jest identyfikowana przez zestaw **liga + sezon + grupa + numer kolejki**, dzięki czemu np. 1. kolejka PLK i 1. kolejka 1LM mogą istnieć jednocześnie.

Automatyczna synchronizacja terminarza i wyników pozostaje aktywna dla 1LM. PLK oraz grupy 2LM mają w `0.5.0` przygotowany model danych, konfigurację źródeł i pełną obsługę ręcznych kolejek/meczów; parsery automatycznego importu można dołączać bez kolejnej przebudowy bazy.

## Frontend po zalogowaniu

Strona główna pokazuje wszystkie aktualnie otwarte typowania w podziale na ligi. Każda kolejka jest osobną rozwijaną sekcją. Użytkownik rozwija wybraną kolejkę, zaznacza zwycięzców wszystkich meczów i zapisuje typowanie. Zapis pozostaje nieedytowalny.

Frontend obejmuje:

- rozwijane otwarte kolejki PLK, 1LM i 2LM,
- wyróżnienie grup 2LM,
- ulubioną drużynę i mecze BONUS,
- **Moje typy** jako rozwijane zapisane typowania z oznaczeniem ligi i sezonu,
- **Ranking** z zakresami: Wszechczasów, Liga, Sezon i Kolejka,
- filtry ligi, grupy 2LM, sezonu i kolejki,
- **Moje konto** zamiast wcześniejszej etykiety „Ustawienia”,
- rozbudowane osiągnięcia użytkownika: miejsce, punkty, trafienia, skuteczność, liczba typowań i perfekcyjne kolejki,
- osiągnięcia filtrowane według wszechczasów, konkretnej ligi i konkretnego sezonu.

## Tryby serwisu

W **TypujKosza.pl → Ustawienia** dostępny jest przełącznik trybu publicznego serwisu:

- **Produkcyjny** — standardowe działanie,
- **Testowy** — serwis działa normalnie, ale na górze publicznej strony widoczny jest żółty komunikat o wersji testowej,
- **Przerwa** — zwykli użytkownicy nie mogą się logować; publiczna strona pokazuje logo TypujKosza.pl, slogan i komunikat **„Ruszamy w sezonie 2026/2027”**. Konta administratorów mogą nadal normalnie korzystać z `/wp-admin`.

## Panel administratora

Wersja `0.5.0` dodaje wieloligową warstwę administracyjną:

- **Kolejki i ligi** — wspólny widok PLK, 1LM i 2LM z filtrem ligi, grupy oraz sezonu,
- ręczne tworzenie kolejki ze wskazaniem ligi i grupy 2LM,
- zarządzanie otwarciem/zamknięciem typowania,
- przejście bezpośrednio do meczów konkretnej kolejki,
- **Ligi i źródła** — aktywacja/dezaktywacja lig i grup oraz konfiguracja źródeł danych,
- czytelny status trybu Produkcyjny/Testowy/Przerwa,
- dotychczasowy moduł synchronizacji 1LM pozostaje aktywny.

## Branding

Publiczna marka aplikacji to **TypujKosza.pl**. Wtyczka korzysta z plików:

- `assets/img/typujkosza-logo-horizontal.png`,
- `assets/img/typujkosza-logo-stacked.png`,
- `assets/img/typujkosza-mark.png`.

Kolory bazowe: granat `#07162F`, niebieski `#055EFB`, pomarańczowy `#FB5D0B`, tło `#F4F7FB`. Logo w nagłówku i stopce jest linkiem do kanonicznej strony głównej `https://typujkosza.pl/`.

## Landing i SEO

Dla niezalogowanych użytkowników strona główna zawiera landing renderowany po stronie PHP z opisem zabawy, rywalizacji kibiców, rankingu i sposobu typowania. Warstwa SEO dodaje meta description, Open Graph, Twitter Card oraz dane strukturalne Schema.org `WebApplication`.

Bazowy rozmiar tekstu landingu nie schodzi poniżej `12px`.

## Model typowania

Użytkownik wybiera zwycięzcę każdego spotkania. Nie wpisuje dokładnego wyniku. Jedno kompletne typowanie przypisane jest do konkretnej kolejki i po zapisaniu nie może być edytowane.

Faktyczne wyniki są przechowywane w meczach i służą do naliczania punktów. Administrator może oznaczyć jeden mecz kolejki jako BONUS.

## Ranking

Nowy ranking obsługuje cztery zakresy:

- **Wszechczasów** — wszystkie ligi i sezony,
- **Liga** — wybrana PLK, 1LM lub 2LM,
- **Sezon** — wybrana liga i sezon, opcjonalnie grupa 2LM,
- **Kolejka** — konkretna kolejka wybranej ligi/sezonu/grupy.

## Strona główna i adres kanoniczny

Publiczna aplikacja działa bezpośrednio pod:

`https://typujkosza.pl/`

Stary `/typer` oraz wariant `www` są normalizowane do adresu kanonicznego. Pozostałe podstrony WordPressa i `/wp-admin` działają normalnie.

## OAuth

Google i Facebook wymagają własnych danych OAuth skonfigurowanych w **TypujKosza.pl → Ustawienia**.

Dla logowania WWW Google i Facebook używany jest jeden kanoniczny Redirect URI:

`https://typujkosza.pl/`

Panel diagnostyczny pokazuje dokładny Client ID Google używany przez wtyczkę i aktualny Redirect URI. Mobilne callbacki OAuth pozostają osobnymi endpointami REST.

## Charakter serwisu

TypujKosza.pl jest bezpłatną formą rozrywki i rywalizacji społecznościowej dla kibiców koszykówki. Serwis nie służy do zawierania zakładów ani przyjmowania stawek pieniężnych. Typy, punkty i rankingi mają charakter rozrywkowy i społecznościowy.

## Aktualizacje i wydania

Wtyczka korzysta z `Update URI` i GitHub Releases repozytorium `kaulpl/decka-typer`.

Proces wydania:

1. rozwój na gałęzi `agent/vX.Y.Z-*`,
2. pull request do `main`,
3. GitHub Actions sprawdza składnię PHP/JS i zgodność wersji,
4. squash merge do `main`,
5. workflow tworzy tag `vX.Y.Z`, Release, instalacyjny ZIP i SHA-256.
