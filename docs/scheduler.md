# El despachador de tareas programadas

Acadion tiene trabajo que ocurre solo: el barrido diario de la cartera —becas
por atraso, recargos, quién está moroso— y la purga semanal de los accesos a
bitácoras de tutoría. Todo eso está declarado en `routes/console.php`.

**Nada de eso corre si no hay despachador.** Laravel no se ejecuta a sí mismo:
alguien tiene que invocar `php artisan schedule:run` cada minuto, y ese alguien
es el sistema operativo.

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

Devuelve **código de salida 1** cuando lleva más de diez minutos sin señales, así
que se puede enganchar a la vigilancia del servidor sin leer su texto:

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

Se queda en primer plano invocando el despachador cada minuto. También se puede
forzar una tarea concreta cuando hace falta probarla:

```bash
php artisan tutorias:purgar-accesos --seco
```
