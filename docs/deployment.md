# Despliegue

Requisitos: PHP 8.4+ (probado en 8.5), Composer 2, Node 22+, MySQL 8+, poppler-utils (`pdftotext`, `pdfinfo`),
un worker de colas si se pasa el procesamiento de documentos a jobs, Ollama (embeddings `nomic-embed-text`) o el
proveedor de embeddings que se configure, y `ANTHROPIC_API_KEY` válida para el redactor/visión.

```
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force && php artisan db:seed --force
php artisan storage:link
php artisan melectric:import-catalog <excel> --images=<carpeta>
php artisan melectric:ingest-documents <carpeta-pdf>
php artisan melectric:crawl-public
php artisan config:cache route:cache view:cache
```

- Almacenamiento: `storage/app/private` (fuentes y documentos) en disco o S3 compatible; las imágenes de línea en el
  disco público.
- Respaldos: base `melectric_ia` (mysqldump diario) + `storage/app/private`. El índice vectorial (`chunk_embeddings`) no
  necesita respaldo: se regenera con `melectric:ingest-documents --reprocess`.
- Salud: `/up` (framework). Pendiente: endpoint autorizado con estado de DB, almacenamiento, proveedor de IA y embeddings.
- Modos de falla: sin LLM → respuestas deterministas; sin embeddings → texto completo; crawler caído → los datos ya
  guardados no se tocan; PDF ilegible → queda guardado y en `FAILED`/`NEEDS_REVIEW`.
