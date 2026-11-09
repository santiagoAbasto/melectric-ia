# ADR 0001 — MySQL como fuente de verdad; índice vectorial reconstruible en la misma base

Decisión: los vectores se guardan en `chunk_embeddings` (JSON, normalizados) y se buscan por coseno en PHP.
Motivo: ~150 fragmentos hoy; Qdrant suma un servicio a operar sin beneficio a esta escala. La interfaz (`VectorStore`)
permite pasar a Qdrant cuando el corpus supere ~20 000 fragmentos o la búsqueda pase de ~300 ms.
