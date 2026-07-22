# Acadion — Sistema escolar SaaS multi-tenant

Contexto permanente del proyecto. Léelo al inicio de cada sesión.

## Qué es

Sistema escolar SaaS para escuelas mexicanas. Cada escuela es un **tenant** con
su propia base de datos; una BD **landlord** central guarda el registro de
escuelas y los catálogos universales.

## Fuente de verdad del diseño

**`docs/especificacion-esquema.md`** define el modelo de datos canónico (13
módulos, 5 fases, ~121 tablas). No re-diseñar el dominio: implementarlo.
Cuando la spec tenga una ambigüedad, **preguntar en vez de inventar** y anotar
la resolución en `docs/decisiones.md`.

Los otros dos documentos vivos:

- **`docs/decisiones.md`** — bitácora de decisiones de arquitectura, con el
  porqué de cada una. Léelo antes de cuestionar algo que parezca raro.
- **`docs/plan-migraciones.md`** — checklist del avance por fase y módulo, con
  lo hecho tachado y lo pendiente marcado.

## Stack

- Laravel 12 + PHP 8.3, MySQL 8 (WAMP local, InnoDB obligatorio)
- `stancl/tenancy` v3 en modo **multi-database** (una BD por escuela)
- `spatie/laravel-permission` para el catálogo de permisos
- Inertia + **Vue 3 + TypeScript** + Tailwind v4 + Vite

## Reglas de trabajo

1. **Commits incrementales** en español, Conventional Commits (`feat:`,
   `fix:`, `chore:`, `docs:`, `refactor:`). Uno por unidad lógica.
   **No pedir aprobación antes de commitear.** Sí pedirla antes de `git push`.
2. **Módulo por módulo**, respetando el orden de FKs de la spec. Al terminar
   cada módulo, parar y pedir validación.
3. **Convenciones de la spec al pie de la letra**: tablas en `snake_case`
   plural en español; toda tabla TENANT lleva `$table->auditoria()` (macro) y
   el trait `TieneAuditoria` en su modelo; catálogos TENANT-CONFIG con seeder.
4. **Probar contra la base real** antes de dar algo por hecho. Las pruebas de
   integración se hacen con script + `DB::rollBack()`, y la UI con el
   navegador. Reportar los resultados tal cual, incluidos los fallos.
   La suite versionada es `php scripts/prueba-actas.php` (43 verificaciones).
   NO va en `tests/`: phpunit corre contra SQLite en memoria y ahí se prueba
   justo lo que SQLite no sabe hacer (`LAST_INSERT_ID`, FKs reales, InnoDB).

## Decisiones de arquitectura que NO se deben cambiar

- **Sin FK cruzadas tenant → landlord.** Las columnas que apuntan a catálogos
  de la BD central (`personas.sexo_id`, `carreras.nivel_estudios_id`...) son
  `unsignedBigInteger` sin constraint. Las relaciones Eloquent sí resuelven
  cross-DB porque los modelos landlord usan el trait `CentralConnection`.
- **Modelos organizados por capa y módulo**: `App\Models\Landlord\` para la
  central; `Identidad\`, `Academico\`, `Admisiones\`, `ControlEscolar\`,
  `Asistencia\`, `Formularios\`, `Plataforma\` para el tenant.
- **Seeders separados**: `Database\Seeders\Landlord\LandlordDatabaseSeeder` se
  corre explícito contra la central; `DatabaseSeeder` es el seeder **raíz de
  tenant** y stancl lo ejecuta por escuela. No mezclarlos.
- **Roles unificados con Spatie, en dos niveles.** La tabla `roles` de Spatie
  se extendió con `nombre`, `tiempo_sesion` y `rol_padre_id`. Un rol sin padre
  es una **faceta** (administrativo, docente, alumno, aspirante, tutor
  educativo, padre de familia); los **roles funcionales** cuelgan de ella
  (encargado de admisiones, director de campus, auxiliar de control escolar…)
  y **heredan sus permisos**. La asignación vive en `persona_rol`, con bandera
  `activo` y `campus_id` como alcance.
- **El login es de PERSONAS, no de alumnos.** `usuarios` cuelga de `personas`;
  un aspirante necesita sesión desde el día uno. No existe tabla `users`.
- **Autorización con el `can:` de Laravel**, nunca con el `permission:` de
  Spatie: los roles cuelgan de la persona, y un `Gate::before` resuelve contra
  los permisos efectivos del **rol activo**.
- **La matrícula nace al final.** Un aspirante NO tiene matrícula; se genera al
  convertirlo en alumno, con `GeneradorMatricula` y su regla configurable.
  `contadores_matricula` no debe tener `id` autoincremental (rompe el
  incremento atómico y produce duplicados).
- **Temas relacionales**: `tema_tokens` guarda un color por FILA. Cascada:
  tema de la escuela → tema del usuario → `usuario_tema_override`.
- **La calificación asentada no se edita.** Un acta cerrada es historia
  escolar: para cambiar un número se emite un **acta de corrección**
  (`actas.acta_origen_id`) que da de baja lógica los renglones de kárdex de la
  original y asienta los nuevos. Ambas actas se conservan. Y una materia se
  asienta **una sola vez**: un segundo cierre ordinario duplicaría al alumno en
  su kárdex.
- **NULL no es cero en la captura.** Un componente sin capturar deja la
  calificación incompleta y bloquea el cierre del acta; nunca se pondera como
  0. Un cero es una calificación; un NULL es que el docente no llegó ahí.
- **Autorización de captura en dos capas**: el permiso dice QUÉ puede hacer el
  rol; estar en la tabla `docentes` dice SOBRE QUÉ materias. El permiso solo no
  basta — el rol `docente` tiene `asentar-acta`, así que no sirve para separar
  al docente de la materia de control escolar.

## Entorno local

MySQL de WAMP corriendo. Luego:

```bash
php artisan serve          # http://localhost:8000 (central)
npm run dev                # o npm run build
```

- Escuela de prueba: **http://demo.localhost:8000** — usuario `demo`,
  contraseña `demo1234`.
- Comandos de apoyo: `acadion:usuario-demo`, `acadion:oferta-demo`.
- `php artisan tenants:migrate`, `tenants:seed`, `tenants:list`.
- Si `demo.localhost` no resuelve, agregar `127.0.0.1 demo.localhost` a
  `C:\Windows\System32\drivers\etc\hosts`.

## Estado (actualizar al avanzar)

**Hecho:**

- Fase 0 completa: multi-tenancy, landlord con catálogos universales,
  configuración y feature flags por tenant.
- Fase 1 completa: Identidad (incluido el slice de auth), Estructura
  académica, Formularios dinámicos, Matrícula y admisiones.
- Fase 2 completa: Control escolar (ciclos, grupos, apertura de materias,
  inscripción validada) y Asistencia/reloj checador.
- **Captura de calificaciones y acta** (cierra la operación diaria): tablas
  `calificaciones_componente`, `actas` y `contadores_acta`; servicios
  `CalculadoraCalificacion`, `GeneradorFolioActa` y `AsentadorActa`; pantallas
  `/escolar/captura` (listado) y la hoja por materia con cálculo en vivo,
  firma del acta y acta de corrección.
- Interfaz: login, panel, conmutador de rol, CRUD de aspirantes con expediente
  y conversión a alumno, catálogo académico completo (campus, carreras,
  asignaturas, planes, malla curricular, seriación, esquema de evaluación,
  oferta), control escolar, captura de calificaciones y layout de
  administración con temas.

**Pendiente inmediato:**

1. **Módulo 7 — Finanzas** (Fase 3). Trae una decisión ya tomada y vinculante:
   `adeudos` y `pagos` nacen con `matricula_oferta_id` **nullable** más
   `aspirante_id`, y la conversión aspirante→alumno los **re-liga**. Ver
   `docs/decisiones.md`.
2. Módulos 8 (LMS) y 9 (Titulación SEP) de la Fase 3; luego Fase 4.

**Deuda conocida:**

- `reactivos_cleaver` está vacía a propósito: el banco real del test DISC viene
  del legacy y no debe inventarse.
- Falta pantalla para horarios de `asignatura_grupo`; sin ellos la validación
  de choque no bloquea.
- Falta la **impresión del acta** (PDF con folio, firmas y lista de alumnos).
  Hoy el acta existe y es consultable en pantalla, pero no se puede imprimir.
- `esquema_evaluacion` no se puede editar una vez que hay calificaciones
  capturadas contra él: la FK de `calificaciones_componente` lo impide y el
  CRUD del catálogo académico revienta en vez de explicarlo.
- No hay panel para la app central (landlord): `super_admins` existe pero sin
  interfaz ni guard propio.
