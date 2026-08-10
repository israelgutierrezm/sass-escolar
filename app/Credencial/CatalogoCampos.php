<?php

declare(strict_types=1);

namespace App\Credencial;

use App\Models\Academico\Campus;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;

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
     * @return array<string, array{etiqueta: string, ayuda: string, tipo: string}>
     */
    public static function todos(): array
    {
        return [
            self::FOTO => [
                'etiqueta' => 'Fotografía',
                'ayuda' => 'La del expediente. Se recorta a la caja que le dibujes.',
                'tipo' => 'imagen',
            ],
            'nombre' => [
                'etiqueta' => 'Nombre completo',
                'ayuda' => 'Nombre y apellidos, como estén capturados.',
                'tipo' => 'texto',
            ],
            'matricula' => [
                'etiqueta' => 'Matrícula',
                'ayuda' => 'Sólo si la persona es alumno. Un administrativo no tiene.',
                'tipo' => 'texto',
            ],
            'carrera' => [
                'etiqueta' => 'Carrera',
                'ayuda' => 'Sólo si la persona es alumno.',
                'tipo' => 'texto',
            ],
            'campus' => [
                'etiqueta' => 'Campus',
                'ayuda' => 'El de su matrícula; para el personal, el de su rol.',
                'tipo' => 'texto',
            ],
            'rol' => [
                'etiqueta' => 'Rol',
                'ayuda' => 'Cómo se le nombra en la escuela: Alumno, Docente…',
                'tipo' => 'texto',
            ],
            'curp' => [
                'etiqueta' => 'CURP',
                'ayuda' => 'Dato personal: piénsalo dos veces antes de imprimirlo.',
                'tipo' => 'texto',
            ],
            'vigencia' => [
                'etiqueta' => 'Vigencia',
                'ayuda' => 'El texto que definas abajo, igual para todos.',
                'tipo' => 'texto',
            ],
        ];
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
     * El valor de cada campo para esta persona, ya resuelto.
     *
     * Lo que no aplica NO viene en el arreglo —no viene vacío—, para que el
     * compositor pueda distinguir «no le toca» de «le toca y está en blanco» y
     * no dibuje una etiqueta huérfana.
     *
     * @return array<string, string>
     */
    public static function valores(Usuario $usuario, ?string $vigencia = null): array
    {
        $persona = $usuario->persona;

        if ($persona === null) {
            return [];
        }

        $valores = array_filter([
            'nombre' => $persona->nombreCompleto(),
            'curp' => $persona->curp,
            'rol' => $usuario->rolActivo?->nombre ?? $usuario->rolActivo?->name,
            'vigencia' => $vigencia,
        ], fn ($v) => filled($v));

        return $valores + self::deLaMatricula($persona) + self::delCampus($usuario);
    }

    /**
     * Matrícula y carrera, si es alumno.
     *
     * Se toma la matrícula más reciente cuando hay varias: quien estudia dos
     * carreras tiene una sola credencial, y la vigente es la de su inscripción
     * más nueva. Imprimir las dos no cabe, y elegir «la primera» sería mostrar
     * la carrera que ya terminó.
     *
     * @return array<string, string>
     */
    private static function deLaMatricula(Persona $persona): array
    {
        $matricula = MatriculaOferta::query()
            ->with('oferta.carrera:id,nombre', 'oferta.campus:id,nombre')
            ->where('persona_id', $persona->id)
            ->orderByDesc('id')
            ->first();

        if ($matricula === null) {
            return [];
        }

        return array_filter([
            'matricula' => $matricula->matricula,
            'carrera' => $matricula->oferta?->carrera?->nombre,
            'campus' => $matricula->oferta?->campus?->nombre,
        ], fn ($v) => filled($v));
    }

    /**
     * El campus del personal, que no tiene matrícula de dónde sacarlo.
     *
     * Sale del alcance de su rol (`persona_rol.campus_id`), que devuelve IDS y
     * no nombres —de ahí la consulta—. Con alcance global el arreglo viene
     * vacío, no hay campus que imprimir y el campo se omite: una credencial que
     * dijera «todos los campus» no significa nada. Y con más de uno tampoco se
     * imprime, porque no cabe elegir por la persona cuál es «el suyo».
     *
     * @return array<string, string>
     */
    private static function delCampus(Usuario $usuario): array
    {
        $ids = $usuario->campusDelRolActivo();

        if (count($ids) !== 1) {
            return [];
        }

        $nombre = Campus::query()->whereKey($ids[0])->value('nombre');

        return filled($nombre) ? ['campus' => $nombre] : [];
    }
}
