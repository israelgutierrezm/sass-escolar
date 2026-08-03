<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
