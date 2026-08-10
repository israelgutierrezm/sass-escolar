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
4. **Configurable, no cableado.** Regla del cliente: «que esta plataforma sea
   mejor y no una imagen de los ejemplos o ideas que puedo tener». Sus ejemplos
   son UN caso del mecanismo, no el mecanismo. Un panel no se resuelve con
   `if (rol == finanzas)` sino con tarjetas que declaran qué permiso exigen; los
   roles de ejemplo deben poder borrarse. Cuando algo enumerable se cablea en el
   código, hay que poder explicar por qué no es tabla.
5. **Probar contra la base real** antes de dar algo por hecho. Las pruebas de
   integración se hacen con script + `DB::rollBack()`, y la UI con el
   navegador. Reportar los resultados tal cual, incluidos los fallos.
   Las suites versionadas viven en `scripts/` (23 suites, 641 verificaciones):
   `prueba-actas`, `prueba-plantillas`, `prueba-ventanas-captura`,
   `prueba-ciclo-campus`, `prueba-apertura-grupos`, `prueba-alcance-docente`,
   `prueba-alumnos`, `prueba-docentes`, `prueba-documentos`,
   `prueba-formularios`, `prueba-multicarrera`, `prueba-suplantacion`,
   `prueba-finanzas`, `prueba-cobro`, `prueba-facturacion`, `prueba-emisores`,
   `prueba-roles`, `prueba-crm`, `prueba-formulario-publico`, `prueba-panel`,
   `prueba-configuracion`, `prueba-portal-aspirante`, `prueba-listados`.
   NO van en `tests/`:
   phpunit corre contra SQLite en memoria y ahí se prueba justo lo que SQLite
   no sabe hacer (`LAST_INSERT_ID`, FKs reales, InnoDB).

## Pedido del cliente en curso (2026-07-22)

Cinco entregas, en este orden. A y B ✅ hechas; C, D y E pendientes:

- **A** ✅ Roles y permisos desde pantalla.
- **B** ✅ Menú por oficio (Alumnos y Docentes como secciones propias).
- **C** ✅ CRM de promoción: embudo, seguimiento, promotores y comisiones.
- **D** ✅ Formulario público embebible, con modo captación o inscripción.
- **E** ✅ Panel por rol, con registro de tarjetas por permiso.

**Lo que quedó pendiente de este pedido y hay que retomar:**

- La **ficha del aspirante** (`Aspirantes/Show.vue`) no muestra todavía el
  seguimiento ni la asignación de promotor: los endpoints existen y están
  probados, pero hoy el CRM se opera desde `/promocion`.
- ✅ Resuelto: las reglas de Alumnos y Docentes viven en
  `/plataforma/configuracion`, como pidió el cliente («que alguien con ese
  permiso configure todo antes de que existan registros»).
- ✅ Resuelto para el ASPIRANTE: `/mi-solicitud` ya existe, así que el modo
  «inscripción autogestiva» del formulario público tiene a dónde entrar.
- ✅ Resuelto para el ALUMNO: además de `/mis-cursos`, ya tiene su **kárdex**
  en `/mi-kardex`. Su **estado de cuenta** resultó que YA existía —`ver-adeudos`
  es de la faceta alumno y `VeLaCarteraDelAlumno` lo acota a sus matrículas—:
  entra por `/finanzas` y `/finanzas/cuentas/{matricula}`, y la de otra persona
  le responde 403. Se comprobó antes de construir nada.
- ⏳ El portal **no cobra**: muestra los cargos, pero no hay pasarela conectada.
  `pagos` ya tiene `pasarela` y `pasarela_txn_id` esperándola desde 7.1.

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

## Trampas al migrar (ya mordieron; no volver a pisarlas)

- **Un índice compuesto que empieza por una columna con llave foránea la está
  sosteniendo, aunque nada lo diga.** MySQL acepta cualquier índice que EMPIECE
  por la columna de la foránea, así que `(inscripcion_id, fecha)` o
  `(curso_id, tema)` son los que la cubren. Al intentar tirarlos:
  `Cannot drop index '…': needed in a foreign key constraint`. Hay que crear el
  sustituto ANTES de retirar el viejo — usar
  `App\Support\IndiceQueSostieneUnaFk::reemplazar(...)`, que ya lo hace en un
  renglón y es idempotente. Mordió al separar el pase de lista en teoría y
  práctica, y al retirar `tema` de los reactivos.
- **Al soltar una columna, MySQL la saca de los índices compuestos sin avisar,
  y el índice sigue ahí con OTRO significado.** `adeudos_generacion_unique` se
  creó sobre `(matricula_oferta_id, regla_id, periodo_etiqueta)`;
  `reorganiza_planes_cobro` eliminó `regla_id` y el único quedó en
  `(matricula_oferta_id, periodo_etiqueta)` —dos columnas, mucho más estricto:
  una matrícula admitía UN solo cargo por periodo, así que un plan con
  «Inscripción agosto» y «Colegiatura agosto» reventaba con `Duplicate entry`—.
  **Ya reparado** (`repara_unico_de_generacion_de_adeudos`): ahora es
  `adeudos_generacion_unica` sobre
  `(matricula_oferta_id, concepto_plan_id, periodo_etiqueta)`, que es la terna
  por la que pregunta `generarCargos`, así que la idempotencia por fin tiene red
  debajo y no depende sólo del `SELECT` previo. El nuevo se creó ANTES de tirar
  el viejo: los dos empiezan por `matricula_oferta_id`, que es foránea, y al
  revés el `DROP` habría fallado con «needed in a foreign key constraint».
- **No se indexan las foráneas «por si acaso».** Un índice de más se paga en
  cada escritura, para siempre, a cambio de un problema que aparece solo al
  cambiar el esquema. Se paga cuando hace falta.
- **Migración que puede fallar a la mitad = migración que comprueba antes de
  actuar** (`Schema::hasColumn`, `IndiceQueSostieneUnaFk::existe`). Un reintento
  tras un fallo parcial no debe chocar contra su propio trabajo.
- **Tabla de un modelo con `TieneAuditoria` → `$table->auditoria()`, nunca
  `timestamps()` a secas.** El trait escribe también `created_by` y
  `updated_by`; sin esas columnas la tabla se crea, migra sin quejarse y
  revienta con `Unknown column 'created_by'` en el primer `create()`. Mordió al
  crear `imagenes_contenido`.

## Trampas al programar (ya mordieron)

- **Un `back()` después de PUT/PATCH/DELETE debe ser `back(303)`.** Ante un 302
  el navegador repite el redirect CON EL MISMO MÉTODO: el PATCH sale otra vez
  contra la pantalla destino —que sólo responde GET— y termina en 405 aunque el
  cambio ya se haya guardado. Lo peor es cómo se ve: el dato cambió en la base y
  la pantalla no se entera, así que parece que el botón no funciona. Mordió con
  el interruptor de visibilidad.
- **Los catálogos universales viven en la base CENTRAL.** `niveles_estudio`,
  sexos, países… tienen modelo en `App\Models\Landlord\` con `CentralConnection`.
  Un `DB::table('nivel_estudios')` desde el tenant revienta con «table doesn't
  exist» — y además el nombre real es `niveles_estudio`. Siempre por el modelo.
- **El nombre de una tabla se pregunta, no se adivina.** `oferta` es singular,
  `planes_estudio` no se llama como su modelo, `inscripcion` tampoco. Consultar
  con Eloquent en vez de escribir el nombre a mano evita el problema entero;
  mordió al construir `ContextoAcademico`.
- **Una prueba que no falla al romper lo que dice probar, no prueba nada.** La
  de «pedir la credencial de otro devuelve la propia» pasaba IGUAL con la
  salvaguarda quitada: la credencial de prueba sólo dibujaba el nombre, que sale
  de la persona de la sesión y no cambia aunque cueles la matrícula ajena. Se
  vio mutando el código a propósito. Con la matrícula y la carrera puestas en el
  diseño, la mutación por fin la tumba. Es el tercer caso en este proyecto
  —antes fueron `url:http,https`, `solicitable` y `creditos_del_plan`—: **mutar
  y volver a correr** antes de dar una prueba por buena.
- **Lo que se dibuja hay que MIRARLO.** Dos defectos del compositor pasaron dos
  revisiones de código y aparecieron al abrir el PNG: el nombre de la
  institución salía del lienzo —el tamaño se calculaba del alto, y en vertical
  eso da 45 px para 638 de ancho— y el QR se pegaba con la regla de la foto,
  llenando la caja al recortar: medido, una caja de 382×121 producía un QR de
  365×121, sin patrones de esquina, o sea ilegible. Ninguno lanza excepción.
- **`vue-tsc` está roto en este proyecto** (incompatible con la versión de
  `typescript` instalada: `ERR_PACKAGE_PATH_NOT_EXPORTED`). Sale sin imprimir
  nada y con código 0, o sea que **parece que pasó**. Para comprobar el
  frontend, `npm run build`.
- **`personas.foto` no existe: la columna es `foto_url`** (y guarda una RUTA del
  disco privado, no una URL, pese al nombre). Lo mismo `instituciones.logo_url`.
- **Las pruebas de phpunit NO llegan por HTTP a una ruta de tenant.**
  `routes/tenant.php` se resuelve por dominio y `PreventAccessFromCentralDomains`
  rechaza `localhost`, así que un `$this->get('/mi-credencial')` devuelve 404 sin
  haber entrado nunca al controlador — y ese 404 se confunde con el que la
  pantalla devuelve a propósito. Se invoca al controlador con `peticionDe()`,
  como hacen `MiKardexTest` y las demás.
- **Una ruta que sirven DOS oficios no puede colgar del permiso de uno.** La
  descarga de un adjunto de entrega estaba bajo `can:ver-mis-cursos` con el
  resto del portal del alumno; el controlador sí contemplaba al docente, pero el
  middleware lo rebotaba antes de llegar. Si dos roles distintos entran por el
  mismo endpoint: o un permiso derivado con `Gate::define` (como
  `subir-material`), o sin `can:` y que el controlador resuelva.

## Entorno local

**Dos trampas de esta máquina, ya resueltas:**

- **PHP de WAMP sin certificados raíz.** Venía con `curl.cainfo` vacío, así que
  NINGUNA llamada HTTPS saliente funcionaba: `cURL error 60: SSL certificate
  problem`. Afecta a todo lo que hable con el exterior (clima, feriados,
  Facturapi, el WS de la SEP). Resuelto: `cacert.pem` de curl.se en
  `C:\wamp64\bin\php\php8.3.6\extras\ssl\`, con `curl.cainfo` y
  `openssl.cafile` apuntando ahí en `php.ini` (respaldo del original en
  `php.ini.respaldo-antes-de-cacert`). En Linux no aplica. **Ojo**: al
  actualizar PHP hay que rehacerlo.
- **`public/hot` obsoleto secuestra el frontend.** Si quedó de una sesión de
  `npm run dev` que ya murió, Laravel sigue apuntando al dev server y **ningún
  `npm run build` se ve**: se editan componentes, compila sin errores y la
  pantalla no cambia. Costó media hora de diagnóstico creyendo que el bug era
  del código. Si un cambio de Vue no aparece: `ls public/hot` y bórralo.
- **La caché del tenant NO admite etiquetas.** `stancl/tenancy` envuelve
  `Cache::` con `tags()` para aislar por escuela y el driver del proyecto
  (`database`) no las soporta: cualquier `Cache::remember` revienta con «this
  cache store does not support tagging». Para datos que no son de nadie —el
  clima de unas coordenadas, los feriados oficiales— se construye un almacén
  propio con `Cache::build(['driver' => 'file', ...])`, como hacen
  `ClimaDelCampus` y `FeriadosOficiales`.

MySQL de WAMP corriendo. Luego:

```bash
php artisan serve          # http://localhost:8000 (central)
npm run dev                # o npm run build
```

- Escuela de prueba: **http://demo.localhost:8000**. El acceso está en la RAÍZ
  (`/`), no en `/login` —esa ruta sólo acepta POST y un GET responde 405—, y el
  campo pide **correo o CURP**, no el nombre de usuario. Credenciales
  comprobadas contra la base: **`demo@escuela.mx` / `password`**. La cuenta se
  llama `demo` pero eso no sirve para entrar.
- Comandos de apoyo: `acadion:usuario-demo`, `acadion:oferta-demo`. Ojo:
  `acadion:usuario-demo` sólo CREA; sobre la escuela de ejemplo, que ya tiene
  ese usuario, revienta con `Duplicate entry 'demo'`. Para restablecer la
  contraseña hay que hacerlo a mano.
- La cuenta del alumno de prueba es `alumno.demo.1` (usuario 275), y su
  contraseña es aleatoria de 40 caracteres —la pone `AprovisionadorAcceso`—,
  así que **no se puede entrar como él**. Para ver su portal se usa la
  suplantación: `POST /suplantar/275` desde una sesión con
  `suplantar-usuarios`, y se sale con «Volver a mi cuenta».
- `php artisan tenants:migrate`, `tenants:seed`, `tenants:list`.
- Si `demo.localhost` no resuelve, agregar `127.0.0.1 demo.localhost` a
  `C:\Windows\System32\drivers\etc\hosts`.

### Pruebas

```bash
php artisan test
```

**Corren en MySQL, no en SQLite.** Las migraciones de tenant están escritas
contra MySQL (índice de texto completo en `personas`, `INSERT IGNORE`,
`UPDATE ... JOIN`) y en SQLite abortan; reescribirlas habría significado probar
un esquema distinto del que corre en producción. Usan dos bases que se crean
solas —`acadion_testing` (escuela) y `acadion_testing_central` (catálogos SEP)—
y van separadas porque comparten nombres de tabla (`cache`, `jobs`).

- `tests/bootstrap.php` crea las bases antes de que Laravel conecte.
- `Tests\TenantTestCase` levanta el esquema **una vez** por corrida (>200
  migraciones, ~80 s la primera vez; después `migrate` no tiene nada que
  aplicar y arranca en segundos) y envuelve cada prueba en una transacción.
- Para rehacer el esquema desde cero: borra las dos bases y vuelve a correr.
- Lo que toca el esquema de una escuela hereda de `TenantTestCase`; lo que es
  lógica pura, de `PHPUnit\Framework\TestCase` y no toca la base.

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
- **Módulo 7 — Finanzas, entrega 7.3** (CFDI 4.0): `facturas` y
  `factura_conceptos`; `App\Services\Cfdi\Pac` como interfaz con `PacFalso`
  (el driver real se agrega en `config/cfdi.php` cuando haya PAC contratado);
  `EmisorFactura` (se factura contra PAGOS cobrados, IVA desglosado por
  concepto y hacia atrás desde lo cobrado) y el job `TimbrarFactura`. Una
  factura timbrada NO se edita: `refacturar()` emite la sustituta y luego se
  cancela la original con motivo 01. Pantallas bajo `/finanzas/facturas`,
  permiso `facturar`.
- **Varias razones sociales por escuela** (aclaración del cliente): una escuela
  factura bachillerato con una persona moral, licenciatura con otra. Tablas
  `emisores_fiscales` + `emisor_asignaciones` (pivote), con precedencia
  carrera → nivel de estudios → global. Cada razón social guarda SU certificado
  de sello digital (disco privado) y sus credenciales del PAC (cifradas). El
  emisor se congela en la factura igual que el receptor. Pantalla
  `/finanzas/emisores`, permiso `gestionar-emisores`.
- **Roles y permisos configurables** (`/plataforma/roles`): la escuela crea sus
  propios roles y decide qué lleva cada uno. Los PERMISOS no se crean desde
  pantalla —son llaves que el código consulta— y viven en
  `App\Support\CatalogoPermisos` con dominio, etiqueta y descripción. Las seis
  facetas base van con `roles.protegido`: su clave no se toca porque hay código
  que las conoce por nombre, pero sus permisos sí. Salvaguarda contra el
  auto-encierro: no puedes quitarle `gestionar-roles` a tu propio rol activo.
- **Menú agrupado por oficio**: Alumnos y Docentes son secciones propias, no
  submenús de Control escolar (que se queda con ciclos y grupos). Las URLs no
  cambiaron.
- **CRM de promoción** (`/promocion`): cierra un hueco grande — `etapas_crm`
  estaba sembrada desde la Fase 1 y **nadie la usaba**, `aspirantes` no tenia
  columna de etapa. Ahora hay embudo real, `origenes_aspirante` como catalogo
  (con bandera `autogestivo`), bitacora de contacto `seguimientos_aspirante`
  con proximo contacto, y comisiones que **se devengan al inscribirse** el
  prospecto (`DevengadorComisiones`, dentro de la transaccion de conversion).
  El monto se congela al devengarse. Alcance en dos capas: el permiso dice que,
  la asignacion en `aspirante_asesor` dice sobre quien.
- **Permisos derivados**: `entrar-promocion` se define con `Gate::define` y lo
  abre `ver-mis-prospectos` O `gestionar-promocion`. No es asignable ni esta en
  el catalogo: cuando dos permisos abren la misma puerta, la puerta se declara
  aparte en vez de pedirle a la escuela que adivine la dependencia.
- **Formulario público embebible** (`/p/{token}`, sin sesion): la escuela
  publica un formulario y lo pega en su pagina web con un `<iframe>`. Va en
  **Blade y no en Inertia** — se carga dentro del sitio de la escuela y no debe
  arrastrar la SPA administrativa. Tabla `formularios_publicos` (la publicacion)
  aparte de `formularios` (el cuestionario); token UUID, no consecutivo. Modo
  `captacion` o `inscripcion` (esta ultima crea la cuenta del aspirante).
  Salvaguardas porque los datos los escribe un desconocido: nunca sobreescribe
  una persona existente, deduplica SOLO por CURP, no repite prospecto, no toca
  credenciales de quien ya tenia cuenta, honeypot y `throttle`. Admin en
  `/promocion/publicaciones`.
- **Panel por rol** (`/panel`): es un REGISTRO de tarjetas
  (`App\Panel\TarjetaPanel` + `RegistroTarjetas`), no ramas por rol. Cada
  tarjeta declara el permiso que exige y si aplica a esa persona; el
  controlador no conoce ninguna concreta. Un rol nuevo armado desde
  `/plataforma/roles` obtiene su panel solo. El Vue sabe pintar cuatro formas
  (metrica, lista, barras, accesos), asi que agregar una tarjeta es agregar una
  clase y registrarla. Regla de vacios: una COLA de trabajo vacia se oculta
  (ensena a ignorar la tarjeta); una METRICA propia en cero se muestra ("no
  debes nada" informa).
- **Reglas de operacion configurables** (`/plataforma/configuracion`):
  `App\Configuracion\CatalogoAjustes` declara cada regla con su tipo, rango,
  valor por omision y la CONSECUENCIA de cambiarla; `Ajustes` la lee. Cada
  limite trae su ACCION —advertir o bloquear—, porque no es la misma decision
  en todas las escuelas. Se aplican de verdad: recursamientos y carga del ciclo
  en `ValidadorInscripcion` (que gana `advertencias()` junto a `impedimentos()`),
  extraordinarios en `AsentadorActa` al FIRMAR, y el bloqueo por adeudo cierra
  un hueco real —`situaciones_pago.bloquea` existia desde 7.1 y nadie la
  consultaba—. Sin cache persistente: el bootstrapper de stancl envuelve el
  cache en tags y el store `database` no los soporta.
- **Se retiro el test Cleaver**: la spec lo previo, se migro, y su banco de
  reactivos nunca se sembro, asi que no podia aplicarse. Se elimino en vez de
  apagarlo — una tabla vacia que nadie va a llenar es una promesa falsa.
- **Portal del interesado** (`/mi-solicitud`): el aspirante captura sus datos,
  sube su documentacion y consulta sus cargos. `ProgresoSolicitud` calcula el
  avance sobre TRES PASOS FIJOS (datos, documentos, pago) — no varian por
  campana ni carrera, por decision del cliente. **Ese avance NO es la etapa del
  CRM**: el embudo lo mueve promocion con su criterio y el progreso solo se
  muestra informativamente en la ficha. Da igual quien llene: el mismo calculo
  sirve para el interesado y para el administrador. El controlador no recibe id
  por la URL, asi que no existe pedir el expediente de otro.
- **Un permiso pertenece a una FACETA.** `CatalogoPermisos` declara a que
  facetas corresponde cada permiso, y un rol solo puede recibir los de la suya:
  un administrativo NO puede concederse `ver-mis-materias`. Si pudiera, el
  conmutador de rol dejaria de tener sentido y el alcance por asignacion
  (`docente_asignatura_grupo`) quedaria colgando de un permiso que esa persona
  no puede ejercer. Se filtra en el servidor, no solo en la pantalla.
- **Pantalla de usuarios** (`/plataforma/usuarios`): `gestionar-usuarios`
  existia desde el slice de auth SIN ninguna ruta. Alta de cuentas reutilizando
  persona por CURP, asignacion de roles agrupados por faceta y restablecimiento
  de contrasena. Las cuentas no se eliminan.
- **Dirección general se DERIVA del catálogo**: recibe todos los permisos de su
  faceta (40 de 43), no una lista escrita a mano — una lista a mano se queda
  vieja cada vez que se agrega un permiso, y eso ya produjo un 403
  inexplicable. Los tres que NO tiene son de docente y de aspirante: para
  actuar como tales se le da ese rol y conmuta.
- **Listados con filtros y vista lista/cuadrícula** en Aspirantes, Grupos,
  Promoción por etapa y Usuarios, sobre cuatro componentes reutilizables:
  `PanelFiltros`, `SelectorVista`, `TarjetaPersona` y `TarjetaRegistro` (este
  último para lo que no es persona: un grupo no tiene cara). `NavEscolar` ya no
  trae lista fija — cada pantalla declara sus pestañas y se filtran por permiso.
  Grupos dejó de devolver TODOS los grupos sin paginar.
- **Un aspirante dado de alta a mano nace en la primera etapa del embudo.**
  Antes solo lo hacía el formulario público, así que el prospecto capturado por
  personal quedaba con `etapa_crm_id` en null e **invisible para el CRM**.
  Corregido en el controlador + migración de backfill.
- Pruebas: 23 suites en `scripts/`, 641 verificaciones, todas contra la BD real
  del tenant demo con `DB::rollBack()` al final. `prueba-listados` es la primera
  que invoca a los CONTROLADORES y lee sus props de Inertia, en vez de
  reimplementar la consulta: un `or` sin paréntesis no se detecta de otra forma.
- **Módulo 8 — LMS completo** (seis fases): cursos por materia impartida,
  actividades con entrega y archivos, exámenes con banco de reactivos y
  autocalificación, chat (1‑a‑1 y canal del grupo) y foros. La plantilla del
  plan (`/academico/planes/.../curso`) se COPIA al grupo cuando se abre la
  materia — no se apunta a ella: corregir una falta de ortografía en el plan no
  debe cambiar el examen que un grupo está contestando (`CopiadorDeCurso`).
- **El AULA del alumno** (`/mis-cursos/{materia}/aula[/{lección}]`): la materia
  recorrida como un libro. Índice a la izquierda agrupado por parcial, lección
  al centro, progreso arriba y en el índice, Anterior/Siguiente al pie.
  Reparte las dos preguntas que antes se estorbaban en una sola pantalla:
  «¿cómo voy?» se queda en `/mis-cursos/{materia}` (calificaciones, asistencia,
  docentes) y «¿qué sigue?» vive en el aula.
  - `actividades.contenido` guarda el MATERIAL (HTML del editor: texto, video
    incrustado, SCORM); `instrucciones` sigue diciendo qué hacer con él. Se
    carga desde el mismo formulario del docente y desde la plantilla del plan.
  - **Completada** se decide por tipo y con un solo criterio: lo que se entrega
    lo declara la entrega; la lectura la declara el alumno con un botón, y eso
    va a `actividad_vistas.completada_en`. Marcar la lectura sólo con abrirla
    habría llenado la barra de progreso de mentiras.
  - Las lecciones sin parcial (las lecturas) **heredan el parcial de la
    siguiente que sí pondera**, para que el material que antecede a un ejercicio
    caiga en su mismo bloque. Agrupar tal cual mandaba todas las lecturas a un
    cajón al final, después del ejercicio que exigían leer.
  - El formulario de entrega vive SÓLO en el aula. Estaba también en «Mi
    materia»: dos maneras de entregar lo mismo es como se llega a que una acepte
    archivos y la otra no.
- **`App\Support\HtmlSeguro`**: lista blanca de etiquetas y atributos para todo
  HTML de editor que se pinte con `v-html`. El material lo escribe un docente y
  lo lee cada alumno del grupo: sin sanear, un `<img onerror>` se ejecuta en la
  sesión de todos. El `iframe` sobrevive —es lo que permite incrustar un video o
  un SCORM ya producido— pero con `sandbox` y `referrerpolicy` impuestos por el
  servidor y sólo sobre `https://`. Cubierto por `tests/Unit/HtmlSeguroTest`.
- **Imágenes dentro del material** (`imagenes_contenido` + `/lms/imagenes`): el
  botón «🖼 Imagen» del editor SUBE el archivo y pega la dirección propia.
  Enlazar una imagen ajena dejaba el material a merced de otro servidor —el
  enlace se cae a media asignatura— y le anunciaba a ese servidor dónde estudia
  cada alumno que abre la lección. El archivo va al disco privado; la URL
  pública lleva un **uuid** y no el id, porque un id se cuenta y quien pidiera
  1, 2, 3… se llevaría el material entero de la escuela. Subir exige el permiso
  derivado `subir-material` (docente **o** quien edita el catálogo académico:
  son dos oficios usando el mismo editor); ver sólo exige sesión. Las medidas
  (`ancho`/`alto`) se toman en el servidor con `getimagesize` al subir y viajan
  como `width`/`height` del `<img>`: el navegador reserva el hueco y la lección
  no da el salto al cargar la figura. En el aula el texto va en 68ch pero la
  imagen y el iframe usan el ancho de la tarjeta —una figura no se lee mejor
  encogida a la medida de una línea de texto—.
- **Panel de calificación** (`PanelCalificacion.vue`): el formulario vivía
  DEBAJO de la rejilla, así que calificar a la fila 4 de treinta abría un cuadro
  dos pantallas más abajo — y no mostraba los adjuntos, de modo que una tarea
  entregada en PDF se calificaba a ciegas. Ahora es un panel al costado con el
  trabajo, los archivos, «Máximo» para el puntaje completo y **«Guardar y
  seguir»**, que salta a la siguiente sin calificar (calificar es trabajo en
  serie). Un examen NO se califica ahí: se marca `⚡ Calificada automáticamente`
  y se manda a la pantalla del examen, porque escribir un número encima dejaría
  la nota y las respuestas contando cosas distintas.
- **Interruptor de visibilidad** (`InterruptorVisible.vue`): el ojo publica o
  esconde una actividad desde su renglón, en el portal del docente y en la
  plantilla del plan. Era el gesto más repetido —se arma en borrador y se suelta
  conforme avanza el semestre— y el más caro: abrir el formulario y reenviar
  diez campos para mover un interruptor.
- **Calendario escolar** (`/plataforma/calendario`, permiso
  `gestionar-calendario`): avisos, feriados, recesos, inicio y fin de ciclo,
  evaluaciones y eventos, con rejilla del mes y lista editable debajo.
  - **Dos tablas**: `eventos_calendario` dice qué y cuándo; `evento_destinos`
    dice a quién. Un aviso puede ir a varios públicos a la vez y con columnas
    fijas (campus_id, carrera_id…) quedaría atado a una sola combinación.
  - **Los destinos se SUMAN, no se cruzan.** «Campus norte» + «grupo A» son los
    del campus norte Y ADEMÁS el grupo A. Exigir cumplir todos dejaría casi
    cualquier aviso sin público: nadie es a la vez «todos los docentes» y «el
    grupo A».
  - Segmentación completa: toda la escuela, rol, campus, nivel, carrera, plan,
    grupo, materia y alumnos señalados uno por uno (estos se buscan con
    `BuscadorRemoto`; los demás caben en un `select`).
  - `destino_id` NO lleva FK: apunta a tablas distintas según el tipo. Es lo que
    permite agregar «por turno» mañana sin migrar. A cambio, lo que apunta a
    algo borrado se muestra como «Ya no existe» en vez de reventar.
  - **`ContextoAcademico`** resuelve dónde está parada una persona (campus,
    nivel, carrera, plan, grupos, materias). Un alumno pertenece por su
    matrícula; un docente, por su asignación — y quien hace las dos cosas recibe
    lo de ambos lados. **`AgendaDeUsuario`** es quien contesta «¿esto es para
    mí?», filtrando en SQL contra el índice `(tipo, destino_id)`.
  - Los roles salen de `persona_rol` (`rolesDisponibles()`), **no** del rol
    activo: un aviso para docentes no puede desaparecer porque alguien conmutó
    de rol para revisar otra cosa.
  - Pruebas: `scripts/prueba-calendario.php`, 26 verificaciones contra la BD
    real con rollback.
  - **Feriados oficiales**: botón «Traer feriados {año}» que consulta
    `date.nager.at` (sin llave) y los precarga. Llegan como **borrador**: un
    feriado oficial no siempre es día sin clases en una escuela particular, y
    esa decisión es de la dirección, no de una API. Idempotente: reimportar no
    duplica.
- **Panel en dos columnas**: el TRABAJO a la izquierda (las tarjetas por
  permiso, sin cambios en su registro) y el CONTEXTO a la derecha —agenda y
  clima—, pegado al desplazarse. Mezclarlos en una sola rejilla obligaba a
  barrer la pantalla para encontrar lo que hay que hacer hoy, que es a lo que
  se entra al panel.
  - **`AgendaDelPanel`** junta en UNA línea de tiempo lo del calendario de la
    escuela y lo que vence de sus materias. Nadie piensa «mis entregas» y «los
    avisos» por separado: se piensa «qué me toca esta semana», y con el examen
    del martes en una tarjeta y el puente del miércoles en otra hay que
    cruzarlos de memoria.
  - La misma actividad se cuenta desde los dos lados del escritorio: al alumno
    le sale como **Entrega** (lo que debe) y al docente como **Cierra** (lo que
    le va a caer para calificar).
  - El mini calendario marca con un punto los días que traen algo —el color dice
    de qué clase— y la lista de abajo explica qué es. El detalle dentro de las
    casillas haría ilegibles las dos cosas.
  - «Mis materias» del docente pasó de listar ocho materias a ser una métrica
    con enlace a `/docencia`: el panel contesta «cuánto tengo encima», no
    «cuáles son mis materias» —eso ya lo sabe—.
  - **Panel del alumno con contenido propio**: tenía cuatro tarjetas y una era
    de finanzas. Se agregaron tres en el orden de su día —**Mis clases de hoy**
    (con «ahora» / «ya pasó» según la hora), **Continuar donde me quedé** (el
    avance del contenido, con lo empezado y sin terminar arriba, enlazando
    directo al aula) y **Calificaciones recientes** (las últimas cinco, por
    fecha de CALIFICACIÓN, anunciando si traen comentarios)—. Ninguna se pinta
    vacía: sin clases hoy o sin nada calificado, la tarjeta no aparece.
  - **El ancho de una tarjeta puede depender de su contenido**: devolver
    `ancho_sugerido` en `datos()` y el registro lo respeta. «Accesos directos»
    declara 4 columnas porque puede traer doce, pero a un alumno le salen dos y
    ocupaba el panel entero para un botón. El grid va con `grid-flow-dense`
    para que las chicas rellenen los huecos de las anchas.
  - **Efemérides del día** (`efemerides` + `EfemerideSeeder`): qué se conmemora
    hoy, arriba del calendario de la agenda y sólo cuando hay algo. Se cataloga
    y NO se consume de una API: no hay servicio en español lo bastante fiable, y
    esto lo lee cada alumno —una fecha mal puesta es peor que ninguna—. Se
    guarda `mes`+`dia` (se repite cada año) y el `anio_origen` aparte, para
    decir «hace 216 años» sin restar de cabeza. El seeder trae 28 fechas
    **pocas y ciertas** (cívicas mexicanas y días ONU/UNESCO); vive en el tenant
    para que cada escuela agregue las suyas —aniversario del plantel, semana
    cultural—.
  - **UMA y tipo de cambio** (`IndicadoresFinancieros`, tarjeta bajo
    `ver-adeudos`): los dos números con los que se hacen cuentas. La UMA **no se
    adivina** —si falta la del año en curso lo dice, en vez de mostrar la del
    año pasado como vigente: con un número viejo alguien calcula una beca—, y
    respeta que entra en vigor el 1 de febrero. El dólar sale del **FIX de
    Banxico** si hay `BANXICO_TOKEN` (gratuito) y se marca oficial; sin token
    muestra la referencia del BCE y **dice que es referencia**, porque con ésa
    no se timbra.
  - **`/docencia` («Mis materias»)**: el selector de ciclo ocupaba una tarjeta
    entera de alto para un desplegable y las tarjetas decían «3 de 3 cortes
    abiertos» —jerga que no se puede accionar—. Ahora la cabecera es una línea
    y cada materia muestra lo que reclama trabajo: **por calificar, sin leer y
    si ya se pasó lista hoy**, con las materias que tienen pendientes arriba.
    Botón directo a **Pasar lista** (`?panel=asistencia`, que la pantalla de la
    materia sí respeta) porque es lo que más se repite en el día.
  - **Accesos directos con cifra** (`PendientesDeAcceso`): eran doce recuadros
    idénticos, o sea el menú lateral otra vez. Lo que los justifica es decir
    **cuánto hay esperando ahí**: «Aspirantes» es navegación, «Aspirantes · 12
    sin contactar» es una razón para entrar. Se agrupan por oficio y el grupo
    con pendientes va primero. **El cero se calla** —ocupa el sitio de un dato
    útil y entrena a ignorar la cifra—. Cada contador se calcula sólo si la
    persona tiene el permiso del acceso: nadie paga la consulta de una sección
    a la que no entra.
- **Tarjeta de clima en el panel** (`/panel/clima` + `TarjetaClima.vue`): del
  campus donde la persona estudia, da clase o al que la acota su rol —**no de
  la IP**: desde la red de la escuela todo sale por el mismo enlace y la IP es
  un dato personal—. La IP queda de respaldo sólo si ningún campus tiene
  coordenadas. `campus.latitud/longitud` son opcionales y se capturan en el CRUD
  de campus. Trae el clima actual, 3 días de pronóstico y calidad del aire con
  su recomendación (Open-Meteo, sin llave). Se pide DESPUÉS de cargar la página
  y si falla no se dibuja: el panel no puede depender de un servicio externo.
- **Módulo 9 — Titulación y certificación SEP, completo.** 38 rutas, 17 modelos
  en `App\Models\Emision\`, seis servicios y sus pantallas (lotes en
  `/escolar/titulacion`, catálogos, responsables y configuración del WS). Cubre
  el ciclo entero: armar el lote, validar al egresado, construir el XML,
  sellarlo con el CSD, mandarlo al web service de la SEP y reintentar título por
  título. Lo mismo para certificados, con sus tipos y su regeneración.
  - **Créditos de emisión** con modalidad prepago, postpago e incluido; las
    regeneraciones no vuelven a cobrar y un repetido en el lote no cuenta dos
    veces.
  - **Reenviar es por TÍTULO, no por lote**: el error suele venir del otro lado
    —una caída de la SEP, una validación suya— y remandar el lote entero
    duplicaría los trámites que allá ya se aceptaron.
  - Lo cubren 34 pruebas: el sello (SHA-256, certificado en DER base64 y no PEM,
    verificación contra lo que viaja en el XML, cadena alterada que invalida),
    los créditos, la emisión por carrera y el contrato del WS.
  - Lo único que falta es de tu lado, no de código: la **e.firma** de la escuela
    y el **WSDL de producción** de la SEP.
- **Generación de cargos en bloque** (`finanzas:generar-cargos`, diario a las
  2:45): `GeneradorAdeudos::generarParaTodas` recorre plan por plan —no alumno
  por alumno, para no releer las mismas líneas una vez por asignado— y en
  bloques de 200 con `chunkById`, porque esto corre de madrugada y quedarse sin
  memoria a la mitad dejaría media cartera emitida.
  - **Comando aparte de `finanzas:evaluar`, y antes que él.** Antes porque no se
    puede recargar por mora un cargo que no existe ni decidir quién es deudor
    sin haberlo emitido. Aparte porque esto CREA DEUDA y aquél sólo recalcula la
    que ya hay: esconder un cobro dentro de un comando llamado «evaluar» es como
    se llega a que nadie sepa de dónde salió un adeudo.
  - **Un plan roto no cancela a los demás.** Se aísla cada plan y se reportan los
    que fallan. Hizo falta de verdad: los dos planes del demo apuntan a un
    `ciclo_id` que ya no está en `ciclos` —restos de una resiembra con las
    comprobaciones de foránea apagadas, porque la foránea sí existe— y el primer
    cargo revienta. Sin aislar, esa sola fila dejaba a la escuela ENTERA sin
    emitir y el reporte decía «ok».
  - Requirió antes reparar el único de `adeudos`; ver la trampa de la columna
    soltada. Lo cubren 6 pruebas, incluidas las dos líneas del mismo mes y el
    plan roto.
- **Kárdex del alumno** (`/mi-kardex`): `ver-kardex` lo tenía el rol alumno
  desde siempre, pero el único kárdex del sistema vivía dentro del expediente de
  control escolar, detrás de `ver-alumnos` —permiso de personal que abre el
  listado de TODA la escuela—. Un permiso concedido sin puerta por donde entrar.
  - El cálculo se extrajo a **`App\Services\KardexDelAlumno`** y AHORA LO USAN
    LOS DOS: control escolar y el portal. No es una consulta, son tres
    decisiones de dominio —qué renglones cuentan para los totales (el MEJOR
    intento por materia, no todos), qué se considera «en curso» y cómo se
    promedia—. Copiadas en dos pantallas divergen, y el día que el promedio del
    portal no sea el de la ventanilla nadie sabrá cuál está mal.
  - La matrícula sale de la SESIÓN: la ruta no lleva id, así que no hay dónde
    escribir el de otro. Quien estudia dos carreras elige entre las suyas y la
    elección se busca en esa misma lista —un id ajeno no encuentra pareja y cae
    a la propia—.
  - Se agrupa por PERIODO DEL PLAN y no por ciclo escolar: el plan es el mapa
    del que se avanza. Por ciclo, una materia recursada aparece lejos de sus
    compañeras de semestre y se pierde la forma del avance.
  - La observación oficial SEP se calla cuando dice «NORMAL / ORDINARIO»: salía
    en los 28 renglones de una alumna al corriente, y lo que interesa señalar es
    la excepción —una equivalencia, una revalidación—.
  - **Trampa que mordió otra vez**: la pantalla cargaba `oferta.plan:id,nombre`.
    Las columnas que no se piden llegan en NULL, así que `total_creditos`
    desaparecía —créditos «148» sin el «de 336»— y el promedio se redondeaba con
    la regla por omisión en vez de la del plan. No falla ni avisa: sólo dice
    otro número. Se vio comparando el resumen de la pantalla contra el del
    servicio; lo fija `MiKardexTest`.
- **Asistencia con dos columnas de faltas**: la rejilla está recortada al mes,
  así que su total contesta «¿cómo le fue en noviembre?». Lo que decide el
  derecho a examen es el acumulado del curso, y había que ir mes por mes
  sumándolo de memoria. Ahora salen las dos: **del mes** y **del curso**.

- **Credencial virtual** (`/plataforma/configuraciones/credencial` para armarla,
  `/mi-credencial` para verla): la escuela diseña el gafete de cada rol y cada
  persona se lo descarga.
  - **Por ROL, no una sola para la escuela**: el gafete del alumno trae
    matrícula y carrera; el del docente no tiene ninguna de las dos. Y la
    variante por **nivel de estudios sólo aplica a la faceta alumno** —un
    docente no cursa nada—; el servidor lo vuelve a comprobar, porque una
    credencial de docente atada a «Licenciatura» no la elegiría nunca nadie y
    quedaría configurada para siempre sin emitirse.
  - **Una credencial por MATRÍCULA**: quien estudia dos carreras tiene dos, con
    su propio QR cada una. Misma decisión que el kárdex.
  - **Las cajas se arrastran** sobre el lienzo y se guardan en PORCENTAJE, así
    que el mapa sobrevive a cambiar el tamaño. El fondo del editor lo **dibuja
    el servidor** —el mismo diseño o machote real, pedido sin campos—: imitarlo
    con CSS habría acomodado las cajas respecto a algo que no existe.
  - **La vista previa va con datos inventados y largos** («María Fernanda
    Gutiérrez Villaseñor»), no con los de una persona real: acomodar cajas no
    es motivo para abrir el expediente de nadie, y la primera persona de la
    lista suele ser el caso fácil, que no avisa de que la caja quedó chica.
  - **El QR lleva una DIRECCIÓN, no los datos.** Un código que cargue el nombre
    dentro no verifica nada —cualquiera genera uno que diga lo que quiera—.
    Apuntando a la escuela, lo que se lee sale de su base y un gafete alterado
    se cae solo. La emisión se registra en `credenciales` con **uuid**, porque
    la dirección tiene que ser estable (se imprime) y no adivinable; firmar la
    URL con `APP_KEY` habría evitado la tabla y dejaría inservible toda
    credencial impresa el día que se rote la llave. La fila lleva **rol**
    además de persona y matrícula: quien da clases y estudia tiene dos.
  - `qr_activo` decide si la ficha existe; `qr_publico`, si hace falta sesión.
    **La CURP no sale ahí ni con sesión**: la de cualquier alumno bastaría para
    leerla escaneando gafetes ajenos.
  - `/mi-credencial` **no lleva id** —la persona sale de la sesión— y la clave
    de credencial se busca DENTRO de las suyas: una ajena cae en la propia.
    **Sin permiso propio**: quien decide es la escuela al encender la del rol.
  - Lo cubren 11 pruebas contando píxeles sobre el PNG y comprobando los 404.

**Pendiente inmediato — aquí se retoma:**

*(Antes de tomar algo de esta lista, COMPROBARLO en el código. Dos veces ya
mandó a construir cosas que estaban hechas: la titulación SEP y el estado de
cuenta del alumno.)*

1. Fase 4.

**Deuda conocida:**

- **El navegador SÍ alcanza `demo.localhost`** — la deuda anterior era falsa.
  No resuelve por DNS (`gethostbyname` devuelve el nombre), pero Chromium mapea
  `*.localhost` a loopback por su cuenta, sin tocar el archivo `hosts`. Se
  verificó el panel entrando con la cuenta demo. Para levantarlo:
  `php artisan serve` y navegar a `http://demo.localhost:8000`.
- **Las capturas de pantalla se agotan por tiempo** en este entorno. Lo que sí
  funciona es medir el DOM con `javascript_tool` (altos, anchos, presencia de
  elementos), que sirve para comprobar geometría y estructura — pero NO
  sustituye a que un humano mire el render.
- **Solo el PANEL se ha visto renderizado.** Las demás pantallas siguen
  probadas por datos y por HTTP, sin verificación visual.

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


- **El portal del TUTOR muestra, no opera.** La regla «alumnos y padres sólo
  suben documentos, no los validan» está implementada y probada en el backend;
  la pantalla del padre (`/mis-hijos`) consulta, pero la subida de documentos
  desde ahí sigue sin construirse.
