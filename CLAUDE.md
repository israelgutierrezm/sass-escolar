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
   Las suites versionadas viven en `scripts/` (14 suites, 357 verificaciones):
   `prueba-actas`, `prueba-plantillas`, `prueba-ventanas-captura`,
   `prueba-ciclo-campus`, `prueba-apertura-grupos`, `prueba-alcance-docente`,
   `prueba-alumnos`, `prueba-docentes`, `prueba-documentos`,
   `prueba-formularios`, `prueba-multicarrera`, `prueba-suplantacion`,
   `prueba-finanzas`, `prueba-cobro`. NO van en `tests/`:
   phpunit corre contra SQLite en memoria y ahí se prueba justo lo que SQLite
   no sabe hacer (`LAST_INSERT_ID`, FKs reales, InnoDB).

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
- **El alcance por campus se resuelve con `persona_rol.campus_id`.**
  `Usuario::campusVisibles()` devuelve `null` con alcance global y un arreglo
  cuando está acotado; null ≠ arreglo vacío. Al guardar, lo que el usuario NO
  alcanza se preserva: nunca se destruye lo que no se ve.
- **Un ciclo aplica a N campus** (pivote `ciclo_campus`). Sin campus asignado =
  ciclo global. La clave del ciclo es única en toda la escuela.
- **Las plantillas de evaluación se MATERIALIZAN** en `esquema_evaluacion`, no
  se leen en vivo: las calificaciones apuntan a esa tabla. Una materia con
  calificaciones capturadas nunca se re-aplica, y editar su esquema a mano la
  desliga de la plantilla.
- **Sin ventanas de captura configuradas, el ciclo captura libre.** Configurar
  una es lo que empieza a bloquear. Ojo: `ciclos.captura_calif_hasta` es otra
  cosa —marca el acta como extemporánea al asentarla, no bloquea—.
- **El docente NO es personal administrativo.** No tiene `ver-grupos` ni
  `ver-alumnos`; opera en `/docencia` (sus materias, sus alumnos, su
  expediente). Su alcance sale de `docente_asignatura_grupo`, no del permiso.
  La captura vive en `/captura` —fuera de `/escolar`— porque la usan los dos
  oficios.
- **El alumno es la MATRÍCULA, no la persona.** Una persona puede tener varias
  matrículas; corregir su identidad alcanza a todas, la situación es de cada
  una.

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
- **Portal del docente** (`/docencia`) y **catálogo administrativo de docentes**
  (`/escolar/docentes`) con revisión de su expediente; **gestión de alumnos**
  (`/escolar/alumnos`) con búsqueda, kárdex y edición.
- **Aclaraciones del cliente sobre operación escolar** (cuatro bloques):
  ciclo multi-campus con alcance por rol; plantillas de evaluación
  reutilizables (`/academico/plantillas`) con reparto equitativo; calendario
  de captura por parcial con excepciones auditadas
  (`/escolar/ciclos/{id}/ventanas`); e interfaz de grupos con cascada
  carrera→plan, apertura de materias en lote por periodo y buscador de
  docentes.
- **Separación docente / control escolar**: el docente dejó de ser un
  administrador con menos botones. No ve `/escolar`; entra por `/docencia` y
  sólo alcanza sus propias materias (el filtro va por `docentes.persona_id`, no
  por `personas.id` — ese bug hacía que el alcance nunca se aplicara). La
  captura vive en `/captura`, fuera de `/escolar`, porque el docente perdió
  `ver-grupos`.
- **Tanda de interfaz pedida por el cliente** (bloques A–E):
  - `PanelFiltros.vue` — botón que despliega los filtros disponibles y se
    activan con casilla; `Paginacion.vue`; `SelectorVista.vue` con vista de
    lista y de cuadrícula (`TarjetaPersona.vue`, tarjetas con foto).
  - **Fotos de perfil**: `personas.foto`, servidas desde disco privado por
    `FotoPersonaController` (son datos personales; nunca `public/`).
  - **Multicarrera**: una alumna con dos programas se ve como dos
    `matricula_oferta` de la misma persona, con alta, baja (preguntando cuál
    situación de baja) y kárdex independiente por cada una.
  - **Documentos requeridos con ámbito** (`documento_ambitos`): el expediente
    del docente ya no ofrece papeles de aspirante. Los administradores validan
    o rechazan; alumnos y tutores sólo suben.
  - **Constructor de formularios** (`/formularios`): versionado que re-ata los
    campos condicionales al padre de SU versión, y congelamiento en cuanto hay
    una respuesta capturada.
  - **Suplantación** (`Suplantador`): ver la plataforma como la ve un alumno o
    un docente, con rastro en `auditoria`; sin escalar privilegios ni encadenar.
- Interfaz: login, panel, conmutador de rol, CRUD de aspirantes con expediente
  y conversión a alumno, catálogo académico completo (campus, carreras,
  asignaturas, planes, malla curricular, seriación, esquema de evaluación,
  oferta), control escolar, captura de calificaciones y layout de
  administración con temas.
- **Módulo 7 — Finanzas, entrega 7.1** (núcleo de datos, sin pantallas):
  catálogos (`conceptos_pago`, `situaciones_pago`, `metodos_pago`) con seeder;
  motor configurable (`planes_cobro`, `reglas_generacion`,
  `recargos_descuentos`, `becas_alumno`); núcleo transaccional (`adeudos`,
  `pagos`, `pago_adeudo`, `bitacora_situacion_financiera`); 11 modelos en
  `App\Models\Finanzas\`. `adeudos` y `pagos` tienen titular DUAL —
  `matricula_oferta_id` o `aspirante_id`, exactamente uno, con CHECK en MySQL —
  porque el aspirante paga antes de tener matrícula;
  `App\Services\ReligadorFinanzas` los pasa a la matrícula nueva dentro de la
  transacción de `ConvertidorAspirante` y `MatriculadorOferta`.
- **Módulo 7 — Finanzas, entrega 7.2** (el motor de cobro, con pantallas):
  `GeneradorAdeudos` (idempotente por índice único, no solo por SELECT previo),
  `PeriodosCobro` (calendario aislado: único con parcialidades, semanal ISO,
  quincenal, mensual), `ResolutorPlanCobro` (gana el más específico vigente:
  oferta → plan → carrera → global), `AplicadorRecargosDescuentos` (mora con
  días de gracia sobre el monto base, becas al generar), `RegistradorPago` (el
  estatus del adeudo se DERIVA de lo aplicado; el del pago lo dicta
  `requiere_confirmacion`) y `EstadoCuenta`. Pantallas `/finanzas` (cartera),
  `/finanzas/cuentas/{matricula}` y `/finanzas/planes`. Permiso nuevo
  `gestionar-planes-cobro`, separado de `registrar-pagos`.
- Pruebas: 14 suites en `scripts/`, 357 verificaciones, todas contra la BD real
  del tenant demo con `DB::rollBack()` al final.

**Pendiente inmediato — aquí se retoma:**

1. **Módulo 7, entrega 7.3** — CFDI 4.0: tablas `facturas` y
   `factura_conceptos` (append-only, inmutables por regulación: corregir es
   cancelar y refacturar, nunca UPDATE) y timbrado en cola, porque el PAC puede
   tardar o fallar. Checklist en `docs/plan-migraciones.md`.
2. Enganchar `GeneradorAdeudos::generarParaTodas` y
   `AplicadorRecargosDescuentos::recalcularCartera` a un job diario cuando haya
   scheduler. Los servicios ya están listos y son idempotentes; falta el
   disparador.
3. Módulos 8 (LMS) y 9 (Titulación SEP) de la Fase 3; luego Fase 4.

**Deuda conocida:**

- **Ninguna pantalla nueva se ha validado en el navegador.** Todo está probado
  por datos (suites con rollback) y por HTTP real contra el tenant demo, pero
  el render no lo ha visto nadie: el navegador embebido no alcanza
  `demo.localhost` y la extensión de Chrome no estaba conectada.

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
- **No hay pantalla de administración de roles y permisos.** Los roles se
  siembran con `PermisoSeeder`; para cambiar quién puede qué hay que tocar el
  seeder y re-sembrar. Es lo primero que va a pedir el cliente cuando quiera
  un rol nuevo.
- **No existe portal del alumno ni del tutor.** La regla "alumnos y padres sólo
  suben documentos, no los validan" está implementada y probada en el backend,
  pero no hay pantalla desde la cual ejercerla. La suplantación permite ver el
  lado del docente; el del alumno todavía no tiene qué mostrar.
