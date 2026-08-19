# Decka Typer

Nowoczesna wtyczka WordPress dla społeczności Decki Pelplin do typowania spotkań Pekao S.A. 1 Ligi.

## Wersja

Aktualna wersja: **0.1.0**

## Najważniejsze funkcje

- frontend Typera pod `/typer`,
- typowanie wyniku każdego meczu do chwili jego rozpoczęcia,
- ranking sezonu i poszczególnych kolejek,
- historia typów i statystyki użytkownika,
- logowanie WordPress, Google, Facebook i Apple ID,
- automatyczny import terminarza i wyników z `1lm.pzkosz.pl`,
- ręczne mecze i wyniki chronione przed nadpisaniem przez synchronizację,
- panel administratora: Pulpit, Kolejki, Mecze, Typy, Ranking, Użytkownicy, Statystyki, Synchronizacja 1LM, Historia i Ustawienia,
- aktualizacje wtyczki bezpośrednio z GitHub Releases.

## Aktualizacje WordPress

Wtyczka korzysta z nagłówka `Update URI` oraz publicznych GitHub Releases repozytorium `kaulpl/decka-typer`.

Każde stabilne wydanie zawiera asset:

`decka-typer-X.Y.Z.zip`

Po opublikowaniu nowszego Release WordPress może wykryć nową wersję i zainstalować ją standardowym mechanizmem aktualizacji wtyczek. Użytkownik może również skorzystać ze standardowego przełącznika automatycznych aktualizacji WordPressa.

## Proces wydania

1. Rozwój odbywa się na gałęzi `agent/vX.Y.Z-*`.
2. Pull request trafia do `main`.
3. GitHub Actions sprawdza składnię PHP/JS oraz zgodność `VERSION`.
4. Po stabilnym merge do `main` workflow `Release plugin` ponownie waliduje kod.
5. Workflow buduje prawidłowy pakiet WordPress z katalogiem `decka-typer/`.
6. Automatycznie powstaje tag `vX.Y.Z`, GitHub Release, ZIP oraz plik SHA-256.

## Zasady wersjonowania

- `main` — tylko stabilne wydania,
- poprawki: `0.1.1`, `0.1.2`, ...,
- nowe funkcje: `0.2.0`, `0.3.0`, ...,
- numer wersji musi być identyczny w `VERSION`, nagłówku `decka-typer.php` i `DT_VERSION`,
- zmiany opisujemy w `CHANGELOG.md`.

## Pierwsza instalacja

Pierwszą wersję należy zainstalować z ZIP-a opublikowanego w GitHub Release. Kolejne stabilne wersje będą wykrywane przez mechanizm aktualizacji WordPressa.

## Uwaga dotycząca OAuth

Google, Facebook i Apple wymagają osobnych danych aplikacji OAuth skonfigurowanych w panelu administratora Decka Typer. Sekrety nie są przechowywane w repozytorium.
