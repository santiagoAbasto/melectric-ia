# MElectric IA — Engineering & Product Intelligence

Plataforma técnico-comercial para MElectric: catálogo con procedencia, inteligencia documental, búsqueda eléctrica
estructurada, asistente que responde sólo con evidencia (y se abstiene o deriva cuando no la tiene), bandeja del
operador con revisión de ingeniería, centro de control de IA con trazas y evaluaciones, e identificación por foto (OCR).

## Arranque local

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate      # completar DB_* (MySQL) y DEV_USERS_PASSWORD
php artisan migrate && php artisan db:seed && php artisan storage:link
php artisan melectric:import-catalog                  # Excel B2B V2 + imágenes de línea (storage/app/private/sources)
php artisan melectric:ingest-documents                # 10 PDF públicos del sitio
php artisan melectric:crawl-public                    # páginas públicas (respeta robots, no entra a /Galeria)
swiftc -O bin/ocr-macos.swift -o bin/ocr-macos        # OCR local (sólo macOS)
npm run build && php artisan serve --port=8077
```

- Operadores: `/login` (usuarios de desarrollo `admin@`, `ingenieria@`, `ventas@`, `conocimiento@melectric.test`, contraseña en `DEV_USERS_PASSWORD`).
- Chat del cliente: `/chat`.

## Comandos

| Comando | Para qué |
|---|---|
| `php artisan melectric:import-catalog [excel] [--images=]` | Importa/actualiza el catálogo (idempotente; informa nuevos/actualizados/sin cambios/fallidos) |
| `php artisan melectric:ingest-documents [dir] [--reprocess]` | Pipeline documental de PDF |
| `php artisan melectric:crawl-public` | Sincroniza el sitio público |
| `php artisan melectric:eval [--llm]` | Dataset dorado del asistente con métricas de abstención |
| `./vendor/bin/pest` · `npm run typecheck` · `npm test` · `npm run build` · `npx playwright test` | Calidad |

## Documentación

| Tema | Archivo |
|---|---|
| Arquitectura, ontología, unidades, grafo de compatibilidad, documentos y tablas, búsqueda, RAG, orquestador, centro de IA, ingeniería, visión | `docs/architecture.md` |
| Modelo de datos | `docs/database-erd.md` |
| Inventario de fuentes y fronteras de acceso | `docs/melectric-source-inventory.md`, `docs/data-access-boundaries.md` |
| Seguridad · Pruebas y evaluaciones · Despliegue | `docs/security.md`, `docs/testing.md`, `docs/deployment.md` |
| Decisiones | `docs/adr/` |
| Reglas permanentes para agentes | `CLAUDE.md` |

## Estado y bloqueos

Ver la sección «Estado» al final de `docs/architecture.md` y la memoria del proyecto. Bloqueos reales a la fecha
(2026-11-09): la `ANTHROPIC_API_KEY` del entorno devuelve 401 (se usa Ollama local como redactor y el sistema funciona
sin LLM); el catálogo con precios (`/Galeria`) requiere credenciales que no tenemos; el OCR local sólo corre en macOS.
