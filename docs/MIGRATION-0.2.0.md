# Migracja 0.1.0 → 0.2.0

Aktualizacja wykonuje migrację bazy automatycznie przy pierwszym uruchomieniu kodu 0.2.0.

- Stare typy wyników są konwertowane do wyboru zwycięzcy na podstawie przewidywanego wyniku.
- Punkty zakończonych meczów są przeliczane według zasad winner-only.
- Kompletny historyczny kupon zostaje oznaczony jako zatwierdzony i nieedytowalny.
- Niekompletny historyczny zestaw typów może zostać jednorazowo dokończony; zapis pełnego kuponu blokuje kolejne zmiany.
- Dotychczas publikowane kolejki są po migracji szkicami, aby administrator świadomie wskazał kolejkę aktywną i termin zamknięcia.
