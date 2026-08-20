# Decka Typer iOS

Wersja pluginu 0.4.0 dodaje backend wymagany przez aplikację iOS.

## Adres aplikacji

Domyślna instalacja testowa:

`https://zapisy.basketmania.pl`

## Google OAuth

Do klienta OAuth używanego przez Decka Typer dodaj jako **Authorized redirect URI**:

`https://zapisy.basketmania.pl/wp-json/decka-typer/v1/mobile/auth/google/callback`

Adres WWW pozostaje osobno:

`https://zapisy.basketmania.pl/wp-json/decka-typer/v1/oauth/google/callback`

## Facebook Login

Dodaj jako poprawny redirect URI:

`https://zapisy.basketmania.pl/wp-json/decka-typer/v1/mobile/auth/facebook/callback`

## Przepływ aplikacji

1. Aplikacja uruchamia systemowy `ASWebAuthenticationSession`.
2. Google/Facebook wraca do serwera WordPress.
3. Serwer wydaje podpisany token mobilny i przekierowuje do `deckatyper://oauth`.
4. Token jest przechowywany w Keychain iPhone'a.
5. Aplikacja wymienia token na jednorazowy adres `mobile/web-login`.
6. `WKWebView` otrzymuje zwykłą sesję WordPress i otwiera pełny `/typer`.

Dzięki temu OAuth nie działa wewnątrz osadzonego WebView, a właściwy Typer zachowuje wszystkie funkcje wersji WWW.

## Samodzielna instalacja

Projekt Xcode używa automatycznego podpisywania. W Xcode należy wybrać własny Apple Account / Personal Team i uruchomić projekt na podłączonym iPhonie.
