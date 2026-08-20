# Decka Typer

Nowoczesna wtyczka WordPress dla społeczności Decki Pelplin do typowania zwycięzców spotkań 1 Ligi.

## Wersja

Aktualna wersja: **0.3.0**

## Najważniejsze funkcje

- samodzielny frontend pod `/typer`, bez nagłówka i stopki motywu WordPress,
- typowanie wyłącznie zwycięzcy meczu przez kliknięcie drużyny,
- brak typowania dokładnego wyniku punktowego — w typie przechowywana jest tylko wybrana drużyna,
- jeden nieedytowalny kupon na każdą kolejkę,
- niezapisane wybory są tymczasowe i znikają bez ostrzeżenia po przeładowaniu lub zmianie kolejki,
- administrator otwiera kolejkę i określa termin zamknięcia typowania,
- ranking sezonu i poszczególnych kolejek z bilansem trafień oraz skutecznością procentową,
- historia „Moje typy” pogrupowana w rozwijane kupony według kolejek, z oznaczeniem trafień, nietrafień i punktów,
- aktualne miejsce drużyny `#[N]` pobierane z oficjalnej tabeli 1LM,
- forma pięciu ostatnich rozegranych meczów pod każdą drużyną, z kolorami W/L oraz mini-logotypami rywali,
- rozliczone mecze są kolorowane na zielono/czerwono zależnie od poprawności typu, a końcowy wynik jest pokazany osobno przy każdej drużynie,
- logowanie Google i Facebook,
- automatyczny import terminarza i faktycznych wyników z `1lm.pzkosz.pl`,
- ręczne mecze i faktyczne wyniki chronione przed nadpisaniem przez synchronizację,
- pełne nazwy drużyn oraz wyróżnienie meczu Decki Pelplin,
- panel administratora: Pulpit, Kolejki, Mecze, Typy, Ranking, Użytkownicy, Statystyki, Synchronizacja 1LM, Historia i Ustawienia,
- aktualizacje wtyczki bezpośrednio z GitHub Releases.

## Model typowania

Użytkownik nie wpisuje wyniku punktowego meczu. Dla każdego spotkania wybiera wyłącznie jedną z dwóch drużyn jako zwycięzcę. Tabela `dt_predictions` przechowuje identyfikator wybranej drużyny, punkty i status rozliczenia. Faktyczny wynik spotkania jest przechowywany oddzielnie w tabeli meczów i służy wyłącznie do rozliczenia typu.

Aktualizacja do `0.2.5` usuwa z `dt_predictions` stare kolumny służące niegdyś do typowania wyniku. Odwołania do tych pól pozostają wyłącznie w jednorazowej migracji bardzo starych instalacji, aby przed usunięciem kolumn zamienić dawne typy wynikowe na wybór zwycięzcy.

## Zasada kuponu

Każda kolejka ma status szkicu, otwartej lub zamkniętej. Tylko administrator może otworzyć kolejkę i ustawić termin zakończenia przyjmowania kuponów. Użytkownik wskazuje zwycięzcę wszystkich meczów dostępnej kolejki i zapisuje cały kupon jednym przyciskiem. Po zapisie kupon jest nieodwracalnie zablokowany dla użytkownika. Dopóki użytkownik nie kliknie „Zapisz typy”, jego bieżące wybory nie są zapisywane i mogą zostać swobodnie porzucone przez przeładowanie strony lub zmianę kolejki.

## Dane ligowe i forma

Miejsca drużyn są cache'owane z oficjalnej strony `https://1lm.pzkosz.pl/tabele.html`. Cache odświeża się po synchronizacji terminarza oraz okresowo przy wejściu do Typera. Forma pięciu ostatnich meczów jest wyliczana lokalnie z zakończonych spotkań przechowywanych już w bazie Decka Typer; najstarszy z pięciu meczów znajduje się po lewej, najnowszy po prawej.

## Synchronizacja 1LM

Terminarz i wyniki są pobierane z oficjalnej strony 1LM. Import nie zmienia ręcznie chronionych spotkań ani statusu otwarcia kolejki. Parser czasu preferuje właściwą godzinę meczu publikowaną w terminarzu, a nie wcześniejszą godzinę startu transmisji.

## Aktualizacje WordPress

Wtyczka korzysta z nagłówka `Update URI` oraz publicznych GitHub Releases repozytorium `kaulpl/decka-typer`. Każde stabilne wydanie zawiera asset `decka-typer-X.Y.Z.zip`, który WordPress może zainstalować standardowym mechanizmem aktualizacji wtyczek.

## Proces wydania

1. Rozwój odbywa się na gałęzi `agent/vX.Y.Z-*`.
2. Pull request trafia do `main`.
3. GitHub Actions sprawdza składnię PHP/JS oraz zgodność `VERSION`.
4. Po stabilnym merge do `main` workflow `Release plugin` ponownie waliduje kod.
5. Workflow buduje pakiet WordPress z katalogiem `decka-typer/`.
6. Automatycznie powstaje tag `vX.Y.Z`, GitHub Release, ZIP oraz plik SHA-256.

## OAuth

Google i Facebook wymagają danych aplikacji OAuth skonfigurowanych w `Decka Typer → Ustawienia`. Sekrety nie są przechowywane w repozytorium.
