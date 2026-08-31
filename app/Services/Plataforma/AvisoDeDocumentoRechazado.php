<?php

declare(strict_types=1);

namespace App\Services\Plataforma;

use App\Enums\DestinoEvento;
use App\Enums\PrioridadAviso;
use App\Models\Identidad\Persona;
use App\Models\Plataforma\Aviso;
use App\Services\Familia\RepresentacionDelTutor;

/**
 * Cuando la escuela rechaza un documento, quien lo entregó tiene que ENTERARSE.
 *
 * ── Por qué un aviso y no basta con el expediente ──────────────────────────
 * El motivo del rechazo ya se ve en el portal del alumno y en el de su familia,
 * pero sólo si entran a mirarlo, y nadie entra a su expediente «por si acaso».
 * Un documento rechazado que nadie corrige es un trámite parado, y la escuela
 * se entera el día que hace falta el papel. El aviso es lo que empuja.
 *
 * ── A quién llega ──────────────────────────────────────────────────────────
 * Siempre al ALUMNO, y además a su FAMILIA cuando es menor de edad. La segunda
 * mitad no depende del interruptor de entrega —ése decide si el tutor puede
 * SUBIR, y enterarse es otra cosa—: de un menor responde su familia, tenga o no
 * permiso de cargar el archivo, porque es quien va a traer el papel a la
 * ventanilla. La edad la resuelve {@see RepresentacionDelTutor}, que es donde
 * vive esa definición.
 *
 * ── Se emite al CAMBIAR de estado, no cada vez que se guarda ───────────────
 * Un aviso anuncia un hecho nuevo. Reescribir el motivo de un rechazo que ya
 * estaba rechazado no es un hecho nuevo: el motivo corregido se lee en el
 * expediente y un segundo aviso sólo enseñaría a ignorar el primero. Quien
 * decide es el llamador, que es el único que sabe cómo estaba antes.
 *
 * ── Y CADUCA ───────────────────────────────────────────────────────────────
 * En cuanto el alumno vuelve a subir el documento, el aviso queda diciendo algo
 * que ya no es cierto. No se retira al re-subir —eso ataría la carga de un
 * archivo a la tabla de avisos por una referencia que no existe— así que en su
 * lugar nace con vigencia: pasado ese plazo se calla solo, y la verdad sigue
 * estando donde siempre, en el expediente.
 */
class AvisoDeDocumentoRechazado
{
    /**
     * Días que el aviso se queda a la vista.
     *
     * No es un ajuste de la escuela: nadie va a querer afinar cuántos días dura
     * el recordatorio de un papel rechazado, y este proyecto ya tuvo que
     * retirar interruptores que nadie leía. Es un plazo del mecanismo, escrito
     * donde se ve.
     */
    private const DIAS_VIGENTE = 30;

    public function __construct(private readonly RepresentacionDelTutor $representacion) {}

    /**
     * Levanta el aviso del rechazo.
     *
     * @param  Persona  $persona  el alumno dueño del expediente
     * @param  string  $documento  cómo se llama el papel, tal cual lo ve él
     * @param  string|null  $motivo  lo que escribió quien revisó
     */
    public function emitir(Persona $persona, string $documento, ?string $motivo): Aviso
    {
        $aviso = Aviso::create([
            'titulo' => 'Documento rechazado: '.$documento,
            /*
             * El motivo va DENTRO del cuerpo y no sólo en el expediente: un
             * aviso que dice «tienes un documento rechazado» y obliga a ir a
             * otra pantalla para saber por qué es media notificación.
             */
            'cuerpo' => $this->cuerpo($documento, $motivo),
            /*
             * Importante y no crítico: un crítico exige confirmación y se pone
             * delante de todo hasta que alguien lo acepta. Eso es para lo que
             * no admite «luego lo veo»; un papel por corregir sí lo admite.
             */
            'prioridad' => PrioridadAviso::Importante,
            'publicado' => true,
            'publicado_desde' => now(),
            'vigente_hasta' => now()->addDays(self::DIAS_VIGENTE),
        ]);

        // Al alumno, señalado por su persona: es como `DestinoEvento::Alumno`
        // resuelve, y es lo que hace que le llegue a él y a nadie más.
        $aviso->destinos()->create([
            'tipo' => DestinoEvento::Alumno,
            'destino_id' => $persona->id,
        ]);

        if ($this->representacion->esMenor($persona)) {
            // El modificador, sin id: extiende a las familias lo que el destino
            // de arriba ya dijo.
            $aviso->destinos()->create([
                'tipo' => DestinoEvento::Familiares,
                'destino_id' => null,
            ]);
        }

        return $aviso;
    }

    private function cuerpo(string $documento, ?string $motivo): string
    {
        $texto = sprintf('La escuela revisó «%s» y no lo dio por bueno.', $documento);

        if (filled($motivo)) {
            $texto .= PHP_EOL.PHP_EOL.'Motivo: '.$motivo;
        }

        return $texto.PHP_EOL.PHP_EOL
            .'Vuelve a subirlo corregido desde tu expediente. Al cargarlo de nuevo '
            .'queda otra vez pendiente de revisión.';
    }
}
