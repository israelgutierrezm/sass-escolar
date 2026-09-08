# Plan — Salida segura (recogida de menores)

Diseño escrito ANTES de tocar una tabla, porque toca la SEGURIDAD de un menor en
la puerta y porque es un subsistema con varias piezas. Módulo Familia, rebanada
7.2 (era **E**: no existía nada — verificado, `tutores_alumno` no tiene bandera
de recoger y `checadas` es el reloj del personal, no la salida del alumno).

## Qué es, y qué NO es

Registrar QUIÉN puede recoger a un alumno y validarlo en la puerta. NO es control
de asistencia (eso es el reloj checador), ni un consentimiento (eso es
`autorizaciones`): una salida es un HECHO operativo con quién, cuándo y cómo se
validó.

## Decisiones del cliente (2026-09-07)

1. **Validación en la puerta: lista de autorizados + QR, validado por el
   SERVIDOR.** El guardia escanea un QR; el servidor confirma que esa persona
   está autorizada AHORA para ESE alumno. La vista no autoriza —el QR se valida
   en el servidor, como el timbrado o el sello—.
2. **Quién recoge: tutores + terceros que agrega la familia** (abuela, chofer),
   con nombre e identificación.
3. **Custodia: SÍ.** Se puede marcar a alguien como NO autorizado —un progenitor
   que legalmente no puede recoger—, y el servidor lo rechaza aunque sea tutor.

## El modelo de datos

### `autorizados_recoger`

Una fila por permiso o bloqueo de recogida, del alumno.

- `alumno_persona_id` (NOT NULL)
- `persona_id` (nullable) — la cuenta de la persona autorizada, si la tiene. Un
  tercero (la abuela) normalmente NO la tiene, y por eso es nullable: el login
  es de personas, pero a quien recoge no se le pide cuenta.
- `nombre` (NOT NULL) — de quien recoge.
- `identificacion` (nullable) — tipo y número de su identificación, lo que el
  guardia coteja.
- `parentesco_id` (nullable)
- `foto_ruta` (nullable, disco privado) — para que el guardia la reconozca.
- `permitido` (bool) — **true = AUTORIZA** (un tercero); **false = BLOQUEA**
  (custodia). Un mismo alumno puede tener filas de las dos clases.
- `vigencia_desde` / `vigencia_hasta` (nullable date) — una autorización temporal
  (el chofer de este mes). Vacío = permanente.
- `motivo` (nullable) — la razón del bloqueo de custodia; queda escrita porque
  dentro de un año alguien preguntará por qué se rechazó a esa persona.
- `token` (uuid, nullable) — el que viaja en el QR. Se genera para las
  autorizaciones (no para los bloqueos). **Rebanada 2.**
- Auditoría (`agregado_por` = `created_by`).

### Los TUTORES se autorizan por omisión

No se copian a esta tabla: un tutor de `tutores_alumno` es el responsable legal y
puede recoger salvo que un BLOQUEO de custodia lo diga. Copiarlos crearía un
segundo padrón que se separaría del vínculo. La tabla guarda lo que el vínculo no
dice: los terceros (positivo) y los bloqueos (negativo).

## La regla: `PuedeRecoger`

Dado `(alumno, persona)`, en este orden —**el bloqueo gana**—:

1. **¿Hay un BLOQUEO vigente** (`permitido=false`) para esa persona y ese
   alumno? → **NO**, con su motivo. La custodia vence a todo, incluido ser tutor.
2. **¿Es TUTOR** del alumno (`tutores_alumno`)? → **SÍ**.
3. **¿Hay una AUTORIZACIÓN vigente** (`permitido=true`) para esa persona/token y
   ese alumno? → **SÍ**.
4. Si no → **NO** (no está en la lista).

Vive en UN sitio y lo preguntan la pantalla de la familia (para mostrar la lista
efectiva), la del guardia (rebanada 2) y el registro de salida (rebanada 2). Es la
lección de `estaEnVigor` de las autorizaciones y de `AlcanceDeExpedientes`.

## Quién hace qué

- **La FAMILIA** agrega, edita y retira TERCEROS autorizados para SUS hijos
  (`/mis-hijos`, faceta PADRE). No puede poner bloqueos: la custodia es una
  restricción LEGAL que la escuela registra con su documento, no algo que un
  familiar declara sobre otro.
- **La ESCUELA** registra los BLOQUEOS de custodia (permiso propio
  `gestionar-salida-segura`, faceta administrativa), con su motivo. Y ve la lista
  efectiva por alumno.
- **El GUARDIA** (rebanada 2) valida y registra la salida.

## Privacidad y seguridad

- La FOTO va al disco privado, servida con comprobación —es un dato personal de
  un menor y de terceros—.
- El QR (rebanada 2) lleva un **token**, no los datos: un código que cargue el
  nombre no verifica nada. El servidor resuelve el token y valida contra el
  estado ACTUAL (vigencia, bloqueo), así que un QR de alguien a quien ya se
  bloqueó se rechaza aunque el papel siga circulando. Mismo criterio que la
  credencial.
- El bloqueo de custodia es **sensible**: se ve con su permiso, y su motivo no
  sale al portal de la familia del otro progenitor.

## Las rebanadas

| # | Rebanada | Qué entrega |
|---|---|---|
| 1 | **Registro y reglas** | `autorizados_recoger`; `PuedeRecoger` (bloqueo > tutor > autorización); pantalla de la familia (agregar/retirar terceros con vigencia y foto); pantalla del administrador (bloqueos de custodia + lista efectiva por alumno); permiso `gestionar-salida-segura`. **Completa y verificable sin hardware.** |
| 2 | **La puerta** | `token` (QR) por autorizado; pantalla del guardia (escanea alumno + persona, el servidor valida); `salidas_alumno` (registro de entrega con quién, cuándo, cómo); aviso a los responsables. El QR reusado sobre un estado que cambió se rechaza. |
| 3 | **App / autoservicio** | El QR en la app de la familia; un código de un solo uso «mando hoy a la abuela» time-bounded, para lo no recurrente. |

Cada rebanada para y pide validación.

## Lo que NO se hace todavía, y por qué

- **No hay lector de QR físico en este entorno**, así que la rebanada 2 valida el
  token por endpoint (el escáner es del navegador/app del guardia) y se prueba
  con el token, no con una cámara.
- **Las notificaciones** de la rebanada 2 usan el canal de avisos que ya existe
  (portal), no correo: mismo criterio que el resto del sistema.
