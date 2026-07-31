<?php

declare(strict_types=1);

namespace App\Http\Controllers\Emision;

use App\Enums\EstadoLoteTitulacion;
use App\Http\Controllers\Controller;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Emision\LoteTitulacion;
use App\Models\Emision\Responsable;
use App\Models\Emision\TipoResponsable;
use App\Models\Emision\Titulacion;
use App\Models\Emision\TitulacionWsConfig;
use App\Services\EstadoCertificacion;
use App\Services\Emision\ClienteTitulosSep;
use App\Services\Emision\FirmadorLoteTitulo;
use App\Services\Emision\ValidadorTitulo;
use App\Services\LectorCertificado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Lotes de titulación: armar un bloque de egresados, cerrarlo, firmarlo con la
 * e.firma del responsable de titulación y enviarlo al web service de la SEP.
 *
 * A diferencia del lote de certificación, cada lote lleva una ETAPA
 * (pruebas/producción) que se sella al crearlo con la etapa activa del WS. Antes
 * de firmar y de enviar se valida que la etapa del lote siga coincidiendo con la
 * activa, para no mandar un lote de producción al endpoint de pruebas ni al revés.
 */
class LoteTitulacionController extends Controller
{
    public function index(): \Inertia\Response
    {
        $lotes = LoteTitulacion::query()
            ->withCount([
                'titulaciones',
                'titulaciones as titulados_count' => fn ($q) => $q->where('estado', Titulacion::TITULADO),
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn (LoteTitulacion $l) => $this->filaLote($l));

        return \Inertia\Inertia::render('Titulacion/Lotes/Index', [
            'lotes' => $lotes,
            'etapaActiva' => TitulacionWsConfig::actual()->etapa_activa,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate(['nombre' => ['nullable', 'string', 'max:160']]);

        $lote = DB::transaction(function () use ($datos) {
            $consecutivo = (int) LoteTitulacion::withTrashed()->max('id') + 1;

            return LoteTitulacion::create([
                'folio' => 'LOTE-TIT-'.str_pad((string) $consecutivo, 4, '0', STR_PAD_LEFT),
                'nombre' => $datos['nombre'] ?? null,
                // La etapa se hereda de la etapa activa del WS al crear el lote.
                'etapa' => TitulacionWsConfig::actual()->etapa_activa,
                'estado' => EstadoLoteTitulacion::Borrador,
            ]);
        });

        return redirect()
            ->route('tenant.titulacion.lotes.show', $lote)
            ->with('exito', "Lote {$lote->folio} creado ({$lote->etapa}). Agrégale egresados.");
    }

    public function show(Request $request, LoteTitulacion $lote): \Inertia\Response
    {
        $lote->load([
            'titulaciones' => fn ($q) => $q->with(['matricula.persona', 'matricula.oferta.carrera:id,nombre', 'matricula.oferta.plan:id,nombre', 'matricula.oferta.campus:id,nombre'])->orderBy('id'),
            'responsable',
            'certificado',
        ]);

        return \Inertia\Inertia::render('Titulacion/Lotes/Detalle', [
            'lote' => $this->filaLote($lote),
            'egresados' => $lote->titulaciones->map(fn (Titulacion $t) => $this->filaTitulacion($t)),
            'firma' => $this->contextoFirma(),
            'etapaActiva' => TitulacionWsConfig::actual()->etapa_activa,
            'modoWs' => (string) config('services.titulos_sep.modo', 'fake'),
        ]);
    }

    /** Buscador de egresados elegibles (cerraron su plan; acotado a los campus del rol). */
    public function candidatos(Request $request, EstadoCertificacion $estado): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $campusVisibles = $request->user()->campusDelRolActivo();

        $matriculas = MatriculaOferta::query()
            ->with(['persona:id,nombre,primer_apellido,segundo_apellido,curp', 'oferta.carrera:id,nombre', 'oferta.plan:id,nombre,minimo_asignaturas', 'oferta.campus:id,nombre'])
            ->when($campusVisibles !== [], fn ($qq) => $qq->whereHas('oferta', fn ($o) => $o->whereIn('campus_id', $campusVisibles)))
            ->when($q !== '', fn ($qq) => $qq->where(function ($w) use ($q) {
                $w->where('matricula', 'like', "%{$q}%")
                    ->orWhereHas('persona', fn ($p) => $p
                        ->where('nombre', 'like', "%{$q}%")
                        ->orWhere('primer_apellido', 'like', "%{$q}%")
                        ->orWhere('segundo_apellido', 'like', "%{$q}%")
                        ->orWhere('curp', 'like', "%{$q}%"));
            }))
            // Excluye las que ya están en un lote de titulación (tituladas o pendientes).
            ->whereDoesntHave('titulaciones', fn ($t) => $t->where('estado', '!=', Titulacion::ERROR))
            ->limit(80)
            ->get();

        $elegibles = $matriculas
            ->filter(fn (MatriculaOferta $m) => $estado->disponible($m))
            ->take(40)
            ->map(fn (MatriculaOferta $m) => [
                'matricula_oferta_id' => $m->id,
                'matricula' => $m->matricula,
                'alumno' => trim(implode(' ', array_filter([$m->persona?->nombre, $m->persona?->primer_apellido, $m->persona?->segundo_apellido]))),
                'curp' => $m->persona?->curp,
                'carrera' => $m->oferta?->carrera?->nombre,
                'plan' => $m->oferta?->plan?->nombre,
                'campus' => $m->oferta?->campus?->nombre,
            ])->values();

        return response()->json(['resultados' => $elegibles]);
    }

    public function agregar(Request $request, LoteTitulacion $lote, EstadoCertificacion $estado): RedirectResponse
    {
        abort_unless($lote->estado->admiteAlumnos(), 422, 'El lote ya no admite egresados.');

        $datos = $request->validate([
            'matricula_oferta_ids' => ['required', 'array', 'min:1'],
            'matricula_oferta_ids.*' => ['integer'],
        ]);

        $campusVisibles = $request->user()->campusDelRolActivo();
        $agregados = 0;
        $omitidos = 0;

        foreach (array_unique($datos['matricula_oferta_ids']) as $id) {
            $matricula = MatriculaOferta::with('oferta.plan')->find($id);

            if ($matricula === null
                || ($campusVisibles !== [] && ! in_array($matricula->oferta?->campus_id, $campusVisibles, true))
                || ! $estado->disponible($matricula)) {
                $omitidos++;

                continue;
            }

            $lote->titulaciones()->firstOrCreate(
                ['matricula_oferta_id' => $matricula->id],
                ['estado' => Titulacion::PENDIENTE],
            );
            $agregados++;
        }

        $msg = "Se agregaron {$agregados} egresado(s).".($omitidos > 0 ? " {$omitidos} se omitieron (no elegibles o ya en un lote)." : '');

        return back()->with($agregados > 0 ? 'exito' : 'advertencia', $msg);
    }

    public function quitar(LoteTitulacion $lote, Titulacion $titulacion): RedirectResponse
    {
        abort_unless($lote->estado->admiteAlumnos(), 422, 'El lote ya no admite cambios.');
        abort_unless($titulacion->lote_id === $lote->id, 404);

        $titulacion->delete();

        return back()->with('exito', 'Egresado quitado del lote.');
    }

    /** Cambia la etapa del lote (solo en borrador), por si se creó en la equivocada. */
    public function editarEtapa(Request $request, LoteTitulacion $lote): RedirectResponse
    {
        abort_unless($lote->estado->puedeEditarEtapa(), 422, 'La etapa solo se cambia mientras el lote está en borrador.');

        $datos = $request->validate([
            'etapa' => ['required', Rule::in([TitulacionWsConfig::ETAPA_PRUEBAS, TitulacionWsConfig::ETAPA_PRODUCCION])],
        ]);

        $lote->update(['etapa' => $datos['etapa']]);

        return back()->with('exito', "Etapa del lote cambiada a {$datos['etapa']}.");
    }

    public function cerrar(LoteTitulacion $lote): RedirectResponse
    {
        abort_unless($lote->estado->puedeCerrar(), 422, 'El lote no se puede cerrar.');

        if ($lote->titulaciones()->count() === 0) {
            return back()->with('error', 'Agrega al menos un egresado antes de cerrar el lote.');
        }

        $lote->update(['estado' => EstadoLoteTitulacion::EnEsperaFirma, 'cerrado_en' => now()]);

        return back()->with('exito', 'Lote cerrado. Listo para firmar.');
    }

    public function reabrir(LoteTitulacion $lote): RedirectResponse
    {
        abort_unless($lote->estado->puedeReabrir(), 422, 'El lote no se puede reabrir.');

        $lote->update(['estado' => EstadoLoteTitulacion::Borrador, 'cerrado_en' => null]);

        return back()->with('exito', 'Lote reabierto. Puedes editar sus egresados y su etapa.');
    }

    /** Firma el lote: sella cada egresado con la e.firma del responsable. */
    public function firmar(Request $request, LoteTitulacion $lote, FirmadorLoteTitulo $firmador, ValidadorTitulo $validador): RedirectResponse
    {
        abort_unless($lote->estado->puedeFirmar(), 422, 'El lote no está en espera de firma.');

        // Salvaguarda de etapa: no firmar si el lote ya no coincide con la activa.
        if (! $lote->etapaCoincideConActiva()) {
            $activa = TitulacionWsConfig::actual()->etapa_activa;

            return back()->with('error', "El lote es de «{$lote->etapa}» pero la etapa activa es «{$activa}». Cambia la etapa activa o reabre el lote y ajústalo antes de firmar.");
        }

        $datos = $request->validate([
            'password' => ['required', 'string'],
            'certificado' => ['nullable', 'file', 'max:64'],
            'llave' => ['nullable', 'file', 'max:64'],
        ], [
            'password.required' => 'La contraseña de la llave es obligatoria para firmar.',
        ]);

        // Los datos deben bastar para un título válido y congruente con el XSD.
        $errores = $validador->validarLote($lote);
        if ($errores !== []) {
            return back()->with('errores_firma', $errores);
        }

        $responsable = Responsable::deTipo(TipoResponsable::TITULACION)
            ->activos()
            ->with(['cargo', 'tituloProfesional', 'certificadoVigente'])
            ->first();

        if ($responsable === null) {
            return back()->with('error', 'No hay un responsable de titulación activo. Regístralo en Configuración → Responsables.');
        }

        $certificado = $responsable->certificadoVigente;
        if ($certificado === null) {
            return back()->with('error', 'El responsable no tiene un certificado vigente.');
        }

        if (! $certificado->estaVigente()) {
            return back()->with('error', "El certificado del responsable venció el {$certificado->vigencia_fin?->format('d/m/Y')}. Actualízalo en Configuración → Responsables antes de firmar.");
        }

        $lector = new LectorCertificado;

        $certPem = $request->hasFile('certificado')
            ? $lector->pem((string) file_get_contents($request->file('certificado')->getRealPath()))
            : $certificado->cer_pem;

        if (blank($certPem)) {
            return back()->with('error', 'Sube el certificado (.cer) del responsable, o guárdalo en su ficha para no volver a subirlo.');
        }

        $keyContents = $request->hasFile('llave')
            ? (string) file_get_contents($request->file('llave')->getRealPath())
            : ($certificado->key_encriptado ? Crypt::decryptString($certificado->key_encriptado) : null);

        if (blank($keyContents)) {
            return back()->with('error', 'Sube la llave (.key) del responsable, o cárgala en su ficha para firmar con solo la contraseña.');
        }

        $diagnostico = $lector->diagnosticarLlave($certPem, $keyContents, (string) $datos['password']);
        if ($diagnostico === 'password') {
            return back()->with('error', 'La contraseña de la llave es incorrecta.');
        }
        if ($diagnostico === 'mismatch') {
            return back()->with('error', 'La llave (.key) no corresponde al certificado (.cer). Verifica que sean del mismo responsable.');
        }

        try {
            $resultado = $firmador->firmar($lote, $responsable, $certificado, $certPem, $keyContents, (string) $datos['password']);
        } catch (Throwable $e) {
            return back()->with('error', 'No se pudo firmar el lote. Revisa el certificado y la llave del responsable.');
        }

        if ($resultado['titulados'] === 0) {
            return back()->with('error', 'No se tituló a ningún egresado. Revisa los renglones marcados con error.');
        }

        $msg = "Lote firmado: {$resultado['titulados']} título(s) sellado(s).".($resultado['errores'] > 0 ? " {$resultado['errores']} con error." : '');

        return back()->with('exito', $msg);
    }

    /** Envía los títulos firmados del lote al web service de la SEP. */
    public function enviar(LoteTitulacion $lote, ClienteTitulosSep $cliente): RedirectResponse
    {
        abort_unless($lote->estado->puedeEnviar(), 422, 'Solo un lote firmado se puede enviar.');

        if (! $cliente->habilitado()) {
            return back()->with('error', 'El envío al web service está deshabilitado (modo off).');
        }

        // Salvaguarda de etapa antes de enviar.
        if (! $lote->etapaCoincideConActiva()) {
            $activa = TitulacionWsConfig::actual()->etapa_activa;

            return back()->with('error', "El lote es de «{$lote->etapa}» pero la etapa activa es «{$activa}». No se envía para no mandarlo al endpoint equivocado.");
        }

        $titulos = $lote->titulaciones()->emitidas()->get();
        $enviados = 0;
        $fallidos = 0;

        foreach ($titulos as $t) {
            if (blank($t->xml_path) || ! Storage::disk('local')->exists($t->xml_path)) {
                $fallidos++;

                continue;
            }

            $respuesta = $cliente->cargarTitulo(Storage::disk('local')->get($t->xml_path), $lote->etapa);

            $t->update([
                'folio_proceso_ws' => $respuesta['folio_proceso'],
                'estado_ws' => $respuesta['ok'] ? 'aceptado' : 'rechazado',
                'respuesta_ws' => mb_substr($respuesta['mensaje'], 0, 1000),
                'enviado_ws_en' => now(),
            ]);

            $respuesta['ok'] ? $enviados++ : $fallidos++;
        }

        if ($enviados > 0) {
            $lote->update(['estado' => EstadoLoteTitulacion::Enviado, 'enviado_en' => now()]);
        }

        $msg = "Envío al WS ({$lote->etapa}): {$enviados} aceptado(s)".($fallidos > 0 ? ", {$fallidos} con error." : '.');

        return back()->with($enviados > 0 ? 'exito' : 'error', $msg);
    }

    public function verificarCertificado(Request $request): JsonResponse
    {
        $request->validate(['certificado' => ['required', 'file', 'max:64']]);

        $lector = new LectorCertificado;
        $contenido = (string) file_get_contents($request->file('certificado')->getRealPath());

        if (! $lector->esValido($contenido)) {
            return response()->json(['error' => 'El archivo no es un certificado (.cer) válido.'], 422);
        }

        $datos = $lector->leer($contenido);

        $cert = Responsable::deTipo(TipoResponsable::TITULACION)
            ->activos()
            ->with('certificadoVigente')
            ->first()?->certificadoVigente;

        return response()->json([
            'coincide' => $cert !== null && $cert->serie === $datos['serial'],
            'serie' => $datos['serial'],
            'serie_esperada' => $cert?->serie,
        ]);
    }

    public function destroy(LoteTitulacion $lote): RedirectResponse
    {
        if (in_array($lote->estado, [EstadoLoteTitulacion::Firmado, EstadoLoteTitulacion::Enviado], true)) {
            return back()->with('error', 'Un lote firmado o enviado no se elimina: ya produjo títulos sellados.');
        }

        $lote->titulaciones()->delete();
        $lote->delete();

        return redirect()
            ->route('tenant.titulacion.lotes.index')
            ->with('exito', 'Lote eliminado.');
    }

    /** Descarga en un ZIP todos los XML firmados de un lote. */
    public function xmlZip(LoteTitulacion $lote): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless(in_array($lote->estado, [EstadoLoteTitulacion::Firmado, EstadoLoteTitulacion::Enviado], true), 404);

        $titulos = $lote->titulaciones()->emitidas()->with('matricula')->get();
        abort_if($titulos->isEmpty(), 404);

        $tmp = tempnam(sys_get_temp_dir(), 'lotetit').'.zip';
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($titulos as $t) {
            if (filled($t->xml_path) && Storage::disk('local')->exists($t->xml_path)) {
                $zip->addFromString(($t->matricula?->matricula ?? $t->id).'.xml', Storage::disk('local')->get($t->xml_path));
            }
        }

        $zip->close();

        return response()->download($tmp, "{$lote->folio}.zip")->deleteFileAfterSend(true);
    }

    /** Descarga el XML firmado de un egresado. */
    public function xml(Titulacion $titulacion): StreamedResponse
    {
        abort_unless($titulacion->estaTitulado() && filled($titulacion->xml_path), 404);
        abort_unless(Storage::disk('local')->exists($titulacion->xml_path), 404);

        $nombre = ($titulacion->matricula?->matricula ?? 'titulo').'.xml';

        return Storage::disk('local')->download($titulacion->xml_path, $nombre);
    }

    /** @return array<string, mixed> */
    private function filaLote(LoteTitulacion $lote): array
    {
        return [
            'id' => $lote->id,
            'folio' => $lote->folio,
            'nombre' => $lote->nombre,
            'etapa' => $lote->etapa,
            'estado' => $lote->estado->value,
            'estado_label' => $lote->estado->etiqueta(),
            'estado_color' => $lote->estado->color(),
            'total' => $lote->titulaciones_count ?? $lote->titulaciones->count(),
            'titulados' => $lote->titulados_count ?? $lote->titulaciones->where('estado', Titulacion::TITULADO)->count(),
            'responsable' => $lote->responsable?->nombreCompleto(),
            'etapa_coincide' => $lote->etapaCoincideConActiva(),
            'cerrado_en' => $lote->cerrado_en?->format('d/m/Y H:i'),
            'firmado_en' => $lote->firmado_en?->format('d/m/Y H:i'),
            'enviado_en' => $lote->enviado_en?->format('d/m/Y H:i'),
            'creado_en' => $lote->created_at?->format('d/m/Y'),
        ];
    }

    /** @return array<string, mixed> */
    private function filaTitulacion(Titulacion $t): array
    {
        $persona = $t->matricula?->persona;

        return [
            'id' => $t->id,
            'matricula' => $t->matricula?->matricula,
            'alumno' => trim(implode(' ', array_filter([$persona?->nombre, $persona?->primer_apellido, $persona?->segundo_apellido]))),
            'curp' => $persona?->curp,
            'carrera' => $t->matricula?->oferta?->carrera?->nombre,
            'plan' => $t->matricula?->oferta?->plan?->nombre,
            'campus' => $t->matricula?->oferta?->campus?->nombre,
            'estado' => $t->estado,
            'folio' => $t->folio,
            'error_mensaje' => $t->error_mensaje,
            'estado_ws' => $t->estado_ws,
            'folio_proceso_ws' => $t->folio_proceso_ws,
            'fecha_titulacion' => $t->fecha_titulacion?->format('d/m/Y H:i'),
            'xml_url' => $t->estaTitulado() ? route('tenant.titulacion.titulaciones.xml', $t) : null,
        ];
    }

    /**
     * Contexto para firmar: el responsable de titulación activo y si su material
     * (.cer/.key) ya está guardado.
     *
     * @return array<string, mixed>
     */
    private function contextoFirma(): array
    {
        $responsable = Responsable::deTipo(TipoResponsable::TITULACION)
            ->activos()
            ->with('certificadoVigente')
            ->first();

        $cert = $responsable?->certificadoVigente;

        return [
            'responsable' => $responsable?->nombreCompleto(),
            'tiene_responsable' => $responsable !== null,
            'tiene_cer' => filled($cert?->cer_pem),
            'tiene_key' => filled($cert?->key_encriptado),
            'serie' => $cert?->serie,
            'vigencia_fin' => $cert?->vigencia_fin?->format('d/m/Y'),
            'dias_restantes' => $cert?->diasRestantes(),
            'vencido' => $cert !== null && ! $cert->estaVigente(),
        ];
    }
}
