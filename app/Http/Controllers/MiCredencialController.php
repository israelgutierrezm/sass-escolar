<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Credencial\CatalogoCampos;
use App\Credencial\CodigoQr;
use App\Credencial\Compositor;
use App\Credencial\CredencialesDeLaPersona;
use App\Models\Identidad\Usuario;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as Pantalla;

/**
 * La credencial de quien está en sesión.
 *
 * ── No recibe id de nadie ─────────────────────────────────────────────────
 * La persona sale de la SESIÓN, igual que en `/mi-historial`: la ruta no lleva
 * identificador, así que no existe siquiera dónde escribir el de otro. Quien
 * estudia dos carreras elige entre LAS SUYAS, y la elección se busca en esa
 * misma lista — una clave ajena no encuentra pareja y cae a la primera propia.
 *
 * ── Sin permiso propio ────────────────────────────────────────────────────
 * No hay un `ver-mi-credencial` que asignar. Quien decide si alguien tiene
 * credencial es la escuela al encender la de ese rol, y pedir además un permiso
 * significaría que apagarla en un sitio y olvidarla en el otro deja gente sin
 * gafete sin que nadie entienda por qué. Sin configuración emitible, la
 * pantalla no existe (404) y el enlace no se pinta.
 */
class MiCredencialController extends Controller
{
    public function __construct(
        private readonly CredencialesDeLaPersona $credenciales,
        private readonly Compositor $compositor,
        private readonly CodigoQr $qr,
    ) {}

    public function index(Request $peticion): Pantalla
    {
        $mias = $this->mias($peticion);

        abort_if($mias->isEmpty(), 404);

        $elegida = $this->elegida($mias, $peticion->string('credencial')->toString());

        return Inertia::render('MiCredencial', [
            'credenciales' => $mias->map(fn (array $c) => [
                'clave' => $c['clave'],
                'etiqueta' => $c['etiqueta'],
            ])->values(),
            'elegida' => $elegida['clave'],
            'tiene_reverso' => $elegida['config']->tieneReverso(),
            'firma' => array_filter([
                'nombre' => $elegida['config']->firma_nombre,
                'cargo' => $elegida['config']->firma_cargo,
            ]),
        ]);
    }

    /**
     * El PNG de una cara.
     *
     * Va por su propia ruta y no incrustado en la pantalla: es la MISMA imagen
     * que se ve y que se descarga. Mandarla en base64 dentro de las props
     * duplicaría medio megabyte en cada visita y haría que «descargar» fuera
     * otra cosa que lo que se estaba mirando.
     */
    public function imagen(Request $peticion, string $cara): Response
    {
        abort_unless(in_array($cara, ['anverso', 'reverso'], true), 404);

        $mias = $this->mias($peticion);

        abort_if($mias->isEmpty(), 404);

        $credencial = $this->elegida($mias, $peticion->string('credencial')->toString());
        $config = $credencial['config'];

        abort_if($cara === 'reverso' && ! $config->tieneReverso(), 404);

        $usuario = $peticion->user();
        $persona = $usuario->persona;

        $png = $this->compositor->componer(
            $config,
            $cara,
            CatalogoCampos::valores($persona, $usuario->rolActivo, $credencial['matricula'], $config->vigencia),
            $this->foto($persona->foto_url),
            $config->qr_activo
                ? $this->qr->png($persona, $usuario->rol_activo_id, $credencial['matricula'])
                : null,
        );

        $descarga = $peticion->boolean('descargar');

        return response($png, 200, [
            'Content-Type' => 'image/png',
            /*
             * Sin caché. La credencial se compone con el nombre, la carrera y
             * la vigencia que la escuela tiene HOY: si el navegador guarda la
             * imagen, corregir un apellido no alcanzaría a quien ya la abrió,
             * y una vigencia vencida seguiría enseñándose como buena.
             */
            'Cache-Control' => 'no-store, private',
            'Content-Disposition' => $descarga
                ? 'attachment; filename="'.$this->nombreDeArchivo($credencial['etiqueta'], $cara).'"'
                : 'inline',
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function mias(Request $peticion): Collection
    {
        $usuario = $peticion->user();

        return $usuario instanceof Usuario ? $this->credenciales->para($usuario) : collect();
    }

    /**
     * La credencial pedida, buscada entre LAS SUYAS.
     *
     * Ahí está la salvaguarda: la clave que llega por la URL sólo sirve para
     * escoger dentro de una lista que ya se calculó para esta persona. Una
     * clave de otro no encuentra pareja y se cae a la primera propia, en vez
     * de traer la credencial de nadie más.
     *
     * @param  Collection<int, array<string, mixed>>  $mias
     * @return array<string, mixed>
     */
    private function elegida(Collection $mias, string $clave): array
    {
        return $mias->firstWhere('clave', $clave) ?? $mias->first();
    }

    private function foto(?string $ruta): ?string
    {
        return filled($ruta) && Storage::disk('local')->exists($ruta)
            ? Storage::disk('local')->get($ruta)
            : null;
    }

    private function nombreDeArchivo(string $etiqueta, string $cara): string
    {
        return 'credencial-'.Str::slug($etiqueta ?: 'mi-credencial').'-'.$cara.'.png';
    }
}
