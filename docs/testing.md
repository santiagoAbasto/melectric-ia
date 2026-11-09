# Pruebas y evaluaciones

| Capa | Comando | Qué cubre |
|---|---|---|
| Backend | `./vendor/bin/pest` | Unidades y rangos, normalizador (decimales), extractor, parser, guardia numérica, confianza, tablas, SSRF y robots, importador con filas reales (procedencia, idempotencia, conflictos), búsqueda en orden, tamaño siguiente AC-3, orquestador (citas, abstención, derivaciones, conflicto antes/después de resolver, LLM que inventa, proveedor caído, memoria de conversación), PDF real (tablas, especificaciones con página, conflicto DILM17, deduplicación), archivo falso, permisos, reglas bloqueadas, prompts con vuelta atrás, chat web, retención para ingeniería, crawler sin login |
| Tipos | `npm run typecheck` | TypeScript estricto |
| Frontend | `npm test` | Vitest (componentes de mensaje y confianza) |
| Build | `npm run build` | Vite de producción |
| E2E | `npx playwright test` | Flujos críticos en navegador (ver `tests/e2e`) |
| Asistente | `php artisan melectric:eval [--llm]` | Dataset dorado `tests/AI/evals/golden.json` |
| Sincronizador | `cd scraper && python3 -m unittest test_melectric_sync.py` | Texto sin menú ni pie, JSON-LD sin precios, SSRF y lista blanca, enlaces |

Las pruebas usan SQLite en memoria y proveedores `fake` (forzado en `phpunit.xml`): nunca tocan MySQL ni llaman a una IA real.

## Métricas del dataset dorado

`pass_rate`, `correct_answer_rate`, `false_answer_rate` (respondió cuando debía preguntar, abstenerse o derivar),
`correct_abstention_rate`, `unnecessary_abstention_rate` y `false_confident_technical` (respuesta técnica fallida con
confianza ≥ 0,8 — la métrica más peligrosa). El dataset lo escribió el mismo equipo que hizo el sistema: sirve como
regresión, no como medición independiente. Hay que sumar casos reales de la bandeja (feedback del operador).
