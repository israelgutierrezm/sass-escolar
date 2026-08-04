<?php

declare(strict_types=1);

namespace App\Http\Controllers\Emision;

use App\Enums\EstadoLoteCertificacion;
use App\Http\Controllers\Controller;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Emision\Certificacion;
use App\Models\Emision\LoteCertificacion;
use App\Models\Emision\Responsable;
use App\Models\Emision\TipoResponsable;
use App\Services\EstadoCertificacion;
use App\Services\FirmadorLote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Lotes de certificación: armar un bloque de alumnos que ya cerraron su plan,
 * cerrarlo y firmarlo con la e.firma del responsable de certificación. Cada
 * alumno del lote produce su XML sellado.
 *
 * El lote no discrimina campus ni carrera; lo único que acota es el alcance del
 * rol de quien agrega alumnos (sus campus). Ver App\Services\EstadoCertificacion
 * para la regla de elegibilidad.
 */
class LoteCertificacionController extends Controller
{
    public function index(): \Inertia\Response
    {
        $lotes = LoteCertificacion::query()
            ->withCount([
                'certificaciones',
                'certificaciones as certificados_count' => fn ($q) => $q->where('estado', Certificacion::CERTIFICADO),
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn (LoteCertificacion $l) => $this->filaLote($l));

        return \Inertia\Inertia::render('Certificacion/Lotes/Index', [
            'lotes' => $lotes,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['nullable', 'string', 'max:160'],
            'tipo' => ['required', 'in:total,parcial'],
        ]);

        $lote = DB::transaction(function () use ($datos) {
            $consecutivo = (int) LoteCertificacion::withTrashed()->max('id') + 1;

            return LoteCertificacion::create([
                'folio' => 'LOTE-CERT-'.str_pad((string) $consecutivo, 4, '0', STR_PAD_LEFT),
                'nombre' => $datos['nombre'] ?? null,
                'tipo' => $datos['tipo'],
                'estado' => EstadoLoteCertificacion::Borrador,
            ]);
        });

        return redirect()
            ->route('tenant.certificacion.lotes.show', $lote)
            ->with('exito', "Lote {$lote->folio} creado. Agrégale alumnos.");
    }

    public function show(Request $request, LoteCertificacion $lote): \Inertia\Response
    {
        $lote->load([
            'certificaciones' => fn ($q) => $q->with(['matricula.persona', 'matricula.oferta.carrera:id,nombre', 'matricula.oferta.plan:id,nombre', 'matricula.oferta.campus:id,nombre'])->orderBy('id'),
            'responsable',
            'certificado',
        ]);

        return \Inertia\Inertia::render('Certificacion/Lotes/Detalle', [
            'lote' => $this->filaLote($lote),
            'alumnos' => $lote->certificaciones->map(fn (Certificacion $c) => $this->filaCertificacion($c)),
            'firma' => $this->contextoFirma(),
        ]);
    }

    /** Buscador de alumnos elegibles (acotado a los campus del rol activo). */
    public function candidatos(Request $request, EstadoCertificacion $estado): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        // Un lote parcial busca alumnos con avance sin cerrar el plan; uno total,
        // los que ya lo cerraron.
        $tipo = $request->query('tipo') === 'parcial' ? 'parcial' : 'total';
        $campusVisibles = $request->user()->campusDelRolActivo();

        $matriculas = MatriculaOferta::query()
            // `emite_certificado` viaja en el select: sin la columna el modelo
            // llega a medias y el filtro de abajo dejaría pasar a todos.
            ->with(['persona:id,nombre,primer_apellido,segundo_apellido,curp', 'oferta.carrera:id,nombre,emite_certificado', 'oferta.plan:id,nombre,minimo_asignaturas', 'oferta.campus:id,nombre'])
            ->when($campusVisibles !== [], fn ($qq) => $qq->whereHas('oferta', fn ($o) => $o->whereIn('campus_id', $campusVisibles)))
            ->when($q !== '', fn ($qq) => $qq->where(function ($w) use ($q) {
                $w->where('matricula', 'like', "%{$q}%")
                    ->orWhereHas('persona', fn ($p) => $p
                        ->where('nombre', 'like', "%{$q}%")
                        ->orWhere('primer_apellido', 'like', "%{$q}%")
                        ->orWhere('segundo_apellido', 'like', "%{$q}%")
                        ->orWhere('curp', 'like', "%{$q}%"));
            }))
            // Excluye las que ya están en un lote (emitidas o pendientes).
            ->whereDoesntHave('certificaciones', fn ($c) => $c->where('estado', '!=', Certificacion::ERROR))
            ->limit(80)
            ->get();

        $elegibles = $matriculas
            // La carrera tiene que emitir certificado: un diplomado sin RVOE
            // puede cerrar su plan y no por eso hay documento que expedir.
            ->filter(fn (MatriculaOferta $m) => $estado->emiteCertificado($m))
            ->filter(fn (MatriculaOferta $m) => $tipo === 'parcial' ? $estado->disponibleParcial($m) : $estado->disponible($m))
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

    /** Agrega una o más matrículas al lote (sólo en borrador). */
    public function agregar(Request $request, LoteCertificacion $lote, EstadoCertificacion $estado): RedirectResponse
    {
        abort_unless($lote->estado->admiteAlumnos(), 422, 'El lote ya no admite alumnos.');

        $datos = $request->validate([
            'matricula_oferta_ids' => ['required', 'array', 'min:1'],
            'matricula_oferta_ids.*' => ['integer'],
        ]);

        $campusVisibles = $request->user()->campusDelRolActivo();
        $agregados = 0;
        $omitidos = 0;

        foreach (array_unique($datos['matricula_oferta_ids']) as $id) {
            // La carrera se carga porque `elegibleParaLote` pregunta si emite
            // certificado; sin ella, ese chequeo consulta un modelo a medias.
            $matricula = MatriculaOferta::with(['oferta.plan', 'oferta.carrera'])->find($id);

            // Fuera de mi alcance de campus, o no elegible para el TIPO del lote,
            // o ya en un lote.
            if ($matricula === null
                || ($campusVisibles !== [] && ! in_array($matricula->oferta?->campus_id, $campusVisibles, true))
                || ! $estado->elegibleParaLote($matricula, $lote->tipo)) {
                $omitidos++;

                continue;
            }

            $lote->certificaciones()->create([
                'matricula_oferta_id' => $matricula->id,
                'estado' => Certificacion::PENDIENTE,
            ]);
            $agregados++;
        }

        $msg = "Se agregaron {$agregados} alumno(s).".($omitidos > 0 ? " {$omitidos} se omitieron (no elegibles o ya en un lote)." : '');

        return back()->with($agregados > 0 ? 'exito' : 'advertencia', $msg);
    }

    public function quitar(LoteCertificacion $lote, Certificacion $certificacion): RedirectResponse
    {
        abort_unless($lote->estado->admiteAlumnos(), 422, 'El lote ya no admite cambios.');
        abort_unless($certificacion->lote_id === $lote->id, 404);

        $certificacion->delete();

        return back()->with('exito', 'Alumno quitado del lote.');
    }

    public function cerrar(LoteCertificacion $lote): RedirectResponse
    {
        abort_unless($lote->estado->puedeCerrar(), 422, 'El lote no se puede cerrar.');

        if ($lote->certificaciones()->count() === 0) {
            return back()->with('error', 'Agrega al menos un alumno antes de cerrar el lote.');
        }

        $lote->update([
            'estado' => EstadoLoteCertificacion::EnEsperaFirma,
            'cerrado_en' => now(),
        ]);

        return back()->with('exito', 'Lote cerrado. Listo para firmar.');
    }

    public function reabrir(LoteCertificacion $lote): RedirectResponse
    {
        abort_unless($lote->estado->puedeReabrir(), 422, 'El lote no se puede reabrir.');

        $lote->update(['estado' => EstadoLoteCertificacion::Borrador, 'cerrado_en' => null]);

        return back()->with('exito', 'Lote reabierto. Puedes editar sus alumnos.');
    }

    /** Firma el lote: sella cada alumno con la e.firma del responsable. */
    public function firmar(Request $request, LoteCertificacion $lote, FirmadorLote $firmador): RedirectResponse
    {
        abort_unless($lote->estado->puedeFirmar(), 422, 'El lote no está en espera de firma.');

        $datos = $request->validate([
            'password' => ['required', 'string'],
            'certificado' => ['nullable', 'file', 'max:64'],
            'llave' => ['nullable', 'file', 'max:64'],
        ], [
            'password.required' => 'La contraseña de la llave es obligatoria para firmar.',
        ]);

        // Antes de sellar: los datos deben bastar para un DEC válido y congruente
        // con el XSD. Si no, se listan TODOS los errores y no se firma nada.
        $erroresDec = app(\App\Services\ValidadorDec::class)->validarLote($lote);
        if ($erroresDec !== []) {
            return back()->with('errores_firma', $erroresDec);
        }

        $responsable = Responsable::deTipo(TipoResponsable::CERTIFICACION)
            ->activos()
            ->with(['cargo', 'certificadoVigente'])
            ->first();

        if ($responsable === null) {
            return back()->with('error', 'No hay un responsable de certificación activo. Regístralo en Configuración → Responsables.');
        }

        $certificado = $responsable->certificadoVigente;
        if ($certificado === null) {
            return back()->with('error', 'El responsable no tiene un certificado vigente.');
        }

        // El certificado no debe estar vencido: se sella con una e.firma vigente.
        if (! $certificado->estaVigente()) {
            return back()->with('error', "El certificado del responsable venció el {$certificado->vigencia_fin?->format('d/m/Y')}. Actualízalo en Configuración → Responsables antes de firmar.");
        }

        $lector = new \App\Services\LectorCertificado;

        // Certificado (.cer): el subido en el formulario o el guardado en su ficha.
        $certPem = $request->hasFile('certificado')
            ? $lector->pem((string) file_get_contents($request->file('certificado')->getRealPath()))
            : $certificado->cer_pem;

        if (blank($certPem)) {
            return back()->with('error', 'Sube el certificado (.cer) del responsable, o guárdalo en su ficha para no volver a subirlo.');
        }

        // Llave (.key): la subida, o la guardada cifrada en su ficha.
        $keyContents = $request->hasFile('llave')
            ? (string) file_get_contents($request->file('llave')->getRealPath())
            : ($certificado->key_encriptado ? Crypt::decryptString($certificado->key_encriptado) : null);

        if (blank($keyContents)) {
            return back()->with('error', 'Sube la llave (.key) del responsable, o cárgala en su ficha para firmar con solo la contraseña.');
        }

        // Diagnóstico previo para dar un mensaje preciso: contraseña vs. llave
        // que no corresponde al certificado.
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

        if ($resultado['certificados'] === 0) {
            return back()->with('error', 'No se certificó a ningún alumno. Revisa los renglones marcados con error.');
        }

        $msg = "Lote firmado: {$resultado['certificados']} certificado(s) emitido(s).".($resultado['errores'] > 0 ? " {$resultado['errores']} con error." : '');

        return back()->with('exito', $msg);
    }

    /**
     * Lee un .cer subido y dice si coincide con el certificado registrado del
     * responsable de certificación activo (por número de serie). Alimenta el
     * aviso «coincide / no coincide» al cargar el archivo en el panel de firma.
     */
    public function verificarCertificado(Request $request): JsonResponse
    {
        $request->validate(['certificado' => ['required', 'file', 'max:64']]);

        $lector = new \App\Services\LectorCertificado;
        $contenido = (string) file_get_contents($request->file('certificado')->getRealPath());

        if (! $lector->esValido($contenido)) {
            return response()->json(['error' => 'El archivo no es un certificado (.cer) válido.'], 422);
        }

        $datos = $lector->leer($contenido);

        $cert = Responsable::deTipo(TipoResponsable::CERTIFICACION)
            ->activos()
            ->with('certificadoVigente')
            ->first()?->certificadoVigente;

        return response()->json([
            'coincide' => $cert !== null && $cert->serie === $datos['serial'],
            'serie' => $datos['serial'],
            'serie_esperada' => $cert?->serie,
        ]);
    }

    public function destroy(LoteCertificacion $lote): RedirectResponse
    {
        if ($lote->estado === EstadoLoteCertificacion::Firmado) {
            return back()->with('error', 'Un lote firmado no se elimina: ya emitió certificados.');
        }

        $lote->certificaciones()->delete();
        $lote->delete();

        return redirect()
            ->route('tenant.certificacion.lotes.index')
            ->with('exito', 'Lote eliminado.');
    }

    /** Descarga en un ZIP el XML firmado Y la cadena original (.txt) de cada certificado. */
    public function xmlZip(LoteCertificacion $lote): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless($lote->estado === EstadoLoteCertificacion::Firmado, 404);

        $certs = $lote->certificaciones()->emitidas()->with('matricula')->get();
        abort_if($certs->isEmpty(), 404);

        $tmp = tempnam(sys_get_temp_dir(), 'lote').'.zip';
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($certs as $c) {
            $base = $c->matricula?->matricula ?? (string) $c->id;
            if (filled($c->xml_path) && Storage::disk('local')->exists($c->xml_path)) {
                $zip->addFromString("{$base}.xml", Storage::disk('local')->get($c->xml_path));
            }
            if (filled($c->cadena_original)) {
                $zip->addFromString("{$base}.cadena.txt", $c->cadena_original);
            }
        }

        $zip->close();

        return response()->download($tmp, "{$lote->folio}.zip")->deleteFileAfterSend(true);
    }

    /** Descarga el XML firmado de un alumno. */
    public function xml(Certificacion $certificacion): StreamedResponse
    {
        abort_unless($certificacion->estaCertificado() && filled($certificacion->xml_path), 404);
        abort_unless(Storage::disk('local')->exists($certificacion->xml_path), 404);

        $nombre = ($certificacion->matricula?->matricula ?? 'certificado').'.xml';

        return Storage::disk('local')->download($certificacion->xml_path, $nombre);
    }

    /** Descarga la cadena original (.txt) de un alumno: lo que se selló. */
    public function cadena(Certificacion $certificacion): StreamedResponse
    {
        abort_unless($certificacion->estaCertificado() && filled($certificacion->cadena_original), 404);

        $nombre = ($certificacion->matricula?->matricula ?? 'certificado').'.cadena.txt';

        return response()->streamDownload(
            fn () => print $certificacion->cadena_original,
            $nombre,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    /** Exporta a Excel los certificados del lote (una fila por alumno). */
    public function excel(LoteCertificacion $lote): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $lote->load(['certificaciones.matricula.persona', 'certificaciones.matricula.oferta.carrera', 'certificaciones.matricula.oferta.plan', 'certificaciones.matricula.oferta.campus']);

        $filas = $lote->certificaciones->map(fn (Certificacion $c) => [
            $c->matricula?->matricula,
            trim(implode(' ', array_filter([$c->matricula?->persona?->nombre, $c->matricula?->persona?->primer_apellido, $c->matricula?->persona?->segundo_apellido]))),
            $c->matricula?->persona?->curp,
            $c->matricula?->oferta?->carrera?->nombre,
            $c->matricula?->oferta?->plan?->nombre,
            $c->matricula?->oferta?->campus?->nombre,
            $c->folio,
            $c->estado,
            $c->fecha_certificacion?->format('d/m/Y H:i'),
        ])->all();

        return $this->descargarExcel(
            "Lote {$lote->folio} ({$lote->tipo})",
            ['Matrícula', 'Alumno', 'CURP', 'Carrera', 'Plan', 'Campus', 'Folio', 'Estado', 'Certificado'],
            $filas,
            "{$lote->folio}.xlsx",
        );
    }

    /**
     * Arma un .xlsx simple (encabezado pintado + filas) con PhpSpreadsheet y lo
     * descarga.
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
    private function filaLote(LoteCertificacion $lote): array
    {
        return [
            'id' => $lote->id,
            'folio' => $lote->folio,
            'nombre' => $lote->nombre,
            'tipo' => $lote->tipo,
            'tipo_label' => $lote->tipo === LoteCertificacion::PARCIAL ? 'Parcial' : 'Total',
            'estado' => $lote->estado->value,
            'estado_label' => $lote->estado->etiqueta(),
            'estado_color' => $lote->estado->color(),
            'total' => $lote->certificaciones_count ?? $lote->certificaciones->count(),
            'certificados' => $lote->certificados_count ?? $lote->certificaciones->where('estado', Certificacion::CERTIFICADO)->count(),
            'responsable' => $lote->responsable?->nombreCompleto(),
            'cerrado_en' => $lote->cerrado_en?->format('d/m/Y H:i'),
            'firmado_en' => $lote->firmado_en?->format('d/m/Y H:i'),
            'creado_en' => $lote->created_at?->format('d/m/Y'),
        ];
    }

    /** @return array<string, mixed> */
    private function filaCertificacion(Certificacion $c): array
    {
        $persona = $c->matricula?->persona;

        return [
            'id' => $c->id,
            'matricula_oferta_id' => $c->matricula_oferta_id,
            'matricula' => $c->matricula?->matricula,
            'alumno' => trim(implode(' ', array_filter([$persona?->nombre, $persona?->primer_apellido, $persona?->segundo_apellido]))),
            'curp' => $persona?->curp,
            'carrera' => $c->matricula?->oferta?->carrera?->nombre,
            'plan' => $c->matricula?->oferta?->plan?->nombre,
            'campus' => $c->matricula?->oferta?->campus?->nombre,
            'estado' => $c->estado,
            'folio' => $c->folio,
            'error_mensaje' => $c->error_mensaje,
            'fecha_certificacion' => $c->fecha_certificacion?->format('d/m/Y H:i'),
            'xml_url' => $c->estaCertificado() ? route('tenant.certificacion.certificaciones.xml', $c) : null,
            'cadena_url' => $c->estaCertificado() && filled($c->cadena_original) ? route('tenant.certificacion.certificaciones.cadena', $c) : null,
        ];
    }

    /**
     * Contexto para firmar: el responsable activo y si su material (.cer/.key)
     * ya está guardado, para que el formulario pida solo lo que falta.
     *
     * @return array<string, mixed>
     */
    private function contextoFirma(): array
    {
        $responsable = Responsable::deTipo(TipoResponsable::CERTIFICACION)
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
