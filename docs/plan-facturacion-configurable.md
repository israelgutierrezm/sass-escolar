# Plan — Facturación configurable (CFDI de cara al cliente)

Escrito ANTES de tocar código porque abre un frente nuevo sobre un motor maduro:
hoy la facturación CFDI es **100 % manual y de administrador**, y el pedido
(R06) pide una capa **configurable** —automática, autoservicio de alumno/padre,
público en general, factura global— sin reescribir lo que ya funciona. La
consigna es **complementar, no modificar**.

## Lo que YA existe (no se toca)

Un solo sistema coherente: `FacturaController` → `EmisorFactura` →
`App\Services\Cfdi\Pac` (`FacturapiPac`, que usa `App\Services\Facturacion\FacturapiService`;
`PacFalso` para pruebas). Cubre, **por el administrador**:

- Emisión manual (`emitir`), borrador → cola de timbrado (`TimbrarFactura`).
- Cancelación (motivos 01–04), sustitución/refacturación, nota de crédito.
- Complemento **IEDU** (`ComplementoEducativo`, `factura_iedu`).
- Conciliación con el SAT (`finanzas:conciliar-cfdi`), descarga individual y
  **lote mensual**, cierre fiscal, multi-razón social (`emisores_fiscales`).
- `pagosOcupados`: un pago amparado por una factura VIVA no se puede volver a
  facturar. **Ya protege contra la doble cobertura de un pago.**
- El emisor y el receptor se **congelan** en la factura (editar el perfil no
  cambia CFDI previos). Estados: `borrador/timbrando/timbrada/error/cancelada`.
- Config Facturapi (`FacturacionConfig`), catálogos SAT (`CatalogosSat`).
- Permisos `facturar` / `gestionar-emisores` / `configurar-facturacion`, todos
  ADMINISTRATIVO.

## Lo que FALTA (medido, R06)

- **Facturación automática al confirmar pago** — `RegistradorPago::confirmar` no
  emite; no hay evento `PagoConfirmado` ni listener.
- **Autoservicio alumno/padre** (generar/solicitar) — todo bajo `can:facturar`;
  el portal del padre sólo LEE un resumen (uuid/estatus), sin descargar el CFDI.
- **Solicitud de factura trazable** — no hay tabla/flujo; sólo un bool
  `datos_facturacion.quiere_factura` que alimenta una tarjeta de panel admin.
- **Público en general / RFC genérico** — `XAXX010101000` sólo en scripts de
  prueba; sin perfil genérico configurado.
- **Factura global** — `FacturapiService::emitirFacturaGlobal()` existe SIN
  llamadores (código latente); el motivo de cancelación `04` («nominativa
  global») está definido y sin uso. No hay tipo global, ni periodicidad, ni
  agrupación por periodo.
- **Perfil fiscal desacoplado** — `DatosFacturacion` (por-persona: rfc,
  razon_social, regimen_fiscal, cp, uso_cfdi, `correo_fiscal`, `quiere_factura`,
  `es_tercero`) lo captura el ADMIN, pero la emisión lo **ignora**: recaptura el
  receptor precargando de la ÚLTIMA factura. Dos fuentes de verdad.
- **Sin validación de catálogo SAT** — régimen/uso se validan como texto libre
  (`max:5`), y el datalist de `Emitir.vue` (605, 616…) **diverge** de
  `CatalogosSat` (621, 626…). `correo_fiscal` se captura y nunca llega al CFDI.

## Las rebanadas

Cada una es un flujo completo y verificable. Se construyen en este orden porque
1 es el cimiento de las demás.

### Rebanada 1 — Perfil fiscal unificado + catálogos SAT ✅ (2026-09-09)

Cimiento, bajo riesgo, complementa sin romper. Resuelve **R06.05** (receptor ≠
estudiante, un solo perfil) y **R06.06** (perfil nominativo con catálogos).

1. **Completar los catálogos SAT** (`CatalogosSat`): `regimenesFiscales()` y
   `usosCfdi()` pasan de un subconjunto a los catálogos oficiales COMPLETOS
   (c_RegimenFiscal, c_UsoCFDI). Sólo así validar contra ellos no rechaza un
   código válido. Se agregan `clavesRegimenes()` / `clavesUsos()` para las
   reglas.
2. **Validar régimen/uso contra el catálogo** (`Rule::in`) en los TRES puntos de
   captura del receptor —el perfil del alumno (`AlumnoController::guardarFacturacion`),
   la emisión (`FacturaController::store`) y la refacturación—. El RFC y el CP
   se quedan como están (el RFC deliberadamente no valida dígito verificador: eso
   lo dice el SAT). Es la parte de «usar catálogos, no valores libres».
3. **Unificar los desplegables del frente**: `Emitir.vue` (y la pestaña
   Facturación de `Alumnos/Detalle.vue`) dejan de tener su lista de texto libre y
   usan `CatalogosSat` (prop). Con el catálogo completo, régimen/uso pasan a
   `<select>` cerrado: una fuente, sin divergencia.
4. **`DatosFacturacion` como fuente primaria del receptor**: la emisión
   (`facturables`) precarga del perfil GUARDADO del alumno cuando existe, y sólo
   cae a «la última factura» cuando no hay perfil. El receptor se sigue TECLEANDO
   y CONGELANDO en la factura (el admin puede emitir a un tercero distinto): sólo
   cambia de dónde nace la sugerencia. No se sobrescribe el perfil al emitir.

**Fuera de esta rebanada, a propósito**: propagar `correo_fiscal` al CFDI (es una
columna nueva + tocar el driver del PAC; el modelo de datos ya lo trata como dato
SEPARADO, que es lo que R06.06 exige; la entrega por correo llega con el
autoservicio). No se toca el timbrado ni el flujo del PAC.

### Rebanada 2 — Público en general + factura global ✅ (2026-09-09)

Decidido con el cliente: **on-demand** (el admin la emite), agrupa **todo lo
cobrado y NO facturado del periodo**, periodicidad **mensual** configurable.

- **Público en general = PRESET del SAT**, no editable (`App\Support\PublicoEnGeneral`):
  RFC `XAXX010101000`, «PÚBLICO EN GENERAL», régimen `616`, uso `S01`; el CP es
  el lugar de expedición del emisor. R06.07 «no inventar identidades fiscales».
- **CFDI global** (`facturas.es_global` + `periodicidad_global` + `periodo_global_meses`
  + `periodo_global_anio`; `matricula_oferta_id` ya era nullable): `EmisorFactura::emitirGlobal`
  arma UN comprobante al genérico con `InformacionGlobal` (el driver la mete sólo
  si `es_global`), un renglón por pago, **sin IEDU** (no es nominativa), emisor
  congelado. `globalizables(emisor, desde, hasta)` da los pagos del periodo de esa
  razón social —lo no facturado—.
- **El CORTE**: emitir la global ocupa esos pagos (misma `pagosOcupados`), así que
  ya no se facturan nominativos. Sale de `/finanzas/facturas` (`can:facturar`),
  con previsualización de cuántos pagos y cuánto antes de emitir.
- Ajuste `facturacion.periodicidad_global` (SELECCIÓN, default mensual). El
  periodo es un mes de calendario; la periodicidad es la etiqueta del SAT
  (bimestral mapea a c_Meses 13-18).
- Pruebas: `scripts/prueba-factura-global.php` (15 verif, `es_global` y el bloque
  global comprobados por mutación). `prueba-facturacion` 51/0 sin regresión.

**Fuera de esta rebanada**: el respaldo automático a público-general cuando un
pago se confirma sin datos nominativos (R06.08/R06.09) vive en la rebanada 4; y
la periodicidad como corte real (quincenal = 15 días) —hoy el periodo es el mes—.

### Rebanada 3 — Autoservicio alumno/padre ✅ (2026-09-09) · SOLICITAR + GENERAR

El núcleo seguro y alineado con la spec: el alumno y su familia **SOLICITAN**, la
escuela emite. R06.01 (la emisión no se concede al alumno) + R06.11 (estados) +
R06.15 (bandeja) + R06.16 (mensajes/descarga).

- **`solicitudes_factura`** (calca a `comprobantes_pago`): titular matrícula,
  `pago_ids`, receptor CONGELADO al pedir, estados `pendiente/emitida/rechazada`,
  `factura_id` al emitir. `SolicitudFactura` + `GestorSolicitudFactura`.
- **Solicitar** no es emitir: nace la solicitud; el CFDI lo crea el admin desde
  la bandeja reusando `EmisorFactura`. Guardas: los pagos son SUYOS y facturables
  (lo dice `EmisorFactura::facturables`, una sola verdad), y no hay otra solicitud
  pendiente cubriéndolos (control de duplicado = cobertura de la operación, R06.02).
- **Permiso propio** `solicitar-factura` (faceta alumno + padre), distinto de
  `facturar`. Interruptor por escuela `facturacion.autoservicio_solicitud`
  (apagado por omisión; apagarlo esconde el botón, no el historial).
- **Bandeja** `/finanzas/solicitudes-factura` (`can:facturar`): emitir / rechazar
  con motivo, bajo bloqueo (dos personas no emiten dos facturas por una solicitud).
- **Descarga del CFDI propio** acotada por el trait de la cartera (el personal ve
  cualquiera; el resto, sólo el suyo), como el recibo.
- Portales: panel `PanelSolicitarFactura` reusado por el estado de cuenta y el
  portal del padre; el servicio `AutoservicioFactura` arma sus datos en un solo
  sitio. Al habilitarlo en una escuela: sincronizar permisos (`PermisoSeeder`,
  idempotente) y encender el ajuste.
- Pruebas: `scripts/prueba-solicitud-factura.php` (21 verif, 3 mutaciones), sweep
  del menú, pantallas-con-puerta, auditoría 69.

- **GENERAR (3b)**: el alumno/padre emite su propio CFDI al momento, con su
  perfil, sin bandeja. Capacidad e interruptor APARTE de solicitar
  (`facturacion.autoservicio_generar`, permiso `generar-mi-factura`), porque
  emitir a nombre de la escuela es más delicado —default apagado, se enciende
  con el PAC operando—. Reusa el MISMO motor y las MISMAS guardas
  (`GestorSolicitudFactura::generarDirecto`), y deja constancia como una
  solicitud ya `emitida` para que el historial y la descarga sirvan igual. En el
  portal, «generar» manda sobre «solicitar» cuando la escuela abre las dos
  (`facturaModo`). Cancelación y sustitución siguen sin concederse al alumno.

### Rebanada 4 — Facturación automática al confirmar pago ✅ (2026-09-09)

Con esto R06 queda entregado. Interruptor `facturacion.automatico` (apagado por
omisión). **R06-A** auto + R06.08/R06.09/R06.14.

- **Un evento, una señal para todos los canales** (R06.09): `App\Events\PagoConfirmado`
  se dispara en `RegistradorPago::confirmar` —el único punto por el que un cobro se
  confirma, venga de ventanilla, pasarela o comprobante—, y DESPUÉS del commit, con
  el cobro ya firme. El oyente `FacturarPagoConfirmado` (auto-descubierto) delega en
  `App\Services\Finanzas\FacturacionAutomatica`.
- **La política**: con el automático encendido, un pago de matrícula recién
  confirmado se factura NOMINATIVO si el alumno tiene su perfil fiscal completo y
  pidió factura; si no, no se emite nada y el pago queda para la factura GLOBAL del
  periodo (ausencia no es invalidez, R06.08). `globalizables` EXCLUYE a quien pidió
  nominativa: su pago no se cuela a la global —o ya se le emitió, o le faltan datos
  y queda pendiente—.
- **Nunca tumba el cobro** (R06.09): el pago ya está confirmado cuando corre; una
  falla al facturar se registra y el pago queda pendiente (lo muestra la tarjeta
  `FacturacionPendiente`), sin propagar la excepción ni marcarlo facturado.
- **No refactura lo histórico** (R06.14): sólo actúa sobre confirmaciones nuevas;
  encender el automático no toca los pagos ya cobrados.
- Pruebas: `scripts/prueba-facturacion-automatica.php` (9 verif, integración real
  evento→oyente→política→motor→timbrado; el gate y la exclusión de la global por
  mutación). `prueba-facturacion`/`cobro`/`carreras-de-concurrencia` y phpunit del
  cobro sin regresión (el evento es no-op con el automático apagado).

**Queda como afinación** (no bloquea el cierre de R06): el **resumen combinado**
del comportamiento para alumno/padre antes de guardar (hoy cada interruptor trae su
`consecuencia`, R06.03 parcial) y **mostrar el receptor en el checkout** del pago en
línea (R06.04). La periodicidad como corte real (quincenal = 15 días) sigue anotada
en la rebanada 2.

## Reglas transversales (del pedido, y que el proyecto ya sostiene)

- Apagar un canal de autoservicio es una decisión de interfaz: **no** borra el
  historial ni redefine las obligaciones fiscales.
- Un pago sigue VÁLIDO aunque su proceso fiscal esté pendiente (una falla del PAC
  no borra un cobro) — ya es así: el estatus de la factura está desacoplado del
  pago.
- El control de duplicados protege la **cobertura de la operación**, no impone
  «un pago = una factura»: un pago puede aparecer en la cancelada y en la
  vigente. Ya lo hace `pagosOcupados`.
- Generar, solicitar, consultar y administrar son capacidades DISTINTAS.
