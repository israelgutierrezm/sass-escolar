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
   Las suites versionadas viven en `scripts/` (**66 archivos `prueba-*.php`**;
   esta lista decía 23 y llevaba tiempo desactualizada). Se corren todas de una
   vez con `for f in scripts/prueba-*.php; do php "$f"; done` y casi todas
   imprimen `Resultado: N correctas, M fallidas`. **Ojo al barrer con `grep`**:
   cuatro cierran de otra forma —`prueba-cache-externo`, `prueba-captura-examen`
   y `prueba-mensajes-espanol` con `N en verde`, y `prueba-listados` con
   `TODO EN VERDE — N verificaciones`—, así que un barrido que sólo busque
   «Resultado:» las reporta como rotas sin estarlo.

   **Las 66 están en verde.** Llegaron a estar 33 en rojo —no trece: ese primer
   conteo sólo miró las que imprimían «N fallidas» e ignoró las 21 que morían
   antes, con una excepción sin resumen—. Ninguna caía por un cambio reciente;
   se comprobó corriéndolas contra el árbol limpio.

   Lo que las tumbaba, por si vuelve a pasar:

   - Roles funcionales de EJEMPLO buscados con `firstOrFail()`. Son borrables
     por diseño y el demo los borró. Se plantan con
     `scripts/apoyo-roles.php`, dentro de la transacción de cada suite.
   - Controladores que dejaron de recibir `Request` y ahora piden su
     FormRequest. Se arma con `scripts/apoyo-peticiones.php`, que además ata
     los parámetros de RUTA —sin ellos, la regla de unicidad no sabe a quién
     ignorar y una asignatura choca contra su propia clave—.
   - Validaciones que crecieron (campus del ciclo, nivel y cupo del grupo) y
     columnas retiradas (`oferta.turno_id`, `plan_materias.creditos_en_plan`).
   - `prueba-cobro` importaba `App\Services\PeriodosCobro`, borrada en el
     refactor del motor de cobro (`86a3899`): reventaba en la primera línea,
     llevaba meses figurando como suite y sin comprobar NADA. Reescrita contra
     el motor de líneas fechadas.

   **Una suite crea sólo lo que su rollback puede deshacer.** Varias dejaban
   basura en el demo al morir antes del `DB::rollBack()`, y una de las
   reparaciones plantó un rol fuera de la transacción: a la corrida siguiente
   ya existía y la prueba falló por un resto de sí misma.

   Antes de creerle a una de estas suites, correrla.

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

- ✅ Resuelto: la **ficha del aspirante** ya es el centro del CRM. Ver «CRM
  completo en la ficha» más abajo.
- ✅ Resuelto: las reglas de Alumnos y Docentes viven en
  `/plataforma/configuracion`, como pidió el cliente («que alguien con ese
  permiso configure todo antes de que existan registros»).
- ✅ Resuelto para el ASPIRANTE: `/mi-solicitud` ya existe, así que el modo
  «inscripción autogestiva» del formulario público tiene a dónde entrar.
- ✅ Resuelto para el ALUMNO: además de `/mis-cursos`, ya tiene su **historial académico**
  en `/mi-historial`. Su **estado de cuenta** resultó que YA existía —`ver-adeudos`
  es de la faceta alumno y `VeLaCarteraDelAlumno` lo acota a sus matrículas—:
  entra por `/finanzas` y `/finanzas/cuentas/{matricula}`, y la de otra persona
  le responde 403. Se comprobó antes de construir nada.
- ✅ Resuelto: el portal **SÍ cobra**. Esta línea decía lo contrario y llevaba
  tiempo desactualizada. Hay cinco pasarelas en `App\Services\Pagos\`
  —Stripe, Conekta, MercadoPago, OpenPay, PayPal—, webhook, `PanelPagoEnLinea`
  y `config/pagos.php` con un modo `fake` que recorre el flujo entero sin
  credenciales. El default es `real` a propósito: a un despliegue que olvide la
  variable le toca cobrar, no simular.

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
  (`actas.acta_origen_id`) que da de baja lógica los renglones de historial académico de la
  original y asienta los nuevos. Ambas actas se conservan. Y una materia se
  asienta **una sola vez**: un segundo cierre ordinario duplicaría al alumno en
  su historial académico.
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
- **Un ciclo aplica a N campus** (pivote `ciclo_campus`) y **al menos a uno**:
  `campus_ids` es `required|min:1` desde `eed73bd`. Esto decía «sin campus
  asignado = ciclo global» y dejó de ser verdad ahí. La clave del ciclo es única
  en toda la escuela.
- **El turno NO es de la oferta, es del GRUPO.** `oferta.turno_id` se retiró en
  el mismo `eed73bd`: no distingue una oferta de otra. El alta de oferta reparte
  por CAMPUS y la modalidad es un atributo opcional que se aplica a todas; la
  combinación que no se duplica es (carrera, plan, campus).
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

## Qué número manda cada cosa al SEP (resuelto; no re-litigar)

Los XML del certificado (DEC 3.0) y del título validan contra los XSD oficiales
que viven en `resources/certificados/` y `resources/titulos/`. **Ésa es la
prueba**: `ValidadorDec::validarLote()` y `ValidadorTitulo::validarMatricula()`
los corren, y comprobado contra el demo los dos pasan.

- **En los CATÁLOGOS, el valor oficial es la `clave`.** Nivel de estudios, tipo
  de periodo, tipo de asignatura y tipo de certificación se leen por ahí, y su
  columna `identificador` se retiró por redundante. En `tipos_certificacion` el
  id NO coincide con la clave —1 y 2 contra 79 y 80—, así que leer el id sería
  mandar un número que la SEP no reconoce.
- **En carrera, asignatura, plan y campus NO se puede mover**: ahí
  `identificador` y `clave` son datos DISTINTOS —la clave de una materia y su
  identificador no significan lo mismo—. Se quedan como están; decisión del
  cliente, confirmada.
- **Y hay catálogos donde el valor oficial vive en `identificador` y la clave es
  otra cosa**: `entidades_federativas` (clave = abreviatura RENAPO «AS»,
  identificador = «01»), `cargos` (clave = «director», identificador = «1»), y
  `modalidades_titulacion` y `fundamentos_legales_servicio_social`, que ni
  siquiera tienen columna `clave`. **Unificar «todo por clave» rompería el
  timbrado de todas las escuelas.** Por eso la columna se elige catálogo por
  catálogo en `ConstructorCertificadoXml::idCatalogo()`, y lo fija
  `ClavesSepDelCertificadoTest`.

**El identificador de campus, carrera y asignatura es OBLIGATORIO para firmar.**
Antes, si faltaba, el certificado caía en silencio a la clave y luego al id
local, y el XSD lo aceptaba —esos atributos son `xs:string` sin patrón—: el
documento pasaba la validación llevando un número que la SEP nunca asignó.
`ValidadorDec` lo detiene antes de firmar el lote y nombra la asignatura
concreta a la que le falta (hasta doce, para que un historial mal capturado no
llene la pantalla). En el campus además se volvió `required` al capturarlo;
carrera y asignatura ya lo eran, y su columna ya era NOT NULL — el único hueco
real era el campus. Aun así los tres se comprueban: una columna NOT NULL admite
la cadena vacía, y de una carga masiva puede salir así.

## Trampas al programar (ya mordieron)

- **El 303 después de PUT/PATCH/DELETE lo pone Inertia solo; el `back()` pelado
  NO está roto.** Esta entrada decía que había que escribir `back(303)` siempre,
  y con ella un vistazo al código encuentra 154 acciones «rotas» y manda a
  reescribirlas. Medido contra el demo con una ruta desechable: con la cabecera
  `X-Inertia` un `back()` sale **303**, sin ella sale 302 — el middleware de
  `inertiajs/inertia-laravel` lo convierte, y `HandleInertiaRequests` lo hereda
  y está en el grupo `web`, que es el que usan las rutas de tenant.
  - El mecanismo de fondo sí es real: ante un 302 el navegador repite el
    redirect CON EL MISMO MÉTODO, el PATCH sale otra vez contra una pantalla que
    sólo responde GET y termina en 405 con el cambio ya guardado —parece que el
    botón no sirve—. Por eso importa **cuando la petición no lleva esa
    cabecera**: un formulario Blade, un `fetch` a mano, algo fuera de la SPA.
  - Comprobado que hoy no hay ninguno así: todas las llamadas de
    `resources/js` que no pasan por el router de Inertia son GET o POST, y en
    POST el 302 es correcto.
  - `back(303)` explícito no estorba y hay ~60 sitios que lo usan. Los dos
    estilos conviven sin consecuencia; no vale una reescritura masiva.
- **Los catálogos universales viven en la base CENTRAL.** Sexos, países,
  entidades federativas… tienen modelo en `App\Models\Landlord\` con
  `CentralConnection`. Un `DB::table(...)` desde el tenant revienta con «table
  doesn't exist». Siempre por el modelo.
- **`niveles_estudio` YA NO es universal: se mudó al tenant.** Cada escuela
  administra los suyos, y el modelo bueno es `App\Models\Academico\NivelEstudio`.
  El de landlord se conservó **sólo como semilla** y sigue contestando, que es lo
  que hace peligrosa la confusión: no falla, devuelve la lista sembrada (ids 1–7)
  mientras las carreras de la escuela apuntan a los suyos (81, 82, 85…). Seis
  pantallas quedaron leyendo la tabla equivocada durante semanas sin síntoma;
  salieron a la luz al agregarle el interruptor al catálogo, porque `->activos()`
  sólo existe en el modelo del tenant y todas dieron 500 de golpe. Lo fija
  `tests/Unit/NivelesDeEstudioSonDelTenantTest`, que prohíbe el import viejo.
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
  como hacen `MiHistorialTest` y las demás.
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
- **`Prepared statement needs to be re-prepared` (MySQL 1615) tras migrar.**
  Aparece de golpe en varias suites a la vez y NO es del código: la escuela demo
  tiene **236 tablas** y el `table_definition_cache` de este MySQL está en 600
  para todas las bases juntas. Al correr migraciones, MySQL sube la versión de
  metadatos de lo que toca y va desalojando definiciones; un `DELETE` contra una
  tabla desalojada revienta con 1615.
  - **Se nota porque golpea a las tablas VIEJAS y no a las recién creadas**
    —`ventanas_captura`, `actividades` y `ciclos` fallaban mientras
    `videoconferencias` y `grabaciones`, creadas ese mismo día, iban bien—, que
    es exactamente al revés de lo que haría un error de código nuevo.
  - Se cura con `FLUSH TABLES` (o reiniciando MySQL). Comprobado: tras el flush,
    el mismo `DELETE` pasa.
  - **Ojo al barrer**: hizo caer una suite entera con «0 correctas, 1 fallidas»
    y estuvo a punto de reportarse como una regresión de la entrega.

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
- Comandos de apoyo: `acadion:usuario-demo`, `acadion:oferta-demo`,
  `acadion:rubrica-demo` (deja una materia con docente, rúbrica y entregas por
  calificar; hizo falta porque **el LMS del demo no se podía abrir**:
  `docente_asignatura_grupo` está vacía y los dos únicos cursos cuelgan de
  `asignatura_grupo` 4 y 5, que ya no existen —las vivas van de la 41 a la 72—,
  con 7 actividades y 3 entregas inalcanzables. Otro resto de resiembra con las
  foráneas apagadas; no se repara, porque sería inventarle un grupo a un
  contenido cuyo grupo se perdió). Ojo:
  `acadion:usuario-demo` sólo CREA; sobre la escuela de ejemplo, que ya tiene
  ese usuario, revienta con `Duplicate entry 'demo'`. Para restablecer la
  contraseña hay que hacerlo a mano.
- La cuenta del alumno de prueba es `alumno.demo.1` (usuario 275), y su
  contraseña es aleatoria de 40 caracteres —la pone `AprovisionadorAcceso`—,
  así que **no se puede entrar como él**. Para ver su portal se usa la
  suplantación: `POST /suplantar/275` desde una sesión con
  `suplantar-usuarios`, y se sale con «Volver a mi cuenta».
- `clases:recoger-grabaciones` busca en Google las de las clases de Meet ya
  terminadas (Meet no tiene webhook: hay que preguntarle). Mira las últimas 48 h
  y salta las clases que ya tienen algo anotado.
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
  (`/escolar/alumnos`) con búsqueda, historial académico y edición.
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
    situación de baja) y historial académico independiente por cada una.
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
  avance sobre CUATRO PASOS FIJOS (datos, documentos, formularios y pago) — no
  varian por campana ni carrera, por decision del cliente. Decia tres: los
  formularios entraron al avance en `20d3e03`. **Ese avance NO es la etapa del
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
  último para lo que no es persona: un grupo no tiene cara).
  Grupos dejó de devolver TODOS los grupos sin paginar.
- **Las pestañas de sección son UN componente, `PestanasSeccion`**, y salen del
  catálogo del menú. Eran dos —`NavAcademico` y `NavEscolar`— haciendo lo mismo,
  y por eso divergieron: al derivar las de Académico, Control escolar se quedó
  con su lista escrita a mano de tres opciones mientras la barra lateral
  mostraba siete. Dos organigramas del mismo módulo en la misma pantalla, que es
  el defecto que se había ido a corregir. Con una sola no puede repetirse.
  - Se muestran las HERMANAS del nivel donde está parada la pantalla: dentro de
    un subgrupo, las suyas. Con una sola opción la barra no se dibuja (Alumnos y
    Docentes tienen una).
  - **No todas las secciones tienen pestañas**: Finanzas, Plataforma, Admisiones
    y Certificación se navegan sólo desde la barra lateral. El encabezado no
    depende de ellas, así que la miga se lee igual; ponérselas a todas es una
    decisión de diseño pendiente, no un arreglo.
- **Un aspirante dado de alta a mano nace en la primera etapa del embudo.**
  Antes solo lo hacía el formulario público, así que el prospecto capturado por
  personal quedaba con `etapa_crm_id` en null e **invisible para el CRM**.
  Corregido en el controlador + migración de backfill.
- **CRM completo en la ficha del aspirante** (pedido del cliente 2026-08-11).
  Buena parte existía por debajo y sólo se operaba desde `/promocion`; lo que
  faltaba de verdad era la agenda y el equipo.
  - **La bitácora AGENDA, no sólo registra.** `seguimientos_aspirante` gana
    ciclo de vida: `agendado` → `realizado` (con desenlace y respuesta) o →
    `cancelado` (con motivo). Antes lo único que miraba al futuro era
    `proximo_contacto`, una FECHA SUELTA que no se podía cerrar ni cancelar.
    **Una sola tabla y no dos**: una llamada agendada y una hecha son la misma
    cosa en dos momentos, y separarlas obligaría a re-mezclarlas en la pantalla.
  - Esto MATIZA el «append-only» que declaraba la tabla: lo cerrado no se
    reescribe, pero lo agendado sí cambia porque es un plan. Tres desenlaces y
    ninguna edición más.
  - `resultados_seguimiento` es catálogo. `cuenta_como_contacto` separa hablar
    con alguien de marcarle sin éxito —sin eso «se le llamó seis veces» no dice
    si lo atendieron— y `cierra_el_embudo` marca los que lo dan por perdido.
  - **`AgendaDelAspirante`** tiene las reglas: no se cierra dos veces (el
    conteo de intentos mentiría), cerrar exige desenlace, cancelar exige motivo
    y NO borra, y reprogramar mueve la fecha en vez de inventar un intento.
  - **La ETAPA se mueve desde la ficha.** Antes sólo como efecto secundario de
    registrar un seguimiento en el tablero: para corregir una etapa mal puesta
    había que inventarse un contacto. Ahora es su gesto y deja rastro.
  - **Las rutas de actividad van SIN `can:`**: por ahí entran dos oficios y un
    middleware con el permiso de uno rebotaría al otro. Lo resuelve el
    controlador, con el par de siempre —permiso + asignación—.
  - **Pantalla de asesores** (`/promocion/asesores`): `asesores`,
    `situaciones_asesor` y `campus_asesor` existían desde la Fase 1 y NUNCA
    tuvieron pantalla —el demo tenía cero asesores—, así que todo el CRM colgaba
    de una tabla que nadie podía llenar. Un asesor se APAGA, no se borra.
  - **`AsignadorAsesor`** reparte al crear el prospecto, en tres modos
    configurables: manual, quien-lo-registra y secuencial. **El turno va por
    CARGA, no por un contador guardado**: un contador se desincroniza al darse
    de baja alguien o con dos altas a la vez, y reparte torcido para siempre sin
    que nadie lo note. Se cuentan los prospectos ABIERTOS —contar los históricos
    castigaría al que más ha inscrito—.
  - «Quien registra» cae al reparto cuando quien captura no es asesor: si no,
    los prospectos del formulario público se quedarían huérfanos.
  - **El campus del aspirante es obligatorio al capturarlo** (la columna sigue
    nullable y la regla vive en el FormRequest, para decir «elige el campus» en
    vez de reventar en la base). Sin campus no hay entre quiénes repartir.
  - Las tareas agendadas entran en la **agenda del panel**, con lo vencido en
    rojo: nadie piensa «mis prospectos» y «mi calendario» por separado.
  - **Trampa que mordió**: `Aspirante::asesores()` devuelve modelos **Asesor**
    —PK `persona_id`, sin `nombreCompleto()`—, no `Persona`. Pedirle `->id` da
    null y `->nombreCompleto()` revienta; no se veía porque ningún prospecto
    tenía asesor. Lo cazó `prueba-actividad-crm`.
  - Pruebas: `scripts/prueba-actividad-crm.php`, 29 verificaciones, comprobada
    mutando la regla de re-cierre (caen exactamente las tres que la vigilan).
- Pruebas: 66 suites en `scripts/`, contra la BD real del tenant demo con
  `DB::rollBack()` al final. **66 en verde** (ver la regla 5 para qué tumbó a
  las 33 que estuvieron en rojo). `prueba-listados` es la primera
  que invoca a los CONTROLADORES y lee sus props de Inertia, en vez de
  reimplementar la consulta: un `or` sin paréntesis no se detecta de otra forma.
- **Las reglas configurables se APLICAN de verdad** (auditoría del 2026-08-20).
  Cinco interruptores de `/plataforma/configuracion` no los leía nadie: la
  escuela podía encenderlos y creer que había puesto una regla. Un interruptor
  que no hace lo que dice es peor que no tenerlo, porque se confía en él.
  - `aspirante.exige_documentos_para_convertir` y
    `aspirante.exige_pago_para_convertir` → `ConvertidorAspirante::impedimentos`,
    preguntándole a `ProgresoSolicitud` en vez de recalcular: es lo que ya se le
    enseña al aspirante en su portal, y copiarlo dejaría dos verdades.
  - `docente.exige_cedula_para_asignar` y `docente.max_materias_por_ciclo` →
    `AsignaturaGrupoController::motivoParaNoAsignar`, en el alta de uno y en la
    de lote. En el lote el tope se mira EN CADA VUELTA: comprobarlo sólo al
    principio dejaría pasar doce materias a quien tiene cupo para una.
  - `acta.formato_folio` y `acta.ambito_consecutivo` estaban declarados DOS
    veces —en el catálogo y dentro de `GeneradorFolioActa`, con su propio
    default—. Coincidían por casualidad; ahora hay una sola declaración.
  - **Se retiraron dos cosas que no se podían cumplir**:
    `alumno.matricula_unica_por_persona` prometía el mismo número de matrícula en
    dos programas, y `matricula_oferta.matricula` tiene índice ÚNICO —cumplirlo
    exigiría tirarlo, y entonces la matrícula dejaría de identificar una fila—; y
    el permiso `crear-personas`, que ninguna ruta comprobaba porque una persona
    nunca se crea sola, nace dentro del alta de un aspirante, alumno, docente,
    tutor o usuario, cada una con su propio permiso.
  - **Trampa que mordió al escribir esto**: `ProgresoSolicitud::para()` devuelve
    `['pasos' => …]`, no la lista de pasos. Indexar el resumen entero no
    encontraba ninguno y los `?? true` de respaldo lo convertían en «cumplido»:
    una regla de seguridad fallando ABIERTA y en silencio. La primera versión de
    la prueba tampoco lo veía —decía «o lo detiene o está completo», que pasa
    pase lo que pase—. Se cazó mutando y se corrigió construyendo el caso: un
    aspirante propio, sin un solo documento y con un cargo sin pagar.
  - Pruebas: `scripts/prueba-reglas-configurables.php`, 18 verificaciones,
    comprobada mutando cinco reglas.

- **Clases en línea** (`/plataforma/clases-en-linea` para configurarlas, permiso
  `gestionar-clases-en-linea`): el docente programa desde su materia y al alumno
  le aparece el botón para entrar. Zoom y Google Meet.
  - **Zoom y Meet NO son simétricos, y es la decisión que gobierna todo.** Una
    licencia de Zoom sostiene UNA reunión a la vez —dos clases a las 9:00 exigen
    dos licencias—; una cuenta de Meet no tiene ese límite, porque el enlace nace
    de un evento de Calendar y no de una licencia de anfitrión. Lo declara
    `ProveedoresVideoCatalogo::unaReunionPorCuenta` y ante un proveedor
    desconocido responde `true`, que es el lado seguro.
  - **Meet exige Google Workspace** y una cuenta de servicio con delegación en
    todo el dominio: con Gmail personal no se puede. Y **no tiene enlace de
    anfitrión aparte** —todos entran por el mismo—, así que `url_anfitrion` va
    en null en vez de duplicar el otro.
  - **La FILA es el apartado de la licencia**: se inserta la clase sin enlaces
    dentro de una transacción que bloquea las cuentas, luego se llama al
    proveedor, y al final se le ponen los enlaces. Sin eso, dos docentes
    programando a la vez se llevan la misma licencia y la segunda clase echa a
    la primera. La llamada HTTP va FUERA del bloqueo.
  - **`url_anfitrion` es una CREDENCIAL**: el `start_url` de Zoom entra como
    dueño de la sala sin pedir contraseña. Lo que ve el alumno lo arma
    `Videoconferencia::paraElAlumno()` en el MODELO, no cada pantalla; y el
    `url_join` tampoco viaja mientras la clase no esté abierta.
  - El traslape se guarda con **inicio y fin** (no duración) y se comparan las
    dos condiciones: una clase de 9 a 11 y otra de 10 a 10:30 no comparten hora
    de arranque y chocan igual.
  - `config/video.php` con modo `fake` para recorrer el flujo sin credenciales,
    igual que el cobro. El default es `real`.
  - **Ojo**: el manejador de excepciones no contemplaba el 422 y el aviso de
    «tus licencias están ocupadas» no llegaba. Se agregó **sólo cuando el motivo
    es nuestro**, porque `ValidationException` también es 422 y a ésa la maneja
    Inertia con los errores por campo.
  - Pruebas: `scripts/prueba-clases-en-linea.php`, 29 verificaciones, comprobada
    mutando cuatro reglas —quitarle el límite a Zoom, medir el traslape sólo por
    la hora de inicio, dar el enlace antes de que abra y filtrar el de
    anfitrión— y caen exactamente las que las vigilan.

- **Grabaciones archivadas** (`/plataforma/clases-en-linea`, misma pantalla):
  lo que Zoom o Meet grabó se copia a donde la escuela diga y el enlace queda
  colgado de la clase.
  - **Tres hechos que gobiernan el diseño.** (1) Zoom sólo entrega por API lo
    grabado EN LA NUBE —«grabar en este equipo» se queda en la computadora del
    docente—, y esa nube da unos pocos GB por licencia. (2) Las de Meet YA están
    en el Drive de quien organizó, así que con destino Drive no hay que copiar
    nada. (3) Meet **no tiene webhook**: Zoom avisa con `recording.completed` y a
    Google hay que preguntarle, de ahí el comando `clases:recoger-grabaciones`.
  - **UN destino a la vez** (`disco`, `drive`, `dropbox`). Con dos habría que
    decidir qué enlace ve el alumno y se pagaría dos veces el mismo archivo.
    Cambiar de destino NO mueve lo ya archivado: cada grabación guarda a dónde
    fue.
  - **El destino por omisión es el disco de la escuela**, y es el único que
    puede acotar de verdad quién abre el archivo: la URL la sirve Acadion
    comprobando materia y matrícula. Un enlace de Drive o Dropbox lo abre
    cualquiera que lo tenga.
  - **Si se publican solas lo decide la escuela**, con el ajuste «Publicar las
    grabaciones en cuanto llegan» (`/plataforma/configuracion`, grupo «Clases en
    línea»). Por omisión APAGADO: trae caras y voces de menores, así que el
    valor por omisión es el que no publica a nadie sin que alguien lo pida, y el
    docente enciende una por una desde su materia.
  - **El ajuste se lee AL ANOTAR la grabación y se copia a la fila**, no al
    mirarla. Así cambiar la regla no publica —ni esconde— de golpe lo que ya
    existía: publicar de un plumazo un semestre de clases con menores dentro no
    puede ser el efecto de mover un interruptor. Lo fija la suite con las dos
    direcciones del cambio.
  - **Idempotente por `(origen, id_externo)`**: Zoom reenvía su aviso si no se
    le contesta rápido, y sin esa llave la misma clase se archiva tres veces.
  - **El webhook comprueba FIRMA** (HMAC con el Secret Token, ventana de 5 min).
    No puede usar la defensa de los pagos —allá el aviso sólo dice qué preguntar;
    aquí el cuerpo trae la URL que se descarga—. **Sin secreto configurado se
    rechaza**: aceptarlo a ciegas sería «descárgame lo que yo diga».
  - Se descarga por trozos a un temporal que se borra en `finally`, también
    cuando falla: sin eso, cada reintento deja medio giga en la partición.
  - **La consulta a Meet ya está conectada** (`ConsultorDeGrabacionesMeet`). El
    puente con la API es el **código de reunión** que esconde el enlace
    (`abc-defg-hij`): la API de Meet no sabe de eventos de Calendar, que es lo
    que Acadion crea. Van dos llamadas —`conferenceRecords` filtrando por ese
    código, y `recordings` de cada sesión—, y se filtra por ESPACIO y no por
    fecha porque un mismo enlace se reusa y por hora saldría la clase de otro
    grupo.
  - **Sólo se registra lo que está en `FILE_GENERATED`.** Google anuncia la
    grabación desde que empieza; antes de ese estado el archivo no existe y
    registrarla dejaría una pendiente imposible de bajar.
  - **Con destino Drive no se copia nada**: Google ya dejó el archivo en el
    Drive de quien organizó, y copiarlo del mismo Drive al mismo Drive sería
    pagar dos veces y duplicar un video de menores. Se registra ya archivada.
  - **Lo que va en camino no se reencola.** Sólo se reintenta lo FALLIDO: sin
    eso, cada aviso repetido de Zoom o cada pasada del comando ponía a otro
    trabajador a bajar el mismo video de 600 MB.
  - **El JWT de Google vive en `App\Services\Google\TokenDeServicio`**, con el
    alcance como parámetro. Estaba escrito dos veces (Calendar y Drive) y hacía
    falta una tercera; tres copias de una firma criptográfica es como se llega a
    que una tenga el `sub` mal y falle sólo en el camino que nadie prueba.
  - **Lo que NO se ha comprobado es el viaje contra Google**: hace falta un
    Workspace con grabación habilitada. Está escrito contra la forma documentada
    de la API v2 y probado con respuestas fingidas de esa forma; lo que sí se
    procuró es que cada respuesta inesperada quede en el registro con su cuerpo,
    porque una lista vacía en silencio es indistinguible de «no se grabó».
  - Pruebas: `scripts/prueba-grabaciones.php`, 43 verificaciones, comprobada
    mutando ocho reglas. **Una de esas mutaciones destapó que la prueba de los
    temporales era falsa**: en Windows `tempnam()` recorta el prefijo a TRES
    letras (`grabacion-` → `gra910D.tmp`), así que el glob no encontraba nada y
    pasaba con el borrado quitado. Ahora compara el directorio antes y después.

- **Rúbricas de evaluación** (`/rubricas`, permiso `gestionar-rubricas` y
  derivado `usar-rubricas`): calificar un trabajo eligiendo un nivel por
  criterio, en vez de escribir un número. Cuelga de la RAÍZ como `/captura`,
  porque la usan dos oficios.
  - **DOS ámbitos en UNA tabla** (`rubricas.ambito`): las de la escuela
    («plataforma», sin dueño) y las de cada quien («docente», con `persona_id`).
    La de otro docente no se ve ni con permiso de gestionar, y pedirla da **404
    y no 403** —un 403 ya revelaría que existe—.
  - **El máximo de un criterio NO se guarda**: es su nivel más alto, y el total
    de la rúbrica la suma de esos máximos. Una columna podría contradecir a los
    niveles y no habría a cuál creerle.
  - **Se CONGELA al primer uso** y para cambiarla se DUPLICA, como ya hacían
    `formularios` y `esquema_evaluacion`. Quitarle un criterio dejaría las
    evaluaciones hechas sumando un total que no cuadra. Renombrarla sí se puede:
    es de la ficha, no de la cuenta.
  - **Por eso la actividad la APUNTA en vez de copiarla**, al revés que todo lo
    demás que `CopiadorDeCurso` se lleva al grupo: copiarla por grupo y ciclo
    partiría el catálogo en cientos de duplicados. Lo que obligaba a copiar el
    examen —editar la plantilla cambia lo que un grupo contesta— aquí no aplica.
  - **En la PLANTILLA del plan sólo caben las de la escuela.** Se copia a todos
    los grupos de esa materia; una propia acabaría calificando en grupos de
    otras personas, que ni siquiera pueden verla.
  - **La rúbrica es una ESCALA, no la nota**: sus puntos se llevan a los de la
    ACTIVIDAD (una de 20 sobre una actividad de 10 da 8.5, no 17). Sin eso no se
    podría reusar en trabajos de distinto peso.
  - **Un criterio sin evaluar no es un cero**: lo evaluado se guarda y la
    entrega queda SIN calificar. Misma regla que la captura de calificaciones.
  - **Los puntos los pone el servidor**: de la petición sólo se cree qué nivel
    se eligió, y se comprueba que el nivel sea DE ESE criterio.
    `entrega_rubrica.puntos` se guarda igual porque la evaluación es un hecho
    fechado y el nivel es catálogo.
  - **El alumno la ve ANTES de entregar**, no sólo con la nota; calificada, se
    marca el nivel obtenido y los demás se ATENÚAN, porque ver dónde quedó uno
    respecto de lo de arriba es la mitad de la información.
  - Ni la lectura ni el EXAMEN la llevan: el examen lo califica la máquina, y
    con las dos cosas habría dos notas para la misma entrega.
  - Pruebas: `scripts/prueba-rubricas.php`, 43 verificaciones, comprobada
    mutando tres reglas —creerle los puntos a la petición, quitar el
    congelamiento y contar el blanco como cero— y caen exactamente las que las
    vigilan.
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
  `gestionar-calendario`): **feriados y eventos**, con rejilla del mes y lista
  editable debajo. Decía «avisos, feriados, recesos, inicio y fin de ciclo,
  evaluaciones y eventos» y el enum se redujo a dos: lo que un evento ES lo dice
  su título, y lo único que el tipo aporta es comportamiento —si se trabaja ese
  día—.
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
- **Historial académico del alumno** (`/mi-historial`): `ver-historial-academico` lo tenía el rol alumno
  desde siempre, pero el único historial académico del sistema vivía dentro del expediente de
  control escolar, detrás de `ver-alumnos` —permiso de personal que abre el
  listado de TODA la escuela—. Un permiso concedido sin puerta por donde entrar.
  - El cálculo se extrajo a **`App\Services\HistorialDelAlumno`** y AHORA LO USAN
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
    servicio; lo fija `MiHistorialTest`.
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
    su propio QR cada una. Misma decisión que el historial académico.
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

- **Historial académico imprimible y su diseñador**
  (`/escolar/configuracion/historial`, permiso `gestionar-historial`): la escuela
  decide qué lleva el encabezado, QUÉ COLUMNAS trae la tabla y en qué orden, cómo
  se agrupan las materias, el resumen, la leyenda, la firma y el sello.
  - **No es un editor de cajas como la credencial, y no por atajo**: un historial
    CRECE —siete renglones en primer semestre, trescientos al egresar—, así que
    no hay coordenada que valga para la fila doscientos. Entre los historiales
    reales de referencia la maqueta cambia poco y las columnas cambian mucho.
  - **Una o dos columnas de bloques**: «Periodo 1» y «2» lado a lado, «3» y «4»
    en la fila siguiente. Un bachillerato de seis semestres pasa de tres hojas a
    una. Sin agrupar NO se parte: una lista corrida cortada por la mitad obliga a
    bajar por la izquierda y volver a subir.
  - **El bloque se rotula con la palabra del PLAN** (`tipo_periodo_id` →
    `PlanEstudio::unidadPeriodo()`): «Semestre 1», «Cuatrimestre 3», «Módulo 2».
    La cabecera de la columna homónima usa la misma palabra.
  - **Descarga del alumno con interruptor**, y con marca de agua por omisión: sin
    ella, un PDF idéntico al oficial circula sin sello ni firma autógrafa.
    Cerrada, la ruta responde 404 —no 403—.
  - **En Blade y no en PDF generado**: el proyecto no tiene librería de PDF y el
    navegador ya sabe imprimir. Los estilos van en línea para que un fallo de
    assets no deje sin forma el historial de alguien en el mostrador.
  - Variantes por nivel de estudios, igual que la credencial.
- **Los catálogos de nivel de estudios y tipo de periodo se pueden APAGAR**
  (interruptor en la columna de acciones de `/academico/catalogos`). Apagado = no
  aparece en ninguno de los catorce desplegables. **Sólo se apaga lo que nadie
  usa**, y «nadie usa» mira las OCHO tablas que referencian al nivel, dos de
  ellas sin foránea (`evento_destinos` y `emisor_asignaciones`). El filtro va
  ESCRITO A MANO en cada desplegable (`->activos()`) y no como scope global: el
  global habría filtrado también las lecturas POR ID, y entonces apagar un nivel
  dejaría el historial de una alumna sin su nivel, sin error.
- **Tipos de certificación y títulos profesionales viven en Académico →
  Catálogos.** Los títulos profesionales estaban DOS VECES en el menú —bajo
  certificación y bajo titulación— y era la misma tabla con el mismo controlador.
  Sus columnas son `abreviatura` y `descripcion`, no `clave` y `nombre`: el
  registro genérico las MAPEA en vez de renombrarlas, porque las lee el XML del
  título.
- **«Certificación y titulación» es un solo menú** con un submenú para cada una.

- **Lo capturado ya no se puede enterrar** (2026-08-21). `EsquemaEvaluacion`
  lleva `TieneAuditoria`, o sea borrado LÓGICO: la foránea de
  `calificaciones_componente` no llegaba a dispararse nunca y borrar un
  componente con calificaciones devolvía ÉXITO, dejando los números colgando de
  una fila invisible. Peor que reventar, porque un error se ve: el esquema queda
  sumando 90 %, la final deja de calcularse, y si alguien agrega otro componente
  para llegar a 100 el trabajo del docente queda enterrado con la pantalla
  normal.
  - Ahora se niega y nombra la salida que EXISTE —vaciar esa celda en la hoja de
    captura—, y las actividades del LMS también lo sostienen: una actividad
    declara a qué componente pondera, y quitárselo la deja suelta (ya hubo que
    escribir una migración para reparar tres así).
  - **«Capturada» se define UNA vez**, en `CalificacionComponente::capturadas()`:
    guardar la hoja escribe fila por alumno con NULL donde el docente no llegó,
    así que contar FILAS haría que abrir la pantalla una vez congelara la
    materia para siempre. La usan los dos que deciden congelar: esta guarda y
    `AplicadorPlantillaEvaluacion`, que contaba filas y bloqueaba re-aplicar una
    plantilla sin que nadie hubiera calificado a nadie.
  - Relajarlo destapó un **500 latente**: el aplicador borra el esquema viejo con
    `forceDelete` y sin llevarse antes los rastros en blanco la foránea revienta.
    No se veía porque esas mismas filas bloqueaban antes — se cambiaba un aviso
    claro por un error de base.
  - **Resuelto el 2026-08-22, por decisión del cliente: bloquea y avisa.** Ese
    mismo `forceDelete` disparaba el `nullOnDelete` de `actividades`, así que
    re-aplicar una plantilla dejaba sin componente, en silencio, a las
    actividades del curso. Ahora `motivoParaNoAplicar` pregunta las DOS razones
    en un solo sitio y la materia entra a `bloqueadas` CON su motivo —se bloquea
    por dos causas distintas y la salida de cada una es otra: vaciar celdas de
    captura, o mover las actividades—. `bloqueadas` pasó de `string[]` a
    `{materia, motivo}[]` y la pantalla los enumera.
    - **Se cuentan las actividades de TODOS los cursos**, no sólo las de la
      plantilla del plan: `CopiadorDeCurso` copia al grupo apuntando al MISMO
      `esquema_evaluacion_id`, así que mirar sólo el curso del plan dejaría
      pasar el reemplazo y desengancharía en silencio las de cada grupo abierto.
    - No estorba la PRIMERA aplicación: una materia sin esquema no tiene nada
      colgando. Sólo la re-aplicación sobre trabajo ya hecho.
  - Pruebas: `tests/Feature/RetirarComponenteDeEvaluacionTest`, 7 casos,
    comprobadas mutando tres reglas.

- **Elegir alumnos uno por uno ya funciona** (2026-08-21). Calendario, avisos y
  encuestas comparten `SelectorDestinos` para dirigirle algo a alumnos
  señalados. **No funcionaba en ninguna de las tres, por dos motivos distintos**:
  avisos y encuestas apuntaban a `/api/buscar/alumnos`, que NUNCA existió —y
  `BuscadorRemoto` sólo tiene `finally`, sin `catch`, así que el 404 dejaba la
  caja en blanco, idéntico a «no hay resultados»—; y la del calendario, la única
  con ruta real, unía contra `ofertas` en plural cuando la tabla es `oferta`, o
  sea que reventaba. Ahora es UN endpoint en la raíz, `/buscar/alumnos`, con el
  permiso derivado `dirigir-a-alumnos` (calendario **o** avisos **o** encuestas),
  y los nombres de tabla se le preguntan a los modelos. Cinco pruebas.
  - **Se encontró comparando las URLs literales del frontend contra las rutas
    registradas.** Vale la pena repetirlo al agregar pantallas: es barato y
    encuentra botones muertos que no dan error. Quedaron 22 avisos más, todos
    revisados y falsos positivos —prefijos de menú, líneas de comentario, bases
    a las que se les añade un id, y un `put`/`post` en el mismo renglón—.
  - Se auditó también lo declarado y no usado: **74 permisos y 17 ajustes, todos
    con lector**. Nada que retirar; el barrido de `crear-personas` y compañía ya
    había limpiado esa clase.

- **El panel dejó de tener un mosaico de atajos y tiene TRECE tarjetas nuevas**
  (2026-08-22). «Accesos directos» era el menú lateral otra vez, con una cifra al
  lado; se retiró junto con `PendientesDeAcceso`, que sólo esa tarjeta usaba.
  - **Lo que se midió antes de tocar nada**: de los seis roles base del demo, el
    `administrativo` y el `aspirante` veían UNA tarjeta —los atajos—, así que
    quitarlos dejaba su panel en blanco. Y de 74 permisos del catálogo, sólo 9
    tenían tarjeta anclada. El panel no estaba soso: estaba vacío.
  - Las nuevas, cada una sobre un permiso que no tenía ninguna: **Mi solicitud**
    (aspirante), **Mis hijos**, **Mis tutorados**, **Materias sin titular**,
    **Ocupación de los grupos**, **Listas sin pasar**, **Actas por asentar**,
    **Mi expediente** (docente), **Expedientes por validar**, **Listos para
    inscribir**, **Por confirmar en caja**, **Pendiente de facturar**,
    **Certificación en curso** y **Clases en línea de hoy**.
  - **Ninguna recalcula un criterio que ya existe.** Al revés: hizo falta subir
    tres a un solo sitio —`Grupo::scopeConAlumnos` (estaba dentro de
    `GrupoController::index`), `EmisorFactura::pagosOcupados` (era privado) y
    `Pago::titular()` (lo tenía `ComprobantePago` y no `Pago`, asimetría que se
    cobró con un BadMethodCallException al probar con datos sembrados)—.
  - **Al tutor educativo NO se le enseña lo financiero.** Su pantalla se lo niega
    a propósito —eso es de la familia y de la escuela— y el panel no puede
    abrirlo por la puerta de atrás.
  - **`barras` dibuja relativo al mayor de la serie y escribe `valor` crudo**;
    `porcentaje` hoy sólo lo lee la tarjeta de encuestas. Por eso «Ocupación de
    los grupos» pone el PORCENTAJE en `valor` (como ya hacía «Continuar donde me
    quedé»): con las cabezas ahí, un grupo 25/30 y otro 25/100 saldrían con la
    misma barra. Y el formato de dinero es `moneda`, no `dinero` — con el otro la
    cifra sale sin formato y sin error.
  - **Dos suites pasaban por la razón equivocada y se repararon**:
    `prueba-tarjetas-rol` encendía la clave fija «accesos» y pedía que lo visible
    fuera un SUBCONJUNTO de ella —lo cual se cumple también con CERO tarjetas—,
    así que seguía en verde con la tarjeta ya borrada y con el apagado por rol
    mutado a no hacer nada; y `prueba-panel` comprobaba que el docente viera los
    atajos, que veía todo el mundo por no tener permiso.
  - Comprobado en el navegador con la sesión del demo: 9 tarjetas, sin
    desplazamiento horizontal, los iconos dibujan y ninguna vecina comparte tono.
    Lo único que sobresale del marco es la marca de agua, cortada aposta.

- **`acadion:auditar-datos`**: busca filas que apuntan a registros que ya no
  existen. MySQL sólo comprueba las foráneas al ESCRIBIR, así que una resiembra
  con `SET FOREIGN_KEY_CHECKS=0` deja filas envenenadas que sólo estorban el día
  que alguien toca el esquema. Lee las foráneas declaradas de
  `information_schema`; por omisión sólo informa y `--reparar` pone en NULL lo
  que admite null.
  - **El demo ya está reparado** (2026-08-22): de 199 filas rotas quedan **69**
    —63 en columnas obligatorias, que no se tocan porque la fila entera perdió
    sentido y borrarla es decisión de la escuela, y 6 en filas intocables (ver
    abajo)—. Respaldo previo en
    `C:\Dev\tenantdemo-antes-de-reparar-2026-08-22.sql`.
  - **Que la columna admita NULL no basta.** `adeudos` tiene un CHECK que exige
    exactamente un titular —matrícula o aspirante—, así que anular una matrícula
    rota deja la fila sin ninguno y MySQL lo rechaza. Se intenta y se reporta lo
    que la base niegue, en vez de interpretar cláusulas CHECK.
  - **Y una fila rota en una columna OBLIGATORIA es intocable entera.** MySQL
    revalida TODAS las foráneas de la fila en cualquier `UPDATE`, no sólo la que
    se toca, así que anular su columna anulable revienta con 1452. Comprobado:
    la beca 5 y la conversación 13 arrastran las dos cosas y son exactamente las
    que fallaron. Se destraban resolviendo la obligatoria o borrando la fila.
  - **Se repara COLUMNA POR COLUMNA, no todo o nada.** Iba en una sola
    transacción por escuela «para que no quede media reparación», y con eso la
    primera columna imposible tumbaba a las once buenas y la escuela se quedaba
    sin reparar para siempre. Cada `UPDATE` es atómico de por sí y reparar es
    idempotente. Lo fija `AuditarDatosTest`, comprobado mutando.
  - **Un fallo NO puede terminar diciendo «ninguna referencia rota».** Es lo que
    hacía: la excepción subía al `catch` por escuela, el contador se quedaba en
    cero y el comando declaraba limpia una base con 199 rotas. Ahora sale con
    error y dice que el reporte está incompleto.
  - **Ojo con `persona_rol.campus_id`: ahí NULL significa MÁS.** Reparar
    convierte un rol atado a un campus inexistente en un rol GLOBAL. Es la
    corrección correcta —apuntaba a la nada y así nadie podía trabajar— pero es
    un ensanchamiento de alcance hecho por un comando de mantenimiento, así que
    lo avisa en pantalla y hay que volver a asignar el campus a mano. En el demo
    le tocó a `staff.centro` y `staff.norte`.
  - **La subconsulta lleva alias y no es cosmético**: sin él una foránea que
    apunta a su propia tabla (`roles.rol_padre_id`, `encuestas.origen_id`)
    pierde la correlación y TODA jerarquía válida sale reportada como rota —con
    `--reparar`, la escuela entera se quedaba sin herencia de permisos—. Lo fija
    `AuditarDatosTest`.

**Pendiente inmediato — aquí se retoma:**

*(Antes de tomar algo de esta lista, COMPROBARLO en el código. **Ya van cinco**
que mandaba a construir cosas hechas: la titulación SEP, el estado de cuenta del
alumno, los horarios, la pasarela de pago y el panel del landlord. Las tres
últimas se cayeron al comprobarlas el 2026-08-19; lo comprobado ese día está
marcado abajo con su fecha, y lo que NO la lleve sigue sin verificar.)*

0. **ELIMINAR LA SITUACIÓN DEL ASPIRANTE por completo** (decidido con el
   cliente el 2026-08-11; se aplazó a una sesión propia por tamaño).

   *Por qué*: `situaciones_aspirante` duplicaba al embudo. Ya se le retiraron
   «En proceso» y «Aceptado» —eran etapas disfrazadas de desenlace—, la columna
   salió de la tabla y en la ficha se calla mientras diga «Prospecto». Quedan
   tres valores y sólo dos informan, así que el campo entero se va.

   *Dónde van los dos que informan* —esta es la parte que no se puede
   improvisar—:
   - **Inscrito** → se DERIVA de tener `matricula_oferta`. Es más cierto que el
     campo: hoy se puede tener situación «Inscrito» sin matrícula y nada se
     queja.
   - **Rechazado** → columnas nuevas en `aspirantes`: `descartado_en`
     (timestamp) y `motivo_descarte`. Un descarte tiene fecha y razón; una fila
     de catálogo no puede darlas.

   *Alcance medido*: 6 archivos de aplicación (`AspiranteController`,
   `GuardarAspiranteRequest`, `Aspirante`, `SituacionAspirante`,
   `ConvertidorAspirante`, `RegistradorProspecto`), el seeder de admisiones,
   8 suites de `scripts/` y 11 pruebas de `tests/Feature`. Más el `DROP` de la
   tabla y de `aspirantes.situacion_id`.

   *Por dónde empezar*: `ConvertidorAspirante` la escribe en TRES sitios y
   `GuardarAspiranteRequest` la exige `required`; mientras esos dos no cambien,
   nada más se puede tocar.

1. **Impresión del acta** (PDF con folio, firmas y lista de alumnos).
   Comprobado el 2026-08-19: `/captura` tiene `index`, `guardar`, `cerrar` y
   `corregir`, y **ninguna ruta de impresión**; en `resources/views/impresion/`
   sólo está `historial.blade.php`. El acta existe y se consulta en pantalla.

2. **El portal del TUTOR no opera, y no es que le falte la pantalla.**
   Comprobado el 2026-08-19: `/mis-hijos` sólo tiene `index` y `hijo`, y
   `PadreController` no menciona documentos — **no hay endpoint**, así que
   construir la pantalla no bastaría. La regla «alumnos y padres suben, no
   validan» sí está implementada y probada del lado de admisiones.

3. Fase 4.

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
- **Lo verificado en el navegador, hasta hoy**: el panel, y el 2026-08-19 el
  recorrido entero de rúbricas —`/rubricas` (crear, tarjeta y matriz), el panel
  de calificación del docente en modo rúbrica y el aula del alumno con su
  desglose—. **Todo lo demás sigue probado por datos y por HTTP, sin ver el
  render.** Y aun eso: la verificación es medición del DOM (texto, opacidades,
  anchos, desplazamiento), no una mirada humana, porque las capturas se agotan
  por tiempo.

- `reactivos_cleaver` está vacía a propósito: el banco real del test DISC viene
  del legacy y no debe inventarse. No es deuda: es una decisión.

- ~~«Borrar un componente de evaluación con calificaciones pasa en silencio»~~.
  **Resuelto** (ver más abajo, «Lo capturado ya no se puede enterrar»).

**Tres deudas que estaban aquí y NO eran ciertas** (comprobadas el 2026-08-19;
se dejan escritas para que no vuelvan a apuntarse como pendientes):

- ~~«Falta pantalla para horarios de `asignatura_grupo`; sin ellos la validación
  de choque no bloquea»~~. Están `HorarioController` y `ReglaHorarioController`,
  las pantallas en `resources/js/Pages/Horarios`, la generación automática con
  su propio permiso (`generar-horarios`, separado de `editar-horarios`) y
  `ValidadorInscripcion::choqueDeHorario`, que sí valida.
- ~~«No hay panel para la app central (landlord)»~~. `routes/web.php` tiene el
  guard `auth:central`, login con SSO de Google, alta / suspensión / baja de
  escuelas y la administración de créditos de emisión.
- ~~«El portal no cobra»~~. Ver arriba, en el pedido del cliente.

- **El portal del TUTOR no opera** — ver el punto 2 de los pendientes. Se
  quedaba corto: no es que falte la pantalla, es que no hay endpoint.
