# El despachador de tareas programadas

Acadion tiene trabajo que ocurre solo. Todo está declarado en
`routes/console.php`, y hoy es esto:

| Cuándo | Qué |
|---|---|
| 02:45 diario | `finanzas:generar-cargos` — emite los cargos que falten de cada plan |
| 03:00 diario | `finanzas:evaluar` — becas por atraso, recargos, quién está moroso |
| 03:30 domingo | `tutorias:purgar-accesos` |
| 04:00 domingo | `reportes:purgar-ejecuciones` |
| cada 15 min | `reportes:enviar-programados` |
| cada hora | `clases:recoger-grabaciones` — Meet no avisa; hay que preguntarle |
| cada minuto | el latido, y **el trabajador de la cola** |

**Nada de eso corre si no hay despachador.** Laravel no se ejecuta a sí mismo:
alguien tiene que invocar `php artisan schedule:run` cada minuto, y ese alguien
es el sistema operativo.

**Y la cola de trabajos cuelga de aquí también** — ver «La cola» más abajo. Sin
despachador no hay quien timbre una factura ni quien archive una grabación.

## Lo que hay que entender antes de instalarlo

**Cada minuto, no cada cinco.** Laravel no programa nada por su cuenta: en cada
invocación mira el reloj y decide qué toca. Con una corrida cada cinco minutos,
una tarea `hourly` se dispararía a destiempo y una `everyMinute` se saltaría
cuatro de cada cinco.

**Con el usuario del servidor web, no con root.** Las tareas escriben en
`storage/` y generan archivos que después tiene que leer PHP-FPM. Corriendo
como root, esos archivos quedan con dueño equivocado y la aplicación empieza a
fallar con «permission denied» en sitios que no tienen nada que ver con el cron
— es de los errores más caros de diagnosticar.

**Un solo despachador por instalación.** Si algún día hay más de un servidor de
aplicación, sólo uno lleva el cron; las tareas ya declaran `onOneServer()` para
que el resto no las duplique, pero eso necesita un caché compartido (Redis).

## Instalación

Los archivos están en `deploy/scheduler/`. Elige **una** de las dos vías.

### systemd (recomendado)

Un timer sobrevive a los reinicios, deja registro en `journalctl` y recupera
las corridas que se hayan perdido si el servidor estuvo apagado.

```bash
sudo cp deploy/scheduler/acadion-scheduler.* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now acadion-scheduler.timer
```

Ajusta `WorkingDirectory`, la ruta de `php` y el usuario en el `.service` si tu
instalación no vive en `/var/www/acadion` con `www-data`.

Comprobación:

```bash
systemctl list-timers acadion-scheduler.timer
```

### cron

```bash
sudo crontab -u www-data -e
```

y se pega la línea de `deploy/scheduler/crontab.txt`.

## La cola de trabajos

Tres sitios encolan trabajo: `TimbrarFactura` (desde el controlador de facturas
y desde `EmisorFactura`) y `ArchivarGrabacion` (desde el webhook de Zoom y desde
el recolector de Meet). Con `QUEUE_CONNECTION=database`, encolar es insertar una
fila en `jobs` — **y alguien tiene que venir a tomarla.**

Hasta el 2026-08-27 nadie venía. La factura se quedaba en la tabla para siempre:
sin error, sin log, sin aviso, y con la pantalla diciéndole a quien la emitió que
ya estaba. Es el mismo modo de fallar que el del despachador, y por eso se
resolvió en el mismo sitio: **el trabajador lo levanta el propio despachador**,
cada minuto, con `--stop-when-empty`.

Consecuencia práctica: **instalar el despachador es instalar la cola.** No hay
un supervisor aparte que alguien pueda olvidar. A cambio, si el despachador se
cae, la cola se cae con él — que es justo lo que `scheduler:estado` dice.

**Un solo trabajador sirve a todas las escuelas.** Un trabajo despachado dentro
de un tenant no cae en la base de ese tenant: cae en la tabla `jobs` de la base
**central**, con la escuela serializada en su payload, y el
`QueueTenancyBootstrapper` la reinicia al ejecutarlo. Así que no hay que
levantar un trabajador por escuela.

### Los trabajos que fallaron

Un trabajo que agota sus reintentos pasa a `failed_jobs` y **nadie lo reintenta
solo**. Un timbrado fallido es una factura que no existe ante el SAT, así que
conviene mirarlos:

```bash
php artisan queue:failed
php artisan queue:retry all
```

`scheduler:estado` los cuenta y los nombra aunque la cola esté al día.

### Si se despliega detrás de un balanceador

El trabajador se declara con `onOneServer()`, que necesita un caché compartido
(Redis) para tener efecto. Sin él, cada servidor levantaría el suyo. No es
incorrecto —la cola de base de datos reserva cada fila, así que un trabajo no se
procesa dos veces—, pero conviene saberlo.

## Verificar que de verdad está corriendo

Aquí está el problema real de un scheduler: **cuando deja de correr no falla**.
No hay excepción, no hay log, no hay alerta. Simplemente no pasa nada, y el
síntoma llega semanas después por otro lado —«no se generaron los recargos de
marzo»— cuando ya nadie relaciona una cosa con la otra.

Por eso hay un latido: una marca que se escribe cada minuto desde el propio
scheduler. Para leerla:

```bash
php artisan scheduler:estado
```

Dice **dos cosas**, porque las dos fallan igual de calladas: si el despachador da
señales, y si la cola avanza. De la cola informa cuántos trabajos esperan, desde
cuándo espera el más viejo y cuántos fallaron.

Lo que delata a un trabajador muerto no es que haya pendientes —se acaban de
encolar— sino que el **más viejo** lleve ahí más de quince minutos. Se mira
`available_at` y no `created_at`, para que un trabajo aguardando su reintento no
dispare una alarma cuando el PAC devuelve un error pasajero.

Devuelve **código de salida 1** cuando lleva más de diez minutos sin señales o
cuando la cola está atorada, así que se puede enganchar a la vigilancia del
servidor sin leer su texto:

```bash
*/15 * * * * cd /var/www/acadion && php artisan scheduler:estado || <avisar>
```

Para ver qué tareas hay declaradas y cuándo toca cada una:

```bash
php artisan schedule:list
```

## En desarrollo (WAMP)

En Windows no hay cron. Para trabajar, se deja corriendo en una consola aparte:

```bash
php artisan schedule:work
```

Se queda en primer plano invocando el despachador cada minuto — o sea que también
procesa la cola. Si sólo hace falta eso, sale más directo:

```bash
php artisan queue:work --stop-when-empty
```

También se puede forzar una tarea concreta cuando hace falta probarla:

```bash
php artisan tutorias:purgar-accesos --seco
```
