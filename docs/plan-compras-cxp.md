# Plan — Compras y cuentas por pagar (6.3)

Diseño escrito ANTES de tocar una tabla. Módulo opcional de Finanzas, flujo 6.3.
Era **E**: no existía nada de compras —verificado: no hay `proveedores`,
`ordenes_compra` ni `cuentas_por_pagar`; los «Proveedor» del código son los del
motor de Permanencia—. Se construye ENCIMA de lo que ya hay del lado del gasto:
`centros_costo`, `presupuestos`/`partidas_presupuesto`, `egresos`.

## El LÍMITE, decidido con el cliente: NO es contabilidad

Es control de COMPRAS, no contabilidad. **Fuera de alcance, a propósito**: validar
CFDI recibidos como prueba fiscal, pólizas contables, cuentas contables, balanza,
DIOT. La nota de Finanzas es tajante —«media implementación de contabilidad sería
peor que ninguna, porque se usaría como si lo fuera»— y esto la respeta.

## La invariante que lo cose con lo que ya hay

**El EJERCIDO tiene UNA sola fuente: `egresos`.** Ya es así (rebanada 3.6), y no
se toca. Por eso:

- Un **proveedor** ESTRUCTURA el `beneficiario` del egreso (hoy texto libre), no
  crea una segunda verdad.
- Una **cuenta por pagar** es una OBLIGACIÓN (compromiso), todavía no un gasto.
  Pagarla REGISTRA un `egreso` —ahí, y sólo ahí, el dinero «sale»— y ese egreso
  es el que consume presupuesto. La CxP nunca cuenta como ejercido por sí sola.
- Una **orden de compra** es un COMPROMISO previo; al recibirse genera la CxP. No
  crea egreso: el egreso nace al pagar la CxP.

Así «ejercido» sigue significando lo mismo en todo el sistema, y se puede auditar
renglón por renglón.

## Las tres rebanadas

### Rebanada 1 — Proveedores ✅ (2026-09-08)

`proveedores`: `nombre`, `rfc` (nullable, ÚNICO —opcional pero sin duplicados,
como `empresas` de la bolsa—), `razon_social`, contacto (nombre, teléfono,
correo), `domicilio`, `activo` (se APAGA, no se borra: sus egresos y CxP son
historia), `notas`. Se agrega `egresos.proveedor_id` (nullable): quien captura un
egreso puede elegir al proveedor, y el `beneficiario` libre se queda para pagos
que no son a un proveedor (un reembolso, una persona).

- **Institucional, sin acotar por campus** —un proveedor le factura a la persona
  moral, no a un plantel—, como `patrocinadores` y las cuentas bancarias.
- Permiso `gestionar-proveedores`. Pantalla `/finanzas/proveedores`.

### Rebanada 2 — Cuentas por pagar ✅ (2026-09-08)

**Nota de implementación**: los pagos se enlazan con `egresos.cuenta_por_pagar_id`
(columna propia), NO reusando `egresos.origen_id` —el único
`egreso_origen_unico (origen, origen_id, centro_costo_id)`, que existe para la
idempotencia de la NÓMINA, impediría un segundo pago del mismo centro a la misma
cuenta—. El egreso conserva `origen = 'cxp'` (para el guard) con `origen_id`
NULL, y así las parcialidades conviven.

`cuentas_por_pagar`: `proveedor_id`, `centro_costo_id`, `partida_id`, `ciclo_id`,
`concepto`, `monto`, `fecha`, `vencimiento`, `estado`
(`pendiente`→`parcial`→`pagada`; `cancelada`), `referencia`, auditoría. Se puede
capturar directa (una factura que llegó) o nacer de una orden de compra
(rebanada 3).

- **Pagar una CxP registra un EGRESO** (con su comprobante, su centro y su
  partida copiados de la CxP) y aplica el pago; el estado se DERIVA de lo pagado,
  como el estatus del adeudo en `RegistradorPago`. Un pago parcial deja
  `parcial`.
- **Antigüedad de saldos** (aging): qué se debe, a quién y desde cuándo, con lo
  VENCIDO aparte. Es lo que el módulo viene a contestar.
- **El presupuesto gana COMPROMETIDO** además de ejercido: una CxP pendiente es
  dinero apalabrado que todavía no salió. Pasarse avisa, no bloquea (como el
  presupuesto de egresos y el de becas).
- Permisos `gestionar-cuentas-pagar` y `pagar-proveedores` (dos oficios: quien
  registra la obligación no es siempre quien autoriza el pago).

### Rebanada 3 — Órdenes de compra ✅ (2026-09-08) · CIERRA EL MÓDULO

`ordenes_compra` + `orden_compra_conceptos`: proveedor, centro, partida, ciclo,
conceptos (descripción, cantidad, precio), `estado`
(`borrador`→`autorizada`→`recibida`→`cerrada`; `cancelada`), quién autorizó.

- **Recibir la OC genera la CxP** (la obligación de pagar lo recibido). Autorizar
  la OC es lo que la vuelve un compromiso presupuestal.
- Recepción PARCIAL posible (se recibe parte); la CxP se genera por lo recibido.
- Permisos `gestionar-ordenes-compra` y `autorizar-ordenes-compra`.

## Reglas transversales (se respetan en las tres)

El servidor valida permiso y regla; institucional, sin acotar por campus (es de
la persona moral); toda transición de estado comprueba el origen; el ejercido
sigue siendo sólo `egresos`; nada de datos reales en las pruebas (BD real con
`DB::rollBack()`, mutando las reglas). Módulo opcional: apagarlo no rompe nada.

## Verificación

Sin navegador (login de personas): suite contra la BD real con rollback, mutando
las reglas de seguridad y de derivación de saldos; controladores por HTTP;
`npm run build`; auditoría del demo sin cambios.
