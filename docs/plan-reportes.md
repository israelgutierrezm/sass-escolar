# Módulo 14 · Reportes — plan de arquitectura

> Documento de trabajo. Nace del pedido del cliente del 2026-08-25: historial en
> PDF con diseñador, y un módulo de reportes por área con filtros, columnas
> dinámicas y exportación a Excel/PDF.
>
> El plan salió de tres arquitecturas independientes juzgadas por un panel de
> tres lentes (encaje con el proyecto, seguridad, y cumplir lo pedido); ganó la
> híbrida por 3-0. Los hechos de la sección 0 se comprobaron contra el árbol y
> contra la base del demo ANTES de escribirlo — incluidos cuatro defectos reales
> que este plan destapa y que se listan en la sección 9.
>
> **ESTADO AL 2026-08-26: las rebanadas 0 a 7 están CONSTRUIDAS y en producción.**
> Quedan la 8 (en curso), la 9 y la 10. Esta línea decía «nada de esto está
> implementado todavía» con las ocho ya entregadas, que es exactamente la trampa
> que `docs/plan-migraciones.md` se cobró en su día: un plan sin tachar manda a
> reconstruir lo que ya existe. Al cerrar una rebanada, **tacharla aquí el mismo
> día**.
>
> Lo que se construyó **distinto de lo escrito** está anotado en cada rebanada
> con `[~]`. Lo que el plan pedía y NO se construyó está anotado con `[ ]`
> **dentro de la rebanada que lo prometía**, no al final: un pendiente escrito
> lejos de su promesa no se encuentra.

## 0. HECHOS VERIFICADOS QUE CONDICIONAN EL PLAN

Comprobados uno por uno en el árbol limpio, no citados de memoria:

| Hecho | Dónde | Consecuencia |
|---|---|---|
| **No hay librería PDF.** `composer.json` sólo trae `phpoffice/phpspreadsheet: ^5.9`. mpdf/dompdf aparecen en `composer.lock` únicamente como `suggest` de PhpSpreadsheet | `composer.json`, `composer.lock` | El PDF exige decisión explícita (§5) |
| **`descargarExcel` está duplicado literal**, carácter por carácter | `app/Http/Controllers/Emision/LoteCertificacionController.php:499-518` y `app/Http/Controllers/Emision/LoteTitulacionController.php:610-629` | Extracción obligatoria antes de la tercera copia |
| Ambas copias usan `tempnam(sys_get_temp_dir(), 'xls').'.xlsx'` y `setAutoSize(true)` en bucle sobre todas las columnas | mismas líneas | Fuga de temporales + costo de guardado |
| **Ojo, corrección a lo que decían las propuestas 1 y 2**: esas dos copias SÍ usan `Coordinate::stringFromColumnIndex()` correctamente. El `chr(ord('A') + $i)` roto está SÓLO en encuestas | `app/Services/Encuestas/ExportaResultados.php:198` y `:202` | Se arregla ahí, no en el exportador de lotes |
| **`AcotaPorCampus` recibe `Request`** y tiene TRES métodos de acotado: `acotarMatriculas:50`, `acotarPorCampusPropio:73`, `acotarPorCampusRelacionado:87`. Más `autorizarCampus:112`, `autorizarMatricula:127`, `exigirCampusPropios:143` | `app/Http/Controllers/Concerns/AcotaPorCampus.php` | El 4.º camino ya se escribió A MANO fuera del trait |
| **El 4.º camino (adscripciones) está inline en RH** | `app/Http/Controllers/RH/EmpleadoController.php:70` — `whereHas('adscripciones', fn ($a) => $a->whereIn('campus_id', $campus))` | Prueba viva de la divergencia que la extracción evita |
| Usan el trait 12 controladores + `VeLaCarteraDelAlumno` | 14 archivos con el import | La extracción debe ser delegación, no reescritura |
| **`saldosPorMatricula` y su gemela YA divergen.** `FinanzasController.php:422` lleva `whereNotNull('a.matricula_oferta_id')`; `app/Panel/Tarjetas/CarteraDeLaEscuela.php:69-76` NO lo lleva | dos archivos | La tarjeta del panel suma también los adeudos de ASPIRANTES y la pantalla no: **hoy dan números distintos** |
| **`scopeFaltas` está roto y muerto**: filtra por `AUSENTE = 'ausente'` | `app/Models/Asistencia/AsistenciaClase.php:25` y `:67-70`; lo guardado es `'falta'` (`app/Http/Controllers/PaseListaController.php:33`) | Un reporte de inasistencias devolvería CERO |
| **Dos promedios oficiales** | `app/Services/HistorialDelAlumno.php:209` (mejor intento, una matrícula, redondeo del plan) contra `app/Services/EstadoDelAlumno.php:58` (todos los renglones, todas las matrículas) | Hay que elegir por escrito |
| **`RegistroTarjetas::para()` no comprueba el módulo**: filtra permiso y `TarjetaRol.activas`, nada más | `app/Panel/RegistroTarjetas.php:40-95` | Una tarjeta de reportes debe inyectar `ModulosDeLaEscuela` ella misma |
| `ModulosDeLaEscuela::activo()` **falla cerrado**: sin fila = apagado | `app/Services/Plataforma/ModulosDeLaEscuela.php:29-32` | El módulo nace encendido desde la migración |
| Patrón exacto para registrar módulo encendido e idempotente | `database/migrations/tenant/2026_08_09_110000_registrar_modulos_biblioteca_y_servicios.php` | Se calca |
| **La firma y el sello se piden por URL autenticada** | `resources/views/impresion/historial.blade.php:327` y `:338` → `routes/tenant.php:1138-1139`, dentro del grupo con sesión aunque fuera de `can:gestionar-historial` | Un motor PHP no lleva la cookie |
| `imagen()` ya lee del disco privado | `app/Http/Controllers/DisenoHistorialController.php:106-115` | Es el código que se reusa |
| **El blade tiene 8 reglas de flex/grid** en el impreso | líneas 50, 66, 86, 92, 104, 144, 155, 187 | Ni mpdf ni dompdf las entienden |
| **No hay foliado**: cero `counter(page)`, `@page` sólo en la 25 con `margin` fijo; `font-family` en la 37 **sin un solo `@font-face`**; marca de agua por `position: fixed` en la 183 | mismo archivo | Los tres defectos que el cliente llama "no sirve" |
| **Los anchos suman ~135 %** con las 12 columnas puestas: 4+10+38+9+10+7+7+8+10+10+10+12 | `app/Historial/CatalogoColumnas.php:33-105`, salen a `style="width: {{ }}%"` en el blade:271 | Se normaliza ANTES de cambiar de motor |
| `HistorialImprimibleTest` tiene **12 casos**; tres miran los bloques a dos columnas (`:162`, `:176`, `:191`) | `tests/Feature/HistorialImprimibleTest.php` | La reescritura de maqueta los pone en riesgo |
| `armarEjemplo()` genera 6 periodos y escribe literal `'materias_cursadas' => 36` | `app/Historial/HistorialImprimible.php:88-110` | La vista previa nunca llega a la hoja 2 |
| `Compositor::dibujarTexto()` **nunca lee `$caja['alto']`** y `renglones()` parte sólo por `preg_split('/\s+/')`, aceptando una palabra que no cabe | `app/Credencial/Compositor.php:166-199` y `:223-250` | El compositor de la credencial NO se reusa para un historial (hay CURP y folios sin espacios) |
| `EditorCajasCredencial.vue` existe y es reutilizable | `resources/js/Components/EditorCajasCredencial.vue` | Sí se reusa para las zonas fijas |
| `Ajuste` es `final readonly class` con argumentos nombrados | `app/Configuracion/Ajuste.php:16-39` | Es el molde de `ColumnaReporte`/`FiltroReporte` |
| `DisenoHistorial::columnasEfectivas()` filtra contra el catálogo AL LEER y nunca devuelve vacío | `app/Models/ControlEscolar/DisenoHistorial.php:88-104` | Es el molde del saneador del motor |

---

## 1. MODELO DE DATOS FINAL

Todas TENANT, `snake_case` plural en español, `$table->auditoria()` y trait `TieneAuditoria`. Modelos en `App\Models\Reportes\`.

### 1.1 `areas_reporte` — TENANT-CONFIG, con seeder

```
id
clave            string(50) UNIQUE      no se edita nunca
nombre           string(80)             esto es lo que se renombra
descripcion      string(255) null
icono            string(1000) null      trazo `d` de heroicon, como TarjetaPanel::icono()
orden            unsignedSmallInteger default 0
activo           boolean default true
auditoria()
```

Es el punto 4 del cliente. Pasa la prueba de la regla 4: una fila nueva **hace algo** — aparece como sección con sus reportes dentro, se ordena, se apaga. Es el caso `modalidades_percepcion`, no el caso `tipos_actividad`.

**NO lleva columna `permiso` ni `modulo`, y va escrito en el docblock y en la pantalla.** Un área es una carpeta; el permiso y el módulo los declara la FUENTE. Si el área filtrara, arrastrar un reporte de finanzas a un área llamada "Dirección" concedería acceso a la cartera. Una prueba lo fija: mover un reporte de área y comprobar que sigue exigiendo el mismo permiso.

Se **apaga**, no se borra, cuando tiene reportes; el borrado sólo con área vacía y nombrando la salida ("apágala"), patrón de los catálogos de disciplina.

Seeder con once áreas **borrables**: control escolar, admisiones y promoción, finanzas, docentes, LMS, familia y tutores, certificación y titulación, recursos humanos, bolsa de trabajo, movilidad, general.

### 1.2 `ubicaciones_reporte` — dónde vive cada reporte

```
id
reporte          string(80)             la CLAVE de la clase. SIN FK: apunta a código
area_id          FK areas_reporte cascadeOnDelete
nombre           string(120) null       renombre local; null = el que declara la clase
orden            unsignedSmallInteger default 0
activo           boolean default true
auditoria()
UNIQUE (reporte, deleted_at)  →  `ubicacion_por_reporte`
```

Un reporte vive en **un** área a la vez: con dos, "muévelo de área" no tiene respuesta única. **Sin fila**, cae al `areaSugerida()` que declara la clase: un reporte nuevo aparece solo en su sitio sin sembrar nada (mismo criterio que `fusionarFaltantes` del menú).

`nombre` porque una escuela dice "Kárdex" donde el catálogo dice "Historial académico". Se renombra la ETIQUETA; la CLAVE nunca, porque la guardan vistas, favoritos, programaciones y la bitácora.

El `UNIQUE` incluye `deleted_at` porque `TieneAuditoria` borra en lógico y el soft delete no libera un único (igual que `credenciales_rol`).

Y una regla que ninguna propuesta tenía: **si la ubicación apunta a un área apagada, el reporte cae al área declarada por su clase**, no desaparece. Apagar un área no puede esconder reportes.

### 1.3 `vistas_reporte` — la capa 2, y la respuesta real al punto 5

```
id
reporte           string(80)
nombre            string(120)
descripcion       string(255) null
columnas          json                lista ORDENADA de claves
filtros           json                mapa clave => valor
orden_por         string(60) null
orden_dir         string(4) default 'asc'
agrupar_por       string(60) null
totales           json null           claves de columnas a totalizar
formato_preferido string(10) null
persona_id        FK personas nullOnDelete    dueño; null = de la escuela
rol_id            FK roles nullOnDelete       compartida a ese rol
compartida        boolean default false
predeterminada    boolean default false
auditoria()
UNIQUE (persona_id, reporte, nombre, deleted_at)
```

JSON y no tabla hija por el criterio ya escrito en `credenciales_rol.campos_anverso` y `disenos_historial.columnas`: se lee y se escribe SIEMPRE completa, nunca se ordena ni se filtra por una columna suelta. Una tabla hija pagaría un JOIN y una migración por cada atributo nuevo a cambio de nada.

La lista de columnas es **ordenada**: en un reporte el orden de columnas es la mitad del diseño.

**Se filtra contra el catálogo AL LEER**, no sólo al escribir. Una vista de hace un año puede nombrar una columna retirada; se ejecuta igual, sin esa columna.

**Regla de seguridad, en el docblock**: una vista guarda FILTROS y COLUMNAS, jamás filas. Al ejecutarla se rehace el pipeline entero con el permiso, el rol activo y el alcance de QUIEN LA EJECUTA. Compartir una vista no comparte datos.

**No se congela con el uso**, al revés que `formularios`: un reporte se ejecuta, no se responde; no hay nada que quede mintiendo.

### 1.4 `ejecuciones_reporte` — desde la rebanada 1, no desde la 8

```
id
reporte            string(80)
vista_id           FK vistas_reporte nullOnDelete
persona_id         FK personas nullOnDelete
rol_id             unsignedBigInteger null      CONGELADO, sin FK: el rol puede borrarse
formato            string(10)                   pantalla|xlsx|csv|pdf
filtros            json
columnas           json
columnas_omitidas  json null                    las que se recortaron por permisos
filas              unsignedInteger null
duracion_ms        unsignedInteger null
estado             string(20)                   ok|vacio|truncado|error
mensaje            text null
ip                 string(45) null
auditoria()
índices (reporte, created_at) y (persona_id, created_at)
```

Append-only por disciplina. Tres razones y las tres pesan: (1) es la única forma de contestar "quién se llevó el padrón de 900 alumnos y con qué filtros"; (2) `duracion_ms` encuentra el reporte que un día empieza a tardar cuarenta segundos; (3) es el insumo que decide si el constructor del punto 5 se construye (§7).

`columnas_omitidas` es lo que permite explicar después por qué dos corridas del mismo reporte trajeron distinto número de columnas.

**NO guarda el archivo generado.** Sería un almacén de datos personales creciendo sin política de retención. Ajuste `reportes.dias_bitacora` (365 por omisión) y comando `reportes:purgar-ejecuciones` en `routes/console.php` **desde el primer día**, junto a `finanzas:generar-cargos`.

### 1.5 `reportes_favoritos`

> **[ ] Se construyó SIN `vista_id`** (y sin `orden`). Un favorito apunta hoy
> sólo al reporte, no a «la cartera con mis columnas». No se agregó al escribir
> la tarjeta «Mis reportes» del panel porque tampoco hay forma de marcar un
> favorito CON vista desde la pantalla: la columna nacería sin quien la escriba,
> que es lo que este proyecto ya tuvo que retirar cinco veces. Llega el día que
> la pantalla la escriba.

```
id
persona_id  FK personas cascadeOnDelete
reporte     string(80)
vista_id    FK vistas_reporte nullOnDelete
orden       unsignedSmallInteger default 0
auditoria()
UNIQUE (persona_id, reporte, deleted_at)
```

Apunta opcionalmente a una VISTA, para que el favorito sea "la cartera con mis columnas" y no "la cartera". Rebanada tardía a propósito.

### 1.6 `programaciones_reporte` + `destinatarios_reporte`

```
programaciones_reporte
id
vista_id           FK vistas_reporte cascadeOnDelete
nombre             string(120)
persona_id         FK personas                dueño
rol_id             FK roles NOT NULL          el rol con cuyo alcance CORRE
frecuencia         string(20)                 diaria|semanal|mensual
dia                unsignedTinyInteger null
hora               time
formato            string(10)
activa             boolean default true
suspendida_en      datetime null
motivo_suspension  string(255) null
ultima_corrida_en  datetime null
ultimo_estado      string(20) null
ultimo_error       text null
auditoria()

destinatarios_reporte
id
programacion_id  FK programaciones_reporte cascadeOnDelete
tipo             string(20)                  persona|rol|correo
destino_id       unsignedBigInteger null     SIN FK: apunta a tablas distintas
correo           string(150) null
auditoria()
```

`rol_id` **obligatorio** y no derivado: una corrida programada no tiene rol activo, y el alcance por campus sale precisamente de ahí (`Usuario::campusVisibles()`). Sin fijarlo habría que elegir entre no correr o mandar por correo la escuela entera a quien sólo ve un campus. Si al dueño le retiran el rol o el permiso, la programación se **suspende con su motivo escrito** — nunca se degrada a otro alcance.

`destino_id` sin FK, mismo patrón que `evento_destinos` y `avisos_destinos`: permite agregar "por campus" mañana sin migrar; lo que apunte a algo borrado se muestra como "Ya no existe".

`tipo = correo` (el contador externo, sin cuenta) **recibe un enlace que exige sesión, no el adjunto**.

Cuelga de la VISTA y no del reporte: programar exige haber decidido antes columnas y filtros.

---

## 2. CÓMO SE DECLARA UN REPORTE

Todo en `app/Reportes/`. El molde es `App\Panel\` + `App\Configuracion\`.

### 2.1 La fuente

```php
namespace App\Reportes;

interface FuenteDeReporte
{
    /** Estable: la guardan vistas, ubicaciones, favoritos y la bitácora. */
    public function clave(): string;

    public function titulo(): string;

    /**
     * QUÉ ES UNA FILA, en palabras, y se muestra en la pantalla.
     * «Una fila de este reporte es: una matrícula.»
     * Es lo que impide que alguien lea «alumnos: 28» cuando son
     * las 28 materias de una alumna.
     */
    public function grano(): string;

    /** UNO. Si dos oficios entran, se declara un derivado con Gate::define. */
    public function permiso(): string;

    /** El módulo apagable del que depende ADEMÁS de `reportes`. */
    public function modulo(): ?string;

    /** @return array<int, string> facetas de CatalogoPermisos. v1: [ADMINISTRATIVO] */
    public function facetas(): array;

    /** OBLIGATORIO. Sin valor por omisión. */
    public function recorte(): Recorte;

    /** @return array<string, ColumnaReporte> */
    public function columnas(): array;

    /** @return array<string, FiltroReporte> */
    public function filtros(): array;

    /**
     * El Builder ya construido por la fuente, con su eager loading COMPLETO.
     * El motor le aplica encima el recorte, los filtros y el orden.
     * @param array<string, mixed> $filtros ya saneados y validados
     */
    public function consulta(Usuario $usuario, array $filtros): Builder;

    /** Columna de desempate estable y llave del keyset. Ej. 'matricula_oferta.id'. */
    public function llavePrimaria(): string;
}
```

### 2.2 El reporte es un PRESET sobre la fuente (injerto de la propuesta 1)

Esto es lo que evita cuarenta clases de consulta. "Alumnos inscritos", "Bajas del ciclo" y "Egresados por generación" son **la misma fuente** `matriculas` con tres presets.

```php
abstract class DefinicionReporte
{
    abstract public function clave(): string;
    abstract public function titulo(): string;
    abstract public function descripcion(): string;   // qué contesta Y QUÉ NO
    abstract public function fuente(): string;        // clave de la fuente

    /** Área por omisión; la escuela puede moverlo. */
    public function areaSugerida(): string { return 'general'; }

    /**
     * Filtros que el reporte impone y quien lo ejecuta NO puede aflojar.
     * Es lo que separa un REPORTE de un listado.
     * @return array<string, mixed>
     */
    public function filtrosFijos(): array { return []; }

    /** @return array<int, string>|null null = las de la fuente */
    public function columnasPorOmision(): ?array { return null; }

    /** @return array{0: string, 1: string}|null ['clave', 'asc'] */
    public function ordenPorOmision(): ?array { return null; }

    /** Filtros que el motor se NIEGA a ejecutar sin valor. */
    public function filtrosObligatorios(): array { return []; }
}
```

### 2.3 Los objetos de valor — molde `App\Configuracion\Ajuste`

```php
final readonly class ColumnaReporte
{
    public function __construct(
        public string $clave,
        public string $etiqueta,
        public TipoDato $tipo,              // enum: texto|entero|decimal|dinero|
                                            // fecha|fecha_hora|booleano|porcentaje
        public ?Closure $valor = null,      // fn(Model): mixed — resuelve la celda
        public ?string $columnaSql = null,  // LITERAL escrito por un programador
        public bool $ordenable = false,
        public bool $agrupable = false,
        public ?Agregacion $agregable = null,  // enum: suma|promedio|cuenta|min|max
        public bool $sensible = false,
        public ?string $permisoExtra = null,   // reusa el permiso que YA exista
        public int $ancho = 16,                // sugerencia en caracteres
        public string $alineacion = 'izquierda',
        public ?string $ayuda = null,
    ) {
        // Doble red: la definición no la escribe nadie de fuera Y AUN ASÍ se valida
        // su forma, al construirse, o sea al arrancar y no en producción.
        if ($columnaSql !== null && ! preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/', $columnaSql)) {
            throw new \InvalidArgumentException("columnaSql inválida: {$columnaSql}");
        }
    }
}

final readonly class FiltroReporte
{
    public function __construct(
        public string $clave,
        public string $etiqueta,
        public TipoFiltro $tipo,         // enum: texto|numero|rango_numero|fecha|
                                         // rango_fecha|lista|lista_multiple|booleano
        public Closure $aplicar,         // fn(Builder, mixed): Builder
        public ?Closure $opciones = null,// fn(Usuario): array<valor, etiqueta>
        public ?string $ayuda = null,
    ) {}
}
```

`opciones` recibe el `Usuario` y **lee el catálogo VIVO, nunca filas guardadas**: un campus nuevo aparece solo, y el desplegable de campus sólo ofrece los suyos.

`permisoExtra` **reusa el permiso que ya existe** (injerto de la 2): el salario pide `gestionar-percepciones`, que es literalmente el permiso que hoy separa quién ve cuánto gana cada quien. Sólo para los identificadores personales que hoy no tienen guardián se declara uno nuevo: `exportar-datos-personales`.

### 2.4 `Recorte` — obligatorio, sin valor por omisión

```php
final readonly class Recorte
{
    private function __construct(
        public string $modo,
        public array $args,
        public ?string $razon,
    ) {}

    public static function porOferta(?string $relacion = null): self;   // adeudos, historial, colocaciones
    public static function porColumnaPropia(string $col = 'campus_id'): self; // aspirantes, grupos, aulas
    public static function porRelacion(string $rel = 'campus'): self;   // docentes (m2m)
    public static function porAdscripcion(): self;                      // RH
    public static function porCicloCampus(string $rel = 'ciclo'): self; // ciclos
    public static function sinCampus(string $razon): self;              // obliga a escribir el porqué

    public function aplicar(Builder $q, ?array $campus): Builder;
}
```

**Un `whereIn('campus_id', …)` genérico filtra a CERO en cuatro de los cinco caminos**, y una fuente sin recorte no filtraría NADA y enseñaría la escuela entera — el fallo silencioso peor de los dos. Aquí olvidarlo es no implementar un método abstracto: error al construir, no filtración.

`sinCampus` **sólo lo ejecuta quien tiene alcance global** (`campusVisibles() === null`). A un rol acotado se le NIEGA el reporte con su razón escrita, en vez de darle la escuela entera. Falla cerrado.

### 2.5 El registro — calcado de `RegistroTarjetas`

```php
class RegistroReportes
{
    public function registrarFuente(string $clase): void;   // class-string<FuenteDeReporte>
    public function registrarReporte(string $clase): void;  // class-string<DefinicionReporte>

    /** Agrupado por área, ya filtrado por permiso, módulo y faceta del rol activo. */
    public function para(Usuario $usuario): array;

    public function fuente(string $clave): FuenteDeReporte;      // 404 si no existe
    public function definicion(string $clave): DefinicionReporte; // 404 si no existe
}
```

Se puebla en `AppServiceProvider`, junto a `registrarTarjetasDelPanel()`. El controlador no conoce ningún reporte concreto: un reporte nuevo aparece solo en su área.

---

## 3. CÓMO SE EJECUTA — `App\Reportes\Ejecutor`

Un solo camino para pantalla, XLSX, CSV y PDF. Divergir aquí es cómo se llega a que el Excel y la pantalla digan números distintos.

1. **Resolver** la clave del reporte contra el registro. Desconocida → **404, no 403**: un 403 ya confirma que existe.
2. **Permiso**: `Gate` sobre `$fuente->permiso()`. La ruta ya lo comprobó con `can:`; ésta es la segunda red, la que cubre las corridas programadas.
3. **Módulo**: `ModulosDeLaEscuela::activo($fuente->modulo())` → 404 si apagado. Además del `modulo:reportes` de la sección.
4. **Faceta**: la del rol activo tiene que estar en `$fuente->facetas()`, o 404. Una faceta sin recorte declarado no ve la fuente.
5. **Sanear contra el catálogo** — molde `columnasEfectivas()`:
   - columnas ∩ catálogo; las inexistentes se descartan **en silencio** (una vista vieja no debe reventar). Si queda vacío → `columnasPorOmision()`. Nunca null, nunca vacío.
   - filtros ∩ catálogo; los inexistentes se descartan.
   - `orden_por` se busca por clave y se comprueba `ordenable`; si no, cae al por omisión.
   - `orden_dir` con `Rule::in(['asc','desc'])`, caída a `asc`.
   - `por_pagina` entero acotado.
6. **Columnas sensibles**: las que declaran `sensible` o `permisoExtra` que el usuario no tiene **se omiten y se anotan**. Ni se aborta (dejaría inútil un reporte compartido) ni se calla.
7. **Filtros fijos ENCIMA de los del usuario**: los del preset ganan siempre y no se pueden pisar. Los `filtrosObligatorios()` sin valor **detienen la ejecución** con su mensaje — un reporte de historial sin ciclo barrería la escuela entera.
8. **Validar valores por TIPO**, no por cadena: `entero`→`integer`, `fecha`→`date`, `lista`→`Rule::in(array_keys($opciones($usuario)))`, `lista_multiple`→array con tope de 500. El desplegable no es una defensa.
9. `$q = $fuente->consulta($usuario, $filtros)`.
10. **El RECORTE lo aplica el MOTOR**, no la fuente. Los ids de campus que vengan del cliente pasan antes por `AlcanceDeCampus::exigirPropios()`, que lanza `ValidationException` — no se ignoran en silencio.
11. **Filtros**, uno por uno, con su closure. Bindings de Eloquent siempre.
12. **Orden** con el literal `columnaSql`, **más desempate estable por `llavePrimaria()`**. Sin él, la página 2 repite filas de la 1 cuando hay empates.
13. **Salida** (§4.5–4.7).
14. **Registrar** en `ejecuciones_reporte`: filas, ms, formato, columnas omitidas, estado.

---

## 4. LAS SEIS RESOLUCIONES CONCRETAS

### 4.1 Filtros múltiples

Todos en **AND**. **A propósito no hay OR ni paréntesis**: un constructor de expresiones booleanas es expresividad que nadie de admisiones va a usar bien, y el proyecto ya rechazó eso mismo en los condicionales de formulario, que son `padre == valor` y nada más. El OR que la gente de verdad quiere ("campus norte O centro") lo da `lista_multiple`, que internamente es `whereIn`.

El **operador es un método de la fuente**, escrito en su closure `aplicar`. Del navegador viaja el VALOR, nunca el operador.

### 4.2 Columnas dinámicas — y por qué NO recortan el SELECT (injerto de la 1)

La vista manda una lista ordenada de claves. Se descartan las inexistentes y las sensibles sin permiso; si queda vacía, cae al por omisión.

**Pero el Builder trae el modelo con el eager loading COMPLETO que la fuente declara, y cada celda se resuelve con su closure `valor`.** Es deliberado, y el porqué va escrito en el código: las columnas que no se piden llegan en NULL sin error, y este proyecto ya se cobró ese defecto tres veces — el resumen de `/mi-historial` con `oferta.plan:id,nombre` sin `total_creditos`, el validador de nómina inventando faltantes, y la bandera del buscador de candidatos. Elegir columnas es **presentación, no SELECT**: así quitar una columna de la vista nunca puede cambiar un total. Se paga traer campos que no se pintan; las fuentes pesadas declaran su propio `select` fijo **y completo**.

### 4.3 El GRANO y la multiplicación de filas (injerto de la 2)

Es el defecto que hunde estos motores y **no da error: da otro número**. Con 1016 renglones de historial sobre 32 matrículas en el demo, un `leftJoin historial` sobre una fuente de matrículas convierte a una alumna de 48 materias en 48 filas y el conteo de "alumnos" dice 48.

Tres reglas, escritas en el docblock de `FuenteDeReporte` y fijadas con prueba:

- Cada fuente declara `grano()` en palabras y la pantalla lo muestra.
- **Una relación a-muchos nunca se ofrece como columna suelta.** Sólo como agregación, resuelta con **subconsulta correlacionada** (`selectSub`), jamás con join.
- **Todos los joins son LEFT, nunca INNER.** Un INNER cambia el UNIVERSO — "alumnos" pasaría a ser "alumnos que tienen X" — y ése es un error de conteo que no avisa.

Si de verdad hace falta el detalle, eso es OTRA fuente, con otro grano.

### 4.4 Alcance por campus — prerrequisito de la rebanada 1

Extraer el trait a servicio, recibiendo `Usuario` en vez de `Request`:

```php
namespace App\Services;

final class AlcanceDeCampus
{
    public function de(?Usuario $u): ?array;   // null = TODOS, no «ninguno»
    public function matriculas(Builder $q, ?array $campus, ?string $relacion = null): Builder;
    public function columnaPropia(Builder $q, ?array $campus, string $col = 'campus_id'): Builder;
    public function relacionado(Builder $q, ?array $campus, string $rel = 'campus'): Builder;
    public function adscripcion(Builder $q, ?array $campus): Builder;      // NUEVO: hoy vive inline
    public function cicloCampus(Builder $q, ?array $campus, string $rel = 'ciclo'): Builder; // NUEVO
    public function exigirPropios(?Usuario $u, array $enviados, string $campo = 'campus'): void;
}
```

`AcotaPorCampus` **delega** y conserva sus firmas públicas: los 12 controladores que lo usan no se tocan. `RH/EmpleadoController.php:70`, que hoy tiene el 4.º camino escrito a mano fuera del trait, pasa a usar `adscripcion()` — que es exactamente la divergencia que se viene a evitar, y ya está pasando.

Sin la extracción, el motor tendría una segunda regla de campus (una corrida programada no tiene `Request`), y así es como divergieron `NavAcademico` y `NavEscolar`.

### 4.5 XLSX

`App\Reportes\Salida\ExportadorXlsx` sobre `App\Services\Excel\Exportador` — el namespace ya existe (`ImportadorBase`, `PlantillaBase`).

- Escribe a `php://output` dentro de `response()->streamDownload()`. **Nunca `tempnam`**: elimina de raíz la fuga y el problema del prefijo recortado a tres letras en Windows.
- **Tope duro comprobado ANTES de armar nada**, configurable con `reportes.tope_filas_xlsx` (por omisión 5 000), declarado en `CatalogoAjustes` con su CONSECUENCIA.
- El mensaje va **antes de empezar** y dice la cifra real y la salida: *"Este reporte trae 32 400 filas; en Excel el tope son 5 000. Descárgalo en CSV o acota los filtros."* No un `Allowed memory size exhausted` a los tres minutos.
- **Anchos fijos, no `setAutoSize` masivo** (es lo que hoy hacen las dos copias duplicadas).
- **Formato de celda de verdad por `TipoDato`**: moneda con dos decimales, fecha como FECHA, porcentaje. Sin esto, "ordenar por fecha de ingreso" en el Excel ordena alfabéticamente y nadie entiende por qué — y no da ningún error.
- Recorrido por **keyset**, no `chunkById` (§4.7).

### 4.6 CSV

`fputcsv` contra `php://output` dentro de `streamDownload`, **saltándose PhpSpreadsheet** (su writer `Csv` también exige el `Spreadsheet` completo en memoria). Memoria constante.

**BOM UTF-8 y separador `;`**, o "Gutiérrez" sale roto y todo cae en una columna del Excel español — y ninguna de las dos cosas da error.

### 4.7 Volumen: keyset, no `chunkById` (injerto de la 2)

`chunkById` **REEMPLAZA el ORDER BY del usuario por el de la PK**: un CSV "ordenado por fecha de ingreso" sale ordenado por id, sin error y sin que nadie lo note — justo cuando el usuario acaba de elegir un orden.

Paginación por **keyset con comparación de tuplas** sobre (columnas de orden, `llavePrimaria()`): `WHERE (a, b) > (?, ?)`, que MySQL 8 soporta. Respeta el orden pedido, no descuadra si los datos cambian a media descarga, y consume memoria constante.

En pantalla, `paginate()->withQueryString()`.

### 4.8 Totales y agrupado

Sólo las columnas que declaran `agregable` ofrecen total: promediar una matrícula no significa nada, y un total ofrecido sobre una columna que no lo admite es una cifra que alguien va a citar.

**El total sale de una consulta agregada aparte sobre el MISMO builder ya recortado y sin paginar.** Es la lección literal de `FinanzasController::index():135-138`: un total sacado de la página diría "la cartera son 40 mil" cuando son los 40 mil de los 25 que se están viendo. Y es además una FUGA: un total sin recortar filtra la cifra de toda la escuela debajo de una lista acotada — el número más visible de la pantalla y el más fácil de dar por bueno.

Gráfica: **sólo en pantalla, sólo en modo agrupado, y una sola barra horizontal** reutilizando el tipo `barras` que el panel ya sabe pintar. Cero configuración, cero librería. Más que eso no lo va a usar nadie.

### 4.9 Aviso de columnas omitidas (injerto de la 2)

El documento generado lo **confiesa**: encabezado del PDF y una fila del XLSX/CSV con *"2 columnas omitidas por permisos: CURP, NSS."* Un archivo que sale sin CURP porque quien lo generó no tenía el permiso, y que no lo dice, se manda a la SEP tal cual creyéndolo completo.

### 4.10 Catálogos LANDLORD: nunca JOIN

> **[ ] NO construido.** `TipoDato` no tiene `CatalogoLandlord` —hoy son ocho:
> texto, entero, decimal, dinero, fecha, fecha_hora, booleano y porcentaje— y
> ninguna de las 14 fuentes ofrece agrupar ni filtrar por un catálogo de la base
> central. No estorbó porque las fuentes de la rebanada 7 resolvieron esos
> nombres con closures `valor:`, que traducen en PHP igual que aquí se pedía;
> lo que falta es poder AGRUPAR por ellos, y eso lo decide la rebanada 8.
> **Ojo si se retoma**: agrupar por `sexo_id` sobre una fuente de personas es
> justo el caso donde un grupo de una sola fila identifica a alguien.

`personas.sexo_id`, `genero_id`, `pais_nacimiento_id`, `entidad_nacimiento_id`, `campus.entidad_id` apuntan a la base central y esas tablas **no existen en el tenant**. Se declaran con `TipoDato::CatalogoLandlord` + la clase del modelo con `CentralConnection`; el motor trae el id y traduce en PHP con un mapa cargado una vez.

- **Filtrar** traduce el valor a ids ANTES del `whereIn`.
- **Agrupar** sí (matrícula por sexo: se agrupa por id y se traduce al final).
- **Ordenar NO**, y la columna lo declara: ordenar en PHP sólo la página produce un orden distinto en cada página, peor que no ofrecerlo.

Trampa a repetir en el docblock: **`niveles_estudio` existe en LAS DOS bases con ids incompatibles** y leer la equivocada **no falla** — devuelve otro nombre. El modelo bueno es `App\Models\Academico\NivelEstudio`. Y `entidades_federativas` (LUGARES) no es `identidades_federativas` (PERSONAS).

---

## 5. EL HISTORIAL EN PDF Y SU DISEÑADOR

### 5.1 La queja del cliente es medible, no una impresión

Lo verificado en `resources/views/impresion/historial.blade.php`:

- El membrete es un bloque normal dentro de `<main>`: **se imprime UNA vez**. La hoja 2 de un historial de egresada sale sin nombre de escuela y sin nombre de alumno.
- **No hay foliado**: cero `counter(page)`, `@page` sólo en la 25 con `margin: 14mm 12mm` fijo. Lo que ponga el navegador lo controla el usuario en su diálogo.
- La marca de agua es `position: fixed` (línea 183) y **ninguna de las 12 pruebas de `HistorialImprimibleTest` la mira**: que hoy se repita en la hoja 2 es una suposición sobre el navegador, no un hecho verificado en este repo.
- `font-family` en la 37 **sin un solo `@font-face`**: el PDF que Chrome guarda embebe lo que resolvió en ESA máquina; el mismo historial no es idéntico entre dos ventanillas.
- La apariencia depende de la casilla "Gráficos de fondo", apagada por omisión en Chrome, que borra el fondo de los títulos de grupo.

### 5.2 La decisión de CLAUDE.md se REVOCA, y con este argumento

CLAUDE.md dice, del acta y del historial: *"En Blade y no en PDF generado: el proyecto no tiene librería de PDF y el navegador ya sabe imprimir."*

**Se revoca sólo para el historial y para los reportes; el acta se queda como está.** El argumento original era de COSTO ("no hay librería"), no de diseño, y era correcto mientras el documento cabía en una hoja. Un acta cabe. Un historial de egresada son tres hojas, y las tres cosas que le faltan —membrete por hoja, "Hoja X de Y", marca de agua en todas— **no son cuestión de esfuerzo: el navegador no las puede dar**, porque el CSS de paginación que las daría (cajas de margen `@page`, `counter(page)`, contenido repetido de encabezado) tiene soporte inexistente o inconsistente en los motores de impresión de los navegadores. El "no" no se puede sostener con más trabajo.

Lo que **no** se revoca: la copia de VENTANILLA sigue en Blade con estilos en línea. Su argumento sigue en pie palabra por palabra — *"que un fallo de assets no deje sin forma el historial de alguien en el mostrador"*.

**mpdf y no dompdf**, y no por preferencia: las tres cosas que faltan son nativas en mpdf (`<htmlpageheader>`/`<htmlpagefooter>`, `{PAGENO}`/`{nbpg}`, `SetWatermarkText` con ángulo y opacidad) y frágiles o inexistentes en dompdf, donde el folio exige habilitar `isPhpEnabled` — ejecutar PHP dentro de la plantilla — y la marca depende de un soporte parcial de `transform`. gd y mbstring ya están cargadas en este PHP 8.3.6.

**Se anota en `docs/decisiones.md` con esta redacción**, o la próxima sesión lee CLAUDE.md y deshace el cambio.

### 5.3 Tres cosas ANTES de escribir una línea de maqueta

1. **Firma, sello y logo se leen de `Storage::disk('local')` y se incrustan como `data:` URI.** Hoy el blade los pide por `route()` (líneas 221, 327, 338) y la ruta de la imagen está dentro del grupo autenticado (`routes/tenant.php:1138-1139`). Un motor PHP no lleva la cookie: recibe el redirect al login y **el primer historial oficial sale sin firma y sin sello, sin ninguna excepción**. `DisenoHistorialController::imagen():106-115` ya lee del disco; es ese código el que se reusa.
2. **`tempDir` explícito** en `storage/app/mpdf`, o en WAMP falla por permisos con un síntoma que no señala la causa — la misma clase de trampa que `curl.cainfo` y `public/hot`.
3. **Vista NUEVA**, `impresion/historial-pdf.blade.php`, con la maqueta rehecha **con tablas**. Las 8 reglas de flex/grid del impreso (50, 66, 86, 92, 104, 144, 155, 187) se pierden, y con ellas **las dos columnas de bloques**, que la escuela ya configura con `bloques_por_fila` y que cubren 3 de las 12 pruebas. **Ése es el trabajo, no el `composer require`.** Fuente de verdad del diseño para las dos vistas: `disenos_historial` + `CatalogoColumnas`.

Coste que hay que aceptar y decir: mpdf pesa decenas de MB, casi todo fuentes. Y **hay una medición que ninguna propuesta hizo y que va antes de comprometerse**: renderizar un historial real de 300 renglones con mpdf contra el `memory_limit` de 128M. Si no cabe, el PDF del historial se genera en un job — y para eso sólo hay dos precedentes (`ArchivarGrabacion`, `TimbrarFactura`).

### 5.4 El diseñador: qué se concede y qué se niega

**Se NIEGA el editor de cajas sobre la TABLA**, y hay que darle el argumento entero al cliente porque es cierto y ningún producto del mundo puede darle otra cosa: un historial CRECE — siete renglones en primer semestre, trescientos al egresar — y `HistorialDelAlumno::renglones()` no pagina. **No hay coordenada que valga para la fila doscientos**, ni en mpdf, ni en dompdf, ni en Chrome, ni en InDesign. La decisión está escrita en cuatro sitios del repo y sigue siendo cierta.

**Se CONCEDE el editor de cajas sobre las CUATRO ZONAS DE TAMAÑO FIJO**: (1) membrete, (2) bloque de datos del alumno, (3) pie de firmas y (4) **cabecera y pie de PÁGINA** — esta última sólo existe ahora que hay motor paginado, y es la ganancia que trae mpdf. Ahí una caja en porcentaje significa exactamente lo mismo que en la credencial, porque la superficie no crece. Se reutiliza `EditorCajasCredencial.vue` tal cual, incluido el fondo dibujado por el servidor.

**Lo que NO se reutiliza, y es un hallazgo verificado**: `Compositor::dibujarTexto()` (`app/Credencial/Compositor.php:166-199`) **nunca lee `$caja['alto']`**, así que el texto crece hacia abajo sin tope y nada lo recorta salvo el borde del lienzo; y `renglones()` (`:223-250`) parte sólo por `preg_split('/\s+/')` y acepta una palabra que no cabe. En una credencial no muerde porque los valores son de largo acotado; en un historial hay CURP y folios sin espacios, y se saldrían por la derecha sin error.

### 5.5 Los ocho huecos del diseñador, en orden de cuánto duelen

1. **Ancho y alineación por columna.** Es lo que más se ajusta en un historial real. Hoy están cableados en `CatalogoColumnas::columnas():33-105` y salen a `style="width: X%"` (blade:271); con las 12 columnas puestas **suman ~135 %**. `disenos_historial.columnas` pasa de `["materia", …]` a `[{"clave":"materia","ancho":38,"alineacion":"izquierda"}]`, con migración que convierte lo viejo y `columnasEfectivas()` aceptando las dos formas mientras dure. **Se hace ANTES de cambiar de motor**, porque cualquier motor con tablas estrictas se comporta peor ante eso que el navegador y se confundiría un defecto viejo con una regresión del cambio.
2. Membrete repetido y "Hoja X de Y": capacidad de la rebanada 5, aquí se vuelven interruptor.
3. **Márgenes** (hoy `14mm 12mm` fijo; quien imprime sobre papel membretado necesita 40 mm arriba).
4. **Tipografía**: familia, tamaño base e interlineado (hoy 10pt fijo en la 38; 7.5/8.5pt a dos columnas).
5. **Salto de página por bloque** ("cada periodo en hoja nueva"). Hoy `bloques_por_fila` es la única palanca de maqueta.
6. **N firmantes.** Hoy hay un solo `responsable_nombre`/`responsable_cargo` y dos alturas cableadas: una escuela con director **y** control escolar no lo puede expresar.
7. **Parámetros de la marca de agua** (ángulo, opacidad, tamaño, mosaico) y poder pedirla también en la copia de **VENTANILLA** — hoy sólo existe `marca_agua_alumno`, así que la de mostrador no puede llevar "COPIA".
8. **Colores del documento** tomados del acento de la escuela, en vez de `#eef2f7` y `rgba(190,30,45,.16)` fijos — en un producto donde el morado cableado ya se retiró de 31 sitios por esa misma razón. Y **aviso de desborde** cuando la suma de anchos pasa de 100.

### 5.6 La vista previa tiene que llegar a la tercera hoja

`HistorialImprimible::armarEjemplo():88-110` genera 6 periodos × 6 materias y el resumen dice literal `'materias_cursadas' => 36`. Una licenciatura son 50-60 materias. **Todo lo de paginación sería inverificable desde la propia pantalla que existe para verificarlo.** Sube a 10 periodos. No es adorno: es requisito. El criterio de datos largos ("María Fernanda Gutiérrez Villaseñor") es el correcto y se conserva; sólo se quedó corto en el eje que importa, que es el ALTO.

### 5.7 El historial NO se convierte en un reporte del motor

Y conviene decirlo. Su agrupación por periodo del PLAN, su regla de "mejor intento por materia", su observación oficial SEP y sus firmas son un **documento**, no una tabla; `HistorialDelAlumno` es la fuente única de esas decisiones de dominio y no se toca. Lo que comparten con Reportes es **infraestructura**: `App\Documentos\DocumentoPdf` y el `Formateador`.

Habrá **además** una fuente de reportes `historial` (grano: *un renglón de historial*) para lo que sí es tabular — índice de aprobación por materia, materias reprobadas por carrera. Son dos cosas distintas a propósito.

---

## 6. ÁREAS RENOMBRABLES Y REPORTES MOVIBLES

Pantalla `/reportes/configuracion`, bajo `can:gestionar-areas-reporte`, con el patrón del catálogo genérico de disciplina.

- **Crear** un área, **renombrarla** (`nombre`, nunca `clave`), **reordenarla**, **apagarla**.
- **Mover** un reporte de área y ordenarlo dentro: intercambio del `orden` con el vecino **en transacción**, reusando literalmente `CampoFormularioController::mover():75-103`.
- **Renombrar un reporte** localmente (`ubicaciones_reporte.nombre`).
- Un área **con reportes no se borra**: se apaga, y el mensaje nombra la salida.
- Índice `/reportes` agrupado por área, con buscador y los favoritos arriba.
- Sección en `resources/js/menu/catalogo.ts` con `modulo: 'reportes'`.

**Escrito en el código y en la pantalla**: aquí se cambia dónde vive y cómo se llama un reporte, **nunca quién puede verlo**. Prueba dedicada: mover el reporte de cartera a un área llamada "Dirección" y comprobar que sigue exigiendo el permiso de finanzas.

---

## 7. EL PUNTO 5 (CONSTRUCTOR) COMO PENDIENTE BIEN ENCAMINADO

El cliente pidió literalmente *"en una sección final, después de implementar lo anterior, como PENDIENTE a revisar"*. Se respeta: **no se construye**. Pero no se deja como intención vaga.

### Lo que se deja PREPARADO hoy, sin construirlo

1. **La arquitectura ya lo admite sin rehacer nada.** Un reporte de la escuela sería un `DefinicionReporte` guardado en tabla en vez de en clase: una FUENTE ya declarada + nombre + área + columnas + `filtrosFijos`. **No es SQL configurable**: es un preset sobre una fuente que un programador escribió. Lo que la SEP cambia son columnas, encabezado, orden y formato — todo dato; la consulta ("egresados de este plan con su promedio") es la misma en las cinco versiones que pida.
2. **La tabla, dibujada y no migrada**, en `docs/decisiones.md`:
   `reportes_escuela` (clave generada, nombre, `fuente` string sin FK, `area_id`, `columnas` json, `filtros_fijos` json, `plantilla_id` null, `publicado` bool, `auditoria()`).
3. **El criterio de entrada, escrito y decidible** — esto es lo que lo separa de una promesa. Se construye cuando `ejecuciones_reporte` enseñe **una de dos**:
   (a) al menos **tres vistas guardadas del mismo reporte con formas claramente distintas**, o
   (b) **una petición concreta de la SEP** que ninguna fuente cubra.
   Si a los dos meses el 80 % de las ejecuciones son tres reportes, no se construye y se dice por qué. Este proyecto ya tuvo que retirar cinco interruptores sin lector, un permiso sin ruta y `cierra_el_embudo` que sólo se dibujaba: medir antes de construir es la defensa.
4. **Y hay que decirle que no a "ahí se podría usar los formularios", con el porqué.** De los 11 atributos de `campos_formulario` sólo tres sobreviven al cambio de dominio (`pregunta`→etiqueta, `descripcion`→ayuda, `orden`); los otros ocho describen una CAPTURA, no una consulta. Y `tipos_campo` es la prueba viva de una tabla cuyas filas nuevas no hacen nada: los tres renderizadores hacen `match` sobre la clave literal y una desconocida cae a texto de 500. De ahí sí se toman dos piezas concretas: `PanelFiltros.vue`, ya genérico, y el intercambio de `orden` con el vecino en transacción.
   **Y hay un bloqueo previo** si alguien insiste: `FormularioController::versionar():244-251` escribe `aplica_a_tipo`/`aplica_a_id`, columnas que la migración `2026_08_05_160000` eliminó; como `rol_id` es nullable, **la versión 2 se publica sin llegarle a nadie, sin excepción** — y `scripts/prueba-formularios.php:107-114` escribe las mismas columnas muertas, así que no lo cazaría.

---

## 8. REBANADAS

Cada una entregable y verificable por sí sola. Ninguna es andamio para la siguiente.

---

### ~~Rebanada 0 · Las dos extracciones (sin pantallas)~~ ✅ HECHA

**Se paga sola aunque el módulo se cancele aquí.**

- `descargarExcel` de `LoteCertificacionController.php:499-518` (duplicado literal en `LoteTitulacionController.php:610-629`) sube a `App\Services\Excel\Exportador`, escribiendo a `php://output` con `streamDownload` en vez de `tempnam`, sin el `setAutoSize` masivo y con anchos fijos.
- `FinanzasController::saldosPorMatricula():409` sube a `App\Services\Finanzas\SaldosDeCartera`, y **se resuelve la divergencia**: la copia de `CarteraDeLaEscuela.php:69-76` NO lleva el `whereNotNull('a.matricula_oferta_id')` de la línea 422, así que hoy la tarjeta suma los adeudos de aspirantes y la pantalla no. **Hay que decidir cuál es la buena y dejar UNA.** (Mi lectura: la tarjeta es "la cartera de la escuela" y debe incluir aspirantes; la pantalla es la cartera POR MATRÍCULA y no puede. El servicio expone las dos con un parámetro y un docblock que lo explica.)
- `AcotaPorCampus` → `App\Services\AlcanceDeCampus` recibiendo `Usuario`; el trait delega y conserva firmas. Los 12 controladores no se tocan. `RH/EmpleadoController.php:70` pasa a usar `adscripcion()`.
- De paso: `ExportaResultados.php:198` y `:202` pasan a `Coordinate::stringFromColumnIndex()`.

**Verificable**: los dos Excel de lotes salen idénticos; la tarjeta de cartera y `/finanzas` dan el mismo número (o dan números distintos **a propósito y explicado**); `scripts/prueba-exportador.php` comprueba que no queda basura en el temporal — **glob acotado al prefijo de ESE trabajo y con los dos patrones**, Windows (`exp*.tmp`) y Linux (`exportador-*`), porque mirar todo el directorio ya hizo fallar `prueba-grabaciones` en el barrido — y que un libro de 30 columnas nombra bien la AD.

---

### ~~Rebanada 1 · El motor y UNA fuente real, con bitácora~~ ✅ HECHA

> **[~] La bitácora se construyó con MENOS columnas que §1.4.** Tiene `reporte`,
> `persona_id`, `formato`, `filas`, `milisegundos` (así, no `duracion_ms`),
> `filtros`, `columnas` y `columnas_omitidas`. Le faltan `vista_id`, `rol_id`,
> `estado`, `mensaje` e `ip`. Ninguna se echó de menos hasta hoy porque no había
> pantalla que las leyera; se deciden en la rebanada 8, que es la que le pone
> lectores — y sólo se agrega lo que la pantalla vaya a leer, que es la regla de
> esta casa.
>
> **[ ] Y NO se construyó la retención**, que §1.4 pedía «desde el primer día»:
> no existe el ajuste `reportes.dias_bitacora` ni el comando
> `reportes:purgar-ejecuciones`. La bitácora lleva 119 filas y crece sin tope.
> Entra en la rebanada 8, con su pantalla.

- Migración que registra el módulo `reportes` en `modulos` **y lo enciende en `modulos_activos`**, calcada de `2026_08_09_110000`. Clave en `ModuloSeeder`.
- Dominio "Reportes" en `CatalogoPermisos::CATALOGO`, faceta `[ADMINISTRATIVO]`: `ver-reportes`, `gestionar-areas-reporte`, `exportar-datos-personales`, `auditar-reportes`.
- `GrupoMenu` en `catalogo.ts` con `modulo: 'reportes'`; rutas bajo `Route::middleware(['can:ver-reportes', 'modulo:reportes'])`.
- `app/Reportes/`: `FuenteDeReporte`, `DefinicionReporte`, `ColumnaReporte`, `FiltroReporte`, `Recorte`, `TipoDato`, `TipoFiltro`, `Agregacion`, `RegistroReportes`, `Ejecutor`, `Formateador`.
- **UNA fuente**: `FuenteMatriculas` (grano *"una matrícula"*, `Recorte::porOferta()`), con **dos presets encima** — "Alumnos inscritos" y "Bajas del ciclo" — que es lo que demuestra que el preset funciona.
- **`ejecuciones_reporte` desde aquí**, con `reportes:purgar-ejecuciones` ya en `routes/console.php`. Se exporta desde la rebanada 2; la bitácora no puede llegar seis rebanadas después.
- Pantalla con `PanelFiltros.vue`, `Paginacion.vue`, selector de columnas, orden y totales.

**Verificable** (`scripts/prueba-reportes-motor.php`):
- Columna inventada → se descarta, resultado idéntico. Filtro inventado → se descarta. `orden_por = "id; DROP TABLE personas"` → cae al por omisión. `orden_dir` basura → `asc`. `columnas = ["(select password from usuarios)"]` → descartada. `filtros = {"campus_id": "1 OR 1=1"}` → `ValidationException`.
- El TOTAL coincide con la suma de **todas** las páginas, no de la primera.
- Un usuario acotado a un campus ve **menos** filas que uno global.
- Los dos presets sobre la misma fuente dan conjuntos distintos.
- Módulo apagado → 404 en todas las rutas. Faceta docente → 404.
- **Comprobada mutando**: quitar el recorte, quitar el saneado de columnas, sacar el total de la página paginada — caen exactamente las que las vigilan.

---

### ~~Rebanada 2 · Exportación XLSX y CSV~~ ✅ HECHA

- `ExportadorXlsx` y `ExportadorCsv` sobre el `Exportador` de la rebanada 0, los dos a `php://output`.
- **Keyset**, no `chunkById`.
- Tope de XLSX en `CatalogoAjustes` con su consecuencia; aviso PREVIO con la cifra real y la salida.
- CSV con BOM UTF-8 y `;`.
- Formatos de celda por `TipoDato`.
- Aviso de columnas omitidas dentro del archivo.

**Verificable**: sembrar 50 000 filas × 12 y medir el pico de memoria de las tres salidas; el CSV sale con memoria plana y **en el orden que el usuario pidió** (la prueba que caza el `chunkById`); el XLSX por encima del tope se **niega** con mensaje que ofrece el CSV; el archivo abre acentuado y en columnas en Excel; ningún temporal huérfano.

**Medición pendiente que condiciona el tope**: el `max_execution_time` del SAPI web de WAMP (en CLI vale 0). Hay que medirlo **antes** de fijar el número, no después.

---

### ~~Rebanada 3 · Áreas configurables (pedidos 2 y 4)~~ ✅ HECHA

`areas_reporte` + `ubicaciones_reporte` + seeder de once áreas borrables + `/reportes/configuracion` + índice agrupado.

**Verificable**: renombrar "Control escolar" a "Servicios escolares" y que nada se rompa (la clave no se toca); mover un reporte sin fila previa la crea; un área con reportes no se deja borrar y **nombra la salida**; una ubicación que apunta a un área apagada cae al área de la clase en vez de desaparecer; y —lo que de verdad prueba el diseño— **un reporte NUEVO registrado en código aparece solo en su área sin que nadie configure nada**; y mover un reporte de área **no concede ningún permiso**.

---

### ~~Rebanada 4 · Vistas guardadas, compartidas y favoritos~~ ✅ HECHA

`vistas_reporte` + `reportes_favoritos`. Predeterminada propia; compartir a un rol; compartir a la escuela sólo con `gestionar-areas-reporte`.

**Aquí el cliente ya obtiene "los mismos reportes de formas personalizadas" sin ningún constructor.**

**Verificable**: la prueba central es la de fuga — **una vista compartida por dirección general y ejecutada por el coordinador del campus norte devuelve sólo el norte**, no lo del dueño; comprobada **mutando** el ejecutor para que la vista arrastre el alcance de quien la guardó, y esa prueba cae. Además: retirar una columna del catálogo en código y comprobar que la vista vieja abre igual, sin esa columna, en vez de reventar.

---

### ~~Rebanada 5 · PDF de verdad, y con él el historial (pedido 1, primera mitad)~~ ✅ HECHA

`composer require mpdf/mpdf`. `App\Documentos\DocumentoPdf` con `tempDir` en `storage/app/mpdf`, membrete por página, folio y marca de agua nativa. Vista nueva `impresion/historial-pdf.blade.php` con tablas. Firma, sello y logo del disco como `data:` URI. El mismo motor sirve al PDF de reportes, con su propio tope (más bajo que el del XLSX).

**Antes**: medir mpdf con 300 renglones contra el `memory_limit` de 128M.

**Verificable**: un historial de egresada de tres hojas con membrete en las tres, "Hoja 2 de 3", marca de agua en las tres y el sello puesto. `HistorialImprimibleTest` gana casos de membrete repetido, folio y marca en la hoja 2. **Y se MIRA el PDF**, que es la regla que este proyecto escribió con el QR aplastado y con el nombre de institución fuera del lienzo: dos defectos que pasaron dos revisiones de código y aparecieron al abrir el PNG.

---

### ~~Rebanada 6 · El diseñador del historial, ampliado (pedido 1, segunda mitad)~~ ✅ HECHA

Los ocho huecos de §5.5, **empezando por normalizar los anchos a 100 %**. `armarEjemplo()` sube a 10 periodos. Editor de cajas sobre las cuatro zonas fijas con `EditorCajasCredencial.vue`.

**Verificable**: los anchos normalizados suman 100 en cualquier combinación y el configurador avisa del desborde; dos firmantes salen en el PDF; las 12 pruebas siguen verdes **incluidas las tres de bloques a dos columnas**; una prueba nueva cuenta hojas.

---

### ~~Rebanada 7 · Cobertura por área, una fuente a la vez~~ ✅ HECHA — 34 reportes sobre 14 fuentes, en 9 áreas

Cada fuente es una rebanada verificable de por sí, con su grano, su recorte declarado, sus columnas sensibles marcadas y su suite. **Ninguna reimplementa una regla que ya viva en un servicio.**

Orden por valor: **finanzas** (cartera sobre `SaldosDeCartera`, ingresos por concepto y método, becas) → **control escolar** (kárdex sobre `HistorialDelAlumno`, listas de grupo sobre `Grupo::scopeConAlumnos`, índice de aprobación, actas por asentar) → **admisiones** (sobre `EmbudoAdmision`, que ya agrega en SQL e incluye las etapas vacías a propósito) → **docentes** (carga académica: el filtro va por `docentes.persona_id`, **no** por `personas.id`) → **certificación** (`EstadoCertificacion::elegibleParaLote`) → **asistencia** → **LMS** → **bolsa** (`IndicadorEmpleabilidad`) → **RH** → **movilidad** → **familia**.

**Dos bloqueos que se resuelven antes de su fuente**: `AsistenciaClase::scopeFaltas()` (§9) y la elección del promedio oficial (§9).

**Y un recorte que no se hereda solo**: el umbral de anonimato `ResultadosDeEncuesta::MINIMO_PARA_MOSTRAR = 4`. La fuente de evaluación docente **usa el servicio**, no el SQL: un archivo se reenvía más fácil que una pantalla, y la siguiente encuesta ya nadie la contesta con sinceridad.

**Verificable**: cada fuente se compara contra la pantalla que hoy enseña lo mismo. Donde el demo está en cero (actas, pagos, checadas, colocaciones, recibos de nómina, titulaciones), la suite **siembra su escenario dentro de la transacción y mide POR DIFERENCIA contra una línea base** — la lección que este proyecto pagó dos veces en un solo día. Y una prueba de fan-out: un reporte de alumnos con "materias aprobadas" como conteo **no multiplica filas**, probado con una alumna de 48 renglones de historial.

---

### ~~Rebanada 8 · Totales, agrupados y bitácora con pantalla~~ ✅ HECHA

> **[~] El modo agrupado NO se pudo montar sobre las columnas**, que es como lo
> pedía este renglón. Medido: de las 181 columnas de las 14 fuentes sólo 67
> existen en SQL, y son identificadores —agrupar por matrícula da 32 grupos
> sobre 32 filas— o medidas. **Campus no era agrupable en ninguna de las
> catorce**, ni carrera, ni situación, ni concepto, ni etapa: todas se resuelven
> con una closure sobre una relación precargada. `columnaSql` no significa
> «dimensión», significa «por aquí se puede ORDENAR».
>
> Las dimensiones se DECLARAN aparte (`DimensionReporte` + `FuenteAgrupable`),
> y van tres fuentes por decisión del cliente —una a la vez—: Matrículas
> (conteos), Cartera (medidas de dinero) y Aspirantes (donde el grupo vacío
> existe de verdad).
>
> **[~] Las agregaciones NO son «por subconsulta correlacionada»**, como decía
> este renglón. Se revoca con la medición delante: un alias del SELECT no se
> puede meter dentro de un `sum()` ni usar en un `WHERE` —MySQL 1054—, y el
> módulo lleva meses migrando en la dirección contraria. Se arman vaciando
> `columns` y `orders` sobre el mismo builder ya recortado.
>
> **[~] La barra NO se reutiliza del panel.** No hay componente que reutilizar
> —es plantilla en línea en `Dashboard.vue` con su helper local— y además su
> escala es OTRA: allá se mide contra el mayor de la serie, a propósito, para
> que un embudo que arranca en 200 y termina en 3 no deje invisibles las últimas
> etapas. Aquí el denominador es el total. Dos preguntas distintas.
>
> **[~] Y la bitácora no dejó de anotar los repintados: los DEDUPLICA.**
> Decisión del cliente con la medición delante — 113 de 119 filas eran de
> pantalla, y quitarlas se llevaba el 95 % del insumo con el que se decide la
> rebanada 10, cuyo criterio de entrada se mide justo con esta tabla.
>
> **[~] La tarjeta «Mis reportes» y el módulo de las tarjetas: HECHOS, y de
> otra forma.** El defecto que este renglón cita era real y se reprodujo: con
> `bolsa_trabajo` apagado, «Postulantes en proceso» seguía en el panel con un
> enlace que daba 404.
>
> Pero NO se resolvió como decía —«que la tarjeta inyecte `ModulosDeLaEscuela`
> ella misma»—, porque eso deja la comprobación repartida y **la tarjeta que se
> olvide no falla: se pinta**, que es exactamente lo que había pasado. Se
> comprueba en `RegistroTarjetas::para()`, con una interfaz opcional
> `TarjetaDeModulo` que sólo declaran las tres tarjetas que dependen de un
> módulo apagable.
>
> Y la tarjeta consume `RegistroReportes::para()` en vez de decidir por su
> cuenta qué reportes ofrecer: ese criterio ya existe y son tres cosas —permiso,
> módulo y faceta—.

Modo agrupado con `GROUP BY` y agregaciones por subconsulta correlacionada; totales de consulta aparte; pantalla de `ejecuciones_reporte` bajo `auditar-reportes`; una barra horizontal reutilizando el tipo `barras` del panel; tarjeta "Mis reportes" que **inyecta `ModulosDeLaEscuela` ella misma**, porque `RegistroTarjetas::para()` no comprueba el módulo (`PostulantesEnProceso` ya tiene ese defecto vivo).

**Verificable**: el subtotal de los grupos suma el total general; la bitácora registra una ejecución por descarga y ninguna por repintar; la purga no se lleva lo que está dentro de la retención.

---

### ~~Rebanada 9 · Programación por correo~~ ✅ HECHA

> **[~] La infraestructura de correo NO era mínima**, como decía este renglón.
> Hay `CorreoConfig` con SMTP por escuela, `CorreoService::aplicar()` que cambia
> el mailer en caliente y un probador que funciona. Lo que sí era cierto es lo
> otro: `QUEUE_CONNECTION=database` y **ningún trabajador declarado en el
> repositorio** —cosa que afecta también a `TimbrarFactura` y
> `ArchivarGrabacion`, que ya existían—. Por eso los correos van SÍNCRONOS
> dentro del comando: encolarlos los dejaría esperando para siempre.
>
> **[~] Y NO se construyó el destinatario de tipo `correo`.** El plan lo preveía
> para el contador externo sin cuenta, «que recibe un enlace que exige sesión, no
> el adjunto». Al escribirlo se ve que no funciona: un enlace que exige sesión no
> lo puede abrir quien no tiene cuenta, así que ese destinatario recibiría una
> puerta cerrada y quien lo configure creería que su contador recibe el reporte.
> Y la alternativa —mandarle el adjunto— es exfiltración por diseño: un padrón
> con CURP saliendo todos los lunes a una dirección que la escuela no controla.
> Quien necesite mandárselo a alguien de fuera lo descarga y lo reenvía, con su
> nombre en el envío.

`programaciones_reporte` + `destinatarios_reporte`, comando `reportes:enviar-programados` con `--tenant=*`, en `routes/console.php` con `withoutOverlapping()` y `onOneServer()`, sobre `CorreoConfig`/`CorreoService`.

**Verificable**: programar la cartera para el lunes a las 7, forzar la corrida, recibirla; quitarle el permiso al dueño y comprobar que **no manda nada y deja el motivo escrito**; mutar la regla para que corra con alcance global en vez del guardado y ver que la suite lo tumba.

**Honesto**: la infraestructura de correo es mínima (un solo Mailable, `QUEUE_CONNECTION=database` sin worker declarado en el repo). Es la rebanada más cara en proporción a lo que entrega.

---

### ~~Rebanada 10 · el constructor (pedido 5)~~ HECHA (2026-09-03)

`/reportes/constructor`, con `gestionar-areas-reporte`. El detalle vive en `CLAUDE.md`; aqui lo que le toca a este plan:

> **El criterio de entrada de §7 NO se cumplia, y se construyo igual por pedido explicito del cliente.** Medido el mismo dia: **cero** vistas guardadas y 749 ejecuciones de una sola persona probando, o sea ninguna de las dos senales que §7 pedia. Queda escrito para que nadie lea el criterio como cumplido: lo que decidio fue el cliente, no la medicion.

Lo que se construyo es exactamente lo que §7.1 dibujo —un `DefinicionReporte` guardado en tabla: una FUENTE ya declarada + nombre + area + columnas + `filtrosFijos`— con dos cosas que aquel esbozo no tenia y que hicieron falta:

- **`filtros_obligatorios`**, porque un reporte sobre una fuente grande sin acotar barre la escuela entera y los del codigo que lo necesitan lo declaran; sin esta columna, el armado desde pantalla seria el unico que no puede pedirlo.
- **`RevisionDelReporte`**, que decide si una fila todavia casa con su fuente. Un filtro fijo que desaparecio es FATAL —el reporte contestaria una pregunta mas ancha con el mismo nombre— y una columna retirada solo se descarta, como en una vista guardada.

`plantilla_id` NO se construyo: no hay todavia ninguna plantilla que aplicar, y una columna sin lector es lo que este proyecto ya tuvo que retirar cinco veces.

Y las diez prohibiciones de §10 siguen en pie: **no hay campo de SQL** —lo dice la pantalla, no solo un docblock—, `fuente` es una cadena sin llave foranea, no hay catalogos de tipos, y los filtros siguen siendo AND sin parentesis.

---

## 9. CORRECCIONES PREVIAS OBLIGATORIAS

Fuera del módulo, pero antes de la fuente correspondiente:

1. **`AsistenciaClase::scopeFaltas()`** filtra por `'ausente'` y lo guardado es `'falta'`. Nadie lo llama hoy, por eso nunca se notó. Un reporte de inasistencias devolvería CERO y parecería que nadie falta nunca. Corregir o evitar antes de la fuente de asistencia.
2. **Elegir por escrito en `docs/decisiones.md` cuál es el promedio oficial**: `HistorialDelAlumno::promedio():209` o `EstadoDelAlumno:58`. Hoy un alumno que recursó sale con números distintos en `/mi-historial` y en `/mis-hijos`. Un catálogo de reportes que no elija produce una TERCERA verdad, que es justo lo que `HistorialDelAlumno` se creó para eliminar.
3. **La divergencia de la cartera** (`FinanzasController:422` con `whereNotNull` contra `CarteraDeLaEscuela:69-76` sin él). Se resuelve en la rebanada 0.
4. **Corregir CLAUDE.md**: manda tres veces a `/plataforma/accesos` para apagar módulos, y el interruptor vive en `/plataforma/modulos` — pantalla que además **no está en el menú de nadie**. Por eso `reportes` nace encendido desde la migración.
5. **`ExportaResultados.php:198,202`**: `chr(ord('A') + $i)` devuelve `[` desde la columna 27.
6. **Anotar el bloqueo de `FormularioController::versionar():244-251`** por si alguien intenta apoyar el constructor sobre formularios.

---

## 10. LO QUE EXPLÍCITAMENTE NO SE HARÁ

1. **Un campo de SQL libre**, ni "sólo para dirección", ni "sólo para casos raros". `stancl` aísla por base de datos, **no por permisos de MySQL**: esa caja convierte cualquier cuenta con ese permiso en lectura completa del tenant — `usuarios`, `personas`, los certificados de sello digital — y ninguna lista negra de palabras la cierra. Va escrito en `docs/decisiones.md` **ahora**, porque lo van a pedir en la primera demo.
2. **Tablas espejo del registro** (`fuentes_reporte`, `campos_reporte`) con un comando de sincronización. Es la segunda verdad que este proyecto ya pagó con `acta.formato_folio` declarado dos veces, replicada por cada campo de cada fuente, a cambio de renombrar una etiqueta. Lo que el cliente pidió renombrar son las **áreas**, y eso lo cubre `ubicaciones_reporte`.
3. **`tipos_columna`, `tipos_filtro`, `operadores` como catálogo.** Cada tipo es una **rama de código** — el formato de celda, la regla de validación, la forma del `where`. Una fila nueva no haría nada, que es exactamente lo que `tipos_campo` demuestra hoy: tres de sus filas ya caen al `default` de texto de 500 en los tres renderizadores. Va escrito el porqué, como se hizo con `tipos_actividad`.
4. **OR y paréntesis en los filtros.** Todo en AND; el OR que la gente quiere lo da `lista_multiple`.
5. **Un editor de cajas para la TABLA del historial.** Ningún motor del mundo lo puede dar; el argumento se le entrega entero al cliente.
6. **Guardar el archivo generado** en la bitácora. Sería un almacén de padrones con datos personales creciendo sin retención, indexado por reporte y fecha: exactamente el activo que una escuela no quiere tener.
7. **"Ver como otra persona".** Correr un reporte con el alcance de otro es suplantación, y suplantación ya existe (`Suplantador`) con rastro en `auditoria`, sin escalar privilegios ni encadenar. Un segundo camino sería un segundo camino sin rastro.
8. **Un motor de gráficas configurable.** Media pantalla de configuración para producir algo peor que la barra que el panel ya pinta.
9. **Las plantillas oficiales de la SEP como salida del motor genérico.** La 911 y sus formatos no son "un reporte con otras columnas": son documentos con su propia validación, y este proyecto ya tiene la forma correcta — XSD en `resources/` y un validador que corre ANTES de firmar y nombra la asignatura concreta a la que le falta el identificador. Meterlos por el motor produciría un documento que se ve bien y que la SEP rechaza. Cuando llegue uno concreto: una FUENTE para el dato y el documento por el camino que ya existe.
10. **Alertas por umbral** ("avísame si la cartera vencida pasa de X"). Suena bien y no tiene usuario identificado. Sería el sexto interruptor sin lector de este proyecto.
11. **Construir el módulo sobre `campos_formulario`.**
12. **Reutilizar `Compositor::dibujarTexto()`** del compositor de credenciales para el historial (§5.4).

---

## 11. RIESGOS QUE QUEDAN VIVOS

- **Rendimiento**: un motor genérico produce consultas que nadie revisó. El demo tiene 1016 renglones de historial; una escuela real tiene cientos de miles. Mitigación honesta: tope de filas, `duracion_ms` en la bitácora, y **aviso de campo no indexado en el configurador leído de `information_schema`** — el mismo mecanismo de `acadion:auditar-datos`. Lo que **no** se promete es que cualquier combinación sea rápida.
- **Titulares DUALES**: `adeudos` y `pagos` son matrícula XOR aspirante con CHECK en MySQL. Copiar el `whereNotNull('a.matricula_oferta_id')` de la cartera —correcto ahí— a un reporte de INGRESOS pierde en silencio todo lo cobrado a aspirantes antes de matricularse. Es un número más bajo, no un error visible. Lo mismo `postulaciones_movilidad`.
- **`Recorte::sinCampus` va a generar quejas.** Hay entidades sin ningún camino a campus (movilidad, buena parte del LMS). Negarle esos reportes a un rol acotado es lo seguro y hay que acordarlo con el cliente, no decidirlo en silencio. Relacionado: **`Disciplina\IncidenciaController::index` hoy NO acota por campus en absoluto** — la fuente de reportes sí lo hará, así que quedará más estricta que su propia pantalla. Es un hueco que hay que anotar y corregir aparte, no replicar "por coherencia".
- **Riesgo de producto**: construir diez fuentes que nadie abre. Se mitiga con el orden (la rebanada 1 entrega motor **con** fuente utilizable) y con `ejecuciones_reporte`, que dice qué se usa.
- **Trampa latente del menú**: ponerle `modulo:` a una sección núcleo la ocultaría de golpe, porque los núcleo figuran sin fila en `modulos_activos` y `ModulosDeLaEscuela` falla cerrado.