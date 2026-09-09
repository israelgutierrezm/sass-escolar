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

### Rebanada 1 — Perfil fiscal unificado + catálogos SAT ⏳ (en curso)

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

### Rebanada 2 — Público en general + factura global

Perfil genérico por emisor (RFC XAXX010101000, régimen 616, uso S01/G03, CP del
lugar de expedición) con presets validados; CFDI **global** por periodo usando el
`emitirFacturaGlobal` latente y el objeto `InformacionGlobal` (periodicidad, mes,
año), agrupando los pagos no facturados. **R06.07/R06.08** + tratamiento global.
Pide decisiones fiscales (periodicidad, corte, RFC/régimen genérico).

### Rebanada 3 — Autoservicio alumno/padre

Permisos nuevos de faceta (generar / solicitar), «Generar factura» inmediata
(con su perfil, reusando `EmisorFactura`), «Solicitar factura» (solicitud
trazable con estados + bandeja compartida), y descarga del CFDI propio. **R06-A**
+ R06.01/R06.11/R06.15/R06.16. La emisión/cancelación/sustitución NO se conceden
al alumno/padre.

### Rebanada 4 — Facturación automática al confirmar pago

Evento `PagoConfirmado` → resuelve la política efectiva (nominativo si hay perfil
y `quiere_factura`; respaldo público-general si está configurado; si no,
CONFIRMADO + proceso fiscal PENDIENTE + alerta, nunca «facturado» sin estarlo),
con interruptor por escuela y un **resumen del comportamiento resultante** antes
de guardar. **R06-A** auto + R06.03/R06.04/R06.09/R06.14. Depende de 1–2.

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
