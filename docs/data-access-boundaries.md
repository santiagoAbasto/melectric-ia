# Fronteras de acceso a datos

Cada registro que entra al sistema lleva un `access_level` en su `source` y nunca se mezcla con otro nivel sin dejar rastro.

| Nivel | Qué es | Ejemplos | Puede llegar al cliente final | Puede ir a un proveedor de IA |
|---|---|---|---|---|
| `PUBLIC` | Publicado por MElectric sin login | Páginas institucionales, catálogos PDF de `/Content/Catalogos/` | Sí, con cita | Sí (robots: `use=reference`) |
| `ADMIN_UPLOAD` | Entregado por el cliente o subido por un administrador | Excel de clasificación B2B, imágenes de línea, PDF subidos | Sí, con cita | Sí, sólo los campos necesarios |
| `AUTHORIZED_PRIVATE` | Zona privada de MElectric con credenciales legítimas | `/Galeria` (precios, stock) | Sólo a usuarios autorizados | **No** sin decisión explícita |
| `DERIVED` | Calculado por el sistema a partir de otras fuentes | Especificaciones extraídas de descripciones, vínculos documento↔producto | Sí, marcado como derivado y con su fuente original | Sí |
| `EXTERNAL_VENDOR` | Documentación oficial del fabricante (Eaton, etc.) | Datasheets | Sí, con cita | Sí |

## Reglas

1. **No se evade el login.** `/Galeria/` redirige a `/?ReturnUrl=…`. El crawler público trata cualquier redirección a
   login como «fuera de alcance» y la registra; no prueba credenciales ni endpoints internos.
2. `AuthorizedCatalogConnector` existe como interfaz y está **deshabilitado** hasta que MElectric entregue credenciales
   y autorización por escrito. Cuando se habilite: credenciales sólo en variables de entorno, sesión en memoria, sin
   loguear cookies, y su salida queda con `access_level = AUTHORIZED_PRIVATE`.
3. **robots.txt de MElectric:** `Content-Signal: search=yes, ai-train=no, use=reference`. El sistema usa el contenido
   como referencia citada (RAG) para la propia MElectric; **nunca** para entrenar ni ajustar modelos. El crawler usa su
   propio User-Agent (`MElectricAI-Crawler`), respeta `Disallow` y va a 1 pedido por segundo.
4. Precio y stock son dominio dinámico: no se guardan como conocimiento. Sin `PricingProviderInterface` autorizado, la
   respuesta es «No tengo acceso al precio/stock actualizado».
5. La hoja oculta `Raul 2` (Hager) y la hoja `Raúl` (planilla de trabajo de la lista de precios 2022) no se importan
   hasta que el cliente confirme su vigencia.
6. Los datos de clientes y conversaciones no salen al proveedor de IA más allá del mensaje actual y el contexto técnico
   (potencia, tensión…). Nombre, correo y teléfono no se envían.
