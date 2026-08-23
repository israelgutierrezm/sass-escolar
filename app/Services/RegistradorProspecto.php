<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\EtapaCrm;
use App\Models\Admisiones\RespuestaCampo;
use App\Models\Formularios\CampoFormulario;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Models\Promocion\FormularioPublico;
use App\Models\Promocion\OrigenAspirante;
use App\Models\Promocion\SeguimientoAspirante;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Da de alta un prospecto que llegó SOLO desde la página de la escuela.
 *
 * Todo en una transacción: una persona sin aspirante, o un aspirante sin sus
 * respuestas, son registros que después nadie sabe de dónde salieron.
 *
 * Este servicio corre SIN SESIÓN, con datos que escribió un desconocido en
 * internet. Eso gobierna sus tres reglas más importantes:
 *
 *  1. **Nunca sobreescribe una persona existente.** Si la CURP ya está en la
 *     base, se liga el prospecto a esa persona sin tocarle un solo dato. Un
 *     formulario anónimo que pudiera corregir el nombre o el teléfono de
 *     alguien es una forma de secuestrar un expediente.
 *  2. **La deduplicación es por CURP y solo por CURP.** El correo no
 *     identifica: es trivial teclear el de otro, y ligar por correo dejaría a
 *     un tercero dentro del expediente ajeno. Sin CURP se crea persona nueva y
 *     que admisiones consolide.
 *  3. **No se repite el prospecto.** Si esa persona ya tiene una solicitud
 *     viva para la misma oferta, no se crea otra: se registra el reintento
 *     como seguimiento. Sin esto, quien llena el formulario cinco veces
 *     produce cinco prospectos y cinco llamadas.
 */
class RegistradorProspecto
{
    /**
     * @param  array<string, mixed>  $datos  nombre, primer_apellido, ..., y `respuestas`
     * @return array{aspirante: Aspirante, usuario: ?Usuario, repetido: bool}
     */
    public function registrar(FormularioPublico $publicacion, array $datos, ?string $ip = null): array
    {
        if (! $publicacion->estaAbierto()) {
            // Se revalida aquí y no solo al pintar: la campaña pudo cerrarse
            // entre que alguien abrió la pestaña y envió el formulario.
            throw new RuntimeException('Esta convocatoria ya cerró.');
        }

        return DB::transaction(function () use ($publicacion, $datos, $ip) {
            $persona = $this->resolverPersona($datos);

            $ofertaId = $publicacion->oferta_id ?? ($datos['oferta_id'] ?? null);

            $existente = Aspirante::query()
                ->where('persona_id', $persona->id)
                ->when($ofertaId !== null, fn ($q) => $q->where('oferta_interes_id', $ofertaId))
                ->first();

            if ($existente !== null) {
                // No se duplica; se deja constancia de que volvió a escribir,
                // que para promoción es una señal de interés, no ruido.
                SeguimientoAspirante::create([
                    'aspirante_id' => $existente->id,
                    'persona_id' => null,
                    'etapa_crm_id' => $existente->etapa_crm_id,
                    'nota' => 'Volvió a enviar el formulario público «'.$publicacion->nombre.'».'
                        .($ip !== null ? ' Desde '.$ip.'.' : ''),
                    'momento' => now(),
                ]);

                $publicacion->increment('envios');

                return ['aspirante' => $existente, 'usuario' => null, 'repetido' => true];
            }

            $aspirante = Aspirante::create([
                'persona_id' => $persona->id,
                'oferta_interes_id' => $ofertaId,
                'campus_id' => $publicacion->campus_id,
                'etapa_crm_id' => $publicacion->etapa_crm_id ?? EtapaCrm::orderBy('orden')->value('id'),
                'origen_id' => $publicacion->origen_id ?? $this->origenAutogestivoPorDefecto(),
                'acepto_terminos' => (bool) ($datos['acepto_terminos'] ?? false),
            ]);

            // Se le asigna titular desde el minuto uno si la campaña lo dice.
            // Un prospecto autogestivo sin dueño es el que nadie llama.
            if ($publicacion->asesor_persona_id !== null) {
                $aspirante->asesores()->attach($publicacion->asesor_persona_id, ['titular' => true]);
            }

            $this->guardarRespuestas($publicacion, $aspirante, (array) ($datos['respuestas'] ?? []));

            SeguimientoAspirante::create([
                'aspirante_id' => $aspirante->id,
                'persona_id' => null,
                'etapa_crm_id' => $aspirante->etapa_crm_id,
                'nota' => 'Se registró solo desde «'.$publicacion->nombre.'».'
                    .($ip !== null ? ' Desde '.$ip.'.' : ''),
                'momento' => now(),
            ]);

            $usuario = $publicacion->permiteCuenta()
                ? $this->crearCuenta($persona, $datos)
                : null;

            $publicacion->increment('envios');

            return ['aspirante' => $aspirante, 'usuario' => $usuario, 'repetido' => false];
        });
    }

    /**
     * La persona: la existente si la CURP coincide, o una nueva.
     *
     * NO se actualiza nada de la existente. Ver la nota de la clase.
     */
    private function resolverPersona(array $datos): Persona
    {
        $curp = strtoupper(trim((string) ($datos['curp'] ?? '')));

        if ($curp !== '') {
            $existente = Persona::query()->where('curp', $curp)->first();

            if ($existente !== null) {
                return $existente;
            }
        }

        return Persona::create([
            'nombre' => trim((string) $datos['nombre']),
            'primer_apellido' => trim((string) $datos['primer_apellido']),
            'segundo_apellido' => trim((string) ($datos['segundo_apellido'] ?? '')) ?: null,
            'curp' => $curp !== '' ? $curp : null,
            'email' => trim((string) ($datos['email'] ?? '')) ?: null,
            'celular' => trim((string) ($datos['celular'] ?? '')) ?: null,
            'sexo_id' => $datos['sexo_id'] ?? null,
        ]);
    }

    /**
     * Las respuestas del formulario dinámico. Se guardan con la VERSIÓN que se
     * contestó, no la vigente: si mañana se publica otra, esta respuesta debe
     * seguir queriendo decir lo que quiso decir.
     *
     * @param  array<int|string, mixed>  $respuestas  campo_id => valor
     */
    private function guardarRespuestas(FormularioPublico $publicacion, Aspirante $aspirante, array $respuestas): void
    {
        if ($respuestas === []) {
            return;
        }

        $formulario = $publicacion->formulario;

        $campos = CampoFormulario::query()
            ->where('formulario_id', $formulario->id)
            ->pluck('id')
            ->all();

        foreach ($respuestas as $campoId => $valor) {
            // Solo campos de ESTE formulario: un id ajeno colado en el POST
            // ensuciaría las respuestas de otro.
            if (! in_array((int) $campoId, $campos, true)) {
                continue;
            }

            if ($valor === null || $valor === '' || $valor === []) {
                continue;
            }

            RespuestaCampo::create([
                'aspirante_id' => $aspirante->id,
                'campo_formulario_id' => (int) $campoId,
                'persona_id' => $aspirante->persona_id,
                'formulario_version' => $formulario->version,
                'valor' => is_array($valor) ? implode(', ', $valor) : (string) $valor,
            ]);
        }
    }

    /**
     * Fija el acceso del aspirante en modo autoservicio (formulario público).
     *
     * La cuenta y el rol `aspirante` ya los creó el observer al insertar el
     * aspirante (ver App\Services\AprovisionadorAcceso). Aquí solo se le pone la
     * contraseña que la persona eligió y se marca su acceso como configurado.
     *
     * Si la persona YA tenía una cuenta CON acceso —suya, de antes— no se toca:
     * un formulario anónimo que pudiera reescribir credenciales sería la forma
     * más simple de tomar la cuenta de alguien más.
     */
    private function crearCuenta(Persona $persona, array $datos): ?Usuario
    {
        $password = (string) ($datos['password'] ?? '');

        if ($password === '') {
            return null;
        }

        $usuario = Usuario::query()->where('persona_id', $persona->id)->first();

        if ($usuario === null || $usuario->acceso_configurado) {
            return $usuario;
        }

        $usuario->forceFill([
            'password' => Hash::make($password),
            'acceso_configurado' => true,
        ])->save();

        return $usuario;
    }

    private function origenAutogestivoPorDefecto(): ?int
    {
        return OrigenAspirante::query()->autogestivos()->value('id');
    }
}
