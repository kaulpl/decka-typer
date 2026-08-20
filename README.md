# TypujKosza.pl

**Typuj mecze. Zdobywaj punkty. Rywalizuj w rankingu.**

Wtyczka WordPress do prowadzenia bezpłatnego koszykarskiego typera dla kibiców. Aktualnie obsługuje terminarz i wyniki 1 Ligi Mężczyzn oraz stanowi bazę pod dalszą obsługę kolejnych lig i sezonów.

## Wersja

Aktualna wersja: **0.4.16**

## Branding

Publiczna marka aplikacji to **TypujKosza.pl**. Wtyczka korzysta bezpośrednio z oryginalnych plików PNG przechowywanych w repozytorium:

- `assets/img/typujkosza-logo-horizontal.png`,
- `assets/img/typujkosza-logo-stacked.png`,
- `assets/img/typujkosza-mark.png`.

Podstawowa identyfikacja wykorzystuje granat `#07162F`, niebieski `#055EFB`, pomarańczowy `#FB5D0B` i jasne tło `#F4F7FB`. Publiczny nagłówek wykorzystuje jasny layout z dużym logo TypujKosza.pl, hasłem marki pod logotypem oraz bez osobnego pola nazwy ligi. Od `0.4.13` pod trzykolorowym paskiem pozostaje standardowy odstęp przed właściwą zawartością strony.

## Landing i SEO

Dla niezalogowanych użytkowników strona główna zawiera dodatkowy landing page renderowany po stronie PHP. Obejmuje on naturalne treści dotyczące typera koszykarskiego, rywalizacji kibiców, rankingu, meczów BONUS, ulubionej drużyny oraz instrukcję rozpoczęcia gry. W części wizualnej znajdują się responsywne mockupy ekranów typowania i rankingu z przykładowymi danymi prezentacyjnymi.

Od `0.4.16` landing ma bardziej zwarty rytm pionowy, większą o około 2 px typografię treści i korzysta konsekwentnie z określenia **typowanie** zamiast wcześniejszego słowa „kupon”. Ta sama terminologia jest normalizowana w całym widocznym frontendzie i panelu administratora.

Warstwa SEO dodaje meta description, Open Graph, Twitter Card oraz dane strukturalne Schema.org `WebApplication` z informacją, że korzystanie z aplikacji jest bezpłatne.

## Najważniejsze funkcje

- samodzielny frontend ładowany bezpośrednio jako strona główna WordPressa (`/`), bez nagłówka i stopki motywu,
- kanoniczny publiczny adres aplikacji: `https://typujkosza.pl/`,
- wejście przez inną domenę lub wariant `www` jest przekierowywane na adres kanoniczny,
- stary adres `/typer` przekierowuje na stronę główną TypujKosza.pl,
- landing SEO i prezentacja aplikacji dla niezalogowanych,
- typowanie wyłącznie zwycięzcy meczu,
- jedno nieedytowalne typowanie na kolejkę,
- administrator jawnie otwiera i zamyka kolejki,
- zamknięte kolejki można przeglądać również bez wcześniejszego udziału,
- rankingi: wszechczasów, sezonu i kolejki,
- punkty, trafione/typowane, skuteczność oraz oddzielna kolumna BONUS,
- medale dla miejsc 1–3,
- jeden mecz BONUS w każdej kolejce z konfigurowalnymi dodatkowymi punktami,
- rozwijana historia „Moje typy”,
- publiczna nazwa rankingowa użytkownika,
- wybór ulubionej drużyny i personalizowane oznaczenie jej meczów,
- trwała sesja zwykłego użytkownika do świadomego wylogowania lub usunięcia cookies,
- aktualne miejsce drużyny i forma pięciu wcześniejszych spotkań,
- historyczna forma liczona względem terminu zamknięcia oglądanej kolejki,
- automatyczny import terminarza i faktycznych wyników z 1LM,
- ręcznie chronione mecze i wyniki,
- logowanie Google i Facebook,
- panel administratora: Pulpit, Kolejki, Mecze, Typy, Ranking, Użytkownicy, Statystyki, Synchronizacja, Historia i Ustawienia,
- aktualizacje bezpośrednio z GitHub Releases.

## Strona główna

Od `0.4.9` wtyczka nie wymaga osobnej strony WordPress pod adresem `/typer`. Frontend przejmuje publiczną stronę główną domeny i renderuje własny szablon standalone. Od `0.4.14` publiczny Typer ma stały adres kanoniczny `https://typujkosza.pl/`; przekierowania logowania oraz legacy `/typer` są normalizowane do tego adresu. Pozostałe podstrony WordPressa oraz `/wp-admin` działają normalnie.

## Model typowania

Użytkownik nie wpisuje dokładnego wyniku. Dla każdego spotkania wybiera jedną drużynę jako zwycięzcę. Faktyczny wynik meczu jest przechowywany osobno i służy do rozliczania typu oraz punktów.

## Mecze BONUS

Administrator może oznaczyć jeden mecz w kolejce jako BONUS. Trafienie daje standardową liczbę punktów za zwycięzcę oraz dodatkowe punkty z ustawień. Zmiana meczu BONUS lub wartości bonusu przelicza rozstrzygnięte typy.

## Ranking

Frontend obsługuje trzy zakresy:

- **Wszechczasów** — wszystkie sezony zapisane w bazie,
- **Sezon** — wybrany sezon,
- **Kolejka** — wybrany sezon i konkretną kolejkę.

## Dane ligowe i forma

Miejsca zespołów są cache'owane z oficjalnej tabeli 1LM. Forma pięciu ostatnich spotkań jest liczona lokalnie. Dla historycznej kolejki uwzględniane są tylko mecze zakończone przed terminem zamknięcia tej kolejki, bez wyników należących do niej samej.

## Charakter serwisu

TypujKosza.pl jest bezpłatną formą rozrywki i rywalizacji społecznościowej dla kibiców koszykówki. Serwis nie służy do zawierania zakładów ani przyjmowania stawek pieniężnych. Typy, punkty i rankingi mają charakter rozrywkowy i społecznościowy. Informacja ta jest również prezentowana w stopce publicznego frontendu.

## Aktualizacje WordPress

Wtyczka korzysta z `Update URI` oraz GitHub Releases repozytorium `kaulpl/decka-typer`. Stabilne wydania zawierają instalacyjny ZIP oraz plik SHA-256.

## Proces wydania

1. Rozwój na gałęzi `agent/vX.Y.Z-*`.
2. Pull request do `main`.
3. GitHub Actions sprawdza PHP, JavaScript i zgodność wersji.
4. Po merge workflow buduje pakiet wydania.
5. Powstają tag `vX.Y.Z`, GitHub Release, ZIP i SHA-256.

## OAuth

Google i Facebook wymagają własnych danych OAuth skonfigurowanych w **TypujKosza.pl → Ustawienia**. Od `0.4.15` logowanie WWW używa standardowego endpointu WordPress `wp-admin/admin-post.php`, a nie ścieżki `/wp-json/...`. Dzięki temu callback nie zależy od konfiguracji REST/permalinków serwera. Panel administratora pokazuje dokładny Redirect URI używany przez Google/Facebook; dla Google WWW jest to `https://typujkosza.pl/wp-admin/admin-post.php?action=dt_oauth_callback&provider=google`, a dla Facebooka analogicznie z `provider=facebook`. Mobilne callbacki OAuth pozostają endpointami REST.
