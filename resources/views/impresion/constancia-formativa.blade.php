{{--
    La constancia de liberación.

    TODO sale de `$datos`, que es el SNAPSHOT congelado al liberar. Ni una sola
    relación viva: reimprimirla dentro de tres años tiene que dar exactamente el
    mismo texto, y releyendo las tablas de hoy el mismo folio ampararía dos
    documentos distintos.

    Estilos EN LÍNEA, como el acta: un fallo de assets no puede dejar sin forma
    un documento oficial justo cuando hay que firmarlo. Y mpdf no entiende ni el
    selector de hermano adyacente ni el hex de ocho dígitos —lecciones del
    historial en PDF—, así que aquí no se usan.
--}}
<div style="font-family: sans-serif; color: #111; font-size: 11pt; line-height: 1.5;">

    <div style="text-align: center; border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 22px;">
        <div style="font-size: 15pt; font-weight: bold; text-transform: uppercase;">
            {{ $institucion?->nombre_mostrar ?: ($institucion?->nombre ?? 'La institución') }}
        </div>
        <div style="font-size: 12pt; margin-top: 6px; letter-spacing: 1px;">
            CONSTANCIA DE {{ mb_strtoupper($datos['proceso']['tipo'] ?? 'PROCESO FORMATIVO') }}
        </div>
        <div style="font-size: 9pt; color: #555; margin-top: 4px;">
            Folio <strong>{{ $liberacion->folio }}</strong>
        </div>
    </div>

    @if (! $liberacion->estaVigente())
        {{--
            Se dice ARRIBA además de la marca de agua: quien recibe una hoja
            impresa en blanco y negro puede no distinguir la marca, y este
            documento ya no ampara nada.
        --}}
        <p style="border: 1px solid #b91c1c; background: #fdecec; color: #b91c1c; padding: 8px 12px; margin-bottom: 18px; font-size: 10pt;">
            <strong>Este folio quedó sin efecto</strong> el
            {{ $liberacion->corregida_en?->format('d/m/Y') }}. Se emitió una constancia que lo sustituye;
            pide el folio vigente en servicios escolares.
        </p>
    @endif

    @if ($liberacion->esCorreccion())
        <p style="border: 1px solid #b45309; background: #fff7ed; color: #b45309; padding: 8px 12px; margin-bottom: 18px; font-size: 10pt;">
            Esta constancia <strong>sustituye</strong> al folio
            {{ $liberacion->corrige?->folio ?? '—' }}.
            @if ($liberacion->motivo_correccion)
                Motivo: {{ $liberacion->motivo_correccion }}
            @endif
        </p>
    @endif

    <p style="text-align: justify; margin-bottom: 18px;">
        Se hace constar que <strong>{{ $datos['alumno']['nombre'] ?? '—' }}</strong>,
        con matrícula <strong>{{ $datos['alumno']['matricula'] ?? '—' }}</strong>
        @if (! empty($datos['alumno']['curp']))
            y CURP {{ $datos['alumno']['curp'] }},
        @else
            ,
        @endif
        estudiante de <strong>{{ $datos['alumno']['programa'] ?? '—' }}</strong>@if (! empty($datos['alumno']['campus'])) del {{ $datos['alumno']['campus'] }}@endif,
        concluyó satisfactoriamente su
        <strong>{{ mb_strtolower($datos['proceso']['tipo'] ?? 'proceso formativo') }}</strong>
        @if (! empty($datos['organizacion']['razon_social']))
            en <strong>{{ $datos['organizacion']['razon_social'] }}</strong>,
        @endif
        @if (! empty($datos['horas']['aprobadas']))
            acreditando <strong>{{ $liberacion->horas_acreditadas ?? $datos['horas']['aprobadas'] }} horas</strong>,
        @endif
        {{--
            Las fechas EN LETRA, no en ISO: «del 2026-08-17 al 2027-01-03»
            dentro de un párrafo formal se lee como un volcado de base de datos.
        --}}
        @if (! empty($datos['proceso']['fecha_inicio']) && ! empty($datos['proceso']['fecha_fin']))
            del {{ \Illuminate\Support\Carbon::parse($datos['proceso']['fecha_inicio'])->locale('es')->translatedFormat('j \d\e F \d\e Y') }}
            al {{ \Illuminate\Support\Carbon::parse($datos['proceso']['fecha_fin'])->locale('es')->translatedFormat('j \d\e F \d\e Y') }}.
        @else
            en el periodo señalado en su expediente.
        @endif
    </p>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 10pt;">
        <tbody>
            @foreach ([
                'Programa' => $datos['alumno']['programa'] ?? null,
                'Plan de estudios' => $datos['alumno']['plan'] ?? null,
                'Organización receptora' => $datos['organizacion']['nombre'] ?? null,
                'Plaza o proyecto' => $datos['organizacion']['plaza'] ?? null,
                'Supervisor' => $datos['organizacion']['supervisor'] ?? null,
                'Modalidad' => $datos['proceso']['modalidad'] ?? null,
                'Responsable interno' => $datos['responsable_interno'] ?? null,
                'Horas acreditadas' => $liberacion->horas_acreditadas,
                'Regla aplicada' => ($datos['regla']['nombre'] ?? null)
                    ? ($datos['regla']['nombre'].' · versión '.($datos['regla']['version'] ?? '—'))
                    : null,
            ] as $etiqueta => $valor)
                @if ($valor !== null && $valor !== '')
                    <tr>
                        <td style="border-bottom: 1px solid #ddd; padding: 5px 0; color: #555; width: 38%;">{{ $etiqueta }}</td>
                        <td style="border-bottom: 1px solid #ddd; padding: 5px 0;">{{ $valor }}</td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>

    {{--
        Sólo las evaluaciones CON puntaje.

        Una línea que dice «Del supervisor: sin puntaje» no informa de nada y
        ensucia un documento oficial: la evaluación existe y consta en el
        expediente, pero sin cifra no hay nada que certificar aquí. Es la regla
        de vacíos del proyecto llevada al papel.
    --}}
    @php($conPuntaje = collect($datos['evaluaciones'] ?? [])->filter(fn ($e) => $e['puntaje'] !== null))

    @if ($conPuntaje->isNotEmpty())
        <div style="margin-bottom: 20px;">
            <div style="font-weight: bold; font-size: 10pt; margin-bottom: 6px;">Evaluación</div>
            @foreach ($conPuntaje as $evaluacion)
                <div style="font-size: 10pt; color: #333;">
                    {{ $evaluacion['origen'] }}:
                    {{ $evaluacion['puntaje'] }}@if (! empty($evaluacion['total'])) de {{ $evaluacion['total'] }}@endif
                </div>
            @endforeach
        </div>
    @endif

    @if (! empty($datos['excepciones']))
        {{--
            Las excepciones se DICEN en el documento. Callarlas haría que una
            constancia emitida perdonando un requisito se viera idéntica a otra
            que lo cumplió todo — y quien la recibe tiene derecho a saberlo.
        --}}
        <div style="margin-bottom: 20px; border: 1px solid #ddd; padding: 8px 12px; font-size: 9pt; color: #555;">
            <div style="font-weight: bold; margin-bottom: 4px;">Excepciones autorizadas</div>
            @foreach ($datos['excepciones'] as $excepcion)
                <div>
                    {{ $excepcion['requisito'] }} — {{ $excepcion['motivo'] }}
                    @if (! empty($excepcion['autorizada_por'])) (autorizó {{ $excepcion['autorizada_por'] }})@endif
                </div>
            @endforeach
        </div>
    @endif

    <p style="font-size: 10pt; color: #555; margin-bottom: 40px;">
        Se extiende la presente a los {{ $liberacion->liberado_en?->format('d') }} días del mes de
        {{ $liberacion->liberado_en?->locale('es')->translatedFormat('F \d\e Y') }}.
    </p>

    <div style="text-align: center; margin-top: 50px;">
        <div style="border-top: 1px solid #111; width: 60%; margin: 0 auto; padding-top: 6px; font-size: 10pt;">
            {{ $datos['responsable_interno'] ?? 'Responsable del proceso' }}
        </div>
    </div>
</div>
