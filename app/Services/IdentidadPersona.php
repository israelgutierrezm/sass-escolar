<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Identidad\Persona;
use App\Models\Landlord\EntidadFederativa;
use App\Models\Landlord\Genero;
use App\Models\Landlord\Pais;
use App\Models\Landlord\Sexo;
use App\Support\Curp;
use Illuminate\Support\Collection;

/**
 * Las reglas de identidad de una persona, en un solo lugar.
 *
 * Estaban repartidas en seis controladores —aspirantes, alumnos, docentes,
 * expediente docente, usuarios, formulario público— y cada uno preguntaba el
 * sexo, normalizaba la CURP a su manera y buscaba duplicados con su propio
 * criterio (o con ninguno). Un criterio de duplicados que cambia según por
 * dónde entres no es un criterio: es una fuga.
 *
 * Aquí viven tres decisiones:
 *
 * 1. **El sexo se deriva, no se pregunta.** De la CURP si la hay; del género si
 *    es inequívoco; null si no. Ver la migración `sexo_derivado_en_personas`.
 * 2. **`EXTRANJERO` no es una CURP.** Es la marca de «no tengo»: se traduce a
 *    curp null + entidad «Nacido en el Extranjero», y entonces el país sí
 *    importa. Con CURP el país se obvia: es México, por definición.
 * 3. **Antes de crear a alguien se busca si ya está.** Por CURP, por correo y
 *    por nombre+fecha de nacimiento, en ese orden de confianza.
 */
class IdentidadPersona
{
    /** Clave de la entidad que la CURP usa para quien nació fuera de México. */
    private const ENTIDAD_EXTRANJERO = 'NE';

    /** Géneros que sí determinan el sexo legal. Los demás, a propósito, no. */
    private const GENERO_A_SEXO = ['Masculino' => 'H', 'Femenino' => 'M'];

    /**
     * Traduce lo que llegó del formulario a columnas de `personas`.
     *
     * Recibe los datos ya validados y devuelve el arreglo listo para
     * `create()`/`fill()`, con `curp`, `sexo_id`, `fecha_nacimiento`,
     * `entidad_nacimiento_id` y `pais_nacimiento_id` ya resueltos entre sí.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function resolver(array $datos): array
    {
        $curp = Curp::leer($datos['curp'] ?? null);
        $extranjero = Curp::esMarcaDeExtranjero($datos['curp'] ?? null);

        $resuelto = [
            'nombre' => $datos['nombre'],
            'primer_apellido' => $datos['primer_apellido'],
            'segundo_apellido' => $datos['segundo_apellido'] ?? null,
            'curp' => $curp?->valor,
            'genero_id' => $datos['genero_id'] ?? null,
            'email' => $datos['email'] ?? null,
            'celular' => $datos['celular'] ?? null,
        ];

        // La CURP manda sobre lo tecleado: es dato verificado con dígito
        // verificador, no una captura. Pero solo sobre lo que ella sabe.
        $resuelto['fecha_nacimiento'] = $curp?->fechaNacimiento?->format('Y-m-d')
            ?? ($datos['fecha_nacimiento'] ?? null);

        $resuelto['sexo_id'] = $this->sexoDe($curp, $datos['genero_id'] ?? null);

        [$entidad, $pais] = $this->origenDe($curp, $extranjero, $datos);

        $resuelto['entidad_nacimiento_id'] = $entidad;
        $resuelto['pais_nacimiento_id'] = $pais;

        return $resuelto;
    }

    /**
     * Lo que el formulario debe mostrar cuando se teclea una CURP, sin guardar
     * nada todavía: el eco inmediato que evita recapturar tres campos.
     *
     * @return array<string, mixed>
     */
    public function analizar(?string $texto, ?int $excluirPersonaId = null): array
    {
        if (Curp::esMarcaDeExtranjero($texto)) {
            return [
                'estado' => 'extranjero',
                'mensaje' => 'Sin CURP. Indica el país de nacimiento.',
                'entidad_nacimiento_id' => $this->entidadExtranjero()?->id,
                'pais_sugerido' => null,
            ];
        }

        $curp = Curp::leer($texto);

        if ($curp === null) {
            return [
                'estado' => Curp::normalizar($texto) === '' ? 'vacia' : 'invalida',
                // No se dice «no existe»: eso solo lo sabe RENAPO. Se dice lo
                // único que aquí consta, que está mal escrita.
                'mensaje' => 'La CURP no cuadra. Revisa que esté completa y bien copiada.',
            ];
        }

        $duplicada = Persona::query()
            ->where('curp', $curp->valor)
            ->when($excluirPersonaId, fn ($q, $id) => $q->whereKeyNot($id))
            ->first();

        return [
            'estado' => 'valida',
            'curp' => $curp->valor,
            'fecha_nacimiento' => $curp->fechaNacimiento?->format('Y-m-d'),
            'genero_id' => $this->generoSugerido($curp->claveSexo)?->id,
            'entidad_nacimiento_id' => $this->entidadPorClave($curp->claveEntidad)?->id,
            'pais_nacimiento_id' => $curp->claveEntidad === self::ENTIDAD_EXTRANJERO
                ? null
                : $this->mexico()?->id,
            // Que ya exista NO es un error: el sistema reutiliza la persona en
            // vez de duplicarla. Se avisa para que quien captura lo sepa antes
            // de escribir veinte campos que se van a descartar.
            'persona_existente' => $duplicada === null ? null : $this->ficha($duplicada),
        ];
    }

    /**
     * Personas que podrían ser la misma que se está por dar de alta.
     *
     * Tres criterios, de más a menos confianza. Ninguno bloquea por sí solo:
     * dos hermanos comparten apellidos y a veces correo familiar, y el sistema
     * no puede negarse a registrar al segundo. Quien captura decide.
     *
     * @param  array<string, mixed>  $datos
     * @return Collection<int, array<string, mixed>>
     */
    public function posiblesDuplicados(array $datos, ?int $excluirPersonaId = null): Collection
    {
        $curp = Curp::leer($datos['curp'] ?? null)?->valor;
        $email = filled($datos['email'] ?? null) ? mb_strtolower(trim((string) $datos['email'])) : null;
        $nombre = trim(($datos['nombre'] ?? '').' '.($datos['primer_apellido'] ?? '').' '.($datos['segundo_apellido'] ?? ''));
        $fecha = $datos['fecha_nacimiento'] ?? null;

        return Persona::query()
            ->when($excluirPersonaId, fn ($q, $id) => $q->whereKeyNot($id))
            ->where(function ($q) use ($curp, $email, $nombre, $fecha) {
                $q->whereRaw('1 = 0'); // sin criterios, no coincide con nadie

                if ($curp !== null) {
                    $q->orWhere('curp', $curp);
                }

                if ($email !== null) {
                    $q->orWhereRaw('lower(email) = ?', [$email]);
                }

                // Nombre completo + fecha de nacimiento. El nombre solo NO
                // basta: hay tocayos, y bloquear por homonimia obligaría a la
                // escuela a inventar variantes del nombre para poder capturar.
                if ($fecha !== null && $nombre !== '') {
                    $q->orWhere(fn ($sub) => $sub
                        ->whereRaw("concat_ws(' ', nombre, primer_apellido, segundo_apellido) = ?", [$nombre])
                        ->where('fecha_nacimiento', $fecha));
                }
            })
            ->limit(5)
            ->get()
            ->map(fn (Persona $p) => $this->ficha($p, $curp, $email));
    }

    /**
     * La persona a reutilizar, si la CURP ya está registrada.
     *
     * Solo por CURP: es el único criterio que identifica sin lugar a duda, y
     * reutilizar por correo sería peligroso —familias que comparten uno—.
     */
    public function existentePorCurp(?string $texto): ?Persona
    {
        $curp = Curp::leer($texto);

        return $curp === null ? null : Persona::query()->where('curp', $curp->valor)->first();
    }

    /**
     * Catálogos de nacimiento, ya ordenados como se deben mostrar.
     *
     * «Nacido en el Extranjero» va ARRIBA, junto a «sin especificar», y no
     * perdido en la N entre Nayarit y Nuevo León: es una respuesta de otra
     * naturaleza, no un estado más de la lista.
     *
     * @return array<string, mixed>
     */
    public function catalogosDeOrigen(): array
    {
        $entidades = EntidadFederativa::query()->orderBy('nombre')->get(['id', 'clave', 'nombre']);

        $extranjero = $entidades->firstWhere('clave', self::ENTIDAD_EXTRANJERO);

        return [
            'entidades' => $entidades
                ->reject(fn (EntidadFederativa $e) => $e->clave === self::ENTIDAD_EXTRANJERO)
                ->values()
                ->map(fn (EntidadFederativa $e) => ['id' => $e->id, 'nombre' => $e->nombre])
                ->all(),
            'entidadExtranjero' => $extranjero === null
                ? null
                : ['id' => $extranjero->id, 'nombre' => 'Nacido en el extranjero'],
            'paises' => Pais::query()->orderBy('nombre')->get(['id', 'nombre']),
            'generos' => Genero::query()->orderBy('id')->get(['id', 'nombre']),
        ];
    }

    /** @return array{0: ?int, 1: ?int} entidad y país de nacimiento */
    private function origenDe(?Curp $curp, bool $extranjero, array $datos): array
    {
        if ($extranjero) {
            // Sin CURP y declarado extranjero: la entidad la fija el sistema y
            // el país es lo único que aporta información.
            return [$this->entidadExtranjero()?->id, $datos['pais_nacimiento_id'] ?? null];
        }

        if ($curp !== null) {
            $entidad = $this->entidadPorClave($curp->claveEntidad);

            return [
                $entidad?->id ?? ($datos['entidad_nacimiento_id'] ?? null),
                // Tener CURP implica registro en México. El único matiz es la
                // clave NE: mexicano nacido fuera, y ahí el país sí se pregunta.
                $curp->claveEntidad === self::ENTIDAD_EXTRANJERO
                    ? ($datos['pais_nacimiento_id'] ?? null)
                    : $this->mexico()?->id,
            ];
        }

        $entidadId = $datos['entidad_nacimiento_id'] ?? null;

        return [
            $entidadId,
            $datos['pais_nacimiento_id'] ?? ($entidadId === null ? null : $this->mexico()?->id),
        ];
    }

    private function sexoDe(?Curp $curp, ?int $generoId): ?int
    {
        if ($curp !== null) {
            return $this->sexoPorClave($curp->claveSexo)?->id;
        }

        $genero = $generoId === null ? null : Genero::query()->find($generoId);
        $clave = self::GENERO_A_SEXO[$genero?->nombre] ?? null;

        // «No binario» y «prefiere no decir» caen aquí y devuelven null, que es
        // exactamente lo correcto: no hay dato legal que deducir de ahí.
        return $clave === null ? null : $this->sexoPorClave($clave)?->id;
    }

    private function generoSugerido(string $claveSexo): ?Genero
    {
        $nombre = array_search($claveSexo, self::GENERO_A_SEXO, true);

        return $nombre === false ? null : Genero::query()->where('nombre', $nombre)->first();
    }

    /** @return array<string, mixed> */
    private function ficha(Persona $persona, ?string $curp = null, ?string $email = null): array
    {
        return [
            'id' => $persona->id,
            'nombre_completo' => $persona->nombreCompleto(),
            'curp' => $persona->curp,
            'email' => $persona->email,
            'fecha_nacimiento' => $persona->fecha_nacimiento?->toDateString(),
            'coincide_por' => match (true) {
                $curp !== null && $persona->curp === $curp => 'curp',
                $email !== null && mb_strtolower((string) $persona->email) === $email => 'correo',
                default => 'nombre y fecha de nacimiento',
            },
        ];
    }

    private function entidadExtranjero(): ?EntidadFederativa
    {
        return $this->entidadPorClave(self::ENTIDAD_EXTRANJERO);
    }

    private function entidadPorClave(string $clave): ?EntidadFederativa
    {
        return EntidadFederativa::query()->where('clave', $clave)->first();
    }

    private function sexoPorClave(string $clave): ?Sexo
    {
        return Sexo::query()->where('clave', $clave)->first();
    }

    private function mexico(): ?Pais
    {
        return Pais::query()->where('clave_iso', 'MEX')->first();
    }
}
