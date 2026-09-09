<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Http\Controllers\Concerns\VeLaCarteraDelAlumno;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Factura;
use App\Models\Finanzas\SolicitudFactura;
use App\Services\GestorSolicitudFactura;
use App\Support\CatalogosSat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El autoservicio de factura: el alumno y su familia la SOLICITAN, la escuela la
 * emite.
 *
 * ── Solicitar no es emitir ─────────────────────────────────────────────────
 * Emitir un CFDI a nombre de la escuela es un acto administrativo (`facturar`);
 * el alumno no lo hace. Lo que hace —si la escuela abre el canal— es dejar
 * constancia de que quiere factura de tales pagos, con tales datos. Quién puede
 * ver y pedir de qué alumno lo decide el mismo trait que el estado de cuenta.
 */
class SolicitudFacturaController extends Controller
{
    use AcotaPorCampus;
    use VeLaCarteraDelAlumno;

    public function __construct(private readonly GestorSolicitudFactura $gestor) {}

    // ── Autoservicio (alumno / familia) ────────────────────────────────────

    /**
     * El alumno o su familia pide factura de sus pagos.
     */
    public function solicitar(Request $request, MatriculaOferta $matricula): RedirectResponse
    {
        $this->exigirQuePuedaVerLaCuenta($request, $matricula);

        // Si la escuela no abrió el canal, la ruta no existe para nadie: 404 y
        // no 403 —un 403 diría «existe pero no es para ti» sobre algo que la
        // escuela no ofrece—. Mismo criterio que la postulación autogestiva.
        AvisoParaElUsuario::aMenosQue(
            app(Ajustes::class)->bool(CatalogoAjustes::FACTURA_AUTOSERVICIO_SOLICITUD),
            404,
            'La solicitud de factura en línea no está disponible en esta escuela.',
        );

        $datos = $request->validate([
            'pago_ids' => ['required', 'array', 'min:1'],
            'pago_ids.*' => ['integer'],
            // Mismos datos y mismo catálogo que la emisión: lo que el alumno
            // pida tiene que poder timbrarse tal cual.
            'rfc' => ['required', 'string', 'min:12', 'max:13'],
            'razon_social' => ['required', 'string', 'max:255'],
            'uso_cfdi' => ['required', 'string', Rule::in(CatalogosSat::clavesUsosCfdi())],
            'regimen_fiscal' => ['required', 'string', Rule::in(CatalogosSat::clavesRegimenes())],
            'cp' => ['required', 'string', 'size:5'],
            // El correo de ENTREGA, aparte de los datos fiscales.
            'correo' => ['nullable', 'email', 'max:190'],
        ]);

        try {
            $this->gestor->solicitar(
                $matricula,
                $datos['pago_ids'],
                [
                    'rfc' => $datos['rfc'],
                    'razon_social' => $datos['razon_social'],
                    'uso_cfdi' => $datos['uso_cfdi'],
                    'regimen_fiscal' => $datos['regimen_fiscal'],
                    'cp' => $datos['cp'],
                    'correo' => $datos['correo'] ?? null,
                ],
                $request->user(),
            );
        } catch (AvisoParaElUsuario|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'exito',
            'Recibimos tu solicitud de factura. La escuela la revisa y, en cuanto la emita, tu CFDI aparecerá aquí.',
        );
    }

    /**
     * El CFDI de una solicitud ya emitida, para quien puede ver esa cuenta.
     *
     * Es la descarga de autoservicio que el listado de facturas —admin— no le
     * daba al alumno. El personal ve cualquiera; el resto, sólo el de su cuenta.
     */
    public function descargarCfdi(Request $request, SolicitudFactura $solicitud, string $tipo): StreamedResponse
    {
        abort_unless(in_array($tipo, ['xml', 'pdf'], true), 404);

        $matricula = $solicitud->matriculaOferta;
        abort_unless($matricula !== null, 404);

        if (! $request->user()->can('facturar')) {
            $this->exigirQuePuedaVerLaCuenta($request, $matricula);
        }

        $factura = $solicitud->factura;
        abort_if($factura === null || ! $factura->estaTimbrada(), 404);

        $ruta = $tipo === 'xml' ? $factura->xml_ruta : $factura->pdf_ruta;
        abort_if($ruta === null || ! Storage::disk('local')->exists($ruta), 404);

        return Storage::disk('local')->download($ruta, ($factura->uuid ?? 'factura-'.$factura->id).'.'.$tipo);
    }

    // ── Bandeja (escuela) ──────────────────────────────────────────────────

    /** La cola de solicitudes por atender. */
    public function index(Request $request): Response
    {
        $estado = (string) $request->query('estado', SolicitudFactura::PENDIENTE);

        $consulta = SolicitudFactura::query()
            ->with([
                'matriculaOferta.persona:id,nombre,primer_apellido,segundo_apellido',
                'matriculaOferta.oferta.programaAcademico:id,nombre',
                'solicitante.persona',
                'factura:id,uuid,estatus,total',
            ])
            ->when(
                in_array($estado, [SolicitudFactura::PENDIENTE, SolicitudFactura::EMITIDA, SolicitudFactura::RECHAZADA], true),
                fn ($q) => $q->where('estado', $estado),
            );

        // Las solicitudes cuelgan de una matrícula: se acotan al campus del rol.
        $this->acotarMatriculas($consulta, $request, 'matriculaOferta');

        $solicitudes = $consulta
            ->orderBy('created_at')
            ->limit(200)
            ->get()
            ->map(fn (SolicitudFactura $s) => [
                'id' => $s->id,
                'alumno' => $s->matriculaOferta?->persona?->nombreCompleto(),
                'matricula' => $s->matriculaOferta?->matricula,
                'programa_academico' => $s->matriculaOferta?->oferta?->programaAcademico?->nombre,
                'receptor_rfc' => $s->receptor_rfc,
                'receptor_razon_social' => $s->receptor_razon_social,
                'operaciones' => count($s->pago_ids ?? []),
                'estado' => $s->estado,
                'motivo_rechazo' => $s->motivo_rechazo,
                'factura' => $s->factura === null ? null : [
                    'id' => $s->factura->id,
                    'uuid' => $s->factura->uuid,
                    'estatus' => $s->factura->estatus,
                ],
                'solicitante' => $s->solicitante?->persona?->nombreCompleto(),
                'solicitada_en' => $s->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('Finanzas/SolicitudesFactura', [
            'solicitudes' => $solicitudes,
            'estado' => $estado,
            'pendientes' => SolicitudFactura::pendientes()->count(),
        ]);
    }

    /** La escuela emite el CFDI de una solicitud. */
    public function emitir(SolicitudFactura $solicitud): RedirectResponse
    {
        try {
            $factura = $this->gestor->emitir($solicitud);
        } catch (AvisoParaElUsuario|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect("/finanzas/facturas/{$factura->id}")
            ->with('advertencia', 'La factura se mandó a timbrar. En cuanto el PAC responda aparecerá su folio fiscal.');
    }

    /** Se rechaza con motivo, que es lo único que quien pidió puede usar. */
    public function rechazar(Request $request, SolicitudFactura $solicitud): RedirectResponse
    {
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'motivo.required' => 'Escribe por qué se rechaza: quien la pidió necesita saber qué corregir.',
        ]);

        try {
            $this->gestor->rechazar($solicitud, $datos['motivo']);
        } catch (AvisoParaElUsuario $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('advertencia', 'Solicitud rechazada. Se le avisa el motivo a quien la pidió.');
    }
}
