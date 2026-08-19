# Changelog

## 0.2.1 — 2026-08-19

- poprawiony mechanizm wykrywania aktualizacji z GitHub Releases,
- updater zgłasza aktualizację WordPressowi wyłącznie wtedy, gdy nowsza wersja ma gotowy instalacyjny ZIP,
- dodany przycisk „Sprawdź aktualizacje” na pulpicie Decka Typer,
- po wykryciu nowszej wersji przycisk zmienia się w „Aktualizuj do wersji X.Y.Z” i uruchamia standardowy aktualizator WordPressa,
- ręczne sprawdzenie czyści cache Decka Typer i transient `update_plugins`, a następnie wymusza ponowne sprawdzenie aktualizacji,
- dodany czytelny status wersji bieżącej, najnowszego GitHub Release i błędów API,
- skrócony cache informacji o Release do 15 minut oraz osobny krótki cache błędów,
- workflow `Release plugin` może być uruchamiany również ręcznie,
- workflow sprawdza, czy Release zawiera zarówno ZIP, jak i SHA-256,
- jeżeli istniejący Release jest niekompletny, workflow odbudowuje paczkę z właściwego tagu i naprawia assety,
- po publikacji workflow pobiera wydany ZIP i weryfikuje jego sumę SHA-256.

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
