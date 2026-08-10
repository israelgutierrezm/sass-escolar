<?php

declare(strict_types=1);

namespace App\Credencial;

use App\Models\Academico\Campus;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Persona;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Rol;

/**
 * Qué se puede imprimir en una credencial, y de dónde sale cada cosa.
 *
 * ── Por qué es un catálogo y no columnas de una tabla ──────────────────────
 * La escuela decide QUÉ campos pone y DÓNDE, así que lo enumerable aquí no son
 * los datos de una credencial concreta sino las piezas disponibles. Cada campo
 * declara su etiqueta, cómo resolver su valor para una persona y si aplica a
 * ella. Agregar «grupo» o «vigencia» mañana es agregar una entrada, no tocar el
 * compositor ni la pantalla.
 *
 * ── El «si aplica», que es lo que pidió el cliente ─────────────────────────
 * Matrícula y carrera existen para un alumno y no para un administrativo. No se
 * resuelve preguntando por el rol —alguien puede ser docente Y estudiar— sino
 * preguntando por el DATO: si la persona tiene matrícula, se imprime; si no, el
 * campo se omite y su hueco queda vacío. Preguntar por el rol dejaría sin
 * matrícula al docente que además es alumno.
 */
class CatalogoCampos
{
    /** La foto no es texto: la dibuja el compositor recortada a su caja. */
    public const FOTO = 'foto';

    /**
     * Todos los campos, con su etiqueta y de dónde sale su valor.
     *
     * @return array<string, array{etiqueta: string, ayuda: string, tipo: string, publico: bool}>
     */
    public static function todos(): array
    {
        return [
            self::FOTO => [
                'etiqueta' => 'Fotografía',
                'ayuda' => 'La del expediente. Se recorta a la caja que le dibujes.',
                'tipo' => 'imagen',
                'publico' => true,
            ],
            'nombre' => [
                'etiqueta' => 'Nombre completo',
                'ayuda' => 'Nombre y apellidos, como estén capturados.',
                'tipo' => 'texto',
                'publico' => true,
            ],
            'matricula' => [
                'etiqueta' => 'Matrícula',
                'ayuda' => 'Sólo si la persona es alumno. Un administrativo no tiene.',
                'tipo' => 'texto',
                'publico' => true,
            ],
            'carrera' => [
                'etiqueta' => 'Carrera',
                'ayuda' => 'Sólo si la persona es alumno.',
                'tipo' => 'texto',
                'publico' => true,
            ],
            'campus' => [
                'etiqueta' => 'Campus',
                'ayuda' => 'El de su matrícula; para el personal, el de su rol.',
                'tipo' => 'texto',
                'publico' => true,
            ],
            'rol' => [
                'etiqueta' => 'Rol',
                'ayuda' => 'Cómo se le nombra en la escuela: Alumno, Docente…',
                'tipo' => 'texto',
                'publico' => true,
            ],
            'curp' => [
                'etiqueta' => 'CURP',
                'ayuda' => 'Dato personal: piénsalo dos veces antes de imprimirlo.',
                'tipo' => 'texto',
                'publico' => false,
            ],
            'vigencia' => [
                'etiqueta' => 'Vigencia',
                'ayuda' => 'El texto que definas abajo, igual para todos.',
                'tipo' => 'texto',
                'publico' => true,
            ],
        ];
    }

    /**
     * Los mismos valores, recortados a lo que puede leer un desconocido.
     *
     * La página del QR sirve para CONFIRMAR una identidad —«¿este gafete es de
     * quien dice ser?»—, no para consultar un expediente. Todo lo que no está
     * impreso en la tarjeta que esa persona trae en la mano es exposición
     * gratuita: la CURP no se muestra ni con sesión iniciada, porque la sesión
     * de cualquier alumno de la escuela bastaría para leerla escaneando gafetes
     * ajenos.
     *
     * @param  array<string, string>  $valores
     * @return array<string, string>
     */
    public static function publicos(array $valores): array
    {
        $todos = self::todos();

        return array_filter(
            $valores,
            fn (string $clave) => ($todos[$clave]['publico'] ?? false) === true,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @return array<int, string> */
    public static function claves(): array
    {
        return array_keys(self::todos());
    }

    public static function existe(string $clave): bool
    {
        return array_key_exists($clave, self::todos());
    }

    /**
     * El valor de cada campo, ya resuelto, para una credencial concreta.
     *
     * Lo que no aplica NO viene en el arreglo —no viene vacío—, para que el
     * compositor pueda distinguir «no le toca» de «le toca y está en blanco» y
     * no dibuje una etiqueta huérfana.
     *
     * ── Persona y ROL, no el usuario con sesión ───────────────────────────
     * Recibía un `Usuario` y leía su rol ACTIVO, y eso está mal por dos lados.
     * Uno: la credencial es de un rol concreto —quien da clases y estudia tiene
     * dos—, así que la que se dibuja no puede depender de en cuál esté parada
     * esa persona en ese momento. Dos: el QR se lee SIN SESIÓN, y ahí no hay
     * usuario a quien preguntarle nada.
     *
     * La matrícula se RECIBE, no se adivina: quien estudia dos carreras tiene
     * dos credenciales, una por cada una, y es quien llama el que dice cuál se
     * está dibujando. Ver `CredencialesDeLaPersona`.
     *
     * @return array<string, string>
     */
    public static function valores(
        Persona $persona,
        Rol $rol,
        ?MatriculaOferta $matricula = null,
        ?string $vigencia = null,
    ): array {
        $valores = array_filter([
            'nombre' => $persona->nombreCompleto(),
            'curp' => $persona->curp,
            'rol' => $rol->nombre ?: $rol->name,
            'vigencia' => $vigencia,
        ], fn ($v) => filled($v));

        return $valores
            + ($matricula === null ? [] : self::deLaMatricula($matricula))
            + self::delCampus($persona, $rol);
    }

    /**
     * Matrícula, carrera y campus de ESTA inscripción.
     *
     * @return array<string, string>
     */
    private static function deLaMatricula(MatriculaOferta $matricula): array
    {
        $matricula->loadMissing('oferta.carrera:id,nombre', 'oferta.campus:id,nombre');

        return array_filter([
            'matricula' => $matricula->matricula,
            'carrera' => $matricula->oferta?->carrera?->nombre,
            'campus' => $matricula->oferta?->campus?->nombre,
        ], fn ($v) => filled($v));
    }

    /**
     * El campus del personal, que no tiene matrícula de dónde sacarlo.
     *
     * Sale del alcance de su rol (`persona_rol.campus_id`), que guarda IDS y no
     * nombres —de ahí la consulta—. Con alcance global el arreglo viene vacío,
     * no hay campus que imprimir y el campo se omite: una credencial que dijera
     * «todos los campus» no significa nada. Y con más de uno tampoco se
     * imprime, porque no cabe elegir por la persona cuál es «el suyo».
     *
     * Se consulta por persona y rol en vez de usar `campusDelRolActivo()`, que
     * cuelga del `Usuario`: aquí puede no haber sesión.
     *
     * @return array<string, string>
     */
    private static function delCampus(Persona $persona, Rol $rol): array
    {
        $ids = PersonaRol::query()
            ->where('persona_id', $persona->id)
            ->where('rol_id', $rol->id)
            ->where('activo', true)
            ->whereNotNull('campus_id')
            ->pluck('campus_id')
            ->all();

        if (count($ids) !== 1) {
            return [];
        }

        $nombre = Campus::query()->whereKey($ids[0])->value('nombre');

        return filled($nombre) ? ['campus' => $nombre] : [];
    }
}
