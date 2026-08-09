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
use App\Models\Landlord\SaldoEmision;
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
    public function index(Request $request): \Inertia\Response
    {
        $filtros = $request->validate([
            'busqueda' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', 'in:borrador,en_espera_firma,firmado,enviado'],
            'etapa' => ['nullable', 'in:pruebas,produccion'],
            'rechazados' => ['nullable', 'in:0,1'],
        ]);

        $lotes = LoteTitulacion::query()
            ->withCount([
                'titulaciones',
                'titulaciones as titulados_count' => fn ($q) => $q->where('estado', Titulacion::TITULADO),
                // Lo que la SEP rechazó: es el trabajo que queda por rehacer,
                // y sin contarlo aquí hay que abrir lote por lote para verlo.
                'titulaciones as rechazados_ws_count' => fn ($q) => $q->where('estado_ws', 'rechazado'),
            ])
            ->when(filled($filtros['busqueda'] ?? null), function ($q) use ($filtros) {
                $texto = '%'.$filtros['busqueda'].'%';
                $q->where(fn ($s) => $s->where('folio', 'like', $texto)->orWhere('nombre', 'like', $texto));
            })
            ->when(filled($filtros['estado'] ?? null), fn ($q) => $q->where('estado', $filtros['estado']))
            ->when(filled($filtros['etapa'] ?? null), fn ($q) => $q->where('etapa', $filtros['etapa']))
            /*
             * «Con rechazos del web service» es el filtro que se usa de verdad:
             * después de enviar un lote grande, lo que se busca no es un folio
             * sino dónde quedó trabajo pendiente.
             */
            ->when(($filtros['rechazados'] ?? '') === '1', fn ($q) => $q->whereHas(
                'titulaciones',
                fn ($t) => $t->where('estado_ws', 'rechazado'),
            ))
            ->orderByDesc('id')
            ->get()
            ->map(fn (LoteTitulacion $l) => $this->filaLote($l));

        return \Inertia\Inertia::render('Titulacion/Lotes/Index', [
            'lotes' => $lotes,
            'filtros' => [
                'busqueda' => $filtros['busqueda'] ?? '',
                'estado' => $filtros['estado'] ?? '',
                'etapa' => $filtros['etapa'] ?? '',
                'rechazados' => $filtros['rechazados'] ?? '',
            ],
            // Firmar es lo que gasta: el saldo se ve donde se firma.
            'saldo' => SaldoEmision::de(tenant()->getTenantKey())->paraPantalla(),
            'etapaActiva' => TitulacionWsConfig::actual()->etapa_activa,
            // La etapa dice a qué endpoint apunta; el modo, si de verdad sale
            // algo. La pantalla anunciaba «producción» sin decir que el envío
            // podía estar simulado.
            'modoWs' => (string) config('services.titulos_sep.modo', 'fake'),
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
            // Aquí es donde se pulsa «Firmar»: aquí importa si el saldo alcanza.
            'saldo' => SaldoEmision::de(tenant()->getTenantKey())->paraPantalla(),
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
            // La bandera viaja en el select: sin la columna el modelo llega a
            // medias y el filtro de abajo dejaría pasar a todos.
            ->with(['persona:id,nombre,primer_apellido,segundo_apellido,curp', 'oferta.carrera:id,nombre,emite_documentos_oficiales', 'oferta.plan:id,nombre,minimo_asignaturas', 'oferta.campus:id,nombre'])
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
            // La carrera tiene que expedir documentos oficiales.
            ->filter(fn (MatriculaOferta $m) => $estado->emiteDocumentos($m) && $estado->disponible($m))
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
            // La carrera se carga porque de ella depende que haya título que
            // emitir; sin esto el filtro del buscador se podría saltar mandando
            // los ids a mano.
            $matricula = MatriculaOferta::with(['oferta.plan', 'oferta.carrera'])->find($id);

            if ($matricula === null
                || ($campusVisibles !== [] && ! in_array($matricula->oferta?->campus_id, $campusVisibles, true))
                || ! $estado->emiteDocumentos($matricula)
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
            // Segundo firmante (opcional): solo su contraseña; su material sale de
            // su ficha.
            'password_2' => ['nullable', 'string'],
        ], [
            'password.required' => 'La contraseña de la llave del firmante 1 es obligatoria.',
        ]);

        // Los datos deben bastar para un título válido y congruente con el XSD.
        $errores = $validador->validarLote($lote);
        if ($errores !== []) {
            return back()->with('errores_firma', $errores);
        }

        // El firmante 1 (obligatorio) es el primer responsable registrado; el
        // firmante 2 (opcional) es el segundo. El orden lo da el registro (id).
        $responsables = Responsable::deTipo(TipoResponsable::TITULACION)
            ->activos()
            ->with(['cargo', 'tituloProfesional', 'certificadoVigente'])
            ->orderBy('id')
            ->get();

        if ($responsables->isEmpty()) {
            return back()->with('error', 'No hay un responsable de titulación activo. Regístralo en Configuración → Responsables.');
        }

        $lector = new LectorCertificado;

        // Firmante 1 (obligatorio): puede subir su .cer/.key o usar los de su ficha.
        $certPemSubido = $request->hasFile('certificado')
            ? $lector->pem((string) file_get_contents($request->file('certificado')->getRealPath()))
            : null;
        $keySubida = $request->hasFile('llave')
            ? (string) file_get_contents($request->file('llave')->getRealPath())
            : null;

        $preparado = $this->prepararFirmante($responsables[0], $certPemSubido, $keySubida, (string) $datos['password'], $lector);
        if (! $preparado['ok']) {
            return back()->with('error', $preparado['error']);
        }
        $firmantes = [$preparado['firmante']];

        // Firmante 2 (opcional): si hay un segundo responsable activo, se exige su
        // contraseña y se usa el material de su ficha.
        if ($responsables->count() > 1) {
            if (blank($datos['password_2'] ?? null)) {
                return back()->with('error', "Hay un segundo responsable ({$responsables[1]->nombreCompleto()}): captura también su contraseña para firmar.");
            }

            $segundo = $this->prepararFirmante($responsables[1], null, null, (string) $datos['password_2'], $lector);
            if (! $segundo['ok']) {
                return back()->with('error', $segundo['error']);
            }
            $firmantes[] = $segundo['firmante'];
        }

        try {
            $resultado = $firmador->firmar($lote, $firmantes);
        } catch (Throwable $e) {
            return back()->with('error', 'No se pudo firmar el lote. Revisa el certificado y la llave de los responsables.');
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

    /**
     * Deja un título listo para volver a generarse.
     *
     * Igual que en certificación —el error de captura hay que poder
     * corregirlo—, y tampoco cuesta un crédito: al volver a firmar, el contador
     * reconoce el trámite por CURP + plan.
     */
    public function regenerar(LoteTitulacion $lote, Titulacion $titulacion): RedirectResponse
    {
        abort_unless($titulacion->lote_id === $lote->id, 404);

        $titulacion->update([
            'estado' => Titulacion::PENDIENTE,
            'xml_path' => null,
            'sello' => null,
            'cadena_original' => null,
            'no_certificado' => null,
            'fecha_titulacion' => null,
            'error_mensaje' => null,
            // También lo del web service: el XML que se mandó ya no existe, y
            // conservar su folio de proceso haría creer que ese envío sigue en
            // pie.
            'folio_proceso_ws' => null,
            'estado_ws' => null,
            'respuesta_ws' => null,
            'enviado_ws_en' => null,
        ]);

        return back()->with(
            'exito',
            'El título quedó listo para volver a generarse. Vuelve a firmar el lote; '
                .'no se cobra otro crédito porque es el mismo alumno y el mismo plan.',
        );
    }

    /**
     * Reenvía UN título al web service, sin tocar el resto del lote.
     *
     * ── Por qué separado del envío del lote ────────────────────────────────
     * En titulación el error puede venir del otro lado: el XML está bien y la
     * SEP lo rechaza por una caída, una validación suya o un dato de catálogo.
     * Reenviar el lote entero para reintentar uno significa volver a mandar los
     * que ya se aceptaron —y eso sí puede duplicar trámites allá—.
     *
     * Reenviar no genera XML, así que no cuenta ni cobra nada: el documento es
     * exactamente el mismo que ya se selló.
     */
    public function reenviar(LoteTitulacion $lote, Titulacion $titulacion, ClienteTitulosSep $cliente): RedirectResponse
    {
        abort_unless($titulacion->lote_id === $lote->id, 404);

        if (! $cliente->habilitado()) {
            return back()->with('error', 'El envío al web service está deshabilitado (modo off).');
        }

        // La misma salvaguarda que el envío del lote: no mandar un título de
        // producción al endpoint de pruebas ni al revés.
        if (! $lote->etapaCoincideConActiva()) {
            $activa = TitulacionWsConfig::actual()->etapa_activa;

            return back()->with('error', "El lote es de «{$lote->etapa}» pero la etapa activa es «{$activa}». No se envía.");
        }

        if (blank($titulacion->xml_path) || ! Storage::disk('local')->exists($titulacion->xml_path)) {
            return back()->with('error', 'Ese título todavía no tiene XML firmado: primero hay que firmarlo.');
        }

        $respuesta = $cliente->cargarTitulo(Storage::disk('local')->get($titulacion->xml_path), $lote->etapa);

        $titulacion->update([
            'folio_proceso_ws' => $respuesta['folio_proceso'],
            'estado_ws' => $respuesta['ok'] ? 'aceptado' : 'rechazado',
            'respuesta_ws' => mb_substr($respuesta['mensaje'], 0, 1000),
            'enviado_ws_en' => now(),
        ]);

        return $respuesta['ok']
            ? back()->with('exito', 'Título reenviado y aceptado por el web service.')
            : back()->with('error', 'El web service volvió a rechazarlo: '.mb_substr($respuesta['mensaje'], 0, 200));
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

    /** Descarga en un ZIP el XML firmado Y la cadena original (.txt) de cada título. */
    public function xmlZip(LoteTitulacion $lote): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless(in_array($lote->estado, [EstadoLoteTitulacion::Firmado, EstadoLoteTitulacion::Enviado], true), 404);

        $titulos = $lote->titulaciones()->emitidas()->with('matricula')->get();
        abort_if($titulos->isEmpty(), 404);

        $tmp = tempnam(sys_get_temp_dir(), 'lotetit').'.zip';
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($titulos as $t) {
            $base = $t->matricula?->matricula ?? (string) $t->id;
            if (filled($t->xml_path) && Storage::disk('local')->exists($t->xml_path)) {
                $zip->addFromString("{$base}.xml", Storage::disk('local')->get($t->xml_path));
            }
            if (filled($t->cadena_original)) {
                $zip->addFromString("{$base}.cadena.txt", $t->cadena_original);
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

    /** Descarga la cadena original (.txt) de un egresado: lo que se selló. */
    public function cadena(Titulacion $titulacion): StreamedResponse
    {
        abort_unless($titulacion->estaTitulado() && filled($titulacion->cadena_original), 404);

        $nombre = ($titulacion->matricula?->matricula ?? 'titulo').'.cadena.txt';

        return response()->streamDownload(
            fn () => print $titulacion->cadena_original,
            $nombre,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    /** Exporta a Excel los títulos del lote (una fila por egresado). */
    public function excel(LoteTitulacion $lote): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $lote->load(['titulaciones.matricula.persona', 'titulaciones.matricula.oferta.carrera', 'titulaciones.matricula.oferta.plan', 'titulaciones.matricula.oferta.campus']);

        $filas = $lote->titulaciones->map(fn (Titulacion $t) => [
            $t->matricula?->matricula,
            trim(implode(' ', array_filter([$t->matricula?->persona?->nombre, $t->matricula?->persona?->primer_apellido, $t->matricula?->persona?->segundo_apellido]))),
            $t->matricula?->persona?->curp,
            $t->matricula?->oferta?->carrera?->nombre,
            $t->matricula?->oferta?->plan?->nombre,
            $t->matricula?->oferta?->campus?->nombre,
            $t->folio,
            $t->estado,
            $t->estado_ws,
            $t->folio_proceso_ws,
            $t->fecha_titulacion?->format('d/m/Y H:i'),
        ])->all();

        return $this->descargarExcel(
            "Lote {$lote->folio} ({$lote->etapa})",
            ['Matrícula', 'Egresado', 'CURP', 'Carrera', 'Plan', 'Campus', 'Folio', 'Estado', 'Estado WS', 'Folio proceso WS', 'Titulado'],
            $filas,
            "{$lote->folio}.xlsx",
        );
    }

    /**
     * Arma un .xlsx simple (encabezado pintado + filas) con PhpSpreadsheet y lo
     * descarga. Compartido por las exportaciones de lotes.
     *
     * @param  array<int, string>  $encabezados
     * @param  array<int, array<int, mixed>>  $filas
     */
    private function descargarExcel(string $titulo, array $encabezados, array $filas, string $archivo): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $hoja = $libro->getActiveSheet();

        $hoja->fromArray($encabezados, null, 'A1');
        $ultima = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($encabezados));
        $hoja->getStyle("A1:{$ultima}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $hoja->getStyle("A1:{$ultima}1")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF2F6FED');
        $hoja->fromArray($filas, null, 'A2');
        foreach (range(1, count($encabezados)) as $i) {
            $hoja->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
        $hoja->setTitle(mb_substr($titulo, 0, 31));

        $tmp = tempnam(sys_get_temp_dir(), 'xls').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save($tmp);

        return response()->download($tmp, $archivo)->deleteFileAfterSend(true);
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
            'rechazados_ws' => $lote->rechazados_ws_count ?? $lote->titulaciones->where('estado_ws', 'rechazado')->count(),
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
            'cadena_url' => $t->estaTitulado() && filled($t->cadena_original) ? route('tenant.titulacion.titulaciones.cadena', $t) : null,
        ];
    }

    /**
     * Prepara un firmante: valida su certificado vigente y su material (.cer/.key
     * subido o de su ficha) contra la contraseña. Devuelve el firmante listo para
     * el firmador, o un error legible.
     *
     * @return array{ok: bool, error?: string, firmante?: array<string, mixed>}
     */
    private function prepararFirmante(Responsable $responsable, ?string $certPemSubido, ?string $keySubida, string $password, LectorCertificado $lector): array
    {
        $cert = $responsable->certificadoVigente;
        $etq = $responsable->nombreCompleto();

        if ($cert === null) {
            return ['ok' => false, 'error' => "{$etq} no tiene un certificado vigente."];
        }
        if (! $cert->estaVigente()) {
            return ['ok' => false, 'error' => "El certificado de {$etq} venció el {$cert->vigencia_fin?->format('d/m/Y')}. Actualízalo en Configuración → Responsables."];
        }

        $certPem = $certPemSubido ?? $cert->cer_pem;
        if (blank($certPem)) {
            return ['ok' => false, 'error' => "Sube el certificado (.cer) de {$etq}, o guárdalo en su ficha."];
        }

        $keyContents = $keySubida ?? ($cert->key_encriptado ? Crypt::decryptString($cert->key_encriptado) : null);
        if (blank($keyContents)) {
            return ['ok' => false, 'error' => "Sube la llave (.key) de {$etq}, o cárgala en su ficha."];
        }

        $diagnostico = $lector->diagnosticarLlave($certPem, $keyContents, $password);
        if ($diagnostico === 'password') {
            return ['ok' => false, 'error' => "La contraseña de la llave de {$etq} es incorrecta."];
        }
        if ($diagnostico === 'mismatch') {
            return ['ok' => false, 'error' => "La llave (.key) de {$etq} no corresponde a su certificado (.cer)."];
        }

        return ['ok' => true, 'firmante' => [
            'responsable' => $responsable,
            'certificado' => $cert,
            'cert_pem' => $certPem,
            'key' => $keyContents,
            'password' => $password,
        ]];
    }

    /**
     * Contexto para firmar: los responsables de titulación activos (el primero es
     * el firmante obligatorio; el segundo, opcional) y si su material ya está
     * guardado, para pedir solo lo que falta.
     *
     * @return array<string, mixed>
     */
    private function contextoFirma(): array
    {
        $responsables = Responsable::deTipo(TipoResponsable::TITULACION)
            ->activos()
            ->with(['cargo', 'certificadoVigente'])
            ->orderBy('id')
            ->get();

        return [
            'tiene_responsable' => $responsables->isNotEmpty(),
            'firmantes' => $responsables->map(function (Responsable $r, int $i) {
                $cert = $r->certificadoVigente;

                return [
                    'orden' => $i + 1,
                    'obligatorio' => $i === 0,
                    'responsable' => $r->nombreCompleto(),
                    'cargo' => $r->cargo?->nombre,
                    'tiene_cer' => filled($cert?->cer_pem),
                    'tiene_key' => filled($cert?->key_encriptado),
                    'serie' => $cert?->serie,
                    'vigencia_fin' => $cert?->vigencia_fin?->format('d/m/Y'),
                    'dias_restantes' => $cert?->diasRestantes(),
                    'vencido' => $cert !== null && ! $cert->estaVigente(),
                    'sin_certificado' => $cert === null,
                ];
            })->all(),
        ];
    }
}
