# Modelo de datos (ERD)

```mermaid
erDiagram
    sources ||--o{ source_records : contiene
    sources ||--o{ documents : origina
    brands ||--o{ products : marca
    product_categories ||--o{ product_families : agrupa
    product_families ||--o{ products : linea
    source_records ||--o{ products : respalda
    products ||--o{ product_aliases : alias
    products ||--o{ product_specifications : especifica
    specification_definitions ||--o{ product_specifications : define
    source_records ||--o{ product_specifications : "procedencia Excel"
    documents ||--o{ product_specifications : "procedencia PDF (+page)"
    products ||--o{ product_relationships : "accesorio / relé / auxiliar"
    documents ||--o{ document_pages : paginas
    documents ||--o{ document_tables : tablas
    document_tables ||--o{ document_table_cells : celdas
    document_tables ||--o{ table_footnotes : notas
    documents ||--o{ document_chunks : fragmentos
    document_chunks ||--o| chunk_embeddings : "vector (reconstruible)"
    documents ||--o{ document_product_links : menciona
    products ||--o{ document_product_links : aparece_en
    products ||--o{ knowledge_conflicts : conflicto
    customers ||--o{ conversations : conversa
    conversations ||--o{ messages : mensajes
    conversations ||--o{ ai_runs : trazas
    ai_runs ||--o{ ai_feedback : feedback
    prompt_definitions ||--o{ prompt_versions : versiones
    users ||--o{ audit_logs : actor
```

Índices pensados por uso: `products.internal_code` (único), `normalized_reference`, `base_reference`, `series`,
`(category_id, family_id)`, FULLTEXT `(reference, description)`; `product_specifications (definition_id, value)`,
`(definition_id, value_text)`, `(product_id, definition_id)`; `documents.sha256` (único); `document_chunks` FULLTEXT;
`conversations (status, mode)`, `last_message_at`; `messages (conversation_id, created_at)`; `ai_runs.created_at`.
