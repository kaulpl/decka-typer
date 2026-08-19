# Changelog

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
