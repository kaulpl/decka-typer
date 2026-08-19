# Czas meczów 1LM

Wersja 0.2.0 preferuje oficjalny termin w formacie `DD.MM.YYYY HH:MM` z modułu meczu. Czas przypisany do transmisji (np. „Emocje TV – 18:55”) nie jest traktowany jako godzina rozpoczęcia spotkania, jeżeli w module znajduje się oficjalny start (np. 19:00).

Daty przechowywane jako lokalny `DATETIME` są w panelu administratora formatowane w tej samej strefie WordPressa, bez ponownego przesuwania przez `strtotime()`.
