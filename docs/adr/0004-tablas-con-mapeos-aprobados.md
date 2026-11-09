# ADR 0004 — Tablas: todas se guardan, sólo los mapeos aprobados generan especificaciones

Decisión: el extractor guarda cualquier tabla con sus celdas (para búsqueda y revisión), pero sólo un mapeo revisado
por tabla conocida (hoy: potencias y corrientes de contactores) escribe especificaciones de producto, con página.
Motivo: interpretar columnas de tablas arbitrarias sin revisión puede asignar un valor al producto equivocado.
