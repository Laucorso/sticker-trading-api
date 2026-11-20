Verifica el cambio actual:
1. Ejecuta la suite completa: `docker compose exec app php artisan test`.
2. Ejecuta 5 veces seguidas la suite de concurrencia para detectar fallos intermitentes.
3. Ejecuta `trading:race`.
4. Resume: qué pasa, qué falla y si algún fallo es intermitente.