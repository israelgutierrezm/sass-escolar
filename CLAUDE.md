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
   Las suites versionadas viven en `scripts/` (**86 archivos `prueba-*.php`**;
   esta lista decía 23 y llevaba tiempo desactualizada; hoy son 103). Se corren todas de una
   vez con `for f in scripts/prueba-*.php; do php "$f"; done` y casi todas
   imprimen `Resultado: N correctas, M fallidas`. **Ojo al barrer con `grep`**:
   cuatro cierran de otra forma —`prueba-cache-externo`, `prueba-captura-examen`
   y `prueba-mensajes-espanol` con `N en verde`, y `prueba-listados` con
   `TODO EN VERDE — N verificaciones`—, así que un barrido que sólo busque
   «Resultado:» las reporta como rotas sin estarlo.

   **Las 103 están en verde**, barridas el 2026-08-27. Trece son del módulo de
   Reportes (`prueba-reportes-*`), y una —`prueba-reportes-ordenables`— no prueba
   una fuente sino una CLASE de defecto sobre todas: recorre el registro y
   exporta por cada columna ordenable de cada reporte, así que un reporte nuevo
   entra solo a la prueba el día que se registra. Llegaron a estar 33 en rojo —no trece: ese primer
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
- Pruebas: 86 suites en `scripts/`, contra la BD real del tenant demo con
  `DB::rollBack()` al final. **86 en verde** (ver la regla 5 para qué tumbó a
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
  - ~~«En Blade y no en PDF generado: el proyecto no tiene librería de PDF»~~.
    **Revocado el 2026-08-25**: hay mpdf y el historial se genera en PDF. Ver
    «El historial se imprime en PDF de verdad» más abajo. La vista Blade se
    conserva como respaldo en `?vista=html`.
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

- **Módulo 11 · Bolsa de trabajo, CERRADO** (2026-08-22): colocaciones e
  indicador de empleabilidad. `/bolsa/colocaciones` y `/bolsa/empleabilidad`.
  - **Una colocación NO siempre viene de una postulación**, al revés de lo que
    pedía la spec: `postulacion_id` es NULLABLE. Un egresado consigue trabajo por
    su cuenta y la escuela se entera al darle seguimiento, y ése es el dato que
    piden las acreditadoras. Obligándolo, el indicador contestaría «a cuántos
    colocó nuestra bolsa» en vez de «cuántos egresados están colocados» — dos
    preguntas distintas, y la que una escuela presume es la segunda.
  - **La etapa «contratado» y la colocación son EL MISMO HECHO**, así que no se
    mueven por separado: `Postulador::mover` se niega a entrar a una etapa que
    coloca si la colocación no existe. Sin ese candado la pantalla diría
    «contratado» y el reporte contaría cero, y nadie lo notaría hasta que la
    acreditadora pidiera el número.
  - **Dos banderas de catálogo, no claves cableadas**:
    `etapas_postulacion.marca_colocacion` y `.es_final` —independientes:
    «Rechazado» cierra y no coloca—, y `situaciones_alumno.cuenta_como_egresado`,
    que es el DENOMINADOR. Preguntar por `clave = 'contratado'` funciona hoy y
    deja de funcionar en silencio el día que la escuela edite su catálogo.
  - **`relacionado_con_carrera` es NULLABLE y son TRES cifras.** Con `false` por
    omisión, una colocación capturada sin preguntar diría «no es de su área»,
    que es una afirmación que nadie hizo. Misma regla que
    `autorizaciones.concedida`.
  - **Se cuenta por MATRÍCULA y con DISTINCT.** Cada programa reporta lo suyo y
    quien egresó de dos carreras egresó de las dos; y quien cambió de trabajo dos
    veces sigue siendo UN egresado colocado. Sin el distinct el porcentaje puede
    pasar del 100 %.
  - **Los filtros mueven las DOS cifras.** Generación y carrera acotan numerador
    y denominador a la vez; un filtro que sólo acotara las colocaciones —«las de
    este año» sobre todos los egresados— daría un número que no significa nada y
    que aun así se leería como el indicador.
  - **Lo que NO entra se dice, con su razón y su salida**: las que no señalan
    carrera (edítalas y elige) y las de quien todavía no egresa —una práctica
    profesional— (entra sola al egresar). Sin eso, la diferencia entre lo
    registrado y lo contado es un misterio que hace desconfiar del número.
  - **Deshacer usa `forceDelete`, no borrado lógico.** El único de la tabla es
    sobre `postulacion_id` a secas y MySQL no distingue una fila dada de baja de
    una viva: con borrado lógico, deshacer dejaría esa postulación sin poder
    colocarse NUNCA —y «me equivoqué en la fecha, lo deshago y lo capturo otra
    vez» es exactamente lo que alguien va a hacer—. La historia queda en la
    bitácora de la postulación, que además la devuelve a la etapa de la que
    venía, LEÍDA de ahí y no adivinada.
  - **No se guarda la fecha de baja del empleo.** Una colocación es el hecho de
    haber sido contratado, que es lo que mide la acreditación; el seguimiento
    longitudinal es otro producto, y media columna de ese producto sería una
    columna que nadie lee.
  - **Trampa que mordió, y se vio SÓLO en el navegador**: el desglose «de dónde
    salieron» contaba TODAS las colocaciones de la escuela mientras el porcentaje
    contaba las de egresados, así que la pantalla ponía «1 por la bolsa» al lado
    de «0 de 14 colocados». Dos universos pegados; quien lee eso deja de creerle
    al tablero entero. Ahora los tres recuadros hablan del mismo conjunto.
  - Tarjeta de panel **Postulantes en proceso**, que cuenta sólo las etapas no
    finales: una cola que nunca baja enseña a ignorarla.
  - Pruebas: `scripts/prueba-bolsa-colocaciones.php`, 56 verificaciones,
    comprobadas mutando diez reglas. **Otra vez la trampa de `QueryException`**
    —desciende de `RuntimeException`, así que el `catch` pelado daba por buena la
    explosión del índice único—: es la segunda en este módulo. Y una comprobación
    estaba escrita como `($x['carrera'] ?? 'x') === null`, que es falsa pase lo
    que pase porque el coalescente reemplaza justamente el null que se quería
    ver; ahora va con `array_key_exists`.
  - **Y una tercera lección: la suite se mide POR DIFERENCIA, no contra cero.**
    Afirmaba «hay dos colocados» dando por hecho que el demo no tenía ninguna;
    pasaba aislada y se cayó con diez fallas en el barrido en cuanto la escuela
    de ejemplo tuvo colocaciones sembradas. Ahora guarda la línea base, compara
    contra ella y elige a sus protagonistas entre los egresados SIN colocación
    previa. Una prueba que sólo pasa cuando la corres sola no prueba nada el día
    que alguien la mete en el barrido.

- **Módulo 12 · Movilidad, CERRADO** (2026-08-23): convenios, convocatorias,
  postulaciones, estancias y **revalidaciones**. `/movilidad/convenios`,
  permiso `gestionar-movilidad`, bajo `modulo:movilidad`.
  - **EL HALLAZGO: el asiento NO necesita una columna «origen».** La spec pedía
    «una bandera de origen movilidad» en `historial`. No hace falta, y además
    habría sido peor: **`tipos_evaluacion` ya traía `revalidacion`** desde la
    fase 2 y **`observaciones_asignatura` —catálogo de la SEP— ya traía
    «REVALIDACIÓN DE ESTUDIOS»**, que es el valor que viaja en el XML del
    certificado. Una columna propia habría dejado el dato FUERA del documento
    oficial y habría creado una segunda forma de decir lo mismo.
  - **Sin acta, a propósito**: `acta_id` y `acta_folio` quedan en NULL porque una
    revalidación sale de un dictamen, no de un cierre de materia. Inventarle un
    folio la haría indistinguible de una materia cursada aquí.
  - **Sólo al SALIENTE y con la estancia CONCLUIDA.** A un entrante no se le
    escribe historial nuestro —no tiene—; y mientras la estancia siga en curso
    las calificaciones de allá no están cerradas, así que asentar metería un
    número que todavía puede cambiar.
  - **No se revalida lo que ya está APROBADO.** `HistorialDelAlumno` toma el
    mejor intento por materia para los totales, así que un segundo asiento
    regalaría los créditos. Sobre una materia REPROBADA sí se puede: ahí es un
    intento legítimo, igual que un recursamiento. Y las ya aprobadas ni siquiera
    se ofrecen en el desplegable.
  - **La calificación equivalente se CAPTURA.** No hay tabla de conversión
    universal entre sistemas de calificación —«B+», «16/20»— y fabricar una sería
    inventarle una nota a alguien. Se guarda además lo que dijo el destino, tal
    cual.
  - **Revocar da de BAJA LÓGICA el renglón**, no lo borra: es historia escolar y
    se conserva con su auditoría, igual que los renglones de un acta corregida.
    Y deja la revalidación lista para rehacerse con la nota correcta.
  - **El promedio se CALCULA de `HistorialDelAlumno` y se CONGELA** en la
    postulación. Tecleado sería un número que alguien puede acomodar, y
    recalcularlo sería una tercera verdad sobre el promedio de un alumno. El
    buscador de candidatos lo ENSEÑA al elegir, para que quien captura vea por
    qué no alcanza.
  - **El cupo se cuenta por la bandera `acepta`, no por la clave**: quien ya
    está en curso o concluyó sigue ocupando su lugar. Contando sólo la etapa
    llamada «aceptado», el cupo se liberaría en cuanto alguien avanzara y la
    escuela mandaría a dos personas a la misma plaza.
  - **`direccion` es columna y no catálogo**, al revés de la spec: saliente y
    entrante son dos caminos del código, no dos filas. Una fila nueva no
    enseñaría un tercer camino.
  - **Vencido ≠ suspendido** en los convenios, y **sin carreras señaladas cubre
    TODAS**: las dos lecciones que ya había dejado la bolsa de trabajo.
  - **Titular DUAL con CHECK**, como `adeudos`. Y aquí mordió **MySQL 3823**: una
    columna que participa en un CHECK no puede tener foránea con acción
    referencial. `nullOnDelete` era además lo incorrecto —dejaría la postulación
    sin NINGÚN titular, justo lo que el CHECK impide—, así que van con
    `constrained()` a secas, igual que `adeudos`.
  - **Y una lección propia sobre la idempotencia**: el CHECK estaba DENTRO del
    `if (! hasTable)`. Al fallar y reintentar, la tabla ya existía, se saltó el
    bloque entero y el CHECK quedó sin crear PARA SIEMPRE con la migración
    marcada como aplicada. **Comprobar antes de actuar es por PIEZA, no por
    bloque.** Lo repara `2026_08_23_121000_movilidad_repara_el_check_del_titular`.
  - Pruebas: `scripts/prueba-movilidad.php` (45 verificaciones, tres mutaciones)
    y `scripts/prueba-movilidad-revalidacion.php` (42 verificaciones, seis
    mutaciones). **Una mutación sobrevivió**: el único de la base
    `(estancia, plan_materia)` tapaba la regla de «ya aprobada», así que quitarla
    no tumbaba nada; se construyó el caso real —una materia que el alumno YA
    aprobó aquí y cursó también allá—.
  - Comprobado en el navegador el recorrido entero, hasta ver el renglón en el
    expediente de la alumna: «Contabilidad I · Aprobada · Ciclo 2026-2 ·
    Revalidación · REVALIDACIÓN DE ESTUDIOS · 8.60».

- **`CampoTexto` rechazaba TODO decimal** (2026-08-23). Con `tipo="number"` el
  navegador usa `step=1` por omisión y bloquea el envío del formulario —«los dos
  valores válidos más aproximados son 8 y 9»— sin que la petición salga. Con
  **67 campos `tipo="number"`** en el sistema, eso dejaba fuera todo sueldo con
  centavos, toda calificación con décimas y todo porcentaje. No se había notado
  porque las pruebas invocan a los controladores y los ejemplos capturados a mano
  eran enteros; salió al escribir «8.6» en una revalidación. Ahora el `step` es
  `any` por omisión y un campo que de verdad sólo admita enteros pasa `paso="1"`.

- **Módulo 10 · Nómina y RH, CERRADO** (2026-08-23): el CFDI de nómina, con su
  interruptor. `nomina.timbrado_cfdi` en `/plataforma/configuracion`.
  - **Apagado por omisión, y apagado no se puede.** Es la decisión del cliente:
    «se implementa, pero el timbrado se enciende desde configuración; si está
    apagado no poder y si está encendido que sea posible y valide la información
    requerida». Apagado, el bloque del CFDI ni se dibuja y la dirección responde
    **404** —misma decisión que la postulación autogestiva de la bolsa—.
  - **El VALIDADOR es lo que de verdad entrega esta rebanada.**
    `ValidadorNomina` corre ANTES de mandar nada y dice **qué falta y DÓNDE se
    captura**, renglón por renglón. Un PAC devolviendo `CFDI40147` sobre cuarenta
    recibos el día de pago no le sirve a nadie. Es el mismo papel que
    `ValidadorDec` con los certificados de la SEP, que nombra la asignatura
    concreta a la que le falta el identificador.
  - **Aquí aterrizan los campos que las rebanadas 1 y 2 dejaron fuera a
    propósito**: `conceptos_nomina.clave_sat` y el régimen fiscal del empleado.
    Se prometió que llegarían con su lector y llegaron con él.
  - **El receptor REUSA `datos_facturacion`.** El RFC, el régimen y el código
    postal de una persona ya viven ahí —es la tabla que la facturación usa para
    el receptor— y son los mismos datos. Una tabla «datos fiscales del empleado»
    sería una segunda verdad sobre el mismo RFC.
  - **Las claves del SAT se capturan, y las que dependen de la escuela se
    siembran en NULL a propósito**: `prestamo` y `por_asignatura` caen en «Otros»
    del catálogo del SAT y qué sean exactamente lo decide cada contador.
    Inventarles una clave daría un comprobante que el SAT acepta diciendo algo
    que nadie decidió. El validador las reclama por su NOMBRE.
  - **El estado del timbrado es del RECIBO**, como se anotó en la rebanada
    anterior: `uuid`, `xml_ruta`, `pac`, `timbrado_en` y `error_timbrado` van en
    cada uno. Un rechazo del PAC **no es una excepción**: se guarda y se enseña
    tal cual, con su código.
  - **`Pac` gana `timbrarNomina()`.** El complemento 1.2 es otro documento, así
    que va aparte y no reusando `timbrar()`. `PacFalso` lo implementa —flujo
    completo sin mandar nada al SAT— y **`FacturapiPac` lo RECHAZA con un
    mensaje que dice qué hacer**: escribir su traducción sin credenciales con las
    que probarla produciría código que parece funcionar y que nadie ha visto
    responder, que es justo por lo que este proyecto tardó en tener driver real
    de facturas.
  - **Dos defectos que salieron al MIRAR la pantalla, no de las pruebas:**
    - **Recalcular un periodo borraba los recibos ya timbrados**, destruyendo el
      registro de un CFDI que existe ante el SAT y cuyo folio no se recupera.
      Ahora los timbrados se saltan uno por uno —y no se bloquea el periodo
      entero: con cuarenta recibos y cinco timbrados, los treinta y cinco
      restantes tienen que poder corregirse—. Tampoco se les agregan ni quitan
      renglones: cambiar importes después de timbrar deja el recibo diciendo una
      cosa y el CFDI otra.
    - **El validador inventaba faltantes.** El controlador cargaba el recibo con
      listas de columnas acotadas y dejaba fuera `tipo_contrato_id` y
      `clave_sat`, así que reclamaba que «el tipo de contrato "—" no tiene clave
      del SAT» sobre un catálogo bien capturado. **Es la trampa que esta bitácora
      ya tenía anotada** —las columnas que no se piden llegan en NULL— y no se
      veía desde la suite porque ésta le preguntaba al servicio con un modelo
      recién cargado. Ahora la prueba pasa POR EL CONTROLADOR.
  - **Y `personas.nss` no estaba en el `$fillable`**: el modelo lo descartaba en
    silencio. No se notó antes porque el controlador de RH escribe con el query
    builder, que no mira el fillable.
  - Pruebas: `scripts/prueba-rh-timbrado.php`, 47 verificaciones, comprobadas
    mutando siete reglas. **Y una comprobación floja corregida**: preguntaba por
    el valor GUARDADO del interruptor en vez de por el DECLARADO, así que pasaba
    en un demo recién migrado y se caía en cuanto alguien lo encendía desde la
    pantalla.
  - **Lo que NO se hace, y hay que saberlo**: no se construye el XML del
    complemento. En esta arquitectura lo arma el driver del PAC —las APIs
    comerciales reciben JSON— y no hay PAC contratado ni XSD de nómina en el
    repo con el que validarlo. Con `CFDI_PAC=falso` el recorrido entero funciona
    y el folio es de mentiras.

- **Módulo 10 · Nómina y RH, tercera rebanada** (2026-08-23): el periodo, el
  recibo y el cálculo. `/rh/nomina`, mismo permiso `gestionar-percepciones`.
  - **El recibo se MATERIALIZA.** Sus renglones guardan el importe calculado, no
    una referencia al sueldo vigente: un documento que se recalcula al mirarlo
    cambia de contenido cuando alguien actualiza un dato de hoy, y un recibo es
    un hecho fechado que hay que poder explicar en cinco años. Misma decisión que
    `esquema_evaluacion`, que la factura con su emisor y que el acta impresa. Y
    además APUNTA al esquema con el que se calculó, para no tener que
    reconstruir qué sueldo regía.
  - **El sueldo se resuelve al FIN DEL PERIODO, no a hoy.** Recalcular una
    quincena vieja tiene que seguir dando lo de entonces; preguntar por «el
    abierto» le aplicaría el aumento de la semana pasada.
  - **Una entrada de reloj SIN SALIDA no se paga, y se REPORTA.** Hay tres
    formas de tratarla y sólo una sirve: contarla hasta el fin del día paga
    horas no trabajadas y el error es a favor del empleado, así que nadie lo
    reclama nunca; ignorarla en silencio le paga de menos sin que sepa por qué;
    no pagarla y decirlo es la única que se puede corregir antes de pagar. Dos
    entradas seguidas descartan la primera en vez de emparejarla con la salida
    de la segunda, que pagaría el hueco de en medio.
  - **La tabla del reloj es `checadas`, no `marcas_reloj`** —ver la rebanada
    anterior—, y sigue VACÍA en el demo: el cálculo por horas se probó sembrando
    checadas dentro de la transacción.
  - **Lo que no se puede calcular se ANOTA, no se supone.** Sin sueldo fijado
    sale el recibo en ceros CON el motivo escrito: uno que no aparece se confunde
    con alguien a quien no le tocaba cobrar, y un cero mudo se paga.
  - **Recalcular avisa de cuántos renglones capturados A MANO se lleva.**
    Rehace todo desde cero, así que un descuento por préstamo desaparece;
    perderlo en silencio es pagarle de más a alguien. Y sólo se quitan a mano los
    que se pusieron a mano: quitar uno calculado dejaría el recibo diciendo algo
    que el esquema no dice, y volvería a aparecer al recalcular.
  - **`formulas_nomina` es porcentaje sobre una base, con tope. Nada más.**
    **El ISR NO se calcula con esto, y es deliberado**: sale de la tarifa por
    rangos del artículo 96 más el subsidio al empleo, no de un factor plano.
    Sembrar una fórmula de ISR con un porcentaje inventado daría un número que
    parece bueno, que alguien enteraría al SAT y que nadie descubriría hasta la
    primera revisión. El concepto `isr` se queda sin fórmula y se captura a mano.
  - **Aquí `es_gravable` por fin tiene lector**: la base `percepciones_gravables`
    lo consulta. Se declaró en la rebanada anterior como propiedad que la escuela
    decide; ahora además se usa.
  - **Un empleado, un recibo por periodo** (único en la base). **Lo que NO se
    prohíbe es que dos periodos se traslapen**: una quincena y un aguinaldo
    extraordinario se enciman de forma legítima. A cambio cuentan las MISMAS
    checadas, así que crear uno encimado lo advierte — descubrirlo en el importe
    es peor.
  - **El timbrado NO es un estado del periodo**, al revés de la spec: va a ser
    una propiedad de cada RECIBO. El SAT puede rechazar uno y aceptar los otros
    cuarenta, igual que la SEP con los títulos de un lote, y un estado de periodo
    obligaría a elegir entre mentir o bloquear a todos.
  - **Una mutación sobrevivió otra vez**, y por lo mismo de siempre: en el
    escenario TODAS las percepciones eran gravables, así que cambiar la base de
    la fórmula de «lo gravable» a «todas» no movía ningún número. Se construyó el
    caso que las separa —una modalidad de base + horas con el concepto de horas
    marcado como no gravable— y ahora la mutación muere. Es el caso que en una
    escuela real aparece el primer mes que alguien recibe vales de despensa.
  - Comprobado en el navegador con aritmética a mano: 28 000 mensuales
    prorrateados a 15 días dan 14 000, el 2.75 % da 385, las 12.5 horas checadas
    a 220 dan 2 750 —con la tercera entrada sin cerrar fuera y reportada—, y el
    recibo sin sueldo sale en ceros con su motivo.
  - Pruebas: `scripts/prueba-rh-nomina.php`, 39 verificaciones, comprobadas
    mutando cinco reglas.

- **Módulo 10 · Nómina y RH, segunda rebanada** (2026-08-22): esquemas de
  percepción y conceptos. `/rh/empleados/{id}/percepciones` y
  `/rh/catalogos-nomina`, permiso **propio** `gestionar-percepciones`.
  - **El sueldo va detrás de OTRO permiso.** Quien captura altas, bajas y
    adscripciones no necesariamente puede ver cuánto gana cada quien: es el dato
    más sensible del sistema. Por eso vive en su propia RUTA y no dentro de la
    ficha —esconder la sección con un `v-if` no es una defensa—; en la ficha sólo
    aparece la puerta, y sólo a quien la puede abrir.
  - **La modalidad se lee por sus BANDERAS, no por su clave.** La spec la
    describía como catálogo con cuatro valores, pero un catálogo cuyos valores el
    código reconoce por nombre no es configurable: la escuela agrega una fila y no
    pasa nada. Aquí cada modalidad declara qué componentes usa
    —`usa_monto_base`, `usa_tarifa_hora`, `usa_tarifa_asignatura`— y el motor
    suma lo que enciendan. **Así «mixto» deja de ser un cuarto caso especial: es
    una fila con dos banderas**, y «base más horas» se crea desde la pantalla y
    FUNCIONA. Comprobado en el navegador: la modalidad inventada apareció en el
    desplegable y el formulario pidió sus dos cifras, sin tocar código.
  - **Y las banderas EXIGEN su dato, mayor que cero.** Un esquema por horas con
    la tarifa en blanco —o en cero— pagaría cero y el recibo saldría, con el neto
    en nada y sin un solo error: es el defecto que no se descubre hasta el día de
    pago. Lo mismo una modalidad sin ningún componente, que se rechaza al
    guardarla en el catálogo y al colgarle un esquema.
  - **Lo que la modalidad no usa se guarda en NULL**, no con lo que venga en la
    petición: si no, cambiar de modalidad aplicaría una tarifa que nadie volvió a
    autorizar. Y NULL y no cero, porque «se le paga cero por hora» es una
    afirmación distinta de «no se le paga por hora».
  - **Un solo esquema abierto por expediente**, y abrir uno cierra el anterior el
    día ANTES —dos esquemas no pueden cubrir la misma fecha—. El anterior se
    conserva: un aumento no borra lo que ganaba antes, que es lo que permite
    explicar un recibo viejo. Y **el sueldo se consulta POR FECHA**
    (`ExpedienteLaboral::esquemaEn`): un recibo de la quincena pasada usa el que
    regía entonces, no el de hoy.
  - Se corrigen las CIFRAS, no las fechas: mover la vigencia reacomodaría el
    tramo que otro esquema ya cubre, y para eso está abrir uno nuevo.
  - **Sin columna de MONEDA**, al revés de la spec: nada la convertiría. Pagar en
    otra moneda necesita además una tasa, su fecha y una política de redondeo;
    media de esas cosas no sirve y a cambio invita a capturar «USD» creyendo que
    el sistema lo entiende.
  - **`conceptos_nomina` sin `clave_sat` todavía**: es un mapeo al CFDI y llega
    con él, igual que el régimen fiscal. `es_gravable` sí está —no es un mapeo,
    es una propiedad que la escuela decide—. Y `naturaleza` es columna y no
    catálogo: un renglón sólo puede sumar o restar, no hay una tercera cosa que
    hacerle a una cuenta.
  - **`formulas_nomina` se aplaza a la rebanada del cálculo**, que es lo único
    que evaluaría una fórmula. Mismo criterio.
  - **Una mutación que SOBREVIVIÓ destapó una inconsistencia real**: la baja
    cerraba las adscripciones pero dejaba el esquema de sueldo ABIERTO, así que
    la consulta por fecha nunca ejercitaba su `vigente_hasta` —no había ningún
    tramo cerrado sin sucesor—. Ahora la baja lo cierra también: hoy la nómina no
    le pagaría igual porque `enNomina()` saca a los dados de baja, pero eso es una
    segunda defensa y el dato tiene que ser cierto por sí solo.
  - Pruebas: `scripts/prueba-rh-percepciones.php`, 46 verificaciones,
    comprobadas mutando siete reglas.

- **`prueba-grabaciones` miraba TODO el directorio temporal del sistema**
  (2026-08-22). Comparaba `glob(sys_get_temp_dir().'/*.tmp')` antes y después
  para ver si el trabajo dejaba basura, así que cualquier temporal que otra
  suite escribiera entremedio —`lote*.zip`, `xls*.xlsx`, `encuesta*`— se contaba
  como suyo: **pasaba corriéndola sola y fallaba en el barrido**. Ahora el glob
  va acotado al prefijo de ESE trabajo, con los dos patrones que hacen falta
  porque `tempnam()` no se comporta igual en los dos sistemas —Windows recorta el
  prefijo a tres letras (`gra*.tmp`) y Linux lo conserva entero y sin extensión
  (`grabacion-*`)—. Comprobado que sigue cazando la mutación del borrado.
  **Es la segunda vez en el día que muerde lo mismo**: una prueba que sólo pasa
  aislada no prueba nada el día que alguien la mete en el barrido. La otra fue
  `prueba-bolsa-colocaciones`, que medía contra cero en vez de por diferencia.

- **Módulo 10 · Nómina y RH, primera rebanada** (2026-08-22): el expediente
  laboral. `/rh/empleados`, permiso `gestionar-rh`, bajo `modulo:nomina`.
  - **El reloj checador NO se llama `marcas_reloj`.** La spec lo nombra así y la
    tabla real es **`checadas`** (`App\Models\Asistencia\Checada`). Es la trampa
    que la bitácora ya tenía anotada —el nombre de una tabla se pregunta, no se
    adivina— y aquí volvía a estar servida porque el módulo 10 la cita por el
    nombre de la spec. **Y está VACÍA en el demo**: el insumo existe
    estructuralmente pero nunca se ha ejercitado con datos, así que el cálculo
    por horas de la rebanada 3 no se podrá comprobar contra nada real sin
    sembrar checadas antes.
  - **El expediente laboral NO reemplaza a `docentes`, lo complementa.**
    `docentes` es identidad ACADÉMICA —clave, cédula, tipo— y de ahí sale a qué
    materias se le puede asignar; el expediente es el vínculo laboral, y lo tiene
    también quien nunca da clase. Fundirlos obligaría a inventarle una cédula
    profesional al personal de intendencia.
  - **`puestos` NO es `cargos`.** `cargos` es el catálogo OFICIAL de la SEP
    —doce entradas, con el número que va en el XML del certificado— y esta misma
    bitácora prohíbe tocarlo. `puestos` es el organigrama de la escuela.
    Fundirlos rompería el timbrado de todas las escuelas por ganar una tabla.
  - **Ni RFC ni CURP se repiten en el expediente**, al revés de lo que pedía la
    spec: `personas` ya los tiene y copiarlos crearía dos verdades. **El NSS se
    agregó a `personas`** por la misma razón —es un identificador que el IMSS le
    da a la PERSONA de por vida—, así que quien es recontratado no vuelve a
    capturarlo. La CLABE y el banco sí van en el expediente: son «a dónde se
    deposita ESTE sueldo».
  - **«Baja» tiene UNA sola fuente de verdad: `fecha_baja`.** Por eso
    `situaciones_empleado` **no siembra ninguna situación de baja** —con las dos
    cosas, un expediente podría decir «activo» con fecha de baja puesta y nadie
    sabría cuál manda—. El catálogo distingue matices de quien SIGUE contratado:
    activo, licencia con goce, licencia sin goce, comisión.
  - **A quién se le paga lo dice la bandera `entra_a_nomina`, no la clave.**
    Licencia SIN goce sigue contratado y no cobra; comisión sí cobra. Preguntar
    por `clave = 'activo'` se equivoca en los dos casos, y ninguno se notaría
    hasta el día de pago. Lo fija una mutación de la suite.
  - **Dar de baja CIERRA las adscripciones abiertas**, con la misma fecha. Sin
    eso, quien renunció seguiría figurando como coordinador del campus norte, que
    es justo la pregunta que esa tabla existe para contestar. **Deshacer la baja
    NO las reabre**: no hay forma de saber si el puesto sigue libre, y
    devolverlo podría pisar a quien ya lo ocupa.
  - **`adscripciones` no duplica `persona_rol.campus_id`**: aquél acota lo que un
    usuario PUEDE VER, ésta dice qué puesto ocupa en el organigrama y desde
    cuándo. Alguien puede tener permisos globales y una sola adscripción.
  - **El régimen fiscal NO está todavía**, a propósito: sólo lo lee el CFDI de
    nómina, que es una rebanada posterior. Una columna sin lector es lo mismo que
    un ajuste que nadie consulta, y este proyecto ya tuvo que retirar varios.
  - **El número de empleado se captura, no se genera.** Una escuela que llega de
    otro sistema ya trae sus números impresos en gafetes y recibos viejos, y
    generárselos pelearía con los que tiene. Es único y lo detiene la VALIDACIÓN,
    no el índice: quien captura lee el mensaje en su formulario.
  - Pruebas: `scripts/prueba-rh-expediente-laboral.php`, 45 verificaciones,
    comprobadas mutando ocho reglas.

- **El demo NO tiene datos de bolsa de trabajo, y es a propósito** (2026-08-22).
  Se sembraron dos empresas, dos vacantes, seis postulaciones y cuatro
  colocaciones para mirar las pantallas en el navegador, y se retiraron al
  terminar por decisión del cliente. Las ocho tablas del módulo están en cero;
  respaldo de lo borrado en
  `C:\Dev\bolsa-demo-antes-de-limpiar-2026-08-22.sql`.
  - El **módulo `bolsa_trabajo` se dejó ENCENDIDO**: apagarlo devuelve 404 en
    todas sus rutas y esconde el menú, o sea que la sección no se podría ni
    mirar. El interruptor está en `/plataforma/modulos`.
  - `bolsa.postulacion_autogestiva` quedó como fila en `configuraciones` con
    valor `0`, que es su valor por omisión: la pantalla de reglas escribe todas
    al guardar, así que es indistinguible del uso normal.
  - Comprobado que las cuatro pantallas se ven bien vacías —cada una con su
    mensaje— y que la tarjeta «Postulantes en proceso» desaparece del panel, que
    es la regla de vacíos del proyecto. Y que `acadion:auditar-datos` sigue
    reportando **las mismas 69** filas rotas de siempre: el borrado no dejó
    ninguna referencia colgando.

- **`hoyLocal()` en `resources/js/utils/fechas.ts`** (2026-08-22): siete
  pantallas ponían la fecha de hoy con `new Date().toISOString().slice(0, 10)`,
  que devuelve UTC. En México —UTC-6— a partir de las 18:00 locales eso da
  MAÑANA toda la tarde y toda la noche. En `PaseDeLista` decidía de qué día era
  la lista que el docente estaba pasando: una lista de la tarde quedaba anotada
  al día siguiente. Se descubrió registrando una colocación por la noche y ver
  la fecha de ingreso con un día de más.

- **Módulo 11 · Bolsa de trabajo, tercera rebanada** (2026-08-22): las
  postulaciones. `/mis-vacantes` para el alumno y
  `/bolsa/vacantes/{id}/postulaciones` para vinculación.
  - **VER y POSTULARSE son dos preguntas distintas.** El permiso `ver-vacantes`
    —faceta ALUMNO— decide si esta persona ve el tablero; el ajuste
    `bolsa.postulacion_autogestiva` decide si puede postularse sola. Apagado, las
    vacantes se siguen viendo —le sirven: se entera y va a ventanilla— y lo que
    desaparece es el botón, con la dirección respondiendo **404**. Es lo que el
    cliente pidió con «poder apagar la postulación autogestiva para forzar en
    ventanilla».
  - **Apagado por omisión**, por decisión del cliente. Una escuela que acaba de
    encender el módulo no tiene todavía a nadie revisando lo que llegue, y con
    esto encendido la primera vacante que publique le abre la puerta a toda la
    matrícula el mismo día.
  - **El interruptor se construyó junto con quien lo lee**, en la misma rebanada:
    un ajuste que nadie consulta es peor que no tenerlo, porque se confía en él.
  - **Capturar por ventanilla NO depende del interruptor**: con él apagado es el
    único camino, y con él encendido sigue llegando gente por teléfono. Los dos
    caminos pasan por `App\Services\Bolsa\Postulador`, porque escribirlos dos
    veces es como se llega a que uno deje de anotar la bitácora y los tiempos de
    colocación cuenten la mitad de los casos.
  - **`capturada_por` en null significa que se postuló SOLA.** Una columna
    `origen` con dos valores diría menos por el mismo espacio: así se sabe además
    QUIÉN la capturó, y la pantalla muestra «Portal» o «Ventanilla», que es lo
    que mide si el portal sirve de algo.
  - **La bitácora existe para MEDIR, no para auditar.** Una fila por cambio de
    etapa, incluida la del ALTA con la etapa de origen en null: sin ese primer
    renglón, «cuánto tarda un egresado en colocarse» no tiene desde cuándo
    contar. Y volver a poner la MISMA etapa no anota nada —dos clics inflarían
    la bitácora con renglones de cero días y falsearían justo lo que mide—.
  - **Con dos carreras no se adivina cuál.** La matrícula de la postulación se
    resuelve sola cuando la persona tiene una, y con varias se PREGUNTA. Hizo
    falta de verdad: en el demo los quince alumnos con matrícula tienen dos o
    tres, así que dejarlo sin preguntar significaba que casi ninguna postulación
    de ventanilla sabría de qué carrera es. Se vio al capturar una en el
    navegador —el renglón salió sin matrícula—, no en las pruebas.
  - Por eso hay un endpoint aparte, `/bolsa/postulantes/{persona}/matriculas`:
    `/buscar/alumnos` entrega PERSONAS y deduplica a propósito —quien estudia dos
    carreras no puede salir dos veces en la caja de elegir a alguien—, así que de
    ahí no sale con cuál se postula. Ese buscador ahora lo abre también
    `gestionar-bolsa-trabajo`, sumado al permiso derivado `dirigir-a-alumnos`.
  - **El CV cuelga de la POSTULACIÓN, no del expediente**: no es un papel que la
    escuela exija, es lo que esa persona mandó a esa vacante, y cambia de una a
    otra. Va al disco privado y su ruta comprueba de quién es.
  - **Trampa que mordió**: `configuraciones.descripcion` era `varchar(255)` y esa
    columna NO la escribe nadie a mano —`Ajustes::guardar()` copia ahí la
    descripción declarada en `CatalogoAjustes`—, o sea que el largo del texto de
    un archivo PHP decidía si la pantalla de configuración podía guardar. La del
    ajuste de la bolsa ocupa 256 caracteres, uno de más, y mover el interruptor
    reventaba con `Data too long` en la cara del usuario. Pasada a TEXT.
  - Pruebas: `scripts/prueba-bolsa-postulaciones.php`, 44 verificaciones,
    comprobadas mutando siete reglas. **Dos de ellas destaparon comprobaciones
    vacías**: (1) `QueryException` desciende de `RuntimeException`, así que el
    `catch` pelado daba por buena la explosión del índice único y la prueba
    pasaba con la regla de «no postularse dos veces» quitada —lo que llegaba a la
    pantalla era un error de SQL—; ahora se mira el tipo Y el mensaje. (2) La de
    «vacante cerrada» se probaba con alguien que ya se había postulado, así que
    el rechazo lo producía la regla de no repetir y la de vigencia nunca se
    evaluaba; hicieron falta DOS personas limpias, una por cada camino.
  - Y el escenario de «una sola matrícula» **se construye**, no se busca: en el
    demo no existe, y una prueba que salta la comprobación cuando no encuentra el
    caso es una prueba que se apaga sola el día que cambian los datos.

- **Módulo 11 · Bolsa de trabajo, segunda rebanada** (2026-08-22): las vacantes.
  `/bolsa/vacantes`, con alta y edición en la MISMA pantalla —dos casi iguales es
  como se llega a que el alta pida un campo que la edición no ofrece—.
  - **Sin carreras señaladas, la vacante es PARA TODAS.** No es un descuido de
    captura: la mitad de las vacantes reales buscan «recién egresados de lo que
    sea», y exigir al menos una obligaría a palomear las veinte carreras cada
    vez. `scopeParaCarrera` incluye las que no señalan ninguna, y la pantalla lo
    dice con palabras —un hueco se lee como captura incompleta—.
  - **`scopeVigentes` cruza TRES condiciones**: abierta, con la fecha de cierre
    por delante, y de una empresa no vetada. La tercera es la que hace que vetar
    a un empleador apague también las vacantes que ya publicó; sin ella, el veto
    sólo lo escondía del padrón mientras sus vacantes seguían recibiendo gente.
  - **Vencida ≠ cerrada.** Una vacante con la situación «abierta» y la fecha
    pasada seguiría pintándose en verde; el color de la lista habla del estado
    REAL y la etiqueta dice «Venció».
  - **Dos columnas de sueldo y no una**: casi ninguna vacante mexicana lo
    publica y las que sí, publican un rango. Con una sola habría que elegir entre
    mentir con el mínimo o inventar un promedio. Un rango invertido se rechaza:
    no es un dato raro, es un error de captura que deja la vacante sin poderse
    filtrar por sueldo.
  - **`habilidades` se siembra con lo transversal, no con lo técnico**: lo
    técnico depende de lo que enseña cada escuela y sembrarlo sería adivinar. Y
    cada habilidad de una vacante puede marcarse `indispensable`, porque una
    vacante con ocho requisitos parece exigirlos todos y nadie se postula.
  - Pruebas: `scripts/prueba-bolsa-vacantes.php`, 16 verificaciones, comprobadas
    mutando cuatro reglas.

- **Módulo 11 · Bolsa de trabajo, primera rebanada** (2026-08-22): los
  empleadores. `/bolsa/empresas`, permiso `gestionar-bolsa-trabajo`, bajo
  `modulo:bolsa_trabajo` —comprobado que apagarlo devuelve 404 y encenderlo 200—.
  - **UN solo lugar para «con quién se habla en esta empresa».** La spec ponía un
    `persona_contacto_id` en `empresas` Y ADEMÁS una tabla de «contactos
    adicionales»: dos sitios donde buscar al reclutador y la duda de si el
    principal aparecía también en la tabla. Aquí hay una sola tabla, con
    `es_principal` y `persona_id` OPCIONAL para el que además tenga cuenta.
    Obligarlos a ser `persona` llenaría el padrón de la escuela con gente que ni
    estudia ni trabaja ahí.
  - **La empresa se APAGA con «vetada», no se borra.** Sus colocaciones
    históricas son el insumo de los reportes de acreditación, y borrarla se las
    llevaría. Por eso la pantalla no tiene botón de eliminar.
  - **`scopePublicables` se define por lo que NO es** —vetada— y no exigiendo
    «activa»: una escuela que renombre su catálogo o agregue «en convenio»
    seguiría publicando, y una con la situación en null no desaparecería en
    silencio.
  - **El RFC es opcional pero único.** Una escuela captura empleadores que le
    llaman antes de tener un papel suyo; pero la misma empresa capturada dos
    veces reparte sus colocaciones entre los duplicados y ningún reporte cuadra.
  - **Un solo contacto principal**, degradando al anterior en la misma
    transacción: con dos, la pantalla enseña el que salga primero.
  - Pruebas: `scripts/prueba-bolsa-empresas.php`, 13 verificaciones, comprobadas
    mutando cuatro reglas. **Una mutación destapó una prueba floja**: quitarle la
    regla de unicidad del RFC seguía impidiendo el duplicado —lo bloquea el
    índice— sólo que con un 500. Ahora se exige que falle con
    `ValidationException` y no de cualquier forma: lo que se prueba es que quien
    captura lea el mensaje en su formulario, no que la base se defienda sola.

- **«Y a sus familias»: el modificador que cierra el módulo 13** (2026-08-22).
  El destino `alumno` casa contra la persona de QUIEN INICIÓ SESIÓN, así que un
  citatorio dirigido a Juan le llegaba a Juan y no a su madre: no había forma de
  mandarle nada a una familia concreta.
  - **Es un MODIFICADOR, no un destino.** No señala a nadie por sí solo —va sin
    id, como «toda la escuela»—: extiende a los tutores lo que los demás
    destinos ya dijeron. «Grupo A» + «y a sus familias» llega a los treinta
    alumnos y a sus padres.
  - **Por eso su condición se CRUZA con las demás**, y es lo único del servicio
    que se cruza: hace falta el modificador Y que algún hijo encaje. Con un OR,
    cualquier aviso con el modificador llegaría a todos los padres de la escuela.
  - **Se descartó el destino «familiares de este alumno»** porque no compone:
    una circular a los padres del grupo A obligaría a señalar treinta alumnos a
    mano, y la de la carrera entera sería impracticable. Como modificador se
    multiplica con todas las segmentaciones que ya existen y con las de mañana.
  - **NO se miran los roles de los hijos.** «Rol: alumno» + familias sonaría a
    «todas las familias», pero eso ya se dice dirigiéndolo al rol de padre; si
    se mezclara, un aviso para docentes con el modificador puesto llegaría a los
    padres de cualquier alumno.
  - **Un aviso cuyo único destino sea el modificador se rechaza**: no habría
    alumnos alcanzados cuyas familias extender, así que se guardaría sin público.
    La regla vive en `App\Rules\AlMenosUnDestinoReal` porque la comparten los
    DOS que guardan destinos —avisos y calendario—, y una validación repetida se
    corrige en uno y se olvida en el otro.
  - Pruebas: `scripts/prueba-aviso-a-familias.php`, 10 verificaciones,
    comprobadas mutando tres reglas —quitar la rama de familiar, sumar en vez de
    cruzar, y dejar que el modificador cuente como destino—.

- **Módulo 13 · Familia, primera mitad** (2026-08-22): el parentesco pasa a
  catálogo y aparecen las AUTORIZACIONES.
  - **El parentesco era un enumerable cableado dos veces**: una lista a mano en
    el controlador y otro mapa de etiquetas en el Vue, y ninguna escuela podía
    agregar «abuela» sin tocar código. Ahora es `parentescos` (TENANT-CONFIG); el
    texto viejo se tradujo por clave en la propia migración.
  - **Dos hechos nuevos del vínculo**: `es_contacto_emergencia` y
    `es_responsable_pago`. No son permisos de visibilidad, son datos de la
    relación que se resolvían preguntando por teléfono.
  - **Se retiró `acceso_materia`**: declarada en el modelo y en el pivote, y NO
    la leía nadie. Además la spec exige que el LMS no se exponga a familiares, y
    esa exclusión tiene que ser ESTRUCTURAL, no una casilla palomeable.
  - **NO se agregaron las banderas finas de la spec** (`ve_pagos`, `ve_facturas`,
    `ve_asistencia`, `ve_avisos`): ninguna tendría hoy quien la lea, y este
    proyecto ya tuvo que retirar ajustes y permisos que nadie consultaba.
  - **Autorizaciones** (`/plataforma/autorizaciones`, permiso
    `gestionar-autorizaciones`; se contestan desde `/mis-hijos`): es lo único del
    módulo 13 que no existía en ninguna forma.
    - **Una fila por VÍNCULO, no por alumno.** Quien autoriza es una persona
      concreta y su respuesta es suya; un alumno con padre y madre recibe dos y
      la escuela ve «respondió uno de dos» en vez de un sí del que nadie se hace
      responsable. **Cuántas respuestas hacen falta NO lo decide el sistema**:
      depende del trámite, así que se muestra el conteo y no se inventa un quórum.
    - **Lleva su propio `titulo`, `detalle` y `fecha_limite`**, que la spec no
      contemplaba. Sin ellos sólo sirve para un consentimiento permanente («uso
      de imagen: sí») y no para el caso más frecuente: «la salida del 5 de
      octubre». Con ellos la misma tabla sirve para los dos.
    - **`concedida` en NULL es «no ha contestado» y NO cuenta como negada.** La
      diferencia es legal, no cosmética. Una vencida sin contestar se queda
      pendiente y vencida — que es información distinta de un «no».
    - **La respuesta se puede cambiar mientras no venza**: revocar un
      consentimiento de uso de imagen es un derecho. Lo que queda del cambio es
      la auditoría (`updated_by`, `updated_at`) más `fecha_respuesta`; la CADENA
      completa de cambios sería una bitácora aparte, y no se construye por si
      acaso.
    - **Un alumno sin familiares vinculados se reporta POR SU NOMBRE al emitir.**
      Es el caso que arruina el trámite: la escuela cree que salió a todos y el
      día de la excursión resulta que a tres nunca se les pidió nada.
    - Reutiliza `/buscar/alumnos` y el permiso derivado `dirigir-a-alumnos`, que
      ahora también abre `gestionar-autorizaciones`.
  - Pruebas: `scripts/prueba-autorizaciones.php`, 14 verificaciones, comprobadas
    mutando cuatro reglas. **La primera versión era vacua**: tomaba el primer
    alumno con familia, que tenía UN solo vínculo, y ahí «una fila por vínculo» y
    «una por alumno» dan el mismo número. Ahora exige un alumno con dos.

- **El expediente del tutor familiar** (`/mis-hijos/expediente`, permiso
  `editar-mi-expediente-tutor`): el padre o tutor entrega lo que la escuela le
  pide A ÉL —su identificación, su comprobante de domicilio—.
  - **El ámbito llevaba desde el principio sin quien lo consumiera.**
    `DocumentoRequerido::AMBITO_TUTOR` existe en el catálogo y el demo YA lo usa
    —«Identificación oficial», obligatoria—, pero el portal de la familia sólo
    mostraba a los hijos: no había dónde entregarla. Es el mismo hueco que tenía
    el ámbito `alumno` antes de «Mi expediente».
  - **Son SUS papeles, no los de su hijo.** Que un tutor entregue por su hijo
    menor es otra conversación y necesita una decisión que el modelo no tiene
    tomada; se dejó anotada en vez de inventarla.
  - Tabla `documentos_tutor`, tercera con la misma forma tras `documentos_alumno`
    y `documentos_docente`. Se repite a propósito: con una sola tabla, los
    papeles del tutor asomarían en el expediente del alumno de quien es las dos
    cosas. Cuelga de `personas` y no del vínculo — quien deja de ser tutor de un
    egresado sigue siéndolo de otro hijo, y sus papeles no se van con el primero.
  - **Permiso propio**, como el del alumno y el del docente: hay escuelas donde
    los papeles del padre se entregan en ventanilla y la sección no debe existir.
  - **El permiso no basta: hace falta el VÍNCULO.** Un administrativo que se
    concediera el permiso no tiene expediente de tutor porque no lo es de nadie.
  - **El `exists` de la validación va acotado al ÁMBITO.** Sin eso, el id de un
    documento de aspirante pasaba y acababa en el expediente del tutor, donde
    nadie lo pidió y nadie lo va a revisar: el desplegable no es una defensa.
  - Pruebas: `scripts/prueba-expediente-tutor.php`, 12 verificaciones,
    comprobadas mutando cinco reglas. **Una de esas mutaciones destapó que la
    prueba del documento ajeno era floja**: la fila existía pero el archivo no,
    así que al quitar la salvaguarda fallaba igual —por 404— y la prueba mataba
    el script en vez de reportar. Ahora el ajeno tiene archivo de verdad, y la
    única razón de que no se descargue es la comprobación de propiedad.
  - Comprobado además el recorrido entero por HTTP suplantando al tutor del
    demo: subir (303), descargar (200) y borrar (303), con la fila y el archivo
    desaparecidos al final.

- **El acta impresa** (`/captura/{materia}/actas/{acta}/imprimir`, botón en la
  hoja de captura). En **Blade**, con estilos en línea para que un fallo de
  assets no deje sin forma un documento oficial justo cuando hay que firmarlo.
  (Decía «porque el proyecto no tiene librería de PDF»; desde el 2026-08-25 sí
  la hay, así que el acta es candidata a pasar a `DocumentoPdf` — pero es una
  hoja suelta que se firma a mano y ahí el PDF aporta menos que en el
  historial, que crece y se pagina.)
  - **Se imprime lo ASENTADO, no lo calculado.** Los renglones salen de
    `historial` —lo que el acta escribió al firmarse— y no de recalcular por
    componente. Si mañana cambia el esquema de evaluación de esa materia, un
    acta de hace un año seguiría imprimiendo los números del día que se firmó,
    que es justo para lo que existe un acta.
  - **Y por eso hace falta `withTrashed()`.** Al cerrar una corrección, los
    renglones de la original se dan de BAJA LÓGICA; como el documento se
    conserva, imprimirlo con la relación normal daba un acta con folio, firma y
    CERO alumnos: se ve perfecta y está vacía. Lo fija una mutación de la suite.
  - **La original avisa de que ya no tiene efecto**, con el folio de la que la
    sustituyó. Sin eso las dos se ven igual de válidas y quien tenga la vieja no
    tiene cómo saber que las calificaciones que lee ya no cuentan.
  - **Un acta abierta responde 404**, no 403: su folio es un `BORRADOR-…` y el
    real se emite al cerrar, así que imprimirla daría un papel con aspecto
    oficial y un número inventado. Ese documento todavía no existe — no es que
    no le toque a quien lo pide.
  - La ruta lleva materia Y acta, y se comprueba que el acta sea de esa materia:
    con sólo el id del acta, cualquiera con una materia propia tendría una puerta
    lateral a la de otro grupo. El alcance lo pone `AutorizaMateriaPropia`.
  - **Dos firmas cuando hace falta**: control escolar cierra el acta si el
    docente se dio de baja, así que titular y firmante pueden no ser la misma
    persona; con un solo espacio el papel diría que firmó quien no firmó.
  - Pruebas: `scripts/prueba-acta-impresa.php`, 15 verificaciones sobre un acta
    cerrada de verdad con `AsentadorActa` —no una fila insertada a mano—,
    comprobada mutando cuatro reglas. Mirado en el navegador: ahí salió un
    «22/08/2026 ." con espacio antes del punto y la nota de corrección repetida,
    dos cosas que ninguna prueba iba a ver.

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
  - **El seeder del demo ya repara ese caso**, no sólo lo crea: era idempotente
    por CORREO y se limitaba a saltarse las cuentas existentes, así que una
    resiembra no devolvía el campus perdido —no duplicaba, pero tampoco
    convergía—. Ahora `PoblarInstitucionDemoSeeder::devolverleSuCampus` reasigna
    lo que está en NULL o apunta a un campus muerto, y **respeta lo movido a
    mano**: una resiembra no puede deshacer lo que alguien decidió mientras usa
    la demo. Lo fija `scripts/prueba-seeder-staff.php` (6 verificaciones,
    comprobada mutando las dos direcciones).
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

- **El desenlace del aspirante se DERIVA: `situaciones_aspirante` se retiró**
  (2026-08-23, decidido con el cliente el 2026-08-11). Era el último catálogo que
  duplicaba al embudo: de sus cinco valores, tres eran puntos del recorrido
  —que dice la etapa— y los dos que informaban de verdad se resuelven mejor por
  otro lado.
  - **INSCRITO sale de tener `matricula_oferta` PARA SU OFERTA DE INTERÉS.** Es
    más cierto que el campo —con él se podía estar «Inscrito» sin matrícula y
    nada se quejaba— y es la misma pareja (persona, oferta) que
    `ConvertidorAspirante` ya comprobaba antes de convertir. Por la OFERTA y no
    por «tiene alguna matrícula»: quien ya estudia una carrera y se postula a
    una segunda sigue siendo un prospecto abierto para ésa, y darlo por inscrito
    lo sacaría del embudo desde el primer día.
  - **RECHAZADO se volvió `descartado_en` + `motivo_descarte`.** Un descarte
    tiene FECHA y RAZÓN, y una fila de catálogo no puede darlas: «Rechazado» no
    dice ni cuándo ni por qué, que es justo lo que se pregunta al revisar por
    qué se cayó un prospecto. El motivo es obligatorio por eso mismo, y
    reactivar se lo lleva.
  - **A los que ya estaban rechazados se les puso `descartado_en` con su
    `updated_at`, y el motivo se dejó en NULL**: nunca hubo uno guardado, y
    escribir «Rechazado» ahí sería fabricar una razón que nadie dio.
  - **`resultados_seguimiento.cierra_el_embudo` por fin hace algo.** Existía
    desde el CRM y sólo se DIBUJABA: marcar «no le interesa» dejaba al prospecto
    abierto, así que la bitácora decía que se perdió mientras el padrón lo
    seguía contando. Ahora descarta, con el nombre del resultado como motivo
    —es la razón que quien atendió ya eligió, y pedirla otra vez sería pedir dos
    veces lo mismo—.
  - **Al inscrito no se le descarta por ninguna de las dos puertas**: lo decide
    `motivoParaNoDescartar()`, que usan la pantalla y la agenda.
  - **`matriculaDeSuOferta()` sólo sirve CORRELACIONADA** —`whereHas`,
    `whereDoesntHave`, `withExists`—. Precargarla con `with()` revienta con
    «Unknown column 'aspirantes.oferta_interes_id'», porque ahí la relación se
    consulta sola y la tabla del padre no está en el FROM. El listado usa
    `withExists('matriculaDeSuOferta as ya_inscrito')`, y `estaInscrito()` se lo
    cree si está: repetirlo por fila es la consulta N+1 que se venía a evitar.
    Mordió al correr `prueba-listados`, con la precarga puesta por mí y encima
    inútil.
  - Pruebas: `scripts/prueba-desenlace-aspirante.php`, 22 verificaciones,
    comprobada mutando cinco reglas —ignorar la oferta al derivar inscrito,
    apagar `cierra_el_embudo`, quitar la defensa del inscrito, aflojar el motivo
    a `nullable` y dejar que reactivar conserve el motivo— y caen exactamente
    las que las vigilan.
  - **Una mutación destapó un hueco en `prueba-listados`**: comprobaba a QUIÉN
    trae el filtro pero no lo que el renglón DICE, así que una subconsulta que
    mintiera —`ya_inscrito` siempre a 1— dejaba la insignia diciendo «Inscrito»
    de todo el mundo y la prueba seguía en verde. El filtro sale de los scopes y
    la insignia del `withExists`: son dos caminos y hay que comprobar los dos.
  - **Y en el navegador salió lo que ninguna prueba iba a ver**: en vista de
    lista un descartado se veía idéntico a uno abierto —el desenlace sólo estaba
    en la cuadrícula—, de modo que filtrar por «Descartado» devolvía filas que no
    decían por qué habían salido. La insignia va en la MISMA celda que la etapa,
    porque son la misma pregunta —dónde quedó— y en columna aparte estaría vacía
    en casi todos los renglones.

- **`docs/plan-migraciones.md` estaba peligrosamente desactualizado, y al
  ponerlo al día salieron TRES tablas del LMS que nunca se construyeron**
  (2026-08-23). El plan marcaba **67 renglones como pendientes**; comprobados
  uno por uno contra la base del demo, **40 existían tal cual** y **24 más
  existían con otro nombre**. Sólo 3 faltaban de verdad.
  - **Es exactamente la trampa contra la que esta bitácora avisa cinco veces.**
    El plan es uno de los tres documentos vivos y se lee al empezar: un renglón
    sin tachar dice «falta construir». Con módulos 8, 9, 10, 11, 12 y 13 enteros
    sin tachar, la siguiente sesión tenía servido rehacer medio sistema.
  - Se agregó la marca **`[~]`** para lo resuelto de OTRA forma que la spec —con
    otro nombre, plegado en otra tabla, o deliberadamente no construido—, con el
    porqué al lado. Sin ella no hay dónde escribir «esto existe pero no se llama
    así», que es la mitad de los casos.
  - **Los desvíos con nombre distinto**, por si vuelve a buscarse por el nombre
    de la spec: `opciones_reactivo`→`reactivo_opciones`,
    `actividad_reactivos`→`examen_reactivo`, `entrega_respuestas`→`respuestas`
    (cuelga del INTENTO, no de la entrega), `foros`/`foro_mensajes`→`foro_temas`
    + `foro_respuestas`, `responsables_firma`→`responsables`,
    `tramites_titulacion`→`titulaciones`, `servicio_social`→
    `titulo_servicio_social`, `antecedentes_academicos`→`titulo_antecedente`,
    `lotes_documento`→`lotes_titulacion` + `lotes_certificacion`,
    `vinculos_familiares`→`tutores_alumno`, `avisos_familiares`→`avisos`,
    `aviso_destinatarios`→`avisos_destinos`.
  - **Lo que falta de verdad, y nadie lo había anotado** (el módulo 8 se declaró
    completo con esto fuera):
    1. **Portafolio de evidencias** (`portafolio_evidencias` +
       `portafolio_archivos`). La spec lo lista como uno de los CUATRO tipos de
       actividad —contenido, ejercicio/examen, **portafolio**, SQA— y viene del
       legacy. Hoy no existen ni las tablas ni el tipo. Lo más cercano es una
       entrega con varios archivos (`entrega_archivos`), que NO es lo mismo: el
       portafolio es una colección que el alumno acumula a lo largo del curso y
       describe pieza por pieza.
    2. **`acceso_videoconferencia`** — quién entró a la clase en línea y cuánto
       se quedó. `videoconferencias` está construida y funcionando, pero la
       asistencia a la sesión no se registra, así que **una clase en línea no
       puede pasar lista sola**.
  - **Y una deuda de diseño que sale a la luz al mapear**: `tipos_actividad`,
    `tipos_reactivo`, `dificultades` y `metodos_resolver` iban a ser catálogos y
    acabaron siendo `actividades.tipo` y `reactivos.tipo`, dos `varchar`.
    `dificultades` y `metodos_resolver` no existen ni como columna. **Choca con
    la regla 4** —«configurable, no cableado»—: una escuela no puede agregar un
    tipo de actividad sin tocar código, y nunca se escribió por qué no es tabla.
  - De paso quedaron al día: el Módulo 3 (`respuestas_campo` ya no está
    diferida), el Módulo 7 (decía «EN CURSO, aquí se retoma 7.2» con 7.2 y 7.3
    cerradas, y daba por pendiente el enganche al scheduler que ya está en
    `routes/console.php`) y el conteo de suites (decía «43 verificaciones» de
    una sola; son 82 archivos, con las cuatro que no cierran con «Resultado:»).

- **Las tres tablas que le faltaban al Módulo 8, construidas** (2026-08-23).
  Eran lo único que quedaba pendiente de código tras poner al día el plan de
  migraciones. El módulo se había declarado completo sin ellas.

  **1. Quién entró a la clase en línea** (`accesos_videoconferencia`).
  `videoconferencias` llevaba desde el 2026-08-19 repartiendo enlaces y nadie
  anotaba quién los usaba.
  - **El mecanismo lo propuso el cliente y es el bueno**: el botón deja de ser un
    enlace directo al proveedor y pasa por una puerta propia
    (`/clases/{clase}/entrar`) que anota el clic y redirige. Cuesta un
    `redirect`; medir permanencia de verdad exigiría el reporte de participantes
    de Zoom **y** de Meet, dos APIs más y un Workspace con el que probarlas.
  - **Se dice QUÉ mide.** La pantalla habla de «se conectaron» y no de
    «asistieron»: lo que hay es el clic con la clase abierta, no permanencia.
    Ponerlo como asistencia haría que alguien firmara un acta con un dato que el
    sistema no tiene. Por eso **no escribe en `asistencias`**: se le enseña al
    docente mientras pasa lista y él decide.
  - **UNA fila por persona y clase, no una por clic.** Contar asistentes tiene
    que ser un `count()` y no un `count(distinct)` que alguien olvidará, y una
    clase con red mala —donde la gente se reconecta seis veces— saldría con seis
    veces más «asistencia» que otra. Las reconexiones viven en `veces` y
    `ultimo_acceso`, que además distingue a quien estuvo desde el principio de
    quien apareció al final.
  - **El `upsert` va con `ON DUPLICATE KEY`**, no con «buscar y si no crear»: el
    doble clic impaciente manda dos peticiones a la vez y el par SELECT+INSERT
    las deja pasar a las dos, así que la segunda revienta contra el índice único
    y le devuelve un error de base a quien sólo quería entrar a clase.
  - **`papel` se congela en la fila.** Quien da clases y además estudia puede
    aparecer de los dos lados en materias distintas, y resolverlo al MIRAR
    obligaría a repreguntar la asignación de entonces, que puede haber cambiado.
  - **Efecto secundario que vale por sí solo**: ni el `url_join` ni el
    `start_url` de Zoom viajan ya al navegador. El de anfitrión es una
    credencial —entra como dueño de la sala— y ahora sale sólo del controlador
    de la puerta, que reconoce el papel. **El docente también entra por ahí**,
    para que su propia llegada quede anotada: «¿el docente llegó a su clase?» es
    de las preguntas que esta tabla existe para contestar.
  - `url_invitado` SÍ sigue viajando al docente: lo copia y lo pega en su grupo
    de mensajería, que es como se avisa de verdad. No es una credencial, y quien
    lo use por ahí simplemente no queda anotado.
  - Pruebas: `scripts/prueba-acceso-clase.php`, 23 verificaciones, comprobada
    mutando cinco reglas —aflojar el 404 a 403, dar el anfitrión al alumno,
    quitar el contador de reconexiones, dejar entrar a una clase cerrada y
    volver a mandar el enlace del proveedor—.

  **2. Portafolio de evidencias** (`portafolio_evidencias` +
  `portafolio_archivos`), quinto valor de `TipoActividad`.
  - **En qué se diferencia de una tarea con adjuntos**: una tarea se entrega DE
    UNA VEZ y sus archivos no tienen nombre propio ni fecha propia. Un
    portafolio se ACUMULA a lo largo del curso y cada pieza lleva su título, su
    descripción y su momento. **Esa descripción por pieza ES el portafolio**: sin
    ella sería una carpeta de archivos, que ya existía.
  - **Cuelga de `entregas`, no de (inscripción, actividad)** como pedía la spec
    —que es exactamente la pareja que `entregas` ya identifica—. Con dos tablas
    diciendo «el trabajo de esta alumna en esta actividad», al calificar habría
    que elegir a cuál creerle. Colgando de la entrega se hereda TODO lo que ya
    funciona: calificación, retroalimentación, rúbrica, «entregada tarde», el
    panel de calificación del docente y el aula del alumno.
  - **La entrega nace en BORRADOR y se cierra aparte**, que es la diferencia con
    una tarea: agregar una pieza NO es entregar, y darlo por entregado al subir
    la primera dejaría al docente calificando un trabajo a medias. Por eso
    `primeraOReviver` y no `actualizarOReviver`: con el otro, sumar una pieza a
    un portafolio ya entregado lo devolvería a PENDIENTE y lo sacaría de la cola
    del docente sin que nadie lo pidiera.
  - **Dos tablas y no una**: una evidencia puede necesitar varios archivos —la
    foto del montaje, el video del ensayo y el PDF del reporte son UNA
    evidencia— y a la vez puede no necesitar ninguno (una reflexión escrita es
    evidencia legítima). Con una sola, corregir una errata del título sería
    corregirla tres veces.
  - **`fecha_evidencia` NO es `created_at`.** Una práctica de octubre se captura
    en diciembre al armar el portafolio, y ordenar por cuándo se subió contaría
    la historia al revés.
  - **Calificado no se toca** —ni agregando, ni editando, ni quitando—: cambiarlo
    dejaría la calificación explicando un trabajo que ya no está. Misma regla del
    acta asentada y de la rúbrica congelada. Y quitar es borrado LÓGICO, al revés
    que `entrega_archivos`: un adjunto retirado antes de entregar es corregirse,
    una evidencia retirada después de calificar es historia escolar.
  - **Reordenar se acota a la entrega propia**: la lista de ids viene del
    navegador y no es fuente de verdad; sin eso, mandar el id de la evidencia de
    otro le reordenaría su portafolio. Lo cazó una mutación.
  - **Con botones ↑↓ y no arrastrando**: arrastrar en táctil pelea con el
    desplazamiento de la página, y esto se abre desde el teléfono.
  - Pruebas: `scripts/prueba-portafolio.php`, 27 verificaciones, comprobada
    mutando siete reglas.
  - **Verificado en el navegador el recorrido entero**, suplantando al alumno y
    al docente: agregar dos evidencias, reordenarlas, entregar, ver la matriz del
    docente con las piezas y sus descripciones en el panel de calificación,
    poner 18 de 20 con retroalimentación, y volver al alumno para leer
    «Calificado: 18 de 20. Ya no se puede modificar.» con los botones
    desaparecidos.
  - **Los datos sembrados para mirarlo se retiraron**: era texto que me inventé
    atribuido a una alumna real del demo. `acadion:auditar-datos` sigue
    reportando las mismas 69 filas rotas de siempre, o sea que el borrado no
    dejó referencias colgando.

- **«Los tipos deberían ser catálogo» era falso: comprobado el 2026-08-23.** Al
  auditar el plan quedó anotado que `tipos_actividad` y `tipos_reactivo` chocan
  con la regla 4 por ser `varchar` y no tabla. Se midió y la decisión es la
  correcta; lo que faltaba era el porqué, que es justo lo que la regla 4 exige.
  - **Cada valor no es un dato, es una RAMA DE CÓDIGO**: 22 ramas por tipo de
    actividad y 74 por tipo de reactivo. Una lectura no pondera ni lleva
    rúbrica, un examen lo califica la máquina, un foro tiene su propio
    controlador, un portafolio se acumula; en los reactivos, `Ordenamiento`
    siempre baraja, `Clasificar` lleva categorías y `Completar` lleva huecos.
  - **Volverlo catálogo sería una promesa falsa**: la escuela agregaría
    «Podcast» y no habría rama que lo atendiera —un tipo en el desplegable que
    no se puede entregar, ni calificar, ni abrir—. Es el mismo defecto de los
    cinco interruptores que nadie leía y de `cierra_el_embudo`, que sólo se
    dibujaba.
  - **La prueba de si algo debe ser catálogo es si una fila nueva HACE algo.**
    En `modalidades_percepcion` sí —«base más horas» se creó desde la pantalla y
    funcionó—. Aquí no. La regla 4 no dice «todo enumerable es tabla»: dice que
    si se cablea hay que poder explicarlo.
  - **`dificultades` y `metodos_resolver` tampoco son deuda**: no existen ni
    como columna, y son una FUNCIÓN que nadie ha pedido —armar el examen con N
    reactivos de cada nivel—. Llegarán con su lector, como `clave_sat`.
    Anotarlas como deuda invitaría a crear columnas sin quien las lea.

- **El barrido de URLs del frontend contra las rutas, repetido el 2026-08-23**
  tras agregar las pantallas del portafolio y la puerta de la clase en línea.
  **23 sospechosas, las 23 falsos positivos**: seis `base-captura` a las que el
  componente añade el id, nueve `prefijo:` del menú —que son para resaltar la
  sección activa, no destinos—, dos líneas de comentario y cuatro que el
  comparador midió mal (una ruta con parámetro opcional, dos con interpolación
  en un segmento intermedio). Ningún botón muerto.

- **Primera ronda de revisión EN EL NAVEGADOR** (2026-08-23), la que la deuda
  pedía desde hace meses. **Y lo primero que salió es que la deuda estaba
  desactualizada: las capturas de pantalla YA FUNCIONAN.** Llevaba tiempo escrito
  que se agotaban por tiempo en este entorno, y por eso todo se venía
  verificando midiendo el DOM. Hoy responden.
  - **Ojo con la geometría de la captura**: sale topada en 800×500, no admite
    recorte por región y NO es un reescalado simple del lienzo —la posición de
    las cosas en la imagen no corresponde a la del DOM—. Sirve para ver si algo
    se ve roto; para medir, sigue mandando `javascript_tool`.
  - Recorrido: panel, académico (carreras), control escolar (grupos), finanzas
    (cartera), RH (nómina), reglas de la escuela, calendario, diseñador de
    credencial y diseñador del historial. **Ninguna pantalla real dio 500**: se
    comprobaron 52 direcciones del menú y todas responden 200.

  **Cuatro defectos que sólo se ven mirando, corregidos:**
  1. **El nombre de quien entra salía truncado en el saludo del panel.** El
     clima llevaba `shrink-0` con tope del 72 % de la tarjeta, así que entre
     `sm` y `xl` se quedaba con casi tres cuartos y al saludo le tocaban 199 px
     para los 267 que pide «Israel Gutierrez Moreno»: se leía «Israel Gutie…».
     Se le quitó el `truncate` —una persona no se abrevia, y en dos renglones
     cabe— y el tope del clima bajó al 60 % hasta `xl`.
  2. **La leyenda de cada tarjeta del panel flotaba lejos de lo que explica.**
     La regla que centra el número de una métrica iba sobre
     `> p:not(:first-child)`, así que alcanzaba también al pie de las otras
     formas —lista, matriz— y a la propia leyenda del número: los dos crecían,
     se repartían el hueco y cada uno se centraba en el suyo. Medido: el «2» en
     una caja de 161 px y «recursos disponibles» en otra de 141, con cien
     píxeles en medio. Ahora número y leyenda van en un bloque que se centra
     junto (36 y 16 px, 2 px entre ellos) y los pies se quedan pegados a su
     lista.
  3. **El selector de diseño de la credencial salía en cuatro columnas de
     97 px**, con quince renglones de texto cada una. `lg:grid-cols-4` mira la
     VENTANA, y esa rejilla vive en la columna derecha: a 1024 px el contenedor
     mide 413. Bajado a dos columnas: 202 px y cuatro renglones. **Se revisaron
     las otras tres rejillas con el mismo patrón** —avisos, calendario y clases
     en línea— y están bien: son cifras cortas o campos con `span`.
  4. **«Certificación y titulación» no cabía en la barra lateral.** Pide 148 px
     y tenía 138. Se apretó el hueco icono-texto (`gap-3`→`gap-2.5`, +4 px) y
     los 6 que faltan no se le quitan a nadie sin estrechar la barra entera por
     UNA etiqueta: se sigue cortando con puntos suspensivos, que es lo correcto,
     y **el `title` pasó a estar siempre** —sólo salía en modo compacto— para
     poder leer el nombre completo.

  **Segunda mitad de la ronda: los PORTALES** (alumno, familia, docente).
  - **En el portal de la familia, el nombre del hijo y sus carreras salían
    truncados.** «Mateo Martínez Ramírez» pedía 179 px y tenía 175 —cortado por
    cuatro—, y «Licenciatura en Administración de Empresas» pedía 271 con 187.
    Lo segundo es lo grave: **dos carreras que empiecen igual se truncan al
    MISMO texto**, y quedan indistinguibles — que es justo lo que el diseño de
    una por renglón venía a evitar (su comentario lo dice). Las dos envuelven
    ahora; las tarjetas se emparejan solas en la rejilla.
  - Panel del alumno, sus cursos, su historial, el portal del docente y el de la
    familia: **sin recortes ni desbordes**. Los vacíos están bien resueltos
    («Todavía no estás inscrito en ninguna materia…»).
  - **La credencial del demo tenía el QR dentro de la banda de color.**
    Medido sobre el PNG: la banda del diseño «clásico» llega a y=223 y la caja
    del QR iba de 104 a 218, con CERO píxeles pintados por debajo —el 78 % del
    reverso en blanco—. **No era del código**: el QR conserva su proporción y su
    zona blanca, o sea que la trampa anotada en su día quedó resuelta; lo que
    estaba mal era `campos_reverso` de esta escuela, con el QR en `y: 10 %`.
    - **Corregido en el DATO** (no hay commit de código): la caja pasó de
      `60 × 12 %` en `y: 10` a **`40 × 25.2 %` en `x: 30, y: 48`**. La caja se
      hizo CUADRADA en píxeles —40 % de 638 son 255, y para igualar hacen falta
      25.2 % de 1011—: con la anterior, ancha y baja, el QR sólo podía crecer
      por el alto. Y va centrada en el área blanca, cuyo centro está en el 61 %.
    - Comprobado recomponiendo el PNG y contando píxeles: QR de **240×240
      (cuadrado)**, de y=492 a 732 —**fuera de la banda**—, márgenes de 198 y
      200 px (centrado), 38 % del ancho, y zona quieta blanca por los cuatro
      lados. Y mirado, que es la regla: los tres patrones de esquina se ven
      limpios.
    - Bajo el QR se colocaron después la **vigencia** y una **leyenda** nueva
      (ver la entrada siguiente).

  **Tercera parte: certificación, captura, examen y foro.**
  - **El folio del lote salía partido en tres renglones** —compartía celda con
    la insignia en una línea rígida—, y al arreglarlo saltó que la píldora «En
    espera de firma» hacía lo mismo dentro del óvalo. `PildoraEstado` gana
    `whitespace-nowrap`: una píldora es de UN renglón, y lo que cede es la
    columna. Para eso su tabla pasó de `overflow-hidden` a `overflow-x-auto`
    —medía 720 px en 718, así que RECORTABA en vez de desplazar—.
  - **La barra lateral no enseñaba dónde estabas.** 1036 px de menú en 580
    visibles y arrancaba siempre arriba: en Encuestas el renglón activo quedaba
    a 825 px con `scrollTop` en 0. Ahora se asoma sola, con un tercio de altura
    por encima, y no se mueve si ya se veía.
  - **La captura aplastaba los nombres**: siete columnas en 709 px sin
    desplazar, «Roberto Guzmán Herrera» en tres renglones y filas de 85 px. Con
    ancho mínimo y desplazamiento, 65 px.
  - **El control de tamaño de letra se mudó al panel de Apariencia.** Lo había
    reportado como observación inocua tras mirarlo en dos pantallas; en la hoja
    de captura tapa «Fulano: falta capturar Actividades», que es la razón por la
    que no se puede firmar el acta. Cualquier control fijo estorba en algún
    sitio; éste no necesitaba estarlo.
  - **Los plazos salían con SEGUNDOS** —«Cierra el 2026-09-23 21:17:33»— en el
    examen y el foro, y en un formato que no usa ninguna otra pantalla. Sólo se
    cambiaron esos tres: hay **46 usos de `toDateTimeString()`** en
    controladores y un cambio en bloque es arriesgado, porque `Y-m-d H:i:s` lo
    parsea `new Date()` y `d/m/Y H:i` no.

- **El examen barajaba aunque el docente apagara el barajado** (2026-08-23).
  Salió al presentar un examen sembrado para la revisión.
  - `AplicadorExamen::sortearReactivos` tenía **elegir y ordenar en la misma
    condición**: `if ($barajar || $reactivos_a_presentar !== null) shuffle()`.
    Bastaba con fijar «presentar N» para que el orden saliera al azar con
    `barajar_reactivos` en falso — y presentando TODOS también, donde no hay
    siquiera nada que elegir.
  - Son dos decisiones. Para quedarse con N de M hace falta azar —si no, todos
    verían los mismos N—, pero el ORDEN es del docente: se sortea CUÁLES y
    después se devuelven a su sitio. Un examen cuyas preguntas se apoyan unas en
    otras se desordenaba sin que nadie lo pidiera.
  - Pruebas: `scripts/prueba-orden-examen.php`, 7 verificaciones, comprobada
    contra el código viejo —caen las dos que vigilan el orden—.
  - **Y un falso positivo que se descartó comprobando**: la pista «Marca todas
    las que correspondan» sobre una pregunta de una sola respuesta era culpa de
    la semilla, no del producto. `TipoReactivo` distingue `OpcionUnica` de
    `OpcionMultiple`, y la segunda significa justamente eso.

- **El morado cableado se fue** (2026-08-23). Eran **31 usos de `indigo`** en 15
  archivos —no 21: ese conteo miraba sólo `bg-indigo`—, y el propio código ya
  tenía el precedente escrito en `CampoCheckbox`: «llevaba `text-indigo-600`
  fijo y se quedaba morada en cualquier tema».
  - En un producto donde cada escuela escoge su color, el morado aparecía justo
    al INTERACTUAR: al escribir en un campo (el anillo de foco) y al elegir una
    opción (tarjetas de diseño, niveles, roles, caras de la credencial).
  - Se resolvió con cuatro utilidades en `app.css` —`foco-acento`,
    `elegido-acento`, `elegido-acento-macizo`, `texto-acento`/`fondo-acento`— y
    no con `:style` en cada sitio: con estilo en línea, el siguiente que se
    agregue vuelve a escribir el color a mano.
  - **Trampa que mordió**: `focus:ring-1` de Tailwind compila DESPUÉS que la
    regla propia y con la misma especificidad, así que le pisaba el
    `box-shadow` y dejaba el anillo en `currentcolor`. No se arregla peleando
    por el orden: `.foco-acento` le da el color por `--tw-ring-color` y Tailwind
    lo dibuja. El `box-shadow` se conserva para los campos que no llevan esa
    utilidad.
  - Comprobado midiendo el color computado: todo lo elegido en `rgb(0,106,137)`
    —el acento de la escuela— y cero elementos morados. Lo único que el detector
    marcaba eran el azul de la barra lateral del tema.
  - **El hueco que dejó, cubierto el mismo día**: al medirlo salieron **11
    campos de escritura con foco declarado y 264 SIN él**. Los segundos se
    quedaban con el anillo por omisión del navegador, así que media plataforma
    no se parecía a la otra media.
    - Se resolvió con **una regla global** para `input`, `select` y `textarea`,
      no clase por clase: declararlo campo por campo es justo lo que produjo la
      deriva, y arreglarlo así la reproduce en el siguiente formulario. Las once
      clases `foco-acento` se retiraron — dos mecanismos para lo mismo es como
      se llega a que uno divergiera.
    - **`:where()` no es adorno**: aporta CERO especificidad, así que la regla
      queda en (0,1,0) y las utilidades del estado de error
      —`focus:border-red-500`, `focus:ring-red-500`— siguen ganando. Sin él
      subiría a (0,3,1) y pintaría de acento el foco de un campo que está
      señalando un error. Comprobado poniéndole las clases de error a un campo:
      el anillo pasa a rojo.
    - Y **`:focus-visible`** y no `:focus`: en un campo de escritura el navegador
      lo da igual al hacer clic, así que no se pierde nada, y se evita el anillo
      en un `select` que sólo recibió el foco al cerrarse un diálogo.

- **Las casillas y los radios no tenían NINGÚN indicador de foco** (defecto
  anterior, encontrado el 2026-08-23 al medir lo de arriba). Su regla apagaba el
  contorno del navegador y ponía un `box-shadow` que NO se pintaba —computado
  salía `0 0 0 0` transparente, pisado por la maquinaria de anillos de
  Tailwind—, así que entre las dos cosas quien navega con el teclado no podía
  saber en qué casilla estaba. Ahora va con `outline`, que es el mecanismo del
  navegador para esto y que nadie compone a partir de variables.
  - **Ojo al medir el foco en este entorno**: si el panel del navegador no está
    a la vista, el documento no tiene el foco del sistema y `:focus-visible` no
    casa aunque `document.activeElement` sea el campo. Da un falso negativo. Se
    recupera mandando un `Tab` real con `computer`, y entonces sí se mide.
  - Y **la enumeración por CSSOM no sirve aquí**: `document.styleSheets` sólo
    devolvió 103 reglas de un CSS de 70 KB, así que no encuentra lo que se
    busca. Para saber qué regla gana, grep sobre el CSS compilado —con las
    posiciones, que deciden el empate de especificidad—.

- **La credencial gana una LEYENDA para el reverso** (2026-08-23). `vigencia` ya
  existía —«Vigente hasta julio 2027»— pero es una frase corta sobre una fecha;
  una leyenda es el aviso institucional del dorso: «personal e intransferible,
  en caso de extravío…». Dos cosas distintas, dos columnas: mezclarlas obligaría
  a meter el aviso legal dentro de la línea de la vigencia.
  - Flujo completo, replicando el de `vigencia`: columna
    `credenciales_rol.leyenda` (400, no 120 —son un par de oraciones—),
    `fillable`, `CatalogoCampos` (const + entrada + `ejemplo()` + parámetro de
    `valores()`), controlador (validación, vista previa, props) y los DOS
    callers de `valores()` —`MiCredencial` y `VerificacionCredencial`—.
  - En el Vue es un **`CampoTextarea`** y no un campo de una línea: en un renglón
    único no se leería lo que se escribe. `CampoTextarea` ganó de paso una prop
    `maximo` → `maxlength`, que no tenía, para que el tope del cliente (400)
    coincida con el del servidor.
  - **En el demo** se colocaron los dos campos bajo el QR: vigencia centrada al
    78 % y la leyenda al 84 %. Medido sobre el PNG: la leyenda se parte en dos
    renglones, va centrada (márgenes 70/68), y el bloque acaba a 127 px del
    borde inferior. Verificado también en el navegador: el diseñador carga
    ambos para el rol Alumno.
  - Pruebas: `CompositorCredencialTest::una_leyenda_larga_se_parte_en_renglones`
    —mide que el texto envuelva (más de un renglón) y no rebase su caja—,
    comprobada mutando `renglones()` para que no parta: cae en la aserción del
    envolvido. Total 717 phpunit.

- **Biblioteca y Servicios del alumno, ahora también en la barra lateral**
  (2026-08-23). Vivían SÓLO como tarjetas del panel (`BibliotecaDigital`,
  `MisSolicitudes`), así que un alumno que ya había salido del panel no tenía
  cómo volver a ellas. Se agregaron al menú como dos secciones de un hijo —igual
  que «Mi solicitud» del aspirante y «Mis tutorados» del tutor—, cada una tras
  su permiso (`ver-biblioteca`, `solicitar-servicios`, ambos de faceta ALUMNO):
  si la escuela no publica biblioteca ni abre el catálogo de trámites, la
  entrada no aparece.
  - **Auditoría completa, no sólo esas dos**: se cruzaron las claves de permiso
    de las CINCO facetas no administrativas (`clavesDe()`) contra las URLs del
    menú, y se revisaron los `enlace` de las 30 tarjetas del panel. **El alumno
    era la única faceta con hueco en la barra**; padre, tutor, docente y
    aspirante ya la tenían completa. Ojo con dos que PARECEN faltar y no:
    `editar-mi-disponibilidad` del docente se edita DENTRO de «Mi expediente»
    (no es página aparte), y `ver-historial-academico` de padre y tutor se
    alcanza por el hijo/tutorado, no por un enlace directo.

- **El menú lateral ahora respeta los MÓDULOS de la escuela** (2026-08-24).
  Salió al revisar el pedido de "apagar/encender opciones por perfil": el sistema
  ya tenía DOS niveles para eso —permisos (`/plataforma/roles`) y el editor de
  menú por rol (`/plataforma/menu`, cajón de «Ocultos»)— pero **faltaba el
  tercero**: apagar un MÓDULO en `/plataforma/modulos` dejaba su entrada en la
  barra dando 404, porque la RUTA comprobaba el módulo (`modulo:` middleware) y
  el menú no.
  - Cinco secciones estaban gateadas por módulo y no lo declaraban: `movilidad`,
    `rh`→nomina, `bolsa`→bolsa_trabajo, `servicios-alumno`→servicios,
    `biblioteca-alumno`→biblioteca. Ahora cada una lleva `modulo:` en el
    catálogo; `construir.ts` oculta la sección si su módulo está apagado, y el
    middleware comparte `modulos` (las claves encendidas) como prop.
  - **Fail-open**: si el prop `modulos` no llega —página cacheada tras un
    despliegue— NO se filtra por módulo, porque vaciar la barra por un prop
    ausente es peor que un enlace de más. Sólo con la lista presente se oculta.
  - **El editor de menú por rol también los oculta** (`construirParaEditor` con
    `modulos`): no tiene sentido arreglar una sección que nadie ve; al reencender
    el módulo reaparece, igual que con los permisos.
  - **Los módulos NÚCLEO no se tocan**: academico, control_escolar, finanzas,
    etc. no tienen `modulo:` middleware ni campo `modulo` en el menú, así que no
    se filtran. Ojo: en el demo esos figuran como "apagados" en
    `modulos_activos` (sin fila = default false), pero da igual porque no están
    gateados. **Trampa latente**: ponerle `modulo:` a una sección núcleo la
    ocultaría de golpe, porque su módulo no tiene fila encendida.
  - Verificado en el navegador: con `biblioteca` apagado, la sección desaparece
    de la barra del alumno y `/biblioteca` da 404; al reencender, reaparece.
    Y los otros niveles ya funcionaban —se comprobó ocultando `bolsa` para el
    alumno vía el editor (MenuRol.ocultos): desaparece sin tocar permisos—.

- **Disciplina · incidencias y sanciones de conducta, CERRADO** (2026-08-24).
  Pedido del cliente: «dos opciones para llevar el control de incidencias y
  sanciones para alumnos». NO estaba en la spec —`especificacion-esquema.md`
  sólo tiene «incidencias» de NÓMINA, otra cosa—, así que es función nueva
  diseñada con los patrones del proyecto, no un módulo de la spec. Bajo
  `modulo:disciplina` (apagarlo en `/plataforma/modulos` devuelve 404 y esconde
  el menú), con sección propia en la barra: Incidencias, Sanciones y Catálogos.
  - **El titular es la MATRÍCULA, no la persona.** `incidencias` y `sanciones`
    cuelgan de `matricula_oferta`, igual que el historial académico: quien
    estudia dos carreras tiene su conducta separada por programa, y corregir su
    identidad no mezcla las dos.
  - **`reportada_por`/`aplicada_por` salen de la SESIÓN, no de la petición**, y
    editar NO los reescribe: quien la levantó sigue siendo quien la vio. Dejar
    que el navegador dijera «la reportó fulano» permitiría atribuírsela a otro.
  - **La vigencia la manda el TIPO** (`tipos_sancion.tiene_vigencia`): una
    suspensión pide `desde`/`hasta`, una amonestación no; con un tipo puntual se
    anulan aunque el formulario los traiga —cambiar de suspensión a amonestación
    no puede conservar fechas que ya no significan nada—. Misma forma que las
    modalidades de percepción de nómina: bandera de comportamiento, no clave.
  - **Una sanción puede CITAR las incidencias que la originaron** (pivote
    `incidencia_sancion`), y sólo las del MISMO alumno: sancionar a uno citando
    la incidencia de otro se descarta en el servidor. El endpoint que las ofrece
    (`SancionController::incidenciasDe`) va aparte porque se piden al elegir la
    matrícula, no en la carga de la pantalla.
  - **El docente levanta las de SUS alumnos, y el alcance lo pone la ASIGNACIÓN,
    no el permiso.** `/docencia/incidencias` va con `can:levantar-incidencia`
    (faceta docente), pero el desplegable trae sólo sus alumnos
    (`docente_asignatura_grupo` → Inscripción) y al guardar se vuelve a comprobar
    contra la asignación: una matrícula ajena da **403**. La lista de la pantalla
    no es una defensa.
  - **`/buscar/matriculas` es un endpoint APARTE de `/buscar/alumnos`.** Aquél
    deduplica a PERSONAS —quien tiene dos carreras sale una vez—, y aquí hace
    falta la MATRÍCULA concreta a sancionar. Gateado por el derivado
    `gestionar-disciplina` (definido con `Gate::define`: incidencias O sanciones).
  - **El padre/tutor ve la conducta de su hijo en `/mis-hijos`, SÓLO LECTURA**,
    gateada por `ver-conducta-hijo` (faceta padre) Y el módulo encendido. **El
    alumno NO la ve**: no hay permiso de faceta alumno que la abra. El gate cruza
    permiso Y módulo: apagar el módulo la esconde aunque el padre tenga permiso.
  - **Catálogos TENANT-CONFIG con banderas de comportamiento**
    (`tipos_incidencia.nivel`, `tipos_sancion.tiene_vigencia`), no enums
    cableados: una escuela agrega «Uso de celular» o cambia a cinco niveles de
    gravedad sin tocar código. `CatalogoConductaController` es genérico
    (registro catálogo→modelo con su `extra`), como `CatalogoAcademicoController`
    pero acotado al módulo y gateado por su permiso —los catálogos de conducta
    NO son catálogos de Académico—. Un tipo en uso se APAGA, no se borra (dejaría
    incidencias colgando); apagar exige que nadie lo use, como en Académico.
  - Permisos nuevos en `CatalogoPermisos`: `gestionar-incidencias` y
    `gestionar-sanciones` (faceta administrativa), `levantar-incidencia` (faceta
    docente), `ver-conducta-hijo` (faceta padre). `director_general` los hereda
    de su faceta salvo `levantar-incidencia`/`ver-conducta-hijo`, que son de
    otras facetas.
  - **Trampa que mordió AL PROBAR, no en producción**: `DocenteIncidenciaController`
    lee `$peticion->user()` mientras los de admin usan `Auth::user()`. En una
    petición HTTP real dan lo mismo (el middleware de auth pone el resolutor del
    request), pero la suite tuvo que fijar AMBOS —`auth()->login()` y
    `setUserResolver()`— o el controlador del docente veía null y todo era «no es
    tu alumno». Anotado por si otra prueba invoca ese controlador.
  - Pruebas: `scripts/prueba-disciplina.php`, 25 verificaciones, comprobadas
    mutando las cinco reglas de seguridad —reescribir el reportante al editar,
    ignorar la vigencia del tipo, citar la incidencia de otro alumno, quitar el
    403 del docente y quitar el gate del padre— y caen exactamente las que las
    vigilan. Verificado además en el navegador: el catálogo con sus insignias
    (Nivel 1–3, «Con vigencia» sólo en Suspensión), el formulario que adapta su
    campo por catálogo (número para nivel, casilla para vigencia), y las tres
    hojas en la barra lateral.

- **Movilidad, Disciplina y Bolsa pasaron a colgar de «Alumnos»** (2026-08-25).
  A pedido del cliente: son funciones administrativas SOBRE el alumno, y tenerlas
  como tres secciones sueltas de primer nivel llenaba la barra. Ahora son
  SUBGRUPOS de «Alumnos» —el mismo patrón que «Generación de horarios» dentro de
  Control escolar—, cada uno con su `prefijo` y su `modulo`.
  - **La corrección que el pedido no vio**: esas pantallas las opera PERSONAL,
    no el alumno. El único trozo del alumno es «Mis vacantes» de la bolsa, y no
    podía quedar bajo «Alumnos» —faceta administrativa, que un estudiante nunca
    ve—. Se sacó a su propia sección `bolsa-alumno` (faceta `alumno`, módulo
    `bolsa_trabajo`, una sola hoja `/mis-vacantes`), igual que Biblioteca y
    Servicios. El resto de la bolsa (empresas, vacantes, colocaciones,
    empleabilidad) es el subgrupo administrativo bajo «Alumnos».
  - **Un SUBGRUPO ahora puede depender de un módulo.** `resolver()` en
    `construir.ts` no copiaba `modulo` al bajar de nivel —sólo los grupos de
    primer nivel lo llevaban—, así que un subgrupo con módulo apagado se habría
    quedado visible. Se corrigió: el subgrupo hereda el ámbito del padre pero
    conserva SU módulo, y `filtrar()` ya lo gateaba. Sin esto, apagar
    `disciplina` en `/plataforma/modulos` habría dejado el subgrupo en la barra
    dando 404, justo lo que la entrada anterior vino a arreglar para los grupos.
  - Las claves NO cambiaron (`movilidad`, `disciplina`, `bolsa`, sus hijos): son
    lo que guardan las disposiciones de menú por rol, y renombrarlas rompería los
    menús ya configurados. Sólo cambió DÓNDE cuelgan en el catálogo.
  - Las RUTAS y sus `modulo:` middleware no se tocaron: es reorganización de
    menú, no de acceso. Verificado en el navegador: el admin ve «Alumnos» →
    Listado, Movilidad, Disciplina (→ Incidencias/Sanciones/Catálogos) y Bolsa de
    trabajo; ya no hay Movilidad/Disciplina/Bolsa en la raíz; y el alumno ve su
    «Bolsa de trabajo» → «Vacantes» (`/mis-vacantes`) sin la sección
    administrativa.

- **Hay UN promedio oficial: el de la MATRÍCULA** (2026-08-26). Había **tres**
  implementaciones dando **tres números distintos** para el mismo alumno, y
  ninguna fallaba. El detalle y la tabla comparativa están en
  `docs/decisiones.md`; lo que hay que recordar:
  - Manda `HistorialDelAlumno::promedio()`: **mejor intento por materia**, con la
    precisión del plan, y **por matrícula**. `EstadoDelAlumno` y el detalle del
    portal del padre lo consultan en vez de calcularlo.
  - **Medido antes de decidir**: de las 15 personas con dos carreras en el demo,
    **las 15** leían en `/mis-hijos` un promedio que no era el de ninguna de sus
    dos carreras —Sofía con 8.54 teniendo 8.59 y 8.50—, porque aquel servicio
    promediaba por PERSONA mezclando programas.
  - Las pantallas de padre y tutor necesitan UNA cifra para ordenar: es la **más
    baja** de sus programas —«a cuál hay que atender», que es lo que su docblock
    dice que contestan— y viaja con `promedio_de` para nombrar la carrera en
    cuanto hay más de una. `reprobadas` sí se suma: es cuenta de la persona.
  - De paso, el detalle del portal del padre cobraba los **créditos dos veces**
    de una materia aprobada dos veces.
  - **En el demo no hay ni un recursamiento**, así que la mitad de esta regla
    sólo se ve con el escenario construido dentro de la transacción.

- **El interruptor de secciones vivía en una pantalla inalcanzable**
  (2026-08-26). `/plataforma/modulos` es lo que apaga una sección y **no estaba
  en el menú de nadie**; ahora cuelga de Plataforma → Configuración. Esta
  bitácora mandaba cuatro veces a `/plataforma/accesos`, que es el registro de
  quién inició sesión y no apaga nada — las cuatro están corregidas.

- **El historial académico se imprime en PDF de verdad, y su diseñador ya no es
  ciego** (2026-08-25). El cliente lo dijo sin rodeos: «actualmente como está no
  sirve».
  - **Lo que estaba mal, medido antes de tocar nada**: el navegador le ponía SU
    encabezado —la URL y la fecha del sistema, encima del membrete de la
    escuela—, no había numeración de páginas («Hoja 2 de 7» es lo que impide que
    a un historial de trescientos renglones se le extravíe una hoja), y el
    membrete salía sólo en la primera. Un documento oficial que se entrega en
    ventanilla no puede depender de la configuración de impresión de quien lo
    saca.
  - **Se REVOCÓ la decisión de «sin librería de PDF»**, que llevaba meses
    escrita aquí. Era correcta cuando se tomó —el navegador ya sabe imprimir— y
    dejó de serlo en cuanto el documento necesitó encabezado repetido, pie
    numerado y marca de agua: eso el navegador no lo da y no hay CSS que lo
    supla. Se instaló **mpdf 8.3**.
  - **`App\Documentos\DocumentoPdf` es la base y `App\Historial\HistorialPdf`
    el caso**: el siguiente documento oficial —el acta, un lote— hereda el
    membrete que se repite, el `Hoja {PAGENO} de {nbpg}` y la marca de agua sin
    reescribirlos.
  - **Tres trampas de mpdf que no dan error**, y por eso son caras: no entiende
    el selector de hermano adyacente (`.bloque + .bloque`) —la regla se acepta y
    no se aplica: diez periodos salían en tres páginas—; `page-break-inside:
    avoid` peleando con el salto daba diecinueve páginas en vez de diez; y el
    **hex de ocho dígitos** (color con alfa) lo descarta en silencio, así que el
    tinte se calcula en PHP. Ninguna lanza excepción: producen un PDF que abre.
  - **El diseñador enseña el PDF DE VERDAD mientras se ajusta**, no una imitación
    en HTML. Es la lección de la credencial —ahí el fondo lo dibuja el servidor
    porque acomodar cajas contra algo que no existe es acomodarlas mal—, llevada
    al documento entero: la vista previa pide el mismo PDF que va a salir, con
    datos de ejemplo largos (diez periodos, sesenta materias) para que el ancho
    de columna se decida contra el caso difícil.
  - **El ancho y la alineación son POR COLUMNA**, y los anchos se normalizan a
    100 en el servidor: quien los teclea no tiene por qué hacer que sumen.
  - **Varios firmantes en vez de un responsable único** (`firmantes_historial`).
    Un historial se firma por control escolar Y por la dirección; con tres
    columnas en la tabla del diseño sólo cabía uno, y la migración las retira
    tras copiar lo que hubiera.
  - **Trampas de este trabajo, ya mordidas**: el token CSRF del `<meta>` queda
    viejo tras iniciar sesión —Inertia no recarga la página— y la vista previa
    daba 419; sale de la cookie `XSRF-TOKEN`. El `fetch` seguía el redirect y se
    tragaba el HTML como si fuera un PDF; ahora exige `application/pdf`. Y
    `CatalogoColumnas::porOmision()` no devolvía todas las columnas, así que una
    escuela que nunca abrió el diseñador reventaba con un TypeError.
  - Pruebas: `tests/Feature/HistorialPdfTest` (23 casos, con un motor espía que
    captura las opciones y el cuerpo en vez de contar cadenas dentro del PDF —el
    subsetting de fuentes hacía frágil lo segundo—) y
    `tests/Feature/FirmantesHistorialTest` (13).
  - **Una salvaguarda que no salvaba nada**: un `if ($diseno->exists)` antes de
    leer `firmantes`. La mutación sobrevivió; comprobado que Eloquent devuelve
    una colección vacía, se retiró.

- **Módulo de Reportes** (`/reportes`, permiso `ver-reportes`): reportes por
  área, con filtros, columnas elegibles y descarga en Excel o CSV. Pedido del
  cliente del 2026-08-25. El plan completo, con las diez rebanadas, vive en
  **`docs/plan-reportes.md`**.
  - **Un reporte es una DEFINICIÓN, no una consulta.** `FuenteDeReporte` declara
    las columnas y los filtros de un dominio (hoy `Matriculas`) y cada
    `DefinicionReporte` es una pregunta concreta sobre esa fuente, con sus
    filtros fijos. Así «Alumnos inscritos», «Bajas» y «Egresados por generación»
    comparten el alcance por campus, los permisos por columna y el recorrido por
    lotes en vez de tener tres consultas que divergen.
  - **La autorización la vuelve a resolver el EJECUTOR en cada camino**
    —pantalla, XLSX y CSV—, no la pantalla que armó la petición. Es lo que hace
    que una vista compartida comparta la CONFIGURACIÓN y no los datos: quien la
    abre ve lo suyo, con su alcance de campus y sin las columnas que su permiso
    no alcanza. Lo fija una prueba con ese nombre.
  - **Una columna puede ser SENSIBLE y pedir un permiso extra** (la CURP pide
    `editar-alumnos`): el `select` no es una defensa, y una columna se puede
    pedir por la URL.
  - **El recorte por campus tiene cinco formas** (`Recorte`), porque no todas las
    tablas tienen `campus_id`: por oferta, por columna, por relación, por
    adscripción, y `SIN_CAMPUS`, que **lanza 403** en vez de devolver todo — una
    fuente que no sepa acotarse no puede acabar entregando la escuela entera a
    quien está acotado a un campus.
  - **El CSV se escribe renglón por renglón contra `php://output`** y no con
    PhpSpreadsheet: su escritor de CSV también exige el libro completo en
    memoria, o sea el mismo techo que el XLSX y ninguna de sus ventajas. El XLSX
    sí lo usa, y por eso lleva tope de filas.
  - **Áreas renombrables y reportes movibles** (`/reportes/configuracion`): la
    escuela decide cómo se llaman sus áreas y qué reporte vive en cuál. Un
    reporte que nunca se movió NO tiene fila: vive en su área por omisión, y ésa
    es la trampa que se cobró la cuenta por área (ver el commit de la revisión).
  - **Vistas guardadas, compartidas y favoritos**: una vista guarda filtros,
    columnas y orden con un nombre. Compartirla a toda la escuela o a un rol
    exige `gestionar-areas-reporte` — sin eso, cualquiera le plantaba una vista a
    dirección general y encima era el único que podía quitarla.
  - **Dos defectos del recorrido por lotes que sólo se ven en la EXPORTACIÓN**,
    encontrados por una revisión adversaria y ambos silenciosos: el desempate sin
    dirección y la comparación de tuplas con NULL. Están explicados en el commit
    `013ed0d` y en `Ejecutor::avanzar()`; lo que hay que recordar es la regla de
    MySQL: **`(3,2) > (null,1)` no es falso, es NULL, y una condición NULL
    descarta la fila**.
  - **Lo que sale del sistema hay que neutralizarlo** (`TextoDeCelda`): Excel
    toma como fórmula lo que empieza por `= + - @`, y medio reporte escolar es
    texto que escribió alguien de fuera.
  - Pruebas: `scripts/prueba-reportes-motor.php`, 77 verificaciones.
  - **Rebanada 7 COMPLETA** (2026-08-26): **34 reportes sobre 14 fuentes, en las
    nueve áreas**. Cada fuente se escribió después de un reconocimiento
    adversario del dominio, y ese reconocimiento encontró más defectos que la
    propia construcción.

    | Área | Fuentes | Reportes |
    |---|---|---|
    | Control escolar | Matriculas, Grupos, AsistenciaPorMateria | 7 |
    | Finanzas | Cartera, Cargos, Ingresos | 7 |
    | Admisiones | Aspirantes | 4 |
    | Docentes | Docentes, CargaAcademica | 4 |
    | Certificación | Certificables | 3 |
    | RH | Plantilla | 3 |
    | Bolsa | EgresadosYColocacion | 2 |
    | Movilidad | MovilidadSaliente | 2 |
    | Familia | VinculosFamiliares | 2 |

  - **Las decisiones que se repiten, y hay que conocer antes de tocar esto:**
    - **Ninguna fuente de finanzas declara `modulo`**, y no es descuido:
      `finanzas` está en el catálogo `modulos` SIN fila en `modulos_activos` y el
      ejecutor falla cerrado, así que declararlo daría 404 en todos sus reportes.
      Lo mismo certificación. Bolsa, RH y movilidad SÍ lo declaran, y están
      encendidos.
    - **Un TITULAR DUAL obliga a elegir rama y a decirlo en el GRANO.** Pasa tres
      veces: los cargos y pagos de aspirante (llegan al campus por su propia
      columna, no por una oferta), y las postulaciones de movilidad ENTRANTES
      (no tienen campus nuestro por ningún camino). Mezclarlas obligaría a
      declarar `sinCampus`, que **lanza 403 a todo rol acotado a un plantel**.
    - **`sinCampus` es correcto cuando de verdad no hay campus**: los vínculos
      familiares cuelgan de dos PERSONAS. Ahí se niega el reporte con su razón,
      porque acotarlo por la matrícula del hijo haría que un padre con hijos en
      dos planteles apareciera y desapareciera según quién mire.
    - **Las reglas se leen por la BANDERA del catálogo, nunca por la clave**:
      `entra_a_nomina`, `cuenta_como_egresado`, `acepta`, `cuenta_como_contacto`,
      `bloquea`, `emite_documentos_oficiales`. Cada una se comprueba con un
      escenario SEMBRADO que separe la bandera de la clave — sin él las dos daban
      lo mismo en el demo y la regla pasaba sin comprobarse.
    - **Un agregado que se ORDENA entra por `leftJoinSub`, no por `selectSub`.**
      Ver el defecto 5 más abajo.
    - **Lo que la fuente NO puede contestar se declara con su medición.** El
      ejemplo: la lista de qué le falta a cada quien para certificarse la sabe
      `ValidadorDec`, pero cuesta **255 ms por matrícula** cuando llega a armar
      el XML —más de cuatro minutos en mil filas— y reimplementar sus reglas
      mandaría un lote a la SEP creyéndolo bueno.

  - **SIETE defectos del motor que salieron construyendo esas fuentes.** Ninguno
    daba error; todos daban otro número, una columna vacía o un 500:
    1. **El keyset truncaba en ASCENDENTE.** MySQL ordena los NULL primero en ASC
       y al final en DESC, y la rama del cursor no miraba la dirección: `8 de 14`
       filas. La prueba que lo vigilaba pasaba **por suerte aritmética** —4 nulos
       y lotes de 5 significan que el cursor nunca termina dentro del bloque
       nulo—. Ahora la suite exige que los nulos sean MÁS que el lote.
    2. **Y podía no terminar NUNCA.** Con la columna de orden envuelta en un
       `coalesce`, el cursor compara el atributo contra la columna, no descarta
       el lote emitido y repite las mismas filas sin fin: 32 matrículas → 161
       filas y subiendo. Ahora el cursor tiene que avanzar o el motor se detiene
       diciendo qué arreglar.
    3. **Una columna sin resolutor cuya clave no es el nombre del atributo sale
       VACÍA en todas las filas.** Mordió en tres columnas de golpe. Ahora el
       constructor de `ColumnaReporte` lo prohíbe y dice cómo arreglarlo.
    4. **El TIPO no viajaba al frontend**: iba `alineacion` y no `tipo`, así que
       la pantalla sabía hacia qué lado pegar el número y no cómo escribirlo. Se
       veía «2750.00», «0» y «2750» en la misma fila y las fechas en ISO con zona
       horaria. Lo resuelve `resources/js/utils/celdaReporte.ts`.
    5. **Ordenar por una columna de `selectSub` reventaba la EXPORTACIÓN.** MySQL
       acepta un alias de SELECT en el `ORDER BY` y **no en el `WHERE`**, y el
       keyset avanza con un `WHERE`: en pantalla ordenaba bien y al pulsar
       «Excel» daba «Unknown column». **La regla: un agregado que se ordena entra
       por `leftJoinSub` a una subconsulta YA AGRUPADA, y su alias en el SELECT
       tiene que ser el último segmento de `columnaSql`.**
    6. **TODA casilla marcada daba 500.** Validar no es convertir: la regla
       `boolean` de Laravel ACEPTA la cadena «1» —lo que manda una casilla— pero
       devuelve el valor tal cual, y las closures están tipadas `bool`. Ninguna
       suite lo veía porque todas pasaban booleanos de PHP, que es lo que escribe
       un `filtrosFijos()`. **Probaban el mecanismo, no el camino.**
    7. **`Recorte::porRelacion` fallaba ABIERTO.** Llevaba `orWhereDoesntHave`
       siempre, y en una cadena eso perdona tres cosas: campus sin asignar,
       campus dado de baja y **un eslabón intermedio dado de baja** —que es una
       operación normal—. Una fila así pasaba PARA TODOS LOS CAMPUS. Hoy la
       tolerancia es `incluirSinAsignar`, un argumento con nombre.

  - **La red que cubre la clase entera**: `scripts/prueba-reportes-ordenables.php`
    recorre el REGISTRO y exporta por cada columna ordenable de cada reporte, en
    las dos direcciones y con lotes de 2 para obligar al cursor a avanzar —**318
    combinaciones** hoy—. Rellena los filtros obligatorios según su TIPO y DICE
    qué reportes no tenían filas, para no dar por cubierto lo que no se ejercitó.

  - **Y una lección que se repitió en CADA área**: de las mutaciones que
    sobrevivieron, casi todas fue porque **el demo no tiene el caso**. No hay ni
    un recursamiento, ni un plan con meta cero, ni una carrera que no expida
    papel, ni un empleado en comisión, ni nadie con su única adscripción cerrada,
    ni un aspirante convertido, ni un docente con dos materias del mismo grupo.
    Cada uno de esos escenarios se CONSTRUYE ahora dentro de la transacción. Una
    comprobación que se cumple porque el escenario no existe no comprueba nada.

  - **REVISIÓN ADVERSARIA de la rebanada 7** (2026-08-26): diez agentes sobre
    las 14 fuentes y los 34 reportes, en cinco lentes, con una fase de
    REFUTACIÓN que reprodujo cada hallazgo contra el demo antes de creerle.
    **16 confirmados, 5 refutados.** Las refutaciones valieron tanto como los
    hallazgos: tres describían estados que ninguna línea del código puede
    producir, y una señalaba como duplicado un criterio que no existe a ese
    grano. Todo lo confirmado está corregido, con nueve mutaciones que mueren.

    - **«Su adscripción» estaba escrita TRES veces y las tres divergían.** Es el
      hallazgo grande, y lo reportaron tres lentes por separado.
      - El RECORTE por campus y los FILTROS de campus y puesto no miraban la
        vigencia: casaban contra adscripciones ya cerradas. El coordinador de un
        plantel veía el expediente de quien HOY trabaja en otro —con la columna
        «Campus» de esa misma fila diciéndoselo— y filtrar por «Coordinador de
        carrera» devolvía a quien lo FUE y hoy da clases. **Filas que se
        contradicen a sí mismas.**
      - La SUBCONSULTA que pinta las columnas sí la miraba, pero contra
        `curdate()`. Y como dar de baja CIERRA las adscripciones abiertas, a
        quien ya se fue no le quedaba ninguna: «Bajas de personal» salía con
        **Puesto y Campus en blanco —dos de sus ocho columnas por omisión—**
        para toda baja pasada, y no por falta de captura.
      - Hoy hay UNA definición, `Adscripcion::laQueCuenta()`: la que cubría el
        día que se fue —o el de hoy, si sigue—. Es el mismo criterio que
        `ExpedienteLaboral::esquemaEn` usa para el sueldo. La nombran los tres:
        el recorte y los filtros por la relación `adscripcionesQueCuentan()`, y
        la subconsulta por su SQL, que es la única que no puede usar la relación.
      - **La fecha de corte es lo que destraba las dos mitades a la vez**: una
        vigencia cruda contra `curdate()` habría arreglado la fuga y dejado
        «Bajas» en cero.

    - **Un `permisoExtra` que NO EXISTE esconde la columna para TODO EL MUNDO.**
      Las cuatro sensibles de la plantilla docente —correo, celular, CURP y
      RFC— pedían `editar-docentes`, que nunca estuvo en `CatalogoPermisos` ni
      en la tabla `permissions`. Falla CERRADO, o sea que no hubo fuga, y por
      eso llevaba meses sin notarse: la columna sale del Excel sin decir por
      qué, y la pantalla le explica «tu rol no las alcanza» a quien tiene TODOS
      los permisos administrativos de la escuela. Lo prohíbe ahora el
      constructor de `ColumnaReporte`, junto a sus otras dos comprobaciones.
      - **Ninguna suite lo veía porque todas comprobaban la OMISIÓN —que
        funcionaba— y ninguna que la columna LLEGARA a quien tiene el permiso.**

    - **Un orden por omisión que no se puede aplicar se descartaba EN SILENCIO.**
      `ordenPedido()` sólo devuelve la columna si es `ordenable`, y si no cae a
      la llave primaria sin avisar: el reporte salía ordenado por otra cosa
      mientras su definición declaraba una. Lo prohíbe `RegistroReportes` al
      registrar, que es donde están las dos mitades —la definición dice por qué
      columna, y quién sabe si es ordenable es la FUENTE—.
      - Y el guard **destapó otro hueco al ponerlo**: dos reportes no tenían UNA
        SOLA columna ordenable, así que no se podían ordenar por nada. Hoy los
        34 se pueden ordenar, con 330 combinaciones en la red.
      - **Un guard hace vacua la prueba que lo barre**: recorrer el registro
        pasa siempre, porque el guard impide registrar uno malo. Se comprueba
        construyendo el reporte que se quiere prohibir.

    - **`Recorte::porRelacion` con `incluirSinAsignar` perdonaba TRES cosas y no
      una.** El docente sin campus se enseña a todos —es una cola de trabajo—;
      el docente cuyo campus se BORRÓ, no. Y se puede borrar: `destroy` sólo se
      niega si el campus tiene oferta, y `Campus` usa borrado lógico. Sus
      docentes pasaban a verlos TODOS los coordinadores. La tolerancia se mide
      ahora contra la relación **`withTrashed()`**, que es lo que la acota a lo
      que promete.

    - **La asistencia contaba a los DADOS DE BAJA y la carga académica no**: dos
      fuentes de la misma entrega dando números distintos sobre la misma
      materia. Y es peor que un número de más: a esa inscripción no se le puede
      pasar lista nunca —`DocenciaController` la saca de la lista del docente—,
      así que el renglón se quedaba en «materias sin lista pasada» para siempre,
      sin gesto que lo limpiara.

    - **La bitácora anotaba `milisegundos = 0` en toda EXPORTACIÓN**, que es el
      único formato donde el dato importa: son las que recorren la tabla entera
      por lotes. Y un cero se lee como una medición, no como un dato ausente.

    - **Tres docblocks mandaban a un reporte que no existe** («Cobros de
      aspirantes»), y uno de ellos mandaba a conciliar un descuadre contra él.
      El texto llega al usuario: el controlador pasa `grano()` como prop.

    - **Cinco comprobaciones que pasaban por la razón equivocada**, encontradas
      por la lente de suites vacuas y todas reparadas: un `every(fn => true)`,
      un `verificar(..., true)` literal, un `318 >= 34` que se cumple aunque una
      fuente entera no aporte nada, un `str_contains` contenido en el anterior, y
      un `catch (\Throwable)` que daba por buena cualquier explosión —la tercera
      vez que este proyecto se cobra el `catch` pelado—.
      - Y **una sexta que se cayó al arreglar el código**: «el acotado ve menos
        que el global» sobre los cargos se cumplía sólo por TRES filas huérfanas
        del demo. Al excluirlas —lo correcto: una fila que el recorte no puede
        resolver no puede ser visible sólo para el global— la comprobación se
        vino abajo, bien caída. Ahora se siembra un cargo en otro campus.

  - **NO pasar `pint` sobre `scripts/`** (mordió el 2026-08-26). Su fixer de
    nombres cualificados convierte los FQN en alias y **añade los `use` al bloque
    de importaciones, que en estas suites está DESPUÉS del arranque** —primero
    `require`, luego `$app->make(Kernel::class)->bootstrap()`, y los `use` más
    abajo—. Un alias no aplica a lo que va antes, así que `Kernel::class` pasó a
    resolver al `\Kernel` global: **67 suites reventaron de golpe** al arrancar,
    todas con «Target class [Kernel] does not exist». No es un fallo de pint —el
    código quedaría bien si el bloque estuviera arriba—, pero el arreglo bueno no
    es reordenar 67 archivos: es no pasarle pint a `scripts/`, que no es código
    de la aplicación.

  - **Rebanada 8 · Totales, agrupados y bitácora, CERRADA** (2026-08-26). Cinco
    entregas, y tres de ellas salieron distintas de como el plan las escribía —en
    los tres casos con la medición delante—.

    - **La BITÁCORA por fin se puede mirar** (`/reportes/bitacora`, permiso
      propio `auditar-reportes`). Llevaba desde la primera rebanada
      escribiéndose sin puerta: 119 filas y ninguna pantalla.
      - **No es una puerta trasera a los datos**: guarda lo que se PIDIÓ
        —reporte, filtros, columnas— y nunca lo que salió. Quien audita ve que
        alguien exportó la cartera del campus norte, no la cartera.
      - El permiso se declaró, se sembró y se comprobó con un `can()` EN VIVO
        antes de que existiera la ruta: un `can:` sobre un permiso inexistente
        cierra la puerta a todo el mundo en silencio.
      - Los filtros se traducen a su etiqueta —«ciclo_id: [331]» no se puede
        auditar, «Ciclo de la carga: 331» sí— y un reporte RETIRADO conserva sus
        ejecuciones con su clave, en vez de reventar o desaparecer.
      - **Un defecto que sólo se vio MIRANDO la pantalla**: filtrando por un
        nombre inexistente, la tabla decía «ninguna ejecución» y el resumen de
        arriba seguía diciendo 119. El resumen no aplicaba ese filtro porque va
        por relación y los demás no. Dos universos pegados, el mismo defecto que
        el tablero de la bolsa. Los filtros viven ahora en un solo sitio.

    - **La PURGA que el plan pedía «desde el primer día» y nunca se construyó**
      (`reportes:purgar-ejecuciones`, semanal). Dos trampas medidas:
      - `->delete()` NO borra: el modelo lleva `TieneAuditoria`, así que
        informaría «borradas 400» sin quitar una fila. Y lo peor es que la
        comprobación obvia —«las de dentro de la retención siguen ahí»— se
        cumple igual. Va `forceDelete()` y la prueba mira la tabla FÍSICA.
      - **`withTrashed()` NO es lo que hace falta para borrarlas**, aunque lo
        parezca: comprobado que el macro `forceDelete` va contra el query builder
        crudo y se salta el scope. Lo que sí cambia es el CONTEO —1 contra 2— y
        ése tiene lector: es el número que `--seco` le enseña a quien decide.

    - **La bitácora cuenta PREGUNTAS, no clics.** Medido: 113 de 119 filas eran
      de pantalla, con 44 repeticiones idénticas en menos de dos minutos sobre
      sólo 40 consultas distintas.
      - **Se deduplica en vez de dejar de anotar la pantalla**, que era la letra
        del plan. Decisión del cliente: quitarlas se llevaba el 95 % del insumo
        con el que se decide si construir el constructor de reportes —su criterio
        de entrada se mide con esta tabla— y dejaba de registrar a quien LEE
        columnas sensibles sin descargarlas.
      - Se compara contra la ÚLTIMA de esa persona en ese reporte dentro de diez
        minutos: A, B, A son tres preguntas. **Las DESCARGAS nunca se
        deduplican**: un archivo sale de la escuela y se reenvía.

    - **El PIE de la tabla, y ninguna columna numérica sin decidir.** Los totales
      salen de una consulta agregada aparte sobre el mismo builder ya recortado,
      nunca de la página.
      - **Una columna numérica que no diga qué va al pie NO DEJA ARRANCAR la
        aplicación.** No se deduce del tipo: `TipoDato::esNumerico()` —que
        existía sin un solo lector— se equivoca en ordinales, en umbrales
        repetidos por fila (`certificables.meta` es la meta del PLAN: sumarla
        cuenta cada plan una vez por alumno), en conteos que no se suman entre sí
        (`docentes.grupos` es un `count(distinct)` por docente, su suma son
        parejas) y en porcentajes.
      - Las 38 columnas numéricas de las 13 fuentes quedaron declaradas: **25
        Suma, 1 Promedio, 12 Ninguno**, y las doce escriben en su `ayuda` por qué
        no se totalizan.
      - **El cuadre protege contra `groupBy`/`having` en una fuente, NO contra un
        join que multiplique** —medido: ahí las dos consultas ven las mismas
        filas repetidas, 17 y 17, y cuadran—. El docblock decía lo contrario y se
        corrigió: prometer una red que no existe es peor que no tenerla.
      - Los totales van SÓLO en pantalla: un CSV se abre con otro programa y un
        renglón final que no es un dato lo corrompe en silencio.

    - **Las CABECERAS por fin se pueden pulsar.** Había **165 ranuras
      `ordenable`** declaradas y ningún `<th>` pulsable: al orden sólo se llegaba
      escribiendo la URL a mano, y una suite entera comprobaba un camino que
      nadie tenía. El orden viaja por la misma vía que los filtros, así que el
      enlace de descarga lo hereda solo.

    - **El modo AGRUPADO, y el hallazgo que lo gobierna.** El plan lo pedía sobre
      las columnas y se midió que no se puede: de 181 columnas sólo 67 existen en
      SQL, y son identificadores o medidas. **Campus no era agrupable en ninguna
      de las catorce**, ni carrera, ni situación, ni etapa.
      **`columnaSql` no significa «dimensión»: significa «por aquí se puede
      ORDENAR».**
      - Las dimensiones se declaran aparte (`DimensionReporte` +
        `FuenteAgrupable`). Van tres fuentes, una a la vez por decisión del
        cliente: Matrículas (conteos), Cartera (dinero) y Aspirantes.
      - **Se agrupa por el ID y se rotula con el NOMBRE**: agrupar por el nombre
        fundiría dos campus homónimos.
      - **La dimensión pasa por el filtro de permisos**, que `columnasOmitidas()`
        no puede hacer —recorre las columnas pedidas y `agrupar_por` es otro
        camino—. La etiqueta de un grupo ES el valor de la columna. Hoy eso no se
        podía porque ninguna sensible tiene `columnaSql`: la puerta estaba
        cerrada POR ACCIDENTE.
      - **NO reusa el keyset**: bajo `GROUP BY` la llave primaria no identifica
        la fila y el recorrido lanza «Illegal operator and value combination»
        —pero sólo a partir del SEGUNDO lote, o sea con más de 500 grupos: en la
        escuela grande y nunca en la prueba—.
      - **El grupo SIN etiqueta se enseña.** Esconderlo haría que los subtotales
        dejaran de sumar, que es lo único que un agrupado promete.
      - **Y `leftJoin` sólo donde el NULL es posible**: medido,
        `matricula_oferta.situacion_id` y `.oferta_id` son NOT NULL, así que ahí
        prometía un grupo que la base no puede producir ni sembrándolo. Donde sí
        existe es en `aspirantes`, cuyas tres foráneas son nullable.
      - La barra **no es la del panel** aunque se parezca: allá se mide contra el
        MAYOR de la serie a propósito —para que un embudo que arranca en 200 y
        termina en 3 no deje invisibles las últimas etapas— y aquí contra el
        total. Dos escalas para dos preguntas; queda escrito para que nadie las
        unifique sin leer.

    - **El panel no comprobaba el MÓDULO, y ya lo hace.** Reproducido sembrando
      una postulación: con `bolsa_trabajo` apagado, «Postulantes en proceso»
      seguía en el panel con su enlace a `/bolsa/vacantes`, que la RUTA sí
      comprueba — o sea que llevaba a un 404. Misma lección que el menú lateral.
      - **La comprobación vive en UN sitio**, `RegistroTarjetas::para()`, con la
        interfaz opcional `TarjetaDeModulo`. Dos tarjetas se lo miraban por su
        cuenta y funcionaban; el problema es que con la comprobación repartida,
        **la que se olvida no falla: se pinta**.
      - **Trampa al declararlo**: los módulos NÚCLEO figuran como APAGADOS —no
        tienen fila en `modulos_activos` y `ModulosDeLaEscuela` falla cerrado—,
        así que declarárselo a una tarjeta de finanzas la haría desaparecer de
        golpe. Lo vigila la suite.
      - Y con eso llegó la tarjeta **«Mis reportes»**: los favoritos, y sin
        ninguno, lo que más se corrió en 90 días. Eso último sólo tiene sentido
        desde que la bitácora deduplica: antes habría contado recargas de página.
        Qué reportes ofrece lo dice `RegistroReportes::para()` —permiso, módulo y
        faceta—, no la tarjeta: la bitácora conserva lo que alguien corrió cuando
        SÍ podía, y ofrecerle el atajo hoy lo llevaría a un 403.
      - Pruebas: `scripts/prueba-panel-modulos.php`, 18 verificaciones,
        comprobadas mutando seis reglas. La sexta sobrevivió porque nada
        comprobaba que la tarjeta se CALLE cuando no tiene nada que ofrecer.

    - Pruebas: `prueba-bitacora-reportes` (26), `prueba-reportes-totales` (22) y
      `prueba-reportes-agrupados` (29), más las secciones nuevas de
      `prueba-reportes-motor` y `prueba-reportes-ordenables`. **Comprobadas
      mutando 26 reglas.** Cuatro sobrevivieron y las cuatro enseñaron algo:
      - «el total sale de la página» **no es expresable con un LIMIT** —un LIMIT
        no restringe lo que agrega un `count()`—, así que hizo falta la mutación
        fiel: agregar sobre una subconsulta ya topada.
      - «cuadra siempre true» sobrevivía porque nada construía el caso; se
        construyó una fuente con `groupBy`.
      - «la ventana de repintado se vuelve eterna» sobrevivió DOS veces: la
        primera porque nada la vigilaba, y la segunda porque la comprobación que
        le escribí envejecía una sola fila y el motor busca la más reciente, así
        que encontraba otra y no ejercitaba la ventana.

  - **Lo que NO se construyó de la rebanada 7, y por qué**: el **LMS** se quedó
    fuera —de sus tres cursos, dos cuelgan de `asignatura_grupo` que ya no
    existen, así que no hay contra qué comparar— y la **evaluación docente**
    también: sus encuestas están todas dadas de baja, y esa fuente tiene que
    pasar por `ResultadosDeEncuesta` para respetar el umbral de anonimato
    (`MINIMO_PARA_MOSTRAR = 4`) — un archivo se reenvía más fácil que una
    pantalla, y la siguiente encuesta ya nadie la contesta con sinceridad.
  - **Rebanadas pendientes** (ver el plan): 9 —envío programado por correo, y la
    que el propio plan marca como «la primera que recortaría»— y 10 —el
    constructor de reportes que pidió el cliente—. **El criterio de entrada de la
    10 ya se puede MEDIR**: son tres vistas guardadas del mismo reporte con
    formas distintas, o una petición concreta de la SEP que ninguna fuente cubra,
    y eso se lee en la pantalla de uso que la rebanada 8
    acaba de entregar. Hoy el demo tiene CERO vistas guardadas, así que el
    criterio no se cumple todavía y decirlo es parte del trabajo.

**Pendiente inmediato — aquí se retoma:**

**5. Reportes, rebanadas 7 a 10** — es lo que está en curso. El detalle y el
orden están en `docs/plan-reportes.md`; la 10 es el **constructor de reportes**
que pidió el cliente («la SEP en ocasiones los solicita de formas
personalizadas»), aplazado a propósito hasta tener varias fuentes reales, que es
su criterio de entrada.

*(Antes de tomar algo de esta lista, COMPROBARLO en el código. **Ya van cinco**
que mandaba a construir cosas hechas: la titulación SEP, el estado de cuenta del
alumno, los horarios, la pasarela de pago y el panel del landlord. Las tres
últimas se cayeron al comprobarlas el 2026-08-19; lo comprobado ese día está
marcado abajo con su fecha, y lo que NO la lleve sigue sin verificar.)*

0. ~~**ELIMINAR LA SITUACIÓN DEL ASPIRANTE**~~ — **hecho el 2026-08-23.** Ver
   «El desenlace del aspirante se deriva» en el estado, más arriba.

1. ~~**Impresión del acta**~~ — **hecha el 2026-08-22.** Ver «El acta impresa»
   en el estado, más arriba.

2. ~~**El portal del TUTOR no opera**~~ — **hecho el 2026-08-22.** Ver «El
   expediente del tutor familiar» en el estado, más arriba. Queda ABIERTO, y a
   propósito, si un tutor puede entregar documentos POR su hijo menor: el
   vínculo `tutores_alumno` declara qué puede VER —lo académico, lo
   financiero— y nada sobre qué puede entregar en su nombre, así que decidirlo
   aquí sería decidirlo por la escuela.

4. ~~**Las TRES tablas del LMS que faltan**~~ — **hechas el 2026-08-23**, por
   decisión del cliente. Ver «Las tres tablas que le faltaban al Módulo 8» en el
   estado, más arriba. **Con esto el plan de migraciones no tiene un solo
   renglón sin resolver.**

   La «deuda de diseño» que anoté al mapear —`tipos_actividad`,
   `tipos_reactivo`, `dificultades`, `metodos_resolver`— **se comprobó y NO
   existía**. Ver «Por qué el tipo de actividad y el de reactivo no son
   catálogo» en el estado, más abajo.

3. **Fase 4 — COMPLETA** (2026-08-23): los cuatro módulos cerrados.
   Revisada contra el código el 2026-08-22: de sus 50
   tablas no existía NINGUNA, pero los cuatro módulos ya estaban registrados en
   `modulos`. **La numeración no es una cadena de dependencias**: 10, 11, 12 y
   13 no dependen entre sí —todos cuelgan de Fases 0-3—, así que el orden es
   decisión de negocio. El acordado con el cliente:

   1. ~~**Módulo 13 · Familia**~~ — **cerrado el 2026-08-22.** Con el alcance
      REVISADO: buena
      parte ya estaba construida con otros nombres, y hacerlo literal habría
      creado un segundo vínculo familiar (`vinculos_familiares` contra
      `tutores_alumno`) y un segundo sistema de avisos (`avisos_familiares`
      contra `avisos`, que ya segmenta por nueve tipos de destino).
      **Decisión del cliente: el vínculo se queda POR PERSONA**, no por
      matrícula como pedía la spec.
   2. ~~**Módulo 11 · Bolsa de trabajo**~~ — **CERRADO el 2026-08-22.** Autocontenido, no toca nada
      delicado, y produce el dato que una escuela presume: colocación de
      egresados. Rebanadas: **empresas ✅**, **vacantes ✅**,
      **postulaciones ✅**, **colocaciones ✅**. **Decisión del cliente: la postulación
      es autogestiva Y por ventanilla, con un interruptor para apagar la
      autogestiva y forzar el mostrador; apagado por omisión.**
   3. ~~**Módulo 10 · Nómina y RH**~~ — **CERRADO el 2026-08-23**, el de más valor y el más grande.
      Rebanadas: **expediente laboral ✅**, **esquemas de percepción y
      conceptos ✅**, **periodo y recibo ✅**, **CFDI de nómina ✅**. Su insumo existe (el reloj checador,
      tabla `checadas`) pero está VACÍO en el demo. **Decisión del cliente sobre el CFDI de
      nómina: se implementa, pero el timbrado se enciende desde configuración.
      Apagado, no se puede timbrar; encendido, valida la información que el
      timbrado exige.**
   4. ~~**Módulo 12 · Movilidad**~~ — **CERRADO el 2026-08-23.** Era el dominio más complejo y ESCRIBE
      en el historial académico al aprobar una revalidación, que es lo más
      delicado del sistema. Además `equivalencias` sigue en 0 filas, o sea que
      su mecanismo de apoyo nunca se ha ejercitado.

**Deuda conocida:**

- **El navegador SÍ alcanza `demo.localhost`** — la deuda anterior era falsa.
  No resuelve por DNS (`gethostbyname` devuelve el nombre), pero Chromium mapea
  `*.localhost` a loopback por su cuenta, sin tocar el archivo `hosts`. Se
  verificó el panel entrando con la cuenta demo. Para levantarlo:
  `php artisan serve` y navegar a `http://demo.localhost:8000`.
- ~~«Las capturas de pantalla se agotan por tiempo»~~. **Falso desde el
  2026-08-23: funcionan.** Salen topadas en 800×500, sin recorte por región, y
  su geometría NO corresponde a la del DOM —sirven para ver si algo se ve roto,
  no para medir—. Para medir sigue mandando `javascript_tool`. Con ellas se
  corrieron la primera ronda de revisión y sus cuatro correcciones (ver el
  estado).
- **Lo verificado en el navegador, hasta hoy**: el panel; el 2026-08-22 el
  recorrido entero de la bolsa de trabajo —el tablero del alumno con el
  interruptor en sus dos posiciones, postularse desde el portal, capturar por
  ventanilla eligiendo carrera, mover de etapa y leer la bitácora, el ajuste
  guardándose desde `/plataforma/configuracion`, registrar la contratación,
  editar y deshacer una colocación viendo cómo la postulación vuelve a su etapa,
  y el indicador con y sin filtro—; y el 2026-08-19 el
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
