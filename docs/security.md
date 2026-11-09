# Seguridad

- **Autenticación:** sesión de Laravel (`Auth::attempt`), contraseñas con hash del framework, login con throttle 10/min.
  Usuarios de desarrollo sólo en `local` con contraseña en `DEV_USERS_PASSWORD` (.env), nunca en el código.
- **Autorización:** 9 roles × 16 permisos (`App\Enums\Role`), Gates registrados por permiso y middleware `can:` en cada
  ruta. La UI oculta acciones, pero el backend es el que decide (probado en `tests/Feature/PlatformTest.php`).
- **Chat público:** sin cuenta; cada conversación tiene un token aleatorio de 48 caracteres; throttle en inicio y envío;
  el cliente nunca recibe sugerencias internas, reglas ni trazas.
- **Archivos:** firma `%PDF-` obligatoria aunque la extensión diga PDF, máximo 50 MB, nombre interno por SHA-256 en disco
  privado (`storage/app/private`), servidos sólo a usuarios autenticados con `X-Content-Type-Options: nosniff` y CSP
  `default-src 'none'`; las páginas web guardadas se sirven como texto plano. No se ejecutan macros (PhpSpreadsheet en
  modo sólo datos). SVG no se aceptan.
- **Fuentes de texto:** Word/Excel se validan por firma ZIP y tamaño descomprimido; TXT/MD/CSV no pueden traer bytes
  binarios; la extensión se valida en el servidor (`extensions:pdf,docx,txt,md,csv,xlsx`).
- **Sincronizador Python:** misma política que el PHP (lista blanca, IP públicas, sin redirecciones ni login); el dominio
  a sincronizar se valida contra `SYNC_ALLOWED_HOSTS` antes de lanzar el proceso; corre sin shell (argumentos separados).
- **SSRF:** `UrlGuard` sólo http/https, lista blanca de hosts, sin credenciales en la URL, resuelve DNS y rechaza
  loopback, redes privadas, link-local (incluye 169.254.169.254) y rangos reservados; el crawler no sigue redirecciones.
- **Prompt injection:** documentos y páginas son datos; el system prompt lo dice, la evidencia va entre `<evidencia>`, los
  fragmentos con instrucciones incrustadas se marcan y excluyen, y la guardia numérica filtra lo que el LLM agregue.
- **Privacidad:** al proveedor de IA sólo va el mensaje actual (correos y teléfonos se redactan) y la evidencia; nunca
  nombre, correo ni teléfono del cliente.
- **Secretos:** sólo en variables de entorno (`ANTHROPIC_API_KEY`); `.env` fuera del repositorio; `.env.example` sin valores.
- **Auditoría:** `audit_logs` inmutable (sin `updated_at`; update/delete bloqueados en el modelo): importaciones,
  cambios de referencia, documentos subidos/reemplazados, conflictos resueltos, revisiones, reglas, prompts publicados,
  cambios de modo y aprobaciones de ingeniería.
- **Bases de datos:** desarrollo sólo en `melectric_ia`; pruebas en SQLite en memoria forzado por `phpunit.xml`.
