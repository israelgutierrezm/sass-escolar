<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Jobs\TimbrarFactura;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Factura;
use App\Models\Finanzas\FacturaConcepto;
use App\Models\Finanzas\Pago;
use App\Services\Cfdi\ComplementoEducativo;
use App\Services\DescargaMasivaCfdi;
use App\Services\EmisorFactura;
use App\Services\EmisorNotaCredito;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Facturación electrónica.
 *
 * La regla que gobierna toda esta pantalla: **una factura timbrada no se
 * edita**. No hay `update` ni `destroy` para las que ya tienen UUID; lo único
 * que se puede hacer con ellas es cancelarlas y emitir otra. Un borrador o un
 * intento rechazado sí se borran, porque nunca fueron un documento fiscal.
 */
class FacturaController extends Controller
{
    use AcotaPorCampus;

    public function __construct(
        private readonly EmisorFactura $emisor,
        private readonly ComplementoEducativo $complemento,
        private readonly EmisorNotaCredito $notasCredito,
        private readonly DescargaMasivaCfdi $descargaMasiva,
    ) {}

    public function index(Request $request): Response
    {
        $estatus = (string) $request->query('estatus', '');
        // `boolean` y no el valor crudo: la cadena «0» que manda una casilla es
        // VERDADERA en PHP, así que el filtro no se podría apagar. Es la trampa
        // que este proyecto ya se cobró en reportes y en el panorama documental.
        $soloDiscrepancias = $request->boolean('discrepancia');

        $consulta = Factura::query()
            ->with(['matriculaOferta.persona:id,nombre,primer_apellido,segundo_apellido'])
            ->when($estatus !== '', fn ($q) => $q->where('estatus', $estatus))
            ->when($soloDiscrepancias, fn ($q) => $q->conDiscrepanciaSat());

        // Las facturas cuelgan de una matrícula: se acotan al campus del rol.
        $this->acotarMatriculas($consulta, $request, 'matriculaOferta');

        $facturas = $consulta
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Factura $f) => $this->resumen($f));

        return Inertia::render('Finanzas/Facturas/Index', [
            'facturas' => $facturas,
            'filtros' => ['estatus' => $estatus, 'discrepancia' => $soloDiscrepancias],
            // Cuántas hay en total, no sólo en esta página: es el número que
            // decide si hay que ir a mirar, y con la cuenta de la página una
            // discrepancia en la página 4 sería invisible.
            'discrepancias' => Factura::query()->conDiscrepanciaSat()->count(),
            'estatus' => [
                Factura::ESTATUS_BORRADOR,
                Factura::ESTATUS_TIMBRANDO,
                Factura::ESTATUS_TIMBRADA,
                Factura::ESTATUS_ERROR,
                Factura::ESTATUS_CANCELADA,
            ],
        ]);
    }

    /**
     * El paquete de comprobantes de un periodo: el trabajo mensual del contador.
     *
     * Se acota por campus igual que el listado —y no es cosmético: sin eso,
     * quien sólo alcanza un plantel se llevaría en un ZIP los CFDI de toda la
     * escuela, que es justo lo que su alcance le niega en la pantalla—.
     */
    public function descargarLote(Request $peticion): BinaryFileResponse|RedirectResponse
    {
        $datos = $peticion->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            'con_pdf' => ['boolean'],
        ]);

        $consulta = Factura::query();
        $this->acotarMatriculas($consulta, $peticion, 'matriculaOferta');

        try {
            $paquete = $this->descargaMasiva->armar(
                $consulta,
                $datos['desde'],
                $datos['hasta'],
                // `boolean()` y no el valor crudo: la cadena «0» de una casilla
                // es VERDADERA en PHP. Misma trampa de siempre.
                $peticion->boolean('con_pdf'),
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return response()->download($paquete['ruta'], $paquete['nombre'])->deleteFileAfterSend(true);
    }

    public function show(Factura $factura): Response
    {
        $factura->load([
            'conceptos.pago.metodoPago:id,nombre',
            'matriculaOferta.persona:id,nombre,primer_apellido,segundo_apellido',
            'sustituye:id,uuid,fecha_timbrado',
            'sustituida:id,uuid,factura_sustituye_id',
            'iedu',
            'origen:id,uuid,total',
            'notasCredito',
        ]);

        // Sólo tiene sentido en una factura de ingreso vigente; en las demás es
        // una consulta que nadie va a mirar.
        $disponible = $factura->esNotaCredito() || ! $factura->estaVigente()
            ? []
            : $this->notasCredito->disponiblePorConcepto($factura);

        return Inertia::render('Finanzas/Facturas/Detalle', [
            'factura' => array_merge($this->resumen($factura), [
                'emisor_rfc' => $factura->emisor_rfc,
                'emisor_razon_social' => $factura->emisor_razon_social,
                'emisor_regimen_fiscal' => $factura->emisor_regimen_fiscal,
                'emisor_cp' => $factura->emisor_cp,
                'receptor_uso_cfdi' => $factura->receptor_uso_cfdi,
                'receptor_regimen_fiscal' => $factura->receptor_regimen_fiscal,
                'receptor_cp' => $factura->receptor_cp,
                'forma_pago_sat' => $factura->forma_pago_sat,
                'metodo_pago_sat' => $factura->metodo_pago_sat,
                'moneda' => $factura->moneda,
                'subtotal' => (float) $factura->subtotal,
                'iva' => (float) $factura->iva,
                'pac' => $factura->pac,
                'intentos' => $factura->intentos,
                'ultimo_error' => $factura->ultimo_error,
                'cancelada_en' => $factura->cancelada_en?->toDateTimeString(),
                'motivo_cancelacion' => $factura->motivo_cancelacion,
                // El complemento que la hace deducible, o la razón por la que
                // salió sin él. Lo segundo sólo se escribe cuando la factura sí
                // amparaba enseñanza: en las demás la pregunta no venía al caso
                // y un aviso ahí enseñaría a ignorarlo.
                'iedu' => $factura->iedu === null ? null : [
                    'nombre_alumno' => $factura->iedu->nombre_alumno,
                    'curp' => $factura->iedu->curp,
                    'nivel_educativo' => $factura->iedu->nivel_educativo,
                    'aut_rvoe' => $factura->iedu->aut_rvoe,
                ],
                'iedu_motivo' => $factura->iedu_motivo,
                'tipo' => $factura->tipo,
                'motivo_egreso' => $factura->motivo_egreso,
                'origen' => $factura->origen === null ? null : [
                    'id' => $factura->origen->id,
                    'uuid' => $factura->origen->uuid,
                    'total' => (float) $factura->origen->total,
                ],
                // Lo acreditado y lo que la factura vale hoy. Van CALCULADOS y
                // no en bruto: es la misma cuenta que decide si se puede
                // acreditar más, y repetirla en la pantalla la haría divergir.
                'acreditado' => $factura->acreditado(),
                'total_efectivo' => $factura->totalEfectivo(),
                'notas_credito' => $factura->notasCredito->map(fn (Factura $n) => [
                    'id' => $n->id,
                    'uuid' => $n->uuid,
                    'estatus' => $n->estatus,
                    'total' => (float) $n->total,
                    'motivo' => $n->motivo_egreso,
                    'fecha' => $n->fecha_timbrado?->toDateTimeString(),
                ])->values(),
                // La pantalla no es la defensa: el servicio lo vuelve a
                // comprobar. Esto sólo decide si se dibuja el botón.
                'sat_estado' => $factura->sat_estado,
                'sat_estado_cancelacion' => $factura->sat_estado_cancelacion,
                'sat_error' => $factura->sat_error,
                'sat_consultado_en' => $factura->sat_consultado_en?->toDateTimeString(),
                // La discrepancia la calcula el MODELO: la misma definición que
                // usan el comando y el filtro del listado. Escrita aquí otra
                // vez, los tres acabarían contando cosas distintas del mismo
                // comprobante.
                'discrepancia_sat' => $factura->discrepanciaSat(),
                'acreditable' => ! $factura->esNotaCredito()
                    && $factura->estaVigente()
                    && $factura->totalEfectivo() > 0,
                'editable' => $factura->esEditable(),
                'fiscal' => $factura->esFiscal(),
                'tiene_xml' => $factura->xml_ruta !== null,
                'tiene_pdf' => $factura->pdf_ruta !== null,
                'sustituye' => $factura->sustituye === null ? null : [
                    'id' => $factura->sustituye->id,
                    'uuid' => $factura->sustituye->uuid,
                ],
                'sustituida_por' => $factura->sustituida->map(fn (Factura $f) => [
                    'id' => $f->id,
                    'uuid' => $f->uuid,
                ])->values(),
            ]),
            'conceptos' => $factura->conceptos->map(fn (FacturaConcepto $c) => [
                'id' => $c->id,
                // Cuánto queda por acreditar de este renglón: es lo que la
                // pantalla usa como tope, para no ofrecer un importe que el
                // servidor va a rechazar.
                'disponible' => $disponible[$c->id] ?? 0.0,
                'clave_sat' => $c->clave_sat,
                'descripcion' => $c->descripcion,
                'cantidad' => (float) $c->cantidad,
                'valor_unitario' => (float) $c->valor_unitario,
                'importe' => (float) $c->importe,
                'iva' => (float) $c->iva,
                'pago_id' => $c->pago_id,
                'pago_metodo' => $c->pago?->metodoPago?->nombre,
            ])->values(),
            'motivos' => [
                ['valor' => Factura::MOTIVO_CON_RELACION, 'etiqueta' => '01 — Se emitió con errores, hay una que la sustituye'],
                ['valor' => Factura::MOTIVO_SIN_RELACION, 'etiqueta' => '02 — Se emitió con errores, sin sustituta'],
                ['valor' => Factura::MOTIVO_NO_LLEVO_ACABO, 'etiqueta' => '03 — La operación no se llevó a cabo'],
                ['valor' => Factura::MOTIVO_NOMINATIVA, 'etiqueta' => '04 — Operación nominativa relacionada con la global'],
            ],
        ]);
    }

    /** Los pagos que todavía se pueden facturar de una matrícula. */
    public function facturables(MatriculaOferta $matricula): Response
    {
        return Inertia::render('Finanzas/Facturas/Emitir', [
            'matricula' => [
                'id' => $matricula->id,
                'matricula' => $matricula->matricula,
                'nombre' => $matricula->persona?->nombreCompleto(),
            ],
            'pagos' => $this->emisor->facturables($matricula->id)->map(fn (Pago $p) => [
                'id' => $p->id,
                'monto' => (float) $p->monto,
                'metodo' => $p->metodoPago?->nombre,
                'referencia' => $p->referencia,
                'momento' => $p->momento?->toDateTimeString(),
                'concepto' => $p->adeudos->first()?->concepto?->nombre,
                // Marca cuáles son enseñanza: el complemento educativo ampara
                // el comprobante ENTERO, así que mezclar en una sola factura una
                // colegiatura con una credencial deja a las dos sin deducción.
                'deducible' => (bool) $p->adeudos->first()?->concepto?->deducible_iedu,
            ])->values(),
            // Lo que le falta a ESTA matrícula para que sus colegiaturas puedan
            // llevar complemento. Se avisa antes de emitir porque después el
            // arreglo es cancelar ante el SAT y volver a facturar.
            'iedu' => ['impedimentos' => $this->complemento->impedimentos($matricula)],
            // Se precargan los datos fiscales de su última factura: quien
            // factura cada mes no debería recapturar su RFC cada vez.
            'ultimoReceptor' => $this->ultimoReceptor($matricula),
            'usoDefault' => config('cfdi.uso_cfdi_default'),
        ]);
    }

    public function store(Request $request, MatriculaOferta $matricula): RedirectResponse
    {
        $datos = $request->validate([
            'pago_ids' => ['required', 'array', 'min:1'],
            'pago_ids.*' => [Rule::exists('pagos', 'id')],
            // 12 para persona moral, 13 para física. No se valida el dígito
            // verificador: eso lo dice el SAT, y rechazar aquí un RFC válido
            // que nuestra regex no contempla sería peor que dejarlo pasar.
            'rfc' => ['required', 'string', 'min:12', 'max:13'],
            'razon_social' => ['required', 'string', 'max:255'],
            'uso_cfdi' => ['required', 'string', 'max:5'],
            'regimen_fiscal' => ['required', 'string', 'max:5'],
            'cp' => ['required', 'string', 'size:5'],
        ]);

        try {
            $factura = $this->emisor->emitir($matricula->id, $datos['pago_ids'], [
                'rfc' => $datos['rfc'],
                'razon_social' => $datos['razon_social'],
                'uso_cfdi' => $datos['uso_cfdi'],
                'regimen_fiscal' => $datos['regimen_fiscal'],
                'cp' => $datos['cp'],
            ]);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        // El timbrado corre en cola: se avisa que va en camino, no que ya está.
        // Decir "factura emitida" cuando todavía no tiene UUID haría que el
        // usuario la buscara para imprimirla y no la encontrara.
        return redirect("/finanzas/facturas/{$factura->id}")
            ->with('advertencia', 'La factura se mandó a timbrar. En cuanto el PAC responda aparecerá su folio fiscal.');
    }

    /**
     * Emite la nota de crédito que reduce una factura sin cancelarla.
     *
     * Es la corrección que `refacturar` no cubre: el importe estaba bien el día
     * que se emitió y cambió después —una beca autorizada tarde, un cobro de
     * más—, o ya venció el plazo del SAT para cancelar.
     */
    public function notaCredito(Request $request, Factura $factura): RedirectResponse
    {
        $datos = $request->validate([
            // Obligatorio: reduce lo declarado al SAT, y sin la razón escrita
            // nadie puede explicar dentro de un año por qué la escuela declaró
            // menos ingreso.
            'motivo' => ['required', 'string', 'max:255'],
            'renglones' => ['required', 'array', 'min:1'],
            'renglones.*.concepto_id' => ['required', 'integer'],
            'renglones.*.importe' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $nota = $this->notasCredito->emitir(
                $factura,
                // Se convierte a propósito: `numeric` ACEPTA la cadena «100» y
                // la devuelve como cadena. Es la trampa que este proyecto ya se
                // cobró en el motor de reportes y en el panorama documental.
                array_map(fn (array $r) => [
                    'concepto_id' => (int) $r['concepto_id'],
                    'importe' => (float) $r['importe'],
                ], $datos['renglones']),
                $datos['motivo'],
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect("/finanzas/facturas/{$nota->id}")
            ->with('advertencia', 'La nota de crédito se mandó a timbrar. En cuanto el PAC responda aparecerá su folio fiscal.');
    }

    /**
     * Emite el comprobante que sustituye a otro. Es el primer paso de corregir
     * una factura timbrada; el segundo es cancelar la original con motivo 01.
     */
    public function refacturar(Request $request, Factura $factura): RedirectResponse
    {
        $datos = $request->validate([
            'rfc' => ['required', 'string', 'min:12', 'max:13'],
            'razon_social' => ['required', 'string', 'max:255'],
            'uso_cfdi' => ['required', 'string', 'max:5'],
            'regimen_fiscal' => ['required', 'string', 'max:5'],
            'cp' => ['required', 'string', 'size:5'],
        ]);

        try {
            $sustituta = $this->emisor->refacturar($factura, $datos);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect("/finanzas/facturas/{$sustituta->id}")->with(
            'advertencia',
            'Se mandó a timbrar la factura que sustituye a la #'.$factura->id.'. '
            .'En cuanto tenga folio fiscal, cancela la original con motivo 01.'
        );
    }

    /** Reintenta un timbrado que el PAC rechazó o que no alcanzó a salir. */
    public function reintentar(Factura $factura): RedirectResponse
    {
        if ($factura->esFiscal()) {
            return back()->with('error', 'Esta factura ya está timbrada.');
        }

        $factura->update(['estatus' => Factura::ESTATUS_BORRADOR, 'ultimo_error' => null]);

        TimbrarFactura::dispatch($factura->id);

        return back()->with('advertencia', 'Se mandó a timbrar de nuevo.');
    }

    public function cancelar(Request $request, Factura $factura): RedirectResponse
    {
        $datos = $request->validate([
            'motivo' => ['required', Rule::in([
                Factura::MOTIVO_CON_RELACION,
                Factura::MOTIVO_SIN_RELACION,
                Factura::MOTIVO_NO_LLEVO_ACABO,
                Factura::MOTIVO_NOMINATIVA,
            ])],
            'sustituta_id' => ['nullable', Rule::exists('facturas', 'id')],
        ]);

        $sustituta = $datos['sustituta_id'] === null
            ? null
            : Factura::find($datos['sustituta_id']);

        try {
            $this->emisor->cancelar($factura, $datos['motivo'], $sustituta);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'exito',
            'Factura cancelada. Sus pagos vuelven a poderse facturar.'
        );
    }

    /**
     * Solo se borra lo que nunca fue fiscal. Un CFDI timbrado no se elimina de
     * la base aunque esté cancelado: es el respaldo de lo que se declaró.
     */
    public function destroy(Factura $factura): RedirectResponse
    {
        if (! $factura->esEditable()) {
            return back()->with('error', 'Una factura timbrada no se elimina: se cancela.');
        }

        $factura->delete();

        return redirect('/finanzas/facturas')->with('exito', 'Borrador eliminado.');
    }

    /** Descarga el XML o el PDF del disco privado. Nunca desde `public/`. */
    public function descargar(Factura $factura, string $tipo): StreamedResponse
    {
        abort_unless(in_array($tipo, ['xml', 'pdf'], true), 404);

        $ruta = $tipo === 'xml' ? $factura->xml_ruta : $factura->pdf_ruta;

        abort_if($ruta === null || ! Storage::disk('local')->exists($ruta), 404);

        return Storage::disk('local')->download(
            $ruta,
            ($factura->uuid ?? 'factura-'.$factura->id).'.'.$tipo
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(Factura $factura): array
    {
        return [
            'id' => $factura->id,
            'uuid' => $factura->uuid,
            // Va en el resumen y no sólo en el detalle: en el listado, una nota
            // de crédito se ve idéntica a una factura y su total RESTA. Sin
            // distinguirlas, quien suma la columna con la vista se equivoca.
            'tipo' => $factura->tipo,
            'estatus' => $factura->estatus,
            'receptor_rfc' => $factura->receptor_rfc,
            'receptor_razon_social' => $factura->receptor_razon_social,
            'emisor' => $factura->emisor_razon_social,
            'total' => (float) $factura->total,
            'discrepancia_sat' => $factura->discrepanciaSat(),
            'fecha_timbrado' => $factura->fecha_timbrado?->toDateTimeString(),
            'matricula_id' => $factura->matricula_oferta_id,
            'matricula' => $factura->matriculaOferta?->matricula,
            'alumno' => $factura->matriculaOferta?->persona?->nombreCompleto(),
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function ultimoReceptor(MatriculaOferta $matricula): ?array
    {
        $ultima = Factura::query()
            // De INGRESO: la pregunta es «con qué datos facturó la última vez»,
            // y una nota de crédito copia los del comprobante que reduce, así
            // que como respuesta sería el reflejo de otra factura.
            ->deIngreso()
            ->where('matricula_oferta_id', $matricula->id)
            ->orderByDesc('id')
            ->first();

        return $ultima === null ? null : [
            'rfc' => $ultima->receptor_rfc,
            'razon_social' => $ultima->receptor_razon_social,
            'uso_cfdi' => $ultima->receptor_uso_cfdi,
            'regimen_fiscal' => $ultima->receptor_regimen_fiscal,
            'cp' => $ultima->receptor_cp,
        ];
    }
}
