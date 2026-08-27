<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Barrido diario de la cartera: aplica las becas por atraso, recalcula los
 * recargos y actualiza quién está moroso.
 *
 * A las 3 a.m. porque toca toda la cartera de todos los tenants y no debe
 * competir con la operación del día. `withoutOverlapping` evita que dos
 * corridas se pisen si una se alarga; `onOneServer` lo deja listo para cuando
 * haya más de un servidor.
 */
/*
 * Antes del barrido: emitir los cargos que falten de cada plan.
 *
 * A las 2:45 y no dentro de `finanzas:evaluar` a propósito. Va antes porque no
 * se puede recargar por mora un cargo que todavía no existe ni decidir quién es
 * deudor sin haberlo emitido; y va SEPARADO porque esto crea deuda y aquél sólo
 * recalcula la que ya hay —esconder un cobro dentro de un comando llamado
 * «evaluar» es como se llega a que nadie sepa de dónde salió un adeudo—.
 *
 * Los quince minutos de margen no son una carrera contra el reloj: si el
 * generador se alarga, `withoutOverlapping` impide que dos corridas suyas se
 * pisen, y lo peor que pasa es que unos cargos se emitan después del barrido y
 * su recargo entre al día siguiente.
 */
Schedule::command('finanzas:generar-cargos')
    ->dailyAt('02:45')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('finanzas:evaluar')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * Purga de los accesos a bitácoras de tutoría.
 *
 * Semanal y no diaria: no hay prisa por borrar algo de hace dos años, y una
 * corrida por semana basta para que la tabla no crezca sin control. Los
 * domingos de madrugada, cuando la escuela está vacía.
 */
Schedule::command('tutorias:purgar-accesos')
    ->weeklyOn(0, '03:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * La bitacora de reportes, por lo mismo y a otra hora.
 *
 * Escribe una fila cada vez que alguien abre un reporte, asi que crece sola.
 * A las 04:00 y no a las 03:30 para no encimarla con la purga de tutorias: las
 * dos borran por lotes y compartir la ventana no gana nada.
 */
Schedule::command('reportes:purgar-ejecuciones')
    ->weeklyOn(0, '04:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * Los reportes programados.
 *
 * Cada cuarto de hora y no cada minuto: nadie programa un reporte «a las 7:03»,
 * y con cuartos el despachador no abre una conexion por escuela sesenta veces
 * por hora para no hacer nada. Llegar tarde no salta el turno --la programacion
 * compara con la hora YA PASADA-- y lo que impide el correo repetido es
 * `ultima_corrida_en`, no la punteria.
 */
Schedule::command('reportes:enviar-programados')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * Las grabaciones de Google Meet.
 *
 * ── Este comando existía desde el 2026-08-19 y NADIE lo invocaba ─────────
 * Meet **no tiene webhook**: Zoom avisa con `recording.completed` y a Google hay
 * que preguntarle, y ésa es la razón de ser de este comando. Sin programarlo, la
 * mitad de Meet del archivado de grabaciones no se ejecutaba nunca —ni fallaba,
 * ni avisaba: la clase simplemente se quedaba sin grabación—. Mismo hueco que la
 * cola, encontrado el mismo día y por lo mismo: se construyó el mecanismo y no
 * se enganchó a nada que corriera.
 *
 * ── Cada hora ────────────────────────────────────────────────────────────
 * Una grabación de Meet tarda de minutos a horas en aparecer. Cada quince
 * minutos sería preguntarle a Google cuatro veces por lo mismo sin que pueda
 * haber cambiado; una vez al día dejaría una clase de la mañana sin grabación
 * hasta el día siguiente.
 *
 * Es barato de repetir: sale a la primera si la escuela no tiene Meet
 * configurado, salta las clases que ya tienen algo anotado, y sólo mira las
 * últimas 48 horas.
 */
Schedule::command('clases:recoger-grabaciones')
    ->hourly()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground();

/*
 * Latido del despachador.
 *
 * Un scheduler que deja de correr NO FALLA: simplemente no pasa nada, y nadie
 * se entera hasta que alguien pregunta por qué no se generaron los recargos del
 * mes. Esto deja una marca cada minuto; `scheduler:estado` la lee y dice desde
 * cuándo no hay señales.
 *
 * Va en el almacén de servicios externos —fuera de la caché del tenant— porque
 * el despachador es del SERVIDOR, no de ninguna escuela.
 */
Schedule::call(function () {
    Cache::store('scheduler')->forever('ultimo-latido', now()->toIso8601String());
})->everyMinute()->name('latido-del-despachador');

/*
 * El trabajador de la COLA.
 *
 * ── El hueco que esto cierra ─────────────────────────────────────────────
 * Tres sitios encolan trabajo —`TimbrarFactura` desde el controlador de
 * facturas y desde `EmisorFactura`, y `ArchivarGrabacion` desde el recolector—
 * y **no había nadie que lo procesara**: ni aquí, ni en `deploy/scheduler/`, ni
 * en `docs/scheduler.md`. Con `QUEUE_CONNECTION=database`, una escuela que
 * timbrara una factura dejaba la fila en `jobs` para siempre. Sin error, sin
 * aviso: la factura simplemente nunca se timbraba y quien la emitió creía que
 * sí. No se había notado porque esos caminos nunca se ejercitaron con datos.
 *
 * ── Por qué desde AQUÍ y no con un supervisor ────────────────────────────
 * Porque el despachador ya es un requisito instalado y documentado, y esto no
 * añade una segunda cosa que alguien pueda olvidar. Un supervisor sería mejor
 * para una carga alta; este proyecto no la tiene, y una pieza más de
 * infraestructura que nadie instale no procesa nada.
 *
 * ── Un solo trabajador sirve a TODAS las escuelas ────────────────────────
 * Medido: un trabajo despachado dentro del tenant `demo` cae en la tabla `jobs`
 * de la base CENTRAL, no en la suya, y su payload lleva `tenant: demo`. El
 * `QueueTenancyBootstrapper` —que este proyecto ya tiene encendido en
 * `config/tenancy.php`— reinicia la escuela correcta al ejecutarlo. Así que no
 * hace falta un trabajador por escuela.
 *
 * ── Las banderas, una por una ────────────────────────────────────────────
 * `--stop-when-empty` para que salga en cuanto no haya nada: con la cola vacía
 * —que es lo normal— el costo es un proceso que arranca y muere.
 *
 * `--max-time=55` para que ni con trabajo se quede corriendo indefinidamente:
 * un trabajador eterno se queda con el CÓDIGO VIEJO tras un despliegue y sigue
 * procesando con él sin que nadie lo note. El límite se mira ENTRE trabajos, así
 * que una grabación de media hora se termina de bajar; lo que no hace es
 * empezar otra.
 *
 * `withoutOverlapping(10)` y no más: si un trabajo largo pasa de diez minutos
 * puede arrancar un segundo trabajador, y está bien —la cola de base de datos
 * reserva cada fila, así que no se procesa dos veces, y mientras se baja un
 * video de 600 MB los timbrados no tienen por qué esperar—. Un candado más
 * largo sí sería un problema: si el trabajador muere, nadie lo releva hasta que
 * expire.
 */
Schedule::command('queue:work --stop-when-empty --max-time=55')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground();
