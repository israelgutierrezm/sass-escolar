<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\NivelEstudio;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\TipoAsignatura;
use App\Models\Academico\TipoPeriodo;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Historial;
use App\Models\Landlord\EntidadFederativa;
use App\Models\Landlord\Genero;
use App\Support\Creditos;
use Carbon\CarbonInterface;
use DOMDocument;
use DOMElement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Construye el Documento Electrónico de Certificación (DEC) de la SEP/SIGED:
 * el nodo raíz `Dec` (namespace https://www.siged.sep.gob.mx/certificados/,
 * versión 3.0, tipoCertificado 5 = IPES) con la estructura y el orden exactos
 * de la especificación técnica 3.0, la cadena original y su sellado.
 *
 * `snapshot()` congela los datos ACADÉMICOS del alumno; los datos del RESPONSABLE
 * y el sello los inyecta el FirmadorLote en `$firma` al momento de firmar.
 *
 * Los identificadores oficiales de catálogos SEP (idEntidad, idCampus,
 * idCarrera, idAsignatura, etc.) todavía no se mapean: se rellenan con los ids
 * y claves LOCALES como placeholder para que el XML valide estructuralmente.
 * Cuando la escuela tenga sus claves SEP, solo cambian estos valores aquí.
 */
class ConstructorCertificadoXml
{
    private const NS = 'https://www.siged.sep.gob.mx/certificados/';

    /** Foto académica del alumno-carrera, en el vocabulario del DEC.
     *
     * @return array<string, mixed>
     */
    public function snapshot(MatriculaOferta $matricula, string $tipo = 'total'): array
    {
        $parcial = $tipo === 'parcial';

        $matricula->loadMissing([
            'persona',
            'oferta.carrera',
            'oferta.plan',
            'oferta.campus.institucion',
            'oferta.campus.entidad',
        ]);

        $persona = $matricula->persona;
        $oferta = $matricula->oferta;
        $plan = $oferta?->plan;
        $carrera = $oferta?->carrera;
        $campus = $oferta?->campus;
        $institucion = $campus?->institucion;
        $entidad = $campus?->entidad;

        $historial = Historial::query()
            ->with(['planMateria.asignatura:id,identificador,nombre,creditos,tipo_asignatura_id', 'estatus:id,nombre,clave', 'ciclo:id,clave'])
            ->where('matricula_oferta_id', $matricula->id)
            ->get();

        // Identificadores oficiales de catálogo (no el id auto-incremental).
        $idNivel = $this->idCatalogo(NivelEstudio::class, $carrera?->nivel_estudios_id);
        $idTipoPeriodo = $this->idCatalogo(TipoPeriodo::class, $plan?->tipo_periodo_id);
        $idGenero = $this->idGeneroSep($persona);
        $idEntidad = $this->idCatalogo(EntidadFederativa::class, $campus?->entidad_id);
        // Mapa id → tipo de asignatura de los usados. El tipo vive en la
        // ASIGNATURA (`asignaturas.tipo_asignatura_id`); `plan_materias` no lo
        // tiene —su columna `tipo` es el papel dentro del plan, otro vocabulario—.
        $tiposAsig = TipoAsignatura::query()
            ->whereIn('id', $historial->pluck('planMateria.asignatura.tipo_asignatura_id')->filter()->unique())
            ->get(['id', 'identificador', 'nombre']);
        $idTipoSep = $tiposAsig->pluck('identificador', 'id');
        /*
         * El nombre viaja al WS de la SEP en MAYÚSCULAS.
         *
         * El catálogo se guarda como se lee en pantalla —«Obligatoria»—, pero el
         * web service espera el valor oficial tal cual lo publica la SEP. Es el
         * único de los tres catálogos cuyo NOMBRE viaja: el nivel y el tipo de
         * periodo van sólo por id, así que su ortografía es asunto nuestro.
         */
        $nombreTipo = $tiposAsig->pluck('nombre', 'id')->map(fn (string $n) => mb_strtoupper($n));

        // Mejor intento por materia: una materia aprobada a título tras tronar el
        // ordinario cuenta una vez, como aprobada.
        $mejores = $historial
            ->filter(fn (Historial $h) => $h->plan_materia_id !== null)
            ->groupBy('plan_materia_id')
            ->map(fn ($intentos) => $intentos->sortByDesc(fn (Historial $h) => (float) ($h->calificacion ?? -1))->first())
            ->values();

        $aprobadas = $mejores->filter(fn (Historial $h) => $h->estatus?->clave === 'aprobada');

        $asignaturas = $mejores
            ->sortBy(fn (Historial $h) => [$h->planMateria?->periodo ?? 0, $h->planMateria?->clave_en_plan ?? ''])
            ->map(fn (Historial $h) => [
                'idAsignatura' => (string) ($h->planMateria?->asignatura?->identificador ?? $h->planMateria?->asignatura_id ?? '0'),
                'claveAsignatura' => $h->planMateria?->clave_en_plan,
                'nombre' => $h->planMateria?->asignatura?->nombre,
                'ciclo' => $h->ciclo?->clave ?? 'NA',
                'calificacion' => (string) ($h->calificacion ?? '0'),
                // Sin `identificador` capturado, el id local ES el valor SEP: los
                // tipos se siembran con el id del catálogo oficial (263 = OBLIGATORIA).
                'idTipoAsignatura' => (string) ($idTipoSep[$h->planMateria?->asignatura?->tipo_asignatura_id] ?? $h->planMateria?->asignatura?->tipo_asignatura_id ?? '0'),
                'tipoAsignatura' => $nombreTipo[$h->planMateria?->asignatura?->tipo_asignatura_id] ?? 'OBLIGATORIA',
                'creditos' => (string) ($h->planMateria?->asignatura?->creditos ?? '0'),
            ])->values()->all();

        $numeroCiclos = $mejores->map(fn (Historial $h) => $h->ciclo?->clave)->filter()->unique()->count();

        return [
            'version' => '3.0',
            'tipoCertificado' => '5',
            'emitido_en' => now()->toIso8601String(),
            // ServicioFirmante
            'idEntidad' => (string) ($institucion?->id ?? '0'),
            // Ipes
            'idNombreInstitucion' => (string) ($institucion?->clave ?? $institucion?->id ?? '0'),
            'nombreInstitucion' => $institucion?->nombre,
            'idCampus' => (string) ($campus?->identificador ?? $campus?->clave ?? $campus?->id ?? '0'),
            'campus' => $campus?->nombre,
            'idEntidadFederativa' => (string) ($idEntidad ?? $campus?->entidad_id ?? '0'),
            'entidadFederativa' => $entidad?->nombre,
            // Rvoe
            'numeroRvoe' => (string) ($plan?->rvoe ?? 'SIN-RVOE'),
            'fechaExpedicionRvoe' => $this->fechaHora($plan?->fecha_rvoe),
            // Carrera
            'idCarrera' => (string) ($carrera?->identificador ?? $carrera?->id ?? '0'),
            'claveCarrera' => $carrera?->clave,
            'nombreCarrera' => $carrera?->nombre,
            'idTipoPeriodo' => (string) ($idTipoPeriodo ?? $plan?->tipo_periodo_id ?? '0'),
            'clavePlan' => (string) ($plan?->clave ?? 'SIN-PLAN'),
            'idNivelEstudios' => (string) ($idNivel ?? $carrera?->nivel_estudios_id ?? '0'),
            'calificacionMinima' => (string) ($plan?->calificacion_minima ?? '0'),
            'calificacionMaxima' => (string) ($plan?->calificacion_maxima ?? '10'),
            'calificacionMinimaAprobatoria' => (string) ($plan?->calificacion_minima_aprobatoria ?? '6'),
            // Alumno
            'numeroControl' => (string) ($matricula->matricula ?? 'SN'),
            'curpAlumno' => $persona?->curp,
            'nombre' => $persona?->nombre,
            'primerApellido' => $persona?->primer_apellido,
            'segundoApellido' => $persona?->segundo_apellido,
            'idGenero' => $idGenero,
            'fechaNacimiento' => $this->fechaHora($persona?->fecha_nacimiento),
            // Expedicion
            'idTipoCertificacion' => $parcial ? '80' : '79', // 79 = Total, 80 = Parcial
            'tipoCertificacion' => $parcial ? 'Parcial' : 'Total',
            'fechaExpedicion' => $this->fechaHora(now()),
            'idLugarExpedicion' => (string) ($idEntidad ?? $campus?->entidad_id ?? '0'),
            'lugarExpedicion' => $entidad?->nombre,
            // Asignaturas (totales)
            'total' => count($asignaturas),
            'asignadas' => $aprobadas->count(),
            'promedio' => (string) ($this->promedio($mejores, $plan) ?? '0'),
            'totalCreditos' => (string) ($plan?->total_creditos ?? '0'),
            // La misma suma que el expediente y el portal del padre: tres
            // copias con dos precisiones distintas daban tres cifras.
            'creditosObtenidos' => (string) Creditos::sumar($aprobadas),
            'numeroCiclos' => $numeroCiclos,
            'asignaturas' => $asignaturas,
        ];
    }

    /**
     * Cadena original del DEC según la secuencia 6.5 de la especificación:
     * `||` al inicio y al final, `|` como separador, sin el carácter `|` dentro
     * de los valores. Es lo que se SELLA.
     *
     * @param  array<string, mixed>  $d
     * @param  array<string, mixed>  $firma  responsable_curp, responsable_id_cargo
     */
    public function cadenaOriginal(array $d, array $firma): string
    {
        $partes = [
            $d['version'], $d['tipoCertificado'],
            $d['idEntidad'],
            $d['idNombreInstitucion'], $d['idCampus'], $d['idEntidadFederativa'],
            $firma['responsable_curp'] ?? '', $firma['responsable_id_cargo'] ?? '',
            $d['numeroRvoe'], $d['fechaExpedicionRvoe'],
            $d['idCarrera'], $d['idTipoPeriodo'], $d['clavePlan'], $d['idNivelEstudios'],
            $d['calificacionMinima'], $d['calificacionMaxima'], $d['calificacionMinimaAprobatoria'],
            $d['numeroControl'], $d['curpAlumno'], $d['nombre'], $d['primerApellido'], $d['segundoApellido'],
            $d['idGenero'], $d['fechaNacimiento'],
            $d['idTipoCertificacion'], $d['fechaExpedicion'], $d['idLugarExpedicion'],
            $d['total'], $d['asignadas'], $d['promedio'], $d['totalCreditos'], $d['creditosObtenidos'], $d['numeroCiclos'],
        ];

        foreach ($d['asignaturas'] as $a) {
            array_push($partes, $a['idAsignatura'], $a['ciclo'], $a['calificacion'], $a['idTipoAsignatura'], $a['creditos']);
        }

        $limpias = array_map(fn ($p) => str_replace(['|', "\n", "\r"], ' ', (string) $p), $partes);

        return '||'.implode('|', $limpias).'||';
    }

    /**
     * XML del DEC. `$firma` trae lo del sellado: sello (base64),
     * certificado (base64), no_certificado (serie) y los datos del responsable.
     *
     * @param  array<string, mixed>  $d
     * @param  array<string, mixed>  $firma
     */
    public function xml(array $d, array $firma): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $dec = $dom->createElementNS(self::NS, 'Dec');
        $dom->appendChild($dec);
        $dec->setAttribute('version', $d['version']);
        $dec->setAttribute('tipoCertificado', $d['tipoCertificado']);
        if (filled($firma['folio'] ?? null)) {
            $dec->setAttribute('folioControl', (string) $firma['folio']);
        }
        $dec->setAttribute('sello', (string) ($firma['sello'] ?? ''));
        $dec->setAttribute('certificadoResponsable', (string) ($firma['certificado'] ?? ''));
        $dec->setAttribute('noCertificadoResponsable', (string) ($firma['no_certificado'] ?? ''));

        // 1) ServicioFirmante
        $dec->appendChild($this->nodo($dom, 'ServicioFirmante', ['idEntidad' => $d['idEntidad']]));

        // 2) Ipes + Responsable
        $ipes = $this->nodo($dom, 'Ipes', [
            'idNombreInstitucion' => $d['idNombreInstitucion'],
            'nombreInstitucion' => $d['nombreInstitucion'],
            'idCampus' => $d['idCampus'],
            'campus' => $d['campus'],
            'idEntidadFederativa' => $d['idEntidadFederativa'],
            'entidadFederativa' => $d['entidadFederativa'] ?? null,
        ]);
        $ipes->appendChild($this->nodo($dom, 'Responsable', [
            'curp' => $firma['responsable_curp'] ?? '',
            'nombre' => $firma['responsable_nombre'] ?? '',
            'primerApellido' => $firma['responsable_primer_apellido'] ?? '',
            'segundoApellido' => $firma['responsable_segundo_apellido'] ?? null,
            'idCargo' => $firma['responsable_id_cargo'] ?? '0',
            // El cargo (catálogo, guardado en caso legible) va en MAYÚSCULAS al XML.
            'cargo' => filled($firma['responsable_cargo'] ?? null) ? mb_strtoupper((string) $firma['responsable_cargo'], 'UTF-8') : null,
        ]));
        $dec->appendChild($ipes);

        // 3) Rvoe
        $dec->appendChild($this->nodo($dom, 'Rvoe', [
            'numero' => $d['numeroRvoe'],
            'fechaExpedicion' => $d['fechaExpedicionRvoe'],
        ]));

        // 4) Carrera
        $dec->appendChild($this->nodo($dom, 'Carrera', [
            'idCarrera' => $d['idCarrera'],
            'claveCarrera' => $d['claveCarrera'],
            'nombreCarrera' => $d['nombreCarrera'],
            'idTipoPeriodo' => $d['idTipoPeriodo'],
            'clavePlan' => $d['clavePlan'],
            'idNivelEstudios' => $d['idNivelEstudios'],
            'calificacionMinima' => $d['calificacionMinima'],
            'calificacionMaxima' => $d['calificacionMaxima'],
            'calificacionMinimaAprobatoria' => $d['calificacionMinimaAprobatoria'],
        ]));

        // 5) Alumno
        $dec->appendChild($this->nodo($dom, 'Alumno', [
            'numeroControl' => $d['numeroControl'],
            'curp' => $d['curpAlumno'],
            'nombre' => $d['nombre'],
            'primerApellido' => $d['primerApellido'],
            'segundoApellido' => $d['segundoApellido'],
            'idGenero' => $d['idGenero'],
            'fechaNacimiento' => $d['fechaNacimiento'],
        ]));

        // 6) Expedicion
        $dec->appendChild($this->nodo($dom, 'Expedicion', [
            'idTipoCertificacion' => $d['idTipoCertificacion'],
            'tipoCertificacion' => $d['tipoCertificacion'],
            'fecha' => $d['fechaExpedicion'],
            'idLugarExpedicion' => $d['idLugarExpedicion'],
            'lugarExpedicion' => $d['lugarExpedicion'],
        ]));

        // 7) Asignaturas
        $asignaturas = $this->nodo($dom, 'Asignaturas', [
            'total' => $d['total'],
            'asignadas' => $d['asignadas'],
            'promedio' => $d['promedio'],
            'totalCreditos' => $d['totalCreditos'],
            'creditosObtenidos' => $d['creditosObtenidos'],
            'numeroCiclos' => $d['numeroCiclos'],
        ]);
        foreach ($d['asignaturas'] as $a) {
            $asignaturas->appendChild($this->nodo($dom, 'Asignatura', $a));
        }
        $dec->appendChild($asignaturas);

        return (string) $dom->saveXML();
    }

    /**
     * Crea un nodo con cada valor como atributo (los nulos/vacíos se omiten:
     * los opcionales del DEC no deben aparecer vacíos).
     *
     * @param  array<string, mixed>  $datos
     */
    private function nodo(DOMDocument $dom, string $nombre, array $datos): DOMElement
    {
        $el = $dom->createElementNS(self::NS, $nombre);
        foreach ($datos as $clave => $valor) {
            if ($valor === null || $valor === '') {
                continue;
            }
            $el->setAttribute($clave, (string) $valor);
        }

        return $el;
    }

    /**
     * Identificador oficial de un registro de catálogo por su id local. Devuelve
     * null si no hay id o no existe; el llamador cae al id local como respaldo.
     *
     * @param  class-string<Model>  $modelo
     */
    private function idCatalogo(string $modelo, mixed $id): ?string
    {
        if (blank($id)) {
            return null;
        }

        $identificador = $modelo::query()->whereKey($id)->value('identificador');

        return filled($identificador) ? (string) $identificador : (string) $id;
    }

    /**
     * Identificador oficial del género (idGenero: 250 = Mujer, 251 = Hombre).
     * Preferencia: género capturado; si no, se deriva del sexo de la CURP
     * (posición 11: H/M), que siempre está presente.
     */
    private function idGeneroSep(mixed $persona): int
    {
        if ($persona?->genero_id !== null) {
            $id = Genero::query()->whereKey($persona->genero_id)->value('identificador');
            if (filled($id)) {
                return (int) $id;
            }
        }

        $letra = mb_strtoupper(mb_substr((string) ($persona?->curp ?? ''), 10, 1));
        $nombre = $letra === 'M' ? 'MUJER' : 'HOMBRE';
        $id = Genero::query()->where('nombre', $nombre)->value('identificador');

        return (int) ($id ?? ($letra === 'M' ? 250 : 251));
    }

    /** Formatea una fecha al `xs:dateTime` que exige el DEC (con hora). */
    private function fechaHora(mixed $valor): string
    {
        if ($valor instanceof CarbonInterface) {
            return $valor->format('Y-m-d\TH:i:s');
        }

        if (is_string($valor) && $valor !== '') {
            try {
                return Carbon::parse($valor)->format('Y-m-d\TH:i:s');
            } catch (\Throwable) {
                // cae al placeholder
            }
        }

        return '1900-01-01T00:00:00';
    }

    /** @param  Collection<int, Historial>  $mejores */
    /**
     * El promedio, en la precisión que manda el plan.
     *
     * ── Por qué importa más aquí que en ningún otro sitio ──────────────────
     * Esto va dentro del certificado electrónico: un documento que se sella y
     * se manda a la SEP. Con dos decimales fijos —como estaba—, una escuela con
     * plan de enteros veía un 8 en el expediente y en el historial académico, y su
     * certificado oficial decía 8.33. Dos documentos de la misma escuela que no
     * concuerdan, y el que vale es el que estaba mal.
     *
     * Se pregunta al plan, igual que el expediente y el portal del padre.
     */
    private function promedio($mejores, ?PlanEstudio $plan = null): ?float
    {
        $conCalificacion = $mejores->filter(fn (Historial $h) => $h->calificacion !== null);

        if ($conCalificacion->isEmpty()) {
            return null;
        }

        return PlanEstudio::redondearCon(
            $plan,
            (float) $conCalificacion->avg(fn (Historial $h) => (float) $h->calificacion),
        );
    }
}
