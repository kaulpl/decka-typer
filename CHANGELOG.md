# Changelog

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

- zamknięte kolejki są dostępne do podglądu także bez wcześniejszego kuponu,
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
