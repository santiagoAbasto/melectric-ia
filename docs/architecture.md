# Arquitectura — MElectric IA

Monolito modular Laravel 13 + Inertia 3 + React 19 (TypeScript) + Tailwind 4. MySQL es la fuente de verdad; el índice
vectorial es reconstruible. Sin microservicios: los límites son carpetas y servicios.

```
FUENTES AUTORIZADAS (Excel B2B · PDF públicos · sitio público · subidas de admin)
  ↓  CatalogImporter · DocumentIngestionService · PublicWebsiteConnector   (procedencia: source + source_record / página)
NORMALIZACIÓN  (UnitService · ProductReferenceNormalizer · SpecExtractor · LayoutTableExtractor)
  ↓
PRODUCT DATA PLATFORM  (products · specifications tipadas · aliases · familias · categorías)
TECHNICAL KNOWLEDGE    (documents · pages · tables/cells · chunks · links documento↔producto · conflictos)
RELATIONSHIP GRAPH     (product_relationships con estado AUTO_EXTRACTED / VERIFIED / REJECTED)
  ↓
BÚSQUEDA  ProductSearchService: exacta → normalizada → tamaño de serie → estructurada → texto completo
          KnowledgeSearchService: texto completo + semántica (embeddings locales) sobre documentos vigentes
  ↓
RuleEngine (reglas deterministas, las de seguridad bloqueadas) · RequiredInputService · ConfidenceService
  ↓
ToolRegistry (herramientas tipadas: findReference, searchProducts, getProductSpecs, findCompatibleAccessories,
              compareProducts, getProductDocuments, getTechnicalTable, searchKnowledge, requestHumanReview)
  ↓
AIOrchestrator → borrador determinista con citas → [ModelRouter: ninguno / rápido / razonamiento]
              → LLM redactor opcional → NumericGroundingGuard → texto final
  ↓
ConversationService (AI_AUTO · AI_ASSISTED · HUMAN · ENGINEERING_REVIEW · PAUSED) → Bandeja del operador / chat web
  ↓
Supervisión humana: aprobación de ingeniería, conflictos, revisión de extracciones y relaciones, feedback
```

## Contextos (carpetas)

| Contexto | Código | Qué hace |
|---|---|---|
| Catalog | `app/Catalog` | Importador del Excel, normalizador, extractor de especificaciones, clasificador, unidades, relaciones |
| Documents | `app/Documents` | Pipeline PDF, tablas, referencias, mapeo aprobado de la tabla de contactores, versiones y reemplazos |
| Sources | `app/Sources` | Conector público con robots, lista blanca y guardia SSRF |
| Search | `app/Search` | Parser de consultas, búsqueda de productos y de conocimiento, embeddings y almacén vectorial |
| AI | `app/AI` | Orquestador, reglas, herramientas, confianza, citas, guardia, prompts versionados, evaluación, proveedores |
| Conversations | `app/Conversation` | Conversaciones, modos, derivaciones, aprobación de ingeniería |
| Authorization | `app/Enums/Role.php` + Gates | 9 roles × 16 permisos, verificados en rutas (middleware `can:`) |
| Audit | `app/Models/AuditLog.php` | Registro inmutable (sin `updated_at`; update/delete bloqueados) |

## Ontología de producto

- `brands` → `product_categories` (tipo de producto y rubro Domiciliario/Industrial) → `product_families` (el agrupador
  B2B, con su imagen de línea) → `products` (código interno, referencia cruda, normalizada, base sin bobina, serie).
- Identidad: `internal_code` (Código del Excel), `reference` (cruda, siempre se conserva), `normalized_reference`,
  `base_reference` (sin variante de bobina), `product_aliases` (base, catálogo; preparado para old/new/EAN).
- Especificaciones: `specification_definitions` (clave, tipo, dimensión, unidad canónica, crítica para seguridad) +
  `product_specifications` con `raw_value`, valor o rango en unidad canónica, `qualifier` (p. ej. «AC-3 380-400 V»),
  `extractor` y versión, `confidence`, `status` y procedencia (`source_record_id` o `document_id` + `page`).
- Serie como columna (`products.series`): una tabla aparte no aporta nada mientras no haya atributos de serie propios.

## Unidades (`UnitService`, `Quantity`, `Range`)

Unidad canónica por dimensión (A, V, W, VA, VAR, Hz, F, Ω, mm, s, °C, %). Se compara sólo dentro de la misma
dimensión: 30 mA (0,03 A) nunca equivale a 30 A. Decimales con coma, separador de miles con punto («2.600 A»), rangos
«0,16 a 63 A» o «Reg. 25-32 A» con mínimo, máximo y bordes inclusivos. Siempre se guarda el texto crudo.

## Grafo de compatibilidad

Las relaciones salen de frases del propio catálogo («Relé térmico para DILM17 hasta DILM32») y nacen
`AUTO_EXTRACTED` con la frase como evidencia. Regla `UNVERIFIED_RELATION_NO_CLAIM`: el asistente cita la frase pero no
afirma compatibilidad hasta que un ingeniero la marca `VERIFIED` (Conocimiento → Relaciones). Tipos: `PAIRS_WITH`
(relé térmico ↔ contactor), `AUXILIARY_FOR`, `ACCESSORY_OF` (bobinas, uniones, enclavamientos).

## Inteligencia documental

`register()` valida firma `%PDF-`, extensión, tamaño (≤ 50 MB) y deduplica por SHA-256. `process()` registra cada paso
en `processing_log` con valores reales: páginas (`pdftotext -layout`), tablas (`LayoutTableExtractor`: columnas por
posición, celdas vacías conservadas, encabezados tal cual, confianza por consistencia → `NEEDS_REVIEW` si < 0,85),
mapeos aprobados de tablas conocidas (`ContactorPowerTableMapper`: kW/A AC-3 y Ith AC-1 por tamaño, con página),
referencias (exacta 0,95 · base 0,9 · tamaño de serie 0,7), aviso de reemplazo («reemplaza la versión anterior»),
fragmentos de texto y de tabla, y embeddings. Si algo falla, el archivo queda y el documento pasa a `FAILED`/`NEEDS_REVIEW`.
Versiones: `DocumentPrecedenceService::supersede()` deja el anterior `SUPERSEDED` y descarta sus especificaciones no
verificadas; sólo se citan documentos vigentes. La fecha de subida no decide la vigencia.

Conflictos: cuando dos fuentes dan valores distintos para el mismo campo se crea `knowledge_conflicts` y ambas
especificaciones quedan `NEEDS_REVIEW`. Caso real: el Excel dice DILM17 = 17 A en AC-3 y el catálogo (pág. 3) 18 A.
Mientras el conflicto esté abierto, el asistente muestra los dos valores con su fuente y no da uno como definitivo.

## Búsqueda

`ElectricalQueryParser` (determinista) extrae tipo de producto, intenciones, valores con unidad y candidatos a
referencia. `ProductSearchService` prueba en orden: referencia exacta / código interno → normalizada y alias → tamaño de
serie (DILM25 → todas sus bobinas) → filtros estructurados sobre especificaciones tipadas → texto completo (MySQL
FULLTEXT; LIKE en SQLite). Contactores: tabla AC-3 válida sólo a 380-400 V (fuera de eso deriva); si no hay fila exacta
toma el tamaño siguiente y lo dice (`AC3_TABLE_NEXT_SIZE`). Los embeddings no seleccionan productos.
`KnowledgeSearchService` combina texto completo (0,45) y semántica (0,55) sobre documentos vigentes; marca fragmentos
con instrucciones incrustadas (`PROMPT_INJECTION_FLAGGED`) y los excluye del contexto del LLM.

## Orquestador y LLM

Flujos por intención: persona, saludo, cálculo/proyecto (siempre a ingeniería), precio/stock (siempre a ventas),
comparación, compatibilidad, documentos, variadores (sólo evidencia documental), selección. Cada flujo arma un borrador
determinista con citas `[n]` a fila del Excel o página del PDF. El `ModelRouter` decide: sin LLM para referencias exactas,
preguntas, abstenciones y derivaciones; modelo rápido para selecciones; modelo de razonamiento para comparaciones y
documentos. El LLM sólo reescribe; `NumericGroundingGuard` descarta su texto si aparece un número que no está en la
evidencia o si afirma compatibilidad, precio o stock que el borrador no afirma. Si el proveedor falla, se envía el
borrador (el catálogo y la búsqueda nunca dependen del LLM).

Selecciones técnicas con confianza bajo el umbral del perfil (`SAFETY_LOW_CONFIDENCE_REVIEW`) no llegan al cliente:
quedan como sugerencia en modo `ENGINEERING_REVIEW` hasta que un ingeniero las aprueba (puede editarlas). Queda auditado.

Memoria técnica de la conversación: `technical_context` guarda cada valor con su procedencia (`USER_PROVIDED`,
`CATALOG_CONFIRMED`…) y se reutiliza en preguntas de seguimiento («¿y con bobina de 24 Vcc?»).

Proveedores: `anthropic` (Claude Haiku 5.5 rápido, Opus 5.5 razonamiento/visión; fallback del servidor sólo en
Opus/Sonnet), `ollama` (local, sin salida de datos) y `fake` (sólo pruebas, bloqueado en producción).

## Centro de IA

Resumen, playground (misma tubería que el chat, con traza completa), prompts versionados (borrador → evaluación →
publicación → vuelta atrás; nunca se sobrescribe), reglas (umbral editable; las de seguridad no se apagan), perfiles,
herramientas, trazas (`ai_runs`), evaluaciones (dataset dorado) y costos por modelo, perfil y origen.

## Ingeniería

`RequiredInputService` define qué datos hacen falta por tipo de producto y pregunta como máximo dos. No hay fórmulas de
dimensionamiento: cálculo de kVAR, cortocircuito, selectividad, caída de tensión y proyectos completos derivan a
ingeniería con la lista de datos a enviar (spec §9). Pendiente: `EngineeringCalculationService` con metodología aprobada.

## Visión

`VisionPipeline`: valida la foto (JPG/PNG/WEBP reales por firma, ≤ 10 MB, SVG rechazado) → OCR (`OcrProviderInterface`)
→ campos visibles (In, Un, Icn…) → referencias con `ReferenceDetector` → búsqueda exacta → candidatos con puntaje
(confianza del método × confianza del OCR) → `needs_more_information` si no hay referencia legible o el puntaje es < 0,6.
Respuestas: referencia del catálogo leída → producto con sus datos del catálogo y la aclaración de que la foto no certifica
valores; referencia leída que no existe → no se inventa equivalente; nada legible → se pide una foto frontal y de la
etiqueta. El operador marca Correcta / Incorrecta / No identificable (`ai_feedback`) para armar un dataset curado; no
hay reentrenamiento automático.

Proveedor actual: `MacVisionOcrProvider` (framework Vision de macOS, binario `bin/ocr-macos` compilado de
`bin/ocr-macos.swift`), sólo para desarrollo en Mac. Probado con las fotos del cliente: lee «mRCM-25/4/003» y
«mMC6-B1/3». Para producción en Linux falta enchufar tesseract o un modelo de visión con credenciales válidas. El
OCR corre después de responder la petición HTTP y la respuesta llega por el sondeo del chat.
