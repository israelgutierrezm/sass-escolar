<?php

/**
 * Los reportes que se mandan solos por correo. Con rollback.
 *
 * Se corre con `php scripts/prueba-reportes-programados.php` desde la raíz.
 *
 * ── La regla que esto existe para proteger ────────────────────────────────
 * Un reporte programado corre **con el rol GUARDADO**, no con alcance global ni
 * con el que su dueño tenga activo hoy. De madrugada no hay sesión abierta, así
 * que no hay rol activo del que sacar el alcance por campus; correr en global
 * sería mandarle por correo la escuela entera a quien sólo ve un plantel, todos
 * los lunes y sin que nadie lo mirara.
 *
 * Y si al dueño le retiran el rol o el permiso, **se suspende con el motivo
 * escrito**: no se degrada a otro alcance ni se calla.
 *
 * ── Lo demás que se vigila ────────────────────────────────────────────────
 *  - No se manda dos veces: `ultima_corrida_en` por FRECUENCIA.
 *  - Llegar tarde no salta el turno: se compara con la hora ya pasada.
 *  - Un destinatario sin el permiso del reporte se descarta y se ANOTA. Sin eso,
 *    programar sería una puerta lateral para hacerle llegar a alguien un padrón
 *    que su rol le niega.
 *  - El modo seco no manda ni anota.
 */

use App\Mail\ReporteProgramado as CorreoDelReporte;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Reportes\DestinatarioReporte;
use App\Models\Reportes\ProgramacionReporte;
use App\Models\Reportes\VistaReporte;
use App\Models\Tenant;
use App\Reportes\Envio\EnviadorProgramado;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

$verificaciones = 0;
$fallidas = 0;

function verificar(string $que, bool $bien, string $detalle = ''): void
{
    global $verificaciones, $fallidas;
    $verificaciones++;

    if ($bien) {
        echo "  \033[32mOK\033[0m   {$que}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallidas++;
        echo "  \033[31mFALLA\033[0m {$que}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

/** Una cuenta con el rol dado y el alcance de campus que se le diga. */
function cuentaCon(string $rol, ?int $campusId = null): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Programado',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_prog_'.random_int(100000, 999999),
        'email' => 'prueba_prog_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => Rol::where('name', $rol)->firstOrFail()->id,
    ]);

    $cuenta->persona->asignacionesRol()->create([
        'rol_id' => $cuenta->rol_activo_id,
        'activo' => true,
        'campus_id' => $campusId,
    ]);

    return $cuenta->fresh(['persona', 'rolActivo']);
}

DB::beginTransaction();

try {
    $enviador = app(EnviadorProgramado::class);
    $ejecutor = app(App\Reportes\Ejecutor::class);

    $campusUno = DB::table('oferta')->whereNull('deleted_at')->value('campus_id');
    $rolGlobal = Rol::where('name', 'director_general')->firstOrFail();

    echo PHP_EOL.'1. Se siembra el escenario: el demo no tiene ni una programación'.PHP_EOL;

    verificar('La tabla arranca vacía en el demo',
        ProgramacionReporte::query()->count() === 0,
        ProgramacionReporte::query()->count().' programaciones');

    /*
     * El dueño está ACOTADO a un campus. Es lo único que hace medible la regla:
     * con un dueño global, correr en global y correr con su rol dan lo mismo y
     * la mutación sobreviviría.
     */
    $dueno = cuentaCon('director_general', $campusUno);

    /*
     * ── El dueño tiene DOS roles, y el ACTIVO no es el que programó ───────
     *
     * Es lo único que hace medible la regla. Con un solo rol, «corre con el
     * guardado» y «corre con el que tenga activo» dan lo mismo y la mutación
     * sobrevive — pasó, y por eso este escenario existe.
     *
     * El guardado es uno PROPIO, acotado a un campus y con lo justo para ver la
     * cartera. El activo es dirección general, global. Quien programó la cartera
     * de su plantel y luego conmutó de sombrero no puede empezar a recibir la
     * escuela entera por eso.
     */
    $rolDelCampus = Rol::create([
        'name' => 'prueba_prog_campus_'.random_int(1000, 9999),
        'nombre' => 'Coordinador de prueba',
        'guard_name' => 'web',
        'rol_padre_id' => Rol::where('name', 'administrativo')->value('id'),
    ]);
    $rolDelCampus->givePermissionTo(['ver-reportes', 'ver-adeudos', 'ver-alumnos']);

    $dueno->persona->asignacionesRol()->create([
        'rol_id' => $rolDelCampus->id,
        'activo' => true,
        'campus_id' => $campusUno,
    ]);

    /*
     * Y su asignación de dirección general pasa a ser GLOBAL: así el rol activo
     * ve toda la escuela y el guardado sólo su campus. Si los dos vieran lo
     * mismo, la comprobación de abajo no distinguiría nada.
     */
    DB::table('persona_rol')
        ->where('persona_id', $dueno->persona_id)
        ->where('rol_id', $rolGlobal->id)
        ->update(['campus_id' => null]);

    $dueno = Usuario::find($dueno->id);
    auth()->login($dueno);

    $vista = VistaReporte::create([
        'reporte' => 'estado-de-cartera',
        'nombre' => 'Cartera de prueba',
        'descripcion' => null,
        'columnas' => ['matricula', 'alumno', 'campus', 'saldo'],
        'filtros' => [],
        'orden_por' => 'matricula',
        'orden_dir' => 'asc',
        'persona_id' => $dueno->persona_id,
        'rol_id' => null,
        'predeterminada' => false,
    ]);

    $programacion = ProgramacionReporte::create([
        'vista_id' => $vista->id,
        'nombre' => 'Cartera de los lunes',
        'persona_id' => $dueno->persona_id,
        // El rol ACOTADO, que no es el que tiene activo.
        'rol_id' => $rolDelCampus->id,
        'frecuencia' => ProgramacionReporte::SEMANAL,
        'dia' => 1,
        'hora' => '07:00',
        'formato' => 'csv',
        'activa' => true,
    ]);

    $programacion->destinatarios()->create([
        'tipo' => DestinatarioReporte::PERSONA,
        'destino_id' => $dueno->persona_id,
    ]);

    verificar('Queda programada, viva y sin correr',
        $programacion->activa && $programacion->suspendida_en === null
            && $programacion->ultima_corrida_en === null);

    echo PHP_EOL.'2. Le toca cuando le toca, y no antes'.PHP_EOL;

    /*
     * Un LUNES de verdad, no `startOfWeek()`.
     *
     * En esta aplicación la semana empieza en DOMINGO, así que
     * `now()->startOfWeek()` devuelve un domingo con `dayOfWeekIso` = 7 — y la
     * primera versión de esta sección comprobaba el día equivocado. El producto
     * usa `dayOfWeekIso` (1 = lunes), que no es ambiguo; la prueba tenía que
     * hablar el mismo idioma.
     */
    $lunes = now()->startOfWeek(Carbon::MONDAY);

    $lunesTemprano = $lunes->copy()->setTime(6, 30);
    $lunesALaHora = $lunes->copy()->setTime(7, 0);
    $lunesTarde = $lunes->copy()->setTime(9, 45);
    $martes = $lunes->copy()->addDay()->setTime(7, 0);

    verificar('El «lunes» de la prueba es de verdad un lunes',
        $lunesALaHora->dayOfWeekIso === 1,
        $lunesALaHora->toDateString().' (iso '.$lunesALaHora->dayOfWeekIso.')');

    verificar('Antes de su hora, NO le toca', ! $programacion->leTocaA($lunesTemprano));
    verificar('A su hora, sí', $programacion->leTocaA($lunesALaHora));

    /*
     * Llegar tarde no salta el turno. El despachador corre cada cuarto de hora y
     * la máquina puede haber estado apagada: exigir el minuto exacto haría que
     * una programación se perdiera la semana entera por llegar tarde, y nadie se
     * enteraría —no falla: simplemente no llega el correo—.
     */
    verificar('Y más tarde TAMBIÉN: llegar tarde no salta el turno',
        $programacion->leTocaA($lunesTarde));

    verificar('Otro día de la semana, no', ! $programacion->leTocaA($martes));

    echo PHP_EOL.'3. Corre con el ROL GUARDADO, no con alcance global'.PHP_EOL;

    /*
     * La medición: cuántas filas ve el dueño con su rol acotado contra cuántas
     * hay en toda la escuela. Si no difieren, este escenario no prueba nada.
     */
    $global = cuentaCon('director_general');
    auth()->login($global);
    $todas = $ejecutor->ejecutar($global, 'estado-de-cartera', [
        'columnas' => $vista->columnas, 'por_pagina' => 500,
    ])->total();

    /*
     * Lo que ve con el rol GUARDADO, que es con el que tiene que correr — no con
     * el que trae activo.
     */
    $conElGuardado = Usuario::find($dueno->id);
    $conElGuardado->rol_activo_id = $rolDelCampus->id;
    auth()->login($conElGuardado);
    $suyas = $ejecutor->ejecutar($conElGuardado, 'estado-de-cartera', [
        'columnas' => $vista->columnas, 'por_pagina' => 500,
    ])->total();

    // Y lo que vería con el ACTIVO, que es global.
    $conElActivo = Usuario::find($dueno->id);
    auth()->login($conElActivo);
    $conSuActivo = $ejecutor->ejecutar($conElActivo, 'estado-de-cartera', [
        'columnas' => $vista->columnas, 'por_pagina' => 500,
    ])->total();

    verificar('Con el rol GUARDADO ve menos que con el que trae activo',
        $suyas > 0 && $suyas < $conSuActivo,
        'guardado: '.$suyas.', activo: '.$conSuActivo.', escuela: '.$todas);

    verificar('Y su rol activo NO es el que programó',
        $conElActivo->rol_activo_id !== $rolDelCampus->id,
        'activo '.$conElActivo->rol_activo_id.' vs guardado '.$rolDelCampus->id);

    Mail::fake();

    $linea = $enviador->correr($programacion->fresh(['vista', 'dueno', 'rol', 'destinatarios']), $lunesALaHora);

    verificar('La corrida termina bien', $linea['estado'] === ProgramacionReporte::OK, json_encode($linea));

    $enviados = [];
    Mail::assertSent(CorreoDelReporte::class, function (CorreoDelReporte $correo) use (&$enviados) {
        $enviados[] = $correo;

        return true;
    });

    verificar('Se mandó un correo', count($enviados) === 1, count($enviados).' correos');

    if ($enviados !== []) {
        $correo = $enviados[0];

        /*
         * ── LA COMPROBACIÓN QUE ESTA REBANADA EXISTE PARA HACER ──────────
         *
         * El archivo trae lo que ve el DUEÑO con su rol, no la escuela entera.
         * Se cuentan los renglones del CSV, que es lo que de verdad sale por
         * correo: mirar el `filas` que reporta el servicio comprobaría que el
         * servicio se cree a sí mismo.
         */
        $renglones = substr_count(rtrim($correo->archivo), "\n"); // sin el encabezado

        verificar('El adjunto trae lo del rol GUARDADO, ni el activo ni la escuela',
            $renglones === $suyas && $renglones !== $conSuActivo && $renglones !== $todas,
            $renglones.' renglones; guardado '.$suyas.', activo '.$conSuActivo.', escuela '.$todas);

        verificar('Y el correo DICE con el alcance de quién salió',
            str_contains($correo->alcanceDe, 'Programado') && $correo->filas === $suyas,
            $correo->alcanceDe.' · '.$correo->filas.' filas');
    }

    echo PHP_EOL.'4. No se manda dos veces'.PHP_EOL;

    $yaCorrio = $programacion->fresh();

    verificar('Quedó anotada la corrida', $yaCorrio->ultima_corrida_en !== null);

    verificar('Y ya no le toca ese mismo día', ! $yaCorrio->leTocaA($lunesTarde));

    verificar('Pero al lunes siguiente sí',
        $yaCorrio->leTocaA($lunesALaHora->copy()->addWeek()));

    echo PHP_EOL.'5. Un destinatario sin permiso se DESCARTA y se anota'.PHP_EOL;

    /*
     * Se construye: un rol que entra a reportes pero NO ve la cartera. Sin este
     * caso, «se descarta al que no puede» se cumple sola porque todos pueden.
     */
    $rolCorto = Rol::create([
        'name' => 'prueba_prog_corto_'.random_int(1000, 9999),
        'nombre' => 'Rol sin cartera',
        'guard_name' => 'web',
        'rol_padre_id' => Rol::where('name', 'administrativo')->value('id'),
    ]);
    $rolCorto->givePermissionTo('ver-reportes');

    $mirón = cuentaCon('director_general');
    DB::table('persona_rol')->where('persona_id', $mirón->persona_id)->update(['rol_id' => $rolCorto->id]);
    Usuario::where('id', $mirón->id)->update(['rol_activo_id' => $rolCorto->id]);
    $mirón = Usuario::find($mirón->id);

    verificar('El rol corto NO ve la cartera',
        ! $mirón->can('ver-adeudos'), 'ver-adeudos: '.($mirón->can('ver-adeudos') ? 'sí' : 'no'));

    $programacion->destinatarios()->create([
        'tipo' => DestinatarioReporte::PERSONA,
        'destino_id' => $mirón->persona_id,
    ]);

    /*
     * Se le devuelve el turno, por el query builder y no con `forceFill`+`save`.
     *
     * `save()` sólo escribe lo SUCIO, y el valor en memoria de esta instancia
     * seguía siendo null desde que se creó —quien escribió la corrida fue una
     * copia de `fresh()`—, así que poner null no ensuciaba nada y el reinicio no
     * llegaba a la base. La prueba pasaba, pero no por lo que decía.
     */
    DB::table('programaciones_reporte')->where('id', $programacion->id)
        ->update(['ultima_corrida_en' => null]);

    Mail::fake();
    $enviados = [];

    $segunda = $enviador->correr(
        $programacion->fresh(['vista', 'dueno', 'rol', 'destinatarios']),
        $lunesALaHora,
    );

    Mail::assertSent(CorreoDelReporte::class, function (CorreoDelReporte $c) use (&$enviados) {
        $enviados[] = $c;

        return true;
    });

    verificar('Sigue mandándose al que sí puede',
        $segunda['estado'] === ProgramacionReporte::OK && count($enviados) === 1,
        json_encode($segunda));

    verificar('Y el descartado queda ANOTADO, con su razón',
        str_contains($segunda['detalle'], 'sin permiso'),
        $segunda['detalle']);

    echo PHP_EOL.'6. Si al dueño le quitan el rol, se SUSPENDE con su motivo'.PHP_EOL;

    DB::table('programaciones_reporte')->where('id', $programacion->id)
        ->update(['ultima_corrida_en' => null]);

    DB::table('persona_rol')
        ->where('persona_id', $dueno->persona_id)
        ->where('rol_id', $programacion->rol_id)
        ->update(['activo' => false]);


    Mail::fake();
    $tercera = $enviador->correr(
        $programacion->fresh(['vista', 'dueno', 'rol', 'destinatarios']),
        $lunesALaHora,
    );

    Mail::assertNothingSent();

    verificar('No manda nada', $tercera['estado'] === 'suspendida', json_encode($tercera));

    $suspendida = $programacion->fresh();

    verificar('Queda suspendida, con la fecha y el motivo escritos',
        $suspendida->suspendida_en !== null
            && str_contains((string) $suspendida->motivo_suspension, 'retiraron el rol'),
        (string) $suspendida->motivo_suspension);

    /*
     * Y NO se degrada: suspendida es suspendida. Correr «con lo que le quede»
     * convertiría un cambio de permisos en un correo con distinto contenido que
     * nadie pidió.
     */
    verificar('Y suspendida ya no le toca nunca',
        ! $suspendida->leTocaA($lunesALaHora) && ! $suspendida->leTocaA($lunesALaHora->copy()->addWeek()));

    echo PHP_EOL.'6b. Y si conserva el rol pero PIERDE el permiso, también'.PHP_EOL;

    /*
     * Son dos cosas distintas y las dos tienen que suspender. Quitarle el rol es
     * lo evidente; quitarle al ROL el permiso es lo que pasa de verdad cuando
     * alguien reorganiza `/plataforma/roles`, y ahí el dueño sigue teniendo su
     * rol —así que la comprobación de arriba no lo cazaría—.
     */
    DB::table('persona_rol')
        ->where('persona_id', $dueno->persona_id)
        ->where('rol_id', $programacion->rol_id)
        ->update(['activo' => true]);

    DB::table('programaciones_reporte')->where('id', $programacion->id)->update([
        'suspendida_en' => null, 'motivo_suspension' => null, 'ultima_corrida_en' => null,
    ]);

    $rolDelCampus->revokePermissionTo('ver-adeudos');

    Mail::fake();
    $sinPermiso = $enviador->correr(
        $programacion->fresh(['vista', 'dueno', 'rol', 'destinatarios']),
        $lunesALaHora,
    );

    Mail::assertNothingSent();

    verificar('Sin el permiso NO manda, aunque conserve el rol',
        $sinPermiso['estado'] === 'suspendida', json_encode($sinPermiso));

    verificar('Y el motivo dice que es el PERMISO, no el rol',
        str_contains($programacion->fresh()->motivo_suspension ?? '', 'permiso'),
        (string) $programacion->fresh()->motivo_suspension);

    $rolDelCampus->givePermissionTo('ver-adeudos');

    echo PHP_EOL.'7. El modo SECO no manda ni anota'.PHP_EOL;

    // Se le devuelve el rol y el turno.
    DB::table('persona_rol')
        ->where('persona_id', $dueno->persona_id)
        ->where('rol_id', $programacion->rol_id)
        ->update(['activo' => true]);

    DB::table('programaciones_reporte')->where('id', $programacion->id)->update([
        'suspendida_en' => null,
        'motivo_suspension' => null,
        'ultima_corrida_en' => null,
    ]);

    Mail::fake();
    $antesDelSeco = $programacion->fresh()->ultima_corrida_en;

    $seca = $enviador->correr(
        $programacion->fresh(['vista', 'dueno', 'rol', 'destinatarios']),
        $lunesALaHora,
        seco: true,
    );

    Mail::assertNothingSent();

    /*
     * `assertNothingSent()` de arriba ya es la comprobación de que no manda; una
     * línea con `true` literal no comprueba nada y esta casa las ha retirado
     * varias veces. Lo que queda por mirar es lo OTRO que el modo seco promete.
     */
    $despuesDelSeco = $programacion->fresh()->ultima_corrida_en;

    verificar('Y no anota la corrida',
        $despuesDelSeco?->toDateTimeString() === $antesDelSeco?->toDateTimeString(),
        'antes: '.($antesDelSeco?->toDateTimeString() ?? 'null')
            .', despues: '.($despuesDelSeco?->toDateTimeString() ?? 'null')
            .'  (reporta: '.$seca['estado'].')');

    verificar('Pero DICE qué mandaría', str_contains($seca['detalle'], 'filas'), $seca['detalle']);

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    DB::rollBack();
}
