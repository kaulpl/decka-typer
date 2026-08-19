# Decka Typer 0.2.0

## Model typowania

- Typowanie wyłącznie zwycięzcy meczu.
- Jeden kompletny kupon na kolejkę.
- Po zatwierdzeniu kupon jest nieedytowalny.
- Poprawny zwycięzca otrzymuje liczbę punktów skonfigurowaną w ustawieniach.

## Kolejki

- Importowane kolejki są szkicami.
- Administrator otwiera kolejkę i ustawia termin zamknięcia typowania.
- Jednocześnie może być otwarta jedna kolejka sezonu.
- Frontend pokazuje kolejki otwarte oraz rozpoczęte/zablokowane; użytkownik zachowuje dostęp do własnych historycznych kuponów.

## 1LM

Parser rozróżnia godzinę transmisji od oficjalnej godziny meczu i preferuje format `DD.MM.YYYY HH:MM`. Lokalny czas w panelu administratora nie jest ponownie przesuwany przez strefę czasową.

## Frontend

`/typer` korzysta z samodzielnego szablonu bez nagłówka i stopki motywu WordPress. Pełne nazwy zespołów są zawijane, a mecz Decki Pelplin ma wyróżnienie obejmujące cały moduł.

## Logowanie

Dostawcy społecznościowi: Google i Facebook. Apple ID został usunięty.
