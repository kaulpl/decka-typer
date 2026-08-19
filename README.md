# Decka Typer 0.1.0

Nowoczesna wtyczka WordPress do typowania wyników 1 Ligi Mężczyzn przez społeczność Decki Pelplin.

## Najważniejsze funkcje

- automatyczne utworzenie strony `/typer` z aplikacją dla kibiców,
- synchronizacja terminarza i wyników z `https://1lm.pzkosz.pl/terminarz-i-wyniki.html`,
- automatyczna synchronizacja przez WP-Cron co godzinę,
- ręczna edycja terminu i wyniku meczu,
- ręcznie poprawiony mecz może być chroniony przed kolejnymi synchronizacjami,
- typowanie każdego meczu do momentu jego rozpoczęcia,
- automatyczne naliczanie punktów po pojawieniu się wyniku,
- ranking sezonu i kolejki,
- historia typów użytkownika,
- ręczne korekty punktów administratora z historią zmian,
- logowanie Google, Facebook i Apple ID,
- standardowe logowanie WordPress jako rozwiązanie zapasowe,
- responsywny interfejs desktop/mobile,
- nowoczesny panel administratora: karty, statusy, modale, toasty, badge i szybkie akcje.

## Moduły administratora

1. Pulpit
2. Kolejki
3. Mecze
4. Typy
5. Ranking
6. Użytkownicy
7. Statystyki
8. Synchronizacja 1LM
9. Historia
10. Ustawienia

## Instalacja

1. W WordPressie wybierz **Wtyczki → Dodaj nową → Wyślij wtyczkę na serwer**.
2. Wgraj ZIP `decka-typer-0.1.0.zip`.
3. Aktywuj wtyczkę.
4. Wtyczka utworzy (jeśli jeszcze nie istnieje) stronę `Typer` pod adresem `/typer`.
5. Wejdź w **Decka Typer → Synchronizacja 1LM** i uruchom pierwszy import.
6. Skonfiguruj zasady punktacji i dostawców logowania w **Decka Typer → Ustawienia**.

## Domyślna punktacja

- dokładny wynik: 5 pkt,
- poprawny zwycięzca i dokładna różnica punktów: 3 pkt,
- poprawny zwycięzca: 1 pkt,
- błędny zwycięzca: 0 pkt,
- bonus za perfekcyjną kolejkę: 0 pkt domyślnie (można włączyć w ustawieniach).

## Zasada ręcznej edycji

Mecz zaimportowany z 1LM może zostać poprawiony przez administratora. Podczas zapisu formularz domyślnie włącza flagę **Chroń przed synchronizacją 1LM**. Dopóki flaga pozostaje aktywna, synchronizacja rozpoznaje rekord, ale go nie modyfikuje.

## Logowanie społecznościowe

### Google

Utwórz OAuth Client typu Web Application i dodaj Redirect URI wyświetlany w panelu **Decka Typer → Ustawienia → Google**. Następnie wpisz Client ID i Client Secret.

### Facebook

Utwórz aplikację Meta z Facebook Login i dodaj `Valid OAuth Redirect URI` wyświetlany w ustawieniach wtyczki. Wtyczka używa Graph API v26.0.

### Apple ID

W Apple Developer skonfiguruj Sign in with Apple dla strony internetowej. Potrzebne są:

- Services ID (Client ID),
- Team ID,
- Key ID,
- prywatny klucz `.p8`,
- Return URL wyświetlany w ustawieniach wtyczki.

Apple ID wymaga HTTPS.

## Wymagania

- WordPress 6.5+,
- PHP 8.0+,
- HTTPS dla logowań społecznościowych,
- działające wychodzące połączenia HTTPS z serwera WordPress do PZKosz oraz dostawców OAuth.

Parser synchronizacji ma dwa tryby: DOM (preferowany) oraz zapasowy parser HTML dla serwerów bez rozszerzenia PHP DOM.

## Dane i bezpieczeństwo

- typy zapisują się w dedykowanych tabelach WordPress,
- operacje administratora zabezpieczone są capability checks i nonce,
- zapis typów przez REST API wymaga zalogowanej sesji WordPress i REST nonce,
- OAuth wykorzystuje losowy `state` przechowywany krótkotrwale,
- Apple ID token jest sprawdzany względem kluczy publicznych Apple,
- klucze OAuth są przechowywane w opcji WordPress i powinny być dostępne wyłącznie administratorom.

## Tabele bazy danych

Wtyczka tworzy tabele z prefiksem WordPress:

- `dt_teams`
- `dt_rounds`
- `dt_matches`
- `dt_predictions`
- `dt_social_accounts`
- `dt_point_adjustments`
- `dt_logs`

Dane nie są usuwane po zwykłej dezaktywacji wtyczki.
