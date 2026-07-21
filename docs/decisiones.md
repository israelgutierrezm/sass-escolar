# Bitácora de decisiones — Acadion

Registro de decisiones de arquitectura y diseño tomadas durante la construcción
del sistema. Cada entrada anota la fecha, el contexto, la decisión y su razón.
Cuando la especificación (`especificacion-esquema.md`) presenta una ambigüedad,
la resolución se documenta aquí antes de implementar.

---

## 2026-07-21 — Fase 0: fundación

### Estructura del repositorio: monolítico Laravel + Inertia
- **Decisión:** Laravel 12 vive en la raíz del repo. El front (Vue 3 + TS) irá
  más adelante vía Inertia + Vite, integrado en el mismo proyecto.
- **Razón:** un solo repo, un solo deploy, misma sesión maneja auth. Es el
  patrón ya probado en el proyecto IDP y facilita la integración con
  `stancl/tenancy`. Se descartó separar `backend/` + `frontend/` (SPA aislada)
  por no justificarse en esta etapa.

### Nombre del paquete Composer: `acadion/saas-escolar`
- **Decisión:** `name` del `composer.json` raíz.
- **Razón:** deja espacio al vendor `acadion` por si a futuro se separan
  paquetes (nómina, LMS, movilidad).

### Identidad de Git: local al repo
- **Decisión:** `user.name` / `user.email` configurados solo en
  `acadion/.git/config` (Israel Gutierrez / yosef.gutierrezm@hotmail.com),
  sin tocar la config global de la máquina.
- **Razón:** la config global heredaba otra identidad (del proyecto IDP) que el
  usuario no quiere usar aquí.

### Multi-tenancy: una base de datos por tenant (multi-database)
- **Decisión:** `stancl/tenancy` v3 en modo multi-database. La BD central
  (landlord) es `acadion_landlord`; cada escuela obtiene su propia BD
  (`tenant<id>`). `central_domains = ['127.0.0.1', 'localhost']`.
- **Razón:** lo exige la spec (convenciones globales). Aísla datos por escuela
  a nivel de base de datos, no de prefijo.

### Migraciones default de Laravel → capa TENANT
- **Decisión:** `users`, `cache` y `jobs` (más `password_reset_tokens` y
  `sessions`, que viajan dentro de `create_users_table`) se movieron de
  `database/migrations/` a `database/migrations/tenant/`.
- **Razón:** en un SaaS multi-tenant estas tablas son por escuela, no de la
  landlord. La landlord solo aloja `tenants`, `domains`, `super_admins` y los
  catálogos universales. Los administradores de la casa (super admins) usarán
  su propia tabla en la landlord (Fase 0).

### Migración de `spatie/laravel-permission` → capa TENANT
- **Decisión:** `create_permission_tables` se movió a
  `database/migrations/tenant/`. El trait `HasRoles` se agregó al modelo
  `App\Models\User`.
- **Razón:** según el Módulo 1 de la spec, `roles`, `permisos` y `rol_permiso`
  son tablas TENANT/TENANT-CONFIG: cada escuela define y nombra sus propios
  roles y permisos. El caché de permisos de Spatie queda aislado por tenant
  gracias al `CacheTenancyBootstrapper` (ya activo).
