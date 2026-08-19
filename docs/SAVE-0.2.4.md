# Zapis kuponu — 0.2.4

Standardowy zapis kuponu z `/typer` korzysta teraz z uwierzytelnionego `wp-admin/admin-ajax.php` (`wp_ajax_dt_save_submission`) z osobnym nonce. POST REST `/decka-typer/v1/submission` pozostaje jako fallback kompatybilności, ale frontend nie używa go w normalnym przepływie.

`assets/js/submission-ajax.js` przechwytuje wyłącznie POST do endpointu zapisu i przekazuje payload do AJAX. Odczyty `/bootstrap`, `/round`, `/ranking` i `/me` pozostają bez zmian w REST.

Oba transporty korzystają z jednej metody walidacji i transakcji, więc kupon nadal jest kompletny, jeden na kolejkę i nieedytowalny po zapisie.
