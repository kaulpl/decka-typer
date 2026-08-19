# Walidacja 0.2.0

Przed publikacją sprawdzamy:

- składnię wszystkich plików PHP (`php -l`),
- składnię JavaScript (`node --check`),
- spójność `VERSION`, nagłówka WordPress i `DT_VERSION`,
- parser czasu 1LM na przypadku z transmisją `18:55` i oficjalnym startem `19:00`,
- migrację modelu typowania do winner-only,
- atomowy zapis kompletnego kuponu i blokadę ponownej edycji,
- widoczność tylko właściwych kolejek na `/typer`.
