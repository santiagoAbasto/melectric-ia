# Inventario de fuentes — MElectric

Relevado el 2026-11-09. Hashes SHA-256 abreviados (16 caracteres). Todo lo que figura acá está copiado en
`storage/app/private/sources/` (fuera del repositorio y de la carpeta pública).

## 1. Archivos entregados por el cliente (ADMIN_UPLOAD)

| Fuente | Ruta | Acceso | Contenido | Cobertura | Notas |
|---|---|---|---|---|---|
| Excel «Clasificación catálogo productos B2B V2» | `excel/Clasificacion _ catalogo productos B2B_V2.xlsx` (a60aa1b440d7a667) | ADMIN_UPLOAD | 4 hojas | 2 171 productos, 280 líneas | **Fuente canónica del catálogo.** |
| ↳ hoja `B2B` (visible) | A1:D2172 | | Código interno, Desc Breve (= referencia del fabricante), Desc Detallada, «B2B (Modelo (Agrupador))» | 2 171 filas, 0 códigos repetidos, 7 referencias repetidas | El agrupador coincide con el nombre de la imagen de línea. |
| ↳ hoja `MCBs & RCCBs` (visible) | B5:I879 | | Código, FAMILIA, Subfamilia, MARCA, Descripción (= referencia), Polos | 184 filas: 157 termomagnéticas + 27 diferenciales, todas EATON | Subfamilias: Curva C 4.5/6/10/20kA, Auxiliares, 10/30/300 mA, Selectivos, Super Inmunizados. Los 184 códigos existen en `B2B`. |
| ↳ hoja `Raúl` (oculta) | A2:O3591 | | Copia de trabajo de la «Lista de Precios Diciembre 2022»: código, referencia, descripción, agrupador, contador con fórmulas `=IF(L6=L5,M5+1,1)` | ~2 400 filas | **No tiene columna de precio.** No se importa: es la planilla de trabajo con la que se armó `B2B`. |
| ↳ hoja `Raul 2` (oculta) | B1:O649 | | Productos **Hager** (MU…, CDH…, HMK…, fusibles NH) con descripción y agrupador | ~526 filas | **No se importa**: Hager no figura en la hoja `B2B` ni en el Figma (marcas: MElectric, Eaton, Phoenix Contact). Pendiente de confirmar con el cliente. |
| Excel «Clasificación catálogo productos B2B 2» | `excel/Clasificacion _ catalogo productos B2B 2.xlsx` (bab2a7b248518339) | ADMIN_UPLOAD | Mismas hojas que V2 **sin** `B2B` | — | Versión anterior. Se conserva, no se importa. |
| Imágenes «Imágenes B2B MElectric» | `images/` | ADMIN_UPLOAD | 275 JPG + 1 WEBP | 1 imagen por **línea** (agrupador), no por producto | Los nombres tienen la codificación rota (CP437/CP850 leído como CP1252: `Rel‚ T‚rmico` = «Relé Térmico»). Se emparejan por clave ASCII (ver `LineImageMatcher`). 242 de 280 líneas tienen imagen; varios archivos son idénticos (mismo hash) para líneas distintas. |

## 2. Sitio público (PUBLIC)

`https://www.melectric.com.ar/` — ASP.NET MVC hecho por Maer Software, detrás de Cloudflare.

| Ruta | Estado | Contenido útil |
|---|---|---|
| `/` | 200 | Institucional, marcas (Eaton, MElectric, Phoenix Contact) |
| `/Home/Nosotros` | 200 | Historia (agente Moeller desde 2002, Eaton desde 2008), +160 distribuidores, +30 000 productos, ISO 9001:2015 TÜV Rheinland, laboratorio, capacitaciones |
| `/Home/Faq` | 200 | Horarios, garantía (mayoría 12 meses), asesoramiento técnico, cómo ser distribuidor |
| `/Home/Distribuidores` | 200 | Texto; el listado se carga por script (`Sucursales.js`), no hay datos estáticos |
| `/Blog` | 200 | **Página de catálogos**: 10 enlaces a PDF |
| `/Seguridad/Contacto` | 200 | Formulario de contacto |
| `/Galeria/` | 302 → `/?ReturnUrl=%2FGaleria%2F` | **Catálogo con precios: requiere login.** No se accede. |
| `/sitemap.xml` | 200 | Una sola URL (la home) |
| `/robots.txt` | 200 | `Content-Signal: search=yes, ai-train=no, use=reference`; bloquea ClaudeBot, GPTBot, CCBot y otros crawlers de entrenamiento |

### Catálogos PDF públicos (`/Content/Catalogos/`)

| PDF | Págs. | Fecha del PDF | Hash | Familias / tablas |
|---|---|---|---|---|
| Contactores y Relevos Termicos | 6 | 2024-02-01 | df8bdf9f7e515ea7 | E-Line, DILEM, DILM, DILK, DILMP; **tabla kW/A AC-3 → referencia** y **tabla referencia × tensión de bobina → código interno** |
| Guardamotores PKZM0 y PKZM4 | 4 | 2024-01-30 | 8d7bdbb06a038c7e | PKZM0, PKZM4, rangos de regulación |
| Interruptores compactos, una gama completa de soluciones. | 6 | 2024-07-04 | 0db7585e1abcf2b0 | LZM/NZM, PDC |
| Protección para aplicaciones múltiples. Termomagnéticas y diferenciales | 6 | 2024-01-30 | d6489563f0270dbc | mMC6, mMCM, mMCT, mMCMDC, PLN6, IS, diferenciales; tablas corriente × polos → referencia |
| Líder en soluciones de compensación de energía reactiva | 6 | 2025-11-13 | aca6fce2274ea12f | Capacitores GSADG, reguladores PFR |
| Medición sencilla, supervisión fiable y planificación flexible | 4 | 2024-01-30 | be7c49ba86332c80 | Relés ETR4/ETR2, EMR6 |
| Aparatos de mando y señalización RMQ-Titan® | 4 | 2024-01-30 | dea76814a6d43d89 | M22 (botoneras, pilotos) |
| Variadores de frecuencia MElectric PI150 y PI500 | 6 | 2025-05-20 | cc7c33b72186ba68 | PI150, PI500 |
| Productos General (Folleto General) | 4 | 2024-01-30 | 1c96d9cad9ebd2a2 | Panorama de líneas y servicios |
| Certificado ISO 9001 2024 (`/Content/certificado_9001_2024_ES.pdf`) | 1 | 2024-05-27 | e1cd674e9a1a8e0a | Certificación de la empresa (no de producto) |
| Protección de arco AFDD+ | — | — | — | **404**: el enlace del sitio está roto |

Total: 9 catálogos + 1 certificado, 47 páginas.

## 3. Figma del rediseño (referencia visual, no datos)

`figma.com/design/YycyxTiEuC4d4bEQHlsLwZ/Melectric` — rediseño del sitio público y de la zona privada B2B (Productos,
Carrito, Mis pedidos, Lista de precios, Info de pagos, Cuenta corriente, Reclamos). Tokens: Azul `#1666AE`,
Negro `#1F1F1F`, Gris línea `#E5E7EB`, Gris 600 `#5F5F5F`, Blanco. Filtros del catálogo: Marca → Familia → Subfamilia y
Rubro (Domiciliario / Industrial). Los precios, stock y descuentos que muestra el Figma son **maqueta**, no datos.

## 4. Cobertura y límites conocidos

- El sitio público **no publica productos**: el catálogo real (con precios) está detrás del login de `/Galeria`.
  La cobertura de productos sale del Excel; la técnica, de los PDF.
- Marcas en datos: EATON (casi todo), MElectric (capacitores, controladores FP, variadores), Phoenix Contact (no aparece
  en el Excel: sólo en el sitio y el Figma).
- «+30 000 productos» es la cifra comercial del sitio; el Excel cubre 2 171.
- Las imágenes son por línea: no prueban qué referencia exacta es un producto.
- No hay fichas técnicas por producto ni datasheets de fabricante: las especificaciones salen de la descripción del
  Excel (con confianza menor) y de las tablas de los catálogos (con página).
- Precio y stock: no hay fuente autorizada. El sistema responde «No tengo acceso al precio/stock actualizado».

## 5. Estrategia de actualización

- Excel: reimportar con `php artisan melectric:import-catalog` (idempotente por código; registra nuevo/actualizado/sin cambios).
- PDF: `php artisan melectric:ingest-documents` (por hash; un PDF con el mismo hash no se reprocesa).
- Sitio: `php artisan melectric:crawl-public` con lista blanca de host, robots, 1 pedido/segundo, User-Agent propio.
