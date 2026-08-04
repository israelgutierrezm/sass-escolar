<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CamposIdentidad from '@/Components/CamposIdentidad.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import PestanasPagina from '@/Components/PestanasPagina.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

interface Renglon {
    id: number;
    plan_materia_id: number | null;
    clave_en_plan: string | null;
    materia: string | null;
    creditos: number | null;
    periodo: number | null;
    ciclo: string | null;
    calificacion: string | null;
    estatus: string | null;
    estatus_clave: string | null;
    tipo_evaluacion: string | null;
    acta_folio: string | null;
    observacion: string | null;
    observacion_asignatura: string | null;
    manual: boolean;
}

const props = defineProps<{
    alumno: Record<string, any>;
    persona: Record<string, any>;
    carreras: {
        id: number;
        matricula: string;
        carrera: string | null;
        plan: string | null;
        campus: string | null;
        estatus: string;
        situacion: string | null;
        fecha_ingreso: string | null;
        generacion: string | null;
        materias_en_kardex: number;
        es_actual: boolean;
    }[];
    ofertasDisponibles: { id: number; etiqueta: string }[];
    tutores: {
        id: number;
        nombre: string;
        curp: string | null;
        email: string | null;
        parentesco: string;
        puede_ver_academico: boolean;
        puede_ver_finanzas: boolean;
        suplantable: { usuario_id: number; usuario: string } | null;
    }[];
    facturacion: {
        quiere_factura: boolean;
        es_tercero: boolean;
        rfc: string | null;
        razon_social: string | null;
        regimen_fiscal: string | null;
        cp: string | null;
        uso_cfdi: string | null;
        correo_fiscal: string | null;
        tiene_cliente_facturapi: boolean;
    };
    catalogosFacturacion: { usos_cfdi: { clave: string; texto: string }[]; regimenes: { clave: string; texto: string }[] };
    puedeMatricular: boolean;
    situacionesDeBaja: { id: number; nombre: string }[];
    suplantable: { usuario_id: number; usuario: string } | null;
    historial: Renglon[];
    unidadPeriodo: string;
    resumen: Record<string, any>;
    carga: { ciclo: string; materias: any[] }[];
    materiasDelPlan: { id: number; etiqueta: string }[];
    estatusHistorial: { id: number; nombre: string; clave: string }[];
    minimoAprobatorio: number;
    calificacionMinima: number;
    calificacionMaxima: number;
    tiposEvaluacion: { id: number; nombre: string }[];
    observacionesAsignatura: { id: number; nombre: string; abreviatura: string | null }[];
    ciclos: { id: number; clave: string }[];
    puedeCargarHistorial: boolean;
    certificacion: {
        estado: string;
        folio: string | null;
        lote_id: number | null;
        lote_folio: string | null;
        fecha: string | null;
        xml_url: string | null;
    } | null;
    lotesAbiertos: { id: number; folio: string; nombre: string | null; tipo: string }[];
    puedeCertificar: boolean;
    /**
     * Qué documentos oficiales llega a emitir su carrera. Un diplomado o un
     * curso de educación continua vive en el mismo catálogo y no tiene RVOE
     * detrás: ofrecerle titulación es prometer un trámite que no existe.
     */
    emiteCertificado: boolean;
    emiteTitulo: boolean;
    situaciones: { id: number; nombre: string }[];
    generos: { id: number; nombre: string }[];
    entidades: { id: number; nombre: string }[];
    entidadExtranjero: { id: number; nombre: string } | null;
    paises: { id: number; nombre: string }[];
    mexicoId: number | null;
    puedeEditar: boolean;
    datosTitulo: {
        modalidad: {
            modalidad_titulacion_id: number | null;
            fecha_expedicion: string | null;
            fecha_examen_profesional: string | null;
            fecha_exencion_examen: string | null;
            fecha_terminacion_carrera: string | null;
        };
        servicio_social: { cumplio_servicio_social: boolean | null; fundamento_legal_ss_id: number | null };
        antecedente: {
            institucion_procedencia: string | null;
            nivel_antecedente_id: number | null;
            entidad_federativa_id: number | null;
            fecha_inicio: string | null;
            fecha_terminacion: string | null;
            no_cedula: string | null;
        };
    };
    catalogosTitulo: {
        modalidades: { id: number; identificador: number; descripcion: string; tipo_modalidad: string | null }[];
        fundamentos: { id: number; identificador: number; descripcion: string }[];
        nivelesAntecedente: { id: number; nombre: string; identificador_titulo: number }[];
        entidades: { id: number; nombre: string }[];
    };
}>();

const pestana = ref<'kardex' | 'carga' | 'carreras' | 'tutores' | 'facturacion' | 'datos' | 'titulacion'>('kardex');

// ── Datos del título por carrera (modalidad, servicio social, antecedente) ──
const formModalidad = useForm({ ...props.datosTitulo.modalidad });
const formServicioSocial = useForm({ ...props.datosTitulo.servicio_social });
const formAntecedente = useForm({ ...props.datosTitulo.antecedente });

function guardarModalidad(): void {
    formModalidad.put(`/escolar/alumnos/${props.alumno.id}/titulo/modalidad`, { preserveScroll: true });
}
function guardarServicioSocial(): void {
    formServicioSocial.put(`/escolar/alumnos/${props.alumno.id}/titulo/servicio-social`, { preserveScroll: true });
}
function guardarAntecedente(): void {
    formAntecedente.put(`/escolar/alumnos/${props.alumno.id}/titulo/antecedente`, { preserveScroll: true });
}

// La cédula es obligatoria si el antecedente es Licenciatura (idTipo 2) o
// Maestría (idTipo 1): el identificador_titulo del nivel elegido lo decide.
const cedulaRequerida = computed(() => {
    const nivel = props.catalogosTitulo.nivelesAntecedente.find((n) => n.id === formAntecedente.nivel_antecedente_id);
    return nivel !== undefined && [1, 2].includes(nivel.identificador_titulo);
});

// Completitud por bloque, para el resumen visual de la pestaña.
const modalidadCompleta = computed(() => !!formModalidad.modalidad_titulacion_id && !!formModalidad.fecha_expedicion && !!formModalidad.fecha_terminacion_carrera);
const servicioSocialCompleto = computed(() => formServicioSocial.cumplio_servicio_social !== null && !!formServicioSocial.fundamento_legal_ss_id);
const antecedenteCompleto = computed(() =>
    !!formAntecedente.institucion_procedencia && !!formAntecedente.nivel_antecedente_id && !!formAntecedente.entidad_federativa_id
    && !!formAntecedente.fecha_terminacion && (!cedulaRequerida.value || !!formAntecedente.no_cedula),
);
const bloquesCompletos = computed(() => [modalidadCompleta.value, servicioSocialCompleto.value, antecedenteCompleto.value].filter(Boolean).length);

// Estilo de la insignia de estado de un bloque (verde completo / gris pendiente).
function estiloInsignia(completo: boolean): { backgroundColor: string; color: string } {
    return completo
        ? { backgroundColor: 'color-mix(in srgb, #16a34a 15%, transparent)', color: '#15803d' }
        : { backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' };
}

// ── Certificación desde el expediente ─────────────────────────────────────
// Según su avance, al alumno le toca certificado total (cerró el plan) o
// parcial (tiene avance sin cerrarlo); se le ofrecen solo lotes de ese tipo.
const tipoCertificado = computed<'total' | 'parcial' | null>(() => {
    // Si su carrera no emite certificado, no le toca ninguno: el avance da
    // igual. El backend descarta igual, esto evita ofrecerlo aquí.
    if (! props.emiteCertificado) {
        return null;
    }

    return props.resumen.disponible_certificar ? 'total' : (props.resumen.disponible_parcial ? 'parcial' : null);
});
const lotesElegibles = computed(() => props.lotesAbiertos.filter((l) => l.tipo === tipoCertificado.value));
const loteElegido = ref<number | null>(null);
watch(lotesElegibles, (lista) => {
    if (loteElegido.value === null || !lista.some((l) => l.id === loteElegido.value)) {
        loteElegido.value = lista[0]?.id ?? null;
    }
}, { immediate: true });
const agregandoALote = ref(false);

function agregarALote(): void {
    if (loteElegido.value === null) return;
    agregandoALote.value = true;
    router.post(`/certificacion/lotes/${loteElegido.value}/alumnos`, {
        matricula_oferta_ids: [props.alumno.id],
    }, {
        preserveScroll: true,
        onFinish: () => { agregandoALote.value = false; },
    });
}

// Alternar la carrera en foco: navega al detalle de esa matrícula (misma
// persona, otra carrera). Todo lo académico se recarga para la elegida.
function cambiarCarrera(id: string | number): void {
    router.get(`/escolar/alumnos/${id}`);
}

// Edad y cuenta regresiva al próximo cumpleaños, a partir de la fecha de
// nacimiento. Un detalle humano para que el encabezado no se sienta vacío.
const cumple = computed(() => {
    const f = props.persona.fecha_nacimiento;
    if (!f) return null;

    const nac = new Date(`${f}T00:00:00`);
    if (isNaN(nac.getTime())) return null;

    const hoy = new Date();
    hoy.setHours(0, 0, 0, 0);

    let edad = hoy.getFullYear() - nac.getFullYear();
    let prox = new Date(hoy.getFullYear(), nac.getMonth(), nac.getDate());
    if (prox.getTime() < hoy.getTime()) {
        prox = new Date(hoy.getFullYear() + 1, nac.getMonth(), nac.getDate());
    }

    const dias = Math.round((prox.getTime() - hoy.getTime()) / 86400000);
    const esHoy = dias === 0;
    // Edad que cumplirá (o cumplió hoy): años completos al próximo cumpleaños.
    if (!esHoy && prox.getMonth() < nac.getMonth()) { /* no aplica */ }
    const edadActual = hoy.getMonth() > nac.getMonth() || (hoy.getMonth() === nac.getMonth() && hoy.getDate() >= nac.getDate())
        ? edad
        : edad - 1;

    return { dias, esHoy, edad: edadActual };
});

/* Carga manual al historial (equivalencias, revalidaciones, kárdex histórico) */
const mostrarCargaHistorial = ref(false);
const opciones = (lista: { id: number; nombre: string }[]) => lista.map((x) => ({ valor: x.id, texto: x.nombre }));

const formHistorial = useForm({
    plan_materia_id: null as number | null,
    ciclo_id: null as number | null,
    observacion_asignatura_id: null as number | null,
    estatus_id: null as number | null,
    calificacion: null as number | null,
});

// ¿Ya se capturó una calificación? El estatus solo aparece entonces: es la nota
// la que lo determina (una carga sin nota es una acreditación histórica).
const calificacionCapturada = computed(() => {
    const c = formHistorial.calificacion;

    return c !== null && (c as any) !== '' && !isNaN(Number(c));
});

// Regla única calificación → estatus (misma que EstatusAcademico en el backend):
//  >= mínimo → aprobada (fijo); >0 y <mínimo → reprobada (fijo); ==0 → reprobada
//  o no presentó (a elegir); sin calificación → libre.
const reglaEstatus = computed<{ claves: string[] | null; bloqueado: boolean }>(() => {
    const bruto = formHistorial.calificacion;

    if (bruto === null || bruto === ('' as any) || isNaN(Number(bruto))) {
        return { claves: null, bloqueado: false }; // sin nota: libre
    }

    const c = Number(bruto);

    if (c >= props.minimoAprobatorio) return { claves: ['aprobada'], bloqueado: true };
    if (c > 0) return { claves: ['reprobada'], bloqueado: true };

    return { claves: ['reprobada', 'no_presento'], bloqueado: false };
});

// Estatus ofrecidos: todos si la regla no acota, o solo los admitidos.
const opcionesEstatus = computed(() =>
    props.estatusHistorial
        .filter((e) => reglaEstatus.value.claves === null || reglaEstatus.value.claves.includes(e.clave))
        .map((e) => ({ valor: e.id, texto: e.nombre })),
);

// Al cambiar la calificación, se ajusta el estatus: si el actual ya no aplica se
// reemplaza por el primero admitido; si la regla deja uno solo, ese queda fijo.
watch(
    () => formHistorial.calificacion,
    () => {
        // Sin calificación no hay estatus a elegir (se asienta en el servidor).
        if (!calificacionCapturada.value) {
            formHistorial.estatus_id = null;

            return;
        }

        const permitidos = opcionesEstatus.value.map((o) => o.valor);

        if (formHistorial.estatus_id !== null && !permitidos.includes(formHistorial.estatus_id)) {
            formHistorial.estatus_id = null;
        }

        if (reglaEstatus.value.bloqueado && permitidos.length === 1) {
            formHistorial.estatus_id = permitidos[0];
        }
    },
);

function agregarHistorial(): void {
    formHistorial.post(`/escolar/alumnos/${props.alumno.id}/historial`, {
        preserveScroll: true,
        onSuccess: () => {
            formHistorial.reset();
            mostrarCargaHistorial.value = false;
        },
    });
}

function quitarHistorial(id: number): void {
    if (!confirm('¿Retirar este renglón cargado a mano del historial?')) {
        return;
    }
    router.delete(`/escolar/alumnos/${props.alumno.id}/historial/${id}`, { preserveScroll: true });
}

/* Datos de facturación del alumno */
const factForm = useForm({
    quiere_factura: props.facturacion.quiere_factura,
    es_tercero: props.facturacion.es_tercero,
    rfc: props.facturacion.rfc ?? '',
    razon_social: props.facturacion.razon_social ?? '',
    regimen_fiscal: props.facturacion.regimen_fiscal ?? '',
    cp: props.facturacion.cp ?? '',
    uso_cfdi: props.facturacion.uso_cfdi ?? '',
    correo_fiscal: props.facturacion.correo_fiscal ?? '',
});

function guardarFacturacion(): void {
    factForm.put(`/escolar/alumnos/${props.alumno.id}/facturacion`, { preserveScroll: true });
}

/* Padres y tutores del alumno */
const formTutor = useForm({
    tutor_persona_id: null as number | null,
    nombre: '',
    primer_apellido: '',
    segundo_apellido: '',
    curp: '',
    email: '',
    celular: '',
    parentesco: 'padre',
    puede_ver_academico: true,
    puede_ver_finanzas: true,
});

const agregandoTutor = ref(false);

// Buscador de padres/tutores YA registrados (p. ej. el padre de un hermano):
// se teclea nombre/CURP/correo y se elige, sin recapturar sus datos.
interface TutorCandidato { id: number; nombre: string; curp: string | null; email: string | null }
const tutorBusqueda = ref('');
const tutorResultados = ref<TutorCandidato[]>([]);
const tutorElegido = ref<TutorCandidato | null>(null);
let tempTutor: ReturnType<typeof setTimeout> | undefined;

watch(tutorBusqueda, (q) => {
    clearTimeout(tempTutor);
    if (tutorElegido.value) return;

    const term = q.trim();
    if (term.length < 2) {
        tutorResultados.value = [];
        return;
    }

    tempTutor = setTimeout(async () => {
        try {
            const r = await fetch(`/escolar/alumnos/${props.alumno.id}/tutores/buscar?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
            });
            tutorResultados.value = r.ok ? await r.json() : [];
        } catch {
            tutorResultados.value = [];
        }
    }, 300);
});

function elegirTutor(p: TutorCandidato): void {
    tutorElegido.value = p;
    formTutor.tutor_persona_id = p.id;
    tutorBusqueda.value = p.nombre;
    tutorResultados.value = [];
}

function limpiarTutorElegido(): void {
    tutorElegido.value = null;
    formTutor.tutor_persona_id = null;
    tutorBusqueda.value = '';
    tutorResultados.value = [];
}

function cerrarFormTutor(): void {
    formTutor.reset();
    limpiarTutorElegido();
    agregandoTutor.value = false;
}

function vincularTutor(): void {
    formTutor.post(`/escolar/alumnos/${props.alumno.id}/tutores`, {
        preserveScroll: true,
        onSuccess: cerrarFormTutor,
    });
}

function desvincularTutor(id: number, nombre: string): void {
    if (!confirm(`¿Quitar a "${nombre}" como padre/tutor de este alumno?`)) {
        return;
    }

    router.delete(`/escolar/alumnos/${props.alumno.id}/tutores/${id}`, { preserveScroll: true });
}

const etiquetaParentesco: Record<string, string> = {
    padre: 'Padre',
    madre: 'Madre',
    tutor: 'Tutor',
    otro: 'Otro',
};

/* Otras carreras de la misma persona */
const agregando = ref(false);
const formCarrera = useForm({ oferta_id: null as number | null, generacion: '' });

function agregarCarrera(): void {
    formCarrera.post(`/escolar/alumnos/${props.alumno.id}/carreras`, {
        onSuccess: () => {
            formCarrera.reset();
            agregando.value = false;
        },
    });
}

/*
 * Dar de baja pide CUÁL baja. `estatus` y `situacion_id` son dos ejes: el
 * primero dice que ya no está activa, el segundo si fue temporal o definitiva
 * — que es el dato que después responde "¿puede volver?".
 */
const bajando = ref<number | null>(null);
const situacionBaja = ref<number | null>(props.situacionesDeBaja[0]?.id ?? null);

function confirmarBaja(carreraId: number): void {
    router.put(
        `/escolar/alumnos/${props.alumno.id}/carreras/${carreraId}`,
        { accion: 'baja', situacion_id: situacionBaja.value },
        { preserveScroll: true, onFinish: () => (bajando.value = null) },
    );
}

function reactivar(carreraId: number, matricula: string): void {
    if (!confirm(`Reactivar la matricula ${matricula}?`)) {
        return;
    }

    router.put(
        `/escolar/alumnos/${props.alumno.id}/carreras/${carreraId}`,
        { accion: 'reactivar' },
        { preserveScroll: true },
    );
}

const colorEstatusCarrera: Record<string, string> = {
    activo: 'color-mix(in srgb, #16a34a 16%, transparent)',
    egresado: 'color-mix(in srgb, var(--color-acento) 14%, transparent)',
    baja: 'color-mix(in srgb, #dc2626 14%, transparent)',
};

const form = useForm({
    nombre: props.persona.nombre ?? '',
    primer_apellido: props.persona.primer_apellido ?? '',
    segundo_apellido: props.persona.segundo_apellido ?? '',
    curp: props.persona.curp ?? '',
    rfc: props.persona.rfc ?? '',
    fecha_nacimiento: props.persona.fecha_nacimiento ?? '',
    genero_id: props.persona.genero_id ?? null,
    entidad_nacimiento_id: props.persona.entidad_nacimiento_id ?? null,
    pais_nacimiento_id: props.persona.pais_nacimiento_id ?? null,
    email: props.persona.email ?? '',
    correo_institucional: props.persona.correo_institucional ?? '',
    celular: props.persona.celular ?? '',
    telefono_local: props.persona.telefono_local ?? '',
    situacion_id: props.alumno.situacion_id ?? null,
    estatus: props.alumno.estatus ?? 'activo',
    generacion: props.alumno.generacion ?? '',
    periodo_actual: props.alumno.periodo_actual ?? null,
});

function guardar(): void {
    form.put(`/escolar/alumnos/${props.alumno.id}`, { preserveScroll: true });
}

const avance = computed(() => {
    const total = Number(props.resumen.creditos_del_plan ?? 0);

    if (!total) {
        return null;
    }

    return Math.min(100, Math.round((Number(props.resumen.creditos ?? 0) / total) * 100));
});

function colorCalificacion(r: Renglon): string {
    if (r.estatus_clave === 'aprobada') return '#16a34a';
    if (r.estatus_clave === 'reprobada') return '#dc2626';
    return 'var(--color-suave)';
}

// Fondo tenue para el chip de calificación, del mismo color que el texto.
function fondoCalificacion(r: Renglon): string {
    return `color-mix(in srgb, ${colorCalificacion(r)} 12%, transparent)`;
}

// El kárdex agrupado por periodo (grado) del plan, con las estadísticas de cada
// bloque: cuántas materias, créditos aprobados y promedio del periodo. Es la
// forma en que se lee un kárdex —por semestre/cuatrimestre—, no como lista plana.
const historialPorPeriodo = computed(() => {
    const grupos = new Map<number, Renglon[]>();

    for (const r of props.historial) {
        const p = r.periodo ?? 0; // 0 = sin periodo asignado
        (grupos.get(p) ?? grupos.set(p, []).get(p)!).push(r);
    }

    return [...grupos.entries()]
        .sort((a, b) => a[0] - b[0])
        .map(([periodo, renglones]) => {
            // Una materia puede tener varios intentos (ordinario, a título…). Para
            // el promedio y los créditos se toma SOLO el mejor de cada materia;
            // se muestran todos los renglones, pero el resto se marca como que no
            // promedia.
            const mejorPorMateria = new Map<number | string, Renglon>();
            for (const r of renglones) {
                const clave = r.plan_materia_id ?? r.clave_en_plan ?? r.id;
                const previo = mejorPorMateria.get(clave);
                const nota = r.calificacion === null ? -1 : Number(r.calificacion);
                const notaPrevia = previo ? (previo.calificacion === null ? -1 : Number(previo.calificacion)) : -Infinity;
                if (!previo || nota > notaPrevia) mejorPorMateria.set(clave, r);
            }

            const mejores = [...mejorPorMateria.values()];
            const idsQueCuentan = new Set(mejores.map((r) => r.id));

            const conNota = mejores.filter((r) => r.calificacion !== null && !isNaN(Number(r.calificacion)));
            const promedio = conNota.length
                ? (conNota.reduce((s, r) => s + Number(r.calificacion), 0) / conNota.length).toFixed(1)
                : null;
            const creditos = mejores
                .filter((r) => r.estatus_clave === 'aprobada')
                .reduce((s, r) => s + Number(r.creditos ?? 0), 0);
            const reprobadas = mejores.filter((r) => r.estatus_clave === 'reprobada').length;

            return {
                periodo,
                titulo: periodo === 0 ? 'Sin periodo' : `${props.unidadPeriodo} ${periodo}`,
                renglones,
                materias: mejores.length,
                idsQueCuentan,
                promedio,
                creditos: Math.round(creditos * 100) / 100,
                reprobadas,
            };
        });
});

// Color del promedio del bloque (contra el mínimo aprobatorio del plan).
function colorPromedio(promedio: string | null): string {
    if (promedio === null) return 'var(--color-suave)';

    return Number(promedio) >= props.minimoAprobatorio ? '#16a34a' : '#dc2626';
}

/* Foto de perfil */
const formFoto = useForm({ foto: null as File | null });
const entradaFoto = ref<HTMLInputElement | null>(null);

function subirFoto(evento: Event): void {
    const archivos = (evento.target as HTMLInputElement).files;

    if (!archivos || archivos.length === 0) {
        return;
    }

    formFoto.foto = archivos[0];
    formFoto.post(`/personas/${props.persona.id}/foto`, {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => {
            formFoto.reset();
            if (entradaFoto.value) entradaFoto.value.value = '';
        },
    });
}

function quitarFoto(): void {
    if (!confirm('Quitar la foto?')) return;
    router.delete(`/personas/${props.persona.id}/foto`, { preserveScroll: true });
}

/*
 * "Ver como": entrar con la cuenta de esta persona para reproducir lo que ella
 * ve. Queda registrado en la bitacora, y la banda superior lo recuerda todo el
 * tiempo mientras dure.
 */
function verComo(): void {
    entrarComo(props.suplantable);
}

/** «Ver como» un padre/tutor de la lista (misma bitácora que el alumno). */
function verComoTutor(suplantable: { usuario_id: number; usuario: string } | null): void {
    entrarComo(suplantable);
}

function entrarComo(suplantable: { usuario_id: number; usuario: string } | null): void {
    if (!suplantable) {
        return;
    }

    if (!confirm(`Vas a entrar como ${suplantable.usuario}. Queda registrado quién lo hizo y cuándo. ¿Continuar?`)) {
        return;
    }

    router.post(`/suplantar/${suplantable.usuario_id}`);
}
</script>

<template>
    <Head :title="persona.nombre ? `${persona.nombre} ${persona.primer_apellido}` : 'Alumno'" />

    <AppLayout titulo="Expediente del alumno">
        <NavEscolar
            :secciones="[
                { etiqueta: 'Listado', url: '/escolar/alumnos', permiso: 'ver-alumnos' },
            ]"
        />

        <!-- Cabecera -->
        <section class="tarjeta p-6">
            <BotonVolver href="/escolar/alumnos" texto="Alumnos" class="mb-4" />

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex flex-col items-center gap-2">
                    <img
                        v-if="persona.foto"
                        :src="persona.foto"
                        alt=""
                        class="h-24 w-24 rounded-full object-cover"
                    />
                    <span
                        v-else
                        class="flex h-24 w-24 items-center justify-center rounded-full text-2xl font-semibold"
                        :style="{
                            backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)',
                            color: 'var(--color-acento)',
                        }"
                    >
                        {{ (persona.nombre?.[0] ?? '') + (persona.primer_apellido?.[0] ?? '') }}
                    </span>

                    <div v-if="puedeEditar" class="flex gap-2 text-xs">
                        <label class="cursor-pointer" :style="{ color: 'var(--color-acento)' }">
                            {{ persona.foto ? 'Cambiar' : 'Subir foto' }}
                            <input
                                ref="entradaFoto"
                                type="file"
                                accept="image/*"
                                class="hidden"
                                @change="subirFoto"
                            />
                        </label>
                        <BotonAccion v-if="persona.foto" variante="eliminar" texto="Quitar la foto" @click="quitarFoto" />
                    </div>
                    <p v-if="formFoto.errors.foto" class="text-xs text-red-600">{{ formFoto.errors.foto }}</p>
                </div>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-3">
                        <h2 class="text-lg font-semibold">
                            {{ [persona.nombre, persona.primer_apellido, persona.segundo_apellido].filter(Boolean).join(' ') }}
                        </h2>
                        <span
                            v-if="carreras.length > 1"
                            class="rounded-full px-2 py-0.5 text-xs"
                            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }"
                        >
                            {{ carreras.length }} carreras
                        </span>
                    </div>

                    <!-- Cumpleaños: un guiño humano. -->
                    <div
                        v-if="cumple"
                        class="mt-2 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs"
                        :style="{
                            backgroundColor: cumple.esHoy ? 'color-mix(in srgb, #ec4899 16%, transparent)' : 'var(--color-fondo)',
                            color: cumple.esHoy ? '#be185d' : 'var(--color-suave)',
                        }"
                    >
                        <span aria-hidden="true">🎂</span>
                        <span v-if="cumple.esHoy" class="font-medium">¡Hoy cumple {{ cumple.edad }} años!</span>
                        <span v-else>{{ cumple.edad }} años · faltan {{ cumple.dias }} día(s) para su cumpleaños</span>
                    </div>

                    <!-- Datos generales de la persona. -->
                    <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        <div v-if="persona.curp" class="min-w-0">
                            <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">CURP</dt>
                            <dd class="truncate font-mono text-xs">{{ persona.curp }}</dd>
                        </div>
                        <div v-if="persona.rfc" class="min-w-0">
                            <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">RFC</dt>
                            <dd class="truncate font-mono text-xs">{{ persona.rfc }}</dd>
                        </div>
                        <div v-if="persona.email" class="min-w-0">
                            <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Correo</dt>
                            <dd class="truncate">{{ persona.email }}</dd>
                        </div>
                        <div v-if="persona.correo_institucional" class="min-w-0">
                            <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Correo institucional</dt>
                            <dd class="truncate">{{ persona.correo_institucional }}</dd>
                        </div>
                        <div v-if="persona.celular" class="min-w-0">
                            <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Celular</dt>
                            <dd>{{ persona.celular }}</dd>
                        </div>
                        <div v-if="persona.fecha_nacimiento" class="min-w-0">
                            <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Nacimiento</dt>
                            <dd>
                                {{ persona.fecha_nacimiento }}
                                <span v-if="persona.entidad_nacimiento" :style="{ color: 'var(--color-suave)' }"> · {{ persona.entidad_nacimiento }}</span>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Carrera en foco: el select alterna entre las carreras de la
                 persona y todo lo académico de abajo refleja la elegida. -->
            <div class="mt-4 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-medium">Carrera</span>
                        <div v-if="carreras.length > 1" class="relative flex items-center">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke-width="2"
                                stroke="currentColor"
                                class="pointer-events-none absolute left-2.5 h-4 w-4"
                                :style="{ color: 'var(--color-acento)' }"
                                aria-hidden="true"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-9L21 7.5m0 0L16.5 3M21 7.5H7.5" />
                            </svg>
                            <select
                                :value="alumno.id"
                                class="rounded-lg border bg-transparent py-1.5 pl-8 pr-3 text-sm font-medium"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                title="Cambiar de carrera"
                                @change="cambiarCarrera(($event.target as HTMLSelectElement).value)"
                            >
                                <option v-for="c in carreras" :key="c.id" :value="c.id">
                                    {{ c.carrera }} · {{ c.campus }} ({{ c.estatus }})
                                </option>
                            </select>
                        </div>
                        <span v-else class="text-sm font-medium">{{ alumno.carrera }}</span>
                    </div>
                    <div class="flex flex-col items-end gap-1.5">
                        <span
                            class="rounded-full px-2.5 py-1 text-xs font-medium capitalize"
                            :style="{ backgroundColor: colorEstatusCarrera[alumno.estatus] ?? 'var(--color-fondo)' }"
                        >
                            {{ alumno.estatus }}
                        </span>
                        <button
                            v-if="puedeEditar && alumno.estatus !== 'baja'"
                            type="button"
                            class="boton-baja text-xs"
                            @click="bajando = bajando === alumno.id ? null : alumno.id"
                        >
                            Dar de baja
                        </button>
                        <button
                            v-else-if="puedeEditar"
                            type="button"
                            class="text-xs"
                            :style="{ color: 'var(--color-acento)' }"
                            @click="reactivar(alumno.id, alumno.matricula)"
                        >
                            Reactivar
                        </button>
                    </div>
                </div>

                <!-- Baja de la carrera en foco: mismo flujo que la pestaña Carreras. -->
                <div
                    v-if="bajando === alumno.id"
                    class="mt-4 flex flex-wrap items-end gap-3 rounded-lg border-l-2 py-3 pl-3"
                    style="border-color: #dc2626"
                >
                    <div class="min-w-56">
                        <CampoSelect
                            v-model="situacionBaja"
                            etiqueta="Tipo de baja"
                            :opciones="situacionesDeBaja.map((s) => ({ valor: s.id, texto: s.nombre }))"
                        />
                    </div>
                    <!-- Confirmar la baja lleva ETIQUETA, no un bote de basura:
                         es el botón principal de este mini formulario y hay que
                         leer qué se confirma. Además no borra nada —cambia el
                         estatus y conserva el kárdex—, así que el icono de
                         eliminar diría algo falso. -->
                    <button
                        type="button"
                        class="rounded-lg px-3 py-2 text-sm font-medium text-white"
                        style="background-color: #dc2626"
                        @click="confirmarBaja(alumno.id)"
                    >
                        Confirmar baja
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border px-4 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="bajando = null"
                    >
                        Cancelar
                    </button>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Matrícula</dt>
                        <dd class="font-mono text-sm">{{ alumno.matricula }}</dd>
                    </div>
                    <div v-if="alumno.plan">
                        <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Plan</dt>
                        <dd class="text-sm">{{ alumno.plan }}</dd>
                    </div>
                    <div v-if="alumno.campus">
                        <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Campus</dt>
                        <dd class="text-sm">{{ alumno.campus }}</dd>
                    </div>
                    <div v-if="alumno.situacion">
                        <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Situación</dt>
                        <dd class="text-sm">{{ alumno.situacion }}</dd>
                    </div>
                    <div v-if="alumno.generacion">
                        <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Generación</dt>
                        <dd class="text-sm">{{ alumno.generacion }}</dd>
                    </div>
                    <div v-if="alumno.fecha_ingreso">
                        <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Ingreso</dt>
                        <dd class="text-sm">{{ alumno.fecha_ingreso }}</dd>
                    </div>
                </dl>
            </div>

            <!-- «Ver como alumno»: entrar con su cuenta para ver lo que ve.
                 Abajo a la derecha del recuadro; queda en bitácora. -->
            <div v-if="suplantable" class="mt-4 flex justify-end border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                <BotonAccion variante="ver" texto="Ver como alumno" @click="verComo" />
            </div>
        </section>

        <!-- Resumen académico -->
        <section class="tarjeta p-6">
            <div class="grid gap-4 text-sm sm:grid-cols-5">
                <div>
                    <p :style="{ color: 'var(--color-suave)' }">Materias cursadas</p>
                    <p class="mt-0.5 text-xl font-semibold">{{ resumen.materias_cursadas }}</p>
                </div>
                <div>
                    <p :style="{ color: 'var(--color-suave)' }">Aprobadas</p>
                    <p class="mt-0.5 text-xl font-semibold text-green-600">{{ resumen.aprobadas }}</p>
                </div>
                <div>
                    <p :style="{ color: 'var(--color-suave)' }">Reprobadas</p>
                    <p class="mt-0.5 text-xl font-semibold" :class="resumen.reprobadas ? 'text-red-600' : ''">
                        {{ resumen.reprobadas }}
                    </p>
                </div>
                <div>
                    <p :style="{ color: 'var(--color-suave)' }">Promedio</p>
                    <p class="mt-0.5 text-xl font-semibold">{{ resumen.promedio ?? '—' }}</p>
                </div>
                <div>
                    <p :style="{ color: 'var(--color-suave)' }">Créditos</p>
                    <p class="mt-0.5 text-xl font-semibold">
                        {{ resumen.creditos }}<span v-if="resumen.creditos_del_plan" class="text-sm font-normal" :style="{ color: 'var(--color-suave)' }">
                            / {{ resumen.creditos_del_plan }}</span>
                    </p>
                </div>
            </div>

            <div v-if="avance !== null" class="mt-4">
                <div class="h-2 overflow-hidden rounded-full" style="background-color: color-mix(in srgb, currentColor 10%, transparent)">
                    <div class="h-full rounded-full" :style="{ width: `${avance}%`, backgroundColor: 'var(--color-acento)' }"></div>
                </div>
                <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">{{ avance }}% de créditos del plan</p>
            </div>

            <!-- Disponible para certificar: aprobó todas las materias que el
                 plan exige (materias distintas aprobadas ≥ materias para completar). -->
            <div
                class="mt-4 flex items-center gap-3 rounded-lg border px-4 py-2.5"
                :style="{
                    borderColor: resumen.disponible_certificar ? '#16a34a' : 'var(--color-borde)',
                    backgroundColor: resumen.disponible_certificar ? 'color-mix(in srgb, #16a34a 8%, transparent)' : 'transparent',
                }"
            >
                <span
                    class="grid h-7 w-7 shrink-0 place-items-center rounded-full text-sm font-bold text-white"
                    :style="{ backgroundColor: resumen.disponible_certificar ? '#16a34a' : 'var(--color-suave)' }"
                >
                    {{ resumen.disponible_certificar ? '✓' : '…' }}
                </span>
                <div>
                    <p
                        class="text-sm font-medium"
                        :style="{ color: resumen.disponible_certificar ? '#16a34a' : 'var(--color-contenido)' }"
                    >
                        {{ resumen.disponible_certificar ? 'Disponible para certificar' : 'Aún no disponible para certificar' }}
                    </p>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ resumen.aprobadas }} / {{ resumen.materias_para_completar }} materias del plan aprobadas
                    </p>
                </div>
            </div>

            <!-- Estado de certificación de esta matrícula -->
            <!-- Ya tiene certificado emitido: descargar su XML sellado. -->
            <div
                v-if="certificacion && certificacion.estado === 'certificado'"
                class="mt-3 flex flex-wrap items-center gap-3 rounded-lg border px-4 py-2.5"
                :style="{ borderColor: '#16a34a', backgroundColor: 'color-mix(in srgb, #16a34a 8%, transparent)' }"
            >
                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full text-sm font-bold text-white" :style="{ backgroundColor: '#16a34a' }">✓</span>
                <div>
                    <p class="text-sm font-medium" :style="{ color: '#16a34a' }">Certificado emitido</p>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Folio {{ certificacion.folio }} · Lote {{ certificacion.lote_folio }}<span v-if="certificacion.fecha"> · {{ certificacion.fecha }}</span>
                    </p>
                </div>
                <a v-if="certificacion.xml_url" :href="certificacion.xml_url" class="ml-auto text-sm font-medium" :style="{ color: 'var(--color-acento)' }">
                    Descargar XML
                </a>
            </div>

            <!-- Está en un lote pero aún sin firmar. -->
            <div
                v-else-if="certificacion"
                class="mt-3 flex flex-wrap items-center gap-3 rounded-lg border px-4 py-2.5"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full text-sm font-bold text-white" :style="{ backgroundColor: 'var(--color-suave)' }">…</span>
                <div>
                    <p class="text-sm font-medium">En espera de firma</p>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">Agregado al lote {{ certificacion.lote_folio }}</p>
                </div>
                <a v-if="puedeCertificar && certificacion.lote_id" :href="`/certificacion/lotes/${certificacion.lote_id}`" class="ml-auto text-sm font-medium" :style="{ color: 'var(--color-acento)' }">
                    Ver lote
                </a>
            </div>

            <!-- No está en ningún lote: se puede agregar a uno del tipo que le
                 toca (total si cerró el plan; parcial si tiene avance sin cerrar). -->
            <div
                v-else-if="tipoCertificado && puedeCertificar"
                class="mt-3 flex items-start gap-3 rounded-xl border px-4 py-3.5"
                :style="{
                    borderColor: 'color-mix(in srgb, var(--color-acento) 30%, var(--color-borde))',
                    backgroundColor: 'color-mix(in srgb, var(--color-acento) 6%, transparent)',
                }"
            >
                <span
                    class="grid h-9 w-9 shrink-0 place-items-center rounded-full"
                    :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 15%, transparent)', color: 'var(--color-acento)' }"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" />
                    </svg>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold">
                        {{ tipoCertificado === 'total' ? 'Listo para certificado total' : 'Disponible para certificado parcial' }}
                    </p>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        <template v-if="tipoCertificado === 'total'">Cerró su plan. Agrégalo a un lote total para emitir su certificado.</template>
                        <template v-else>Aún no cierra su plan; se le puede emitir un certificado parcial de lo cursado.</template>
                    </p>
                    <div v-if="lotesElegibles.length" class="mt-2.5 flex flex-wrap items-center gap-2">
                        <select
                            v-model="loteElegido"
                            class="rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)' }"
                        >
                            <option v-for="l in lotesElegibles" :key="l.id" :value="l.id">
                                {{ l.folio }}<span v-if="l.nombre"> — {{ l.nombre }}</span>
                            </option>
                        </select>
                        <BotonPrincipal tipo="button" icono="ninguno" texto="Agregar" :procesando="agregandoALote" @click="agregarALote" />
                    </div>
                    <p v-else class="mt-1.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                        No hay lotes {{ tipoCertificado === 'total' ? 'totales' : 'parciales' }} abiertos.
                        <a href="/certificacion/lotes" :style="{ color: 'var(--color-acento)' }">Crea uno</a> para certificar a este alumno.
                    </p>
                </div>
            </div>
        </section>

        <!-- Pestañas -->
        <PestanasPagina
            :pestanas="[
                { clave: 'kardex', etiqueta: 'Kárdex' },
                { clave: 'carga', etiqueta: 'Carga por ciclo' },
                { clave: 'carreras', etiqueta: `Carreras (${carreras.length})` },
                { clave: 'tutores', etiqueta: `Padres/tutores (${tutores.length})` },
                { clave: 'facturacion', etiqueta: 'Facturación' },
                // La titulación sólo aparece si su carrera llega a emitir
                // título: en un diplomado, la pestaña ofrecía un trámite
                // inexistente y quien la llenaba esperaba un documento.
                ...(emiteTitulo ? [{ clave: 'titulacion', etiqueta: 'Titulación' }] : []),
                { clave: 'datos', etiqueta: 'Datos' },
            ]"
            :model-value="pestana"
            @update:model-value="pestana = $event as any"
        />

        <!-- Kárdex -->
        <section v-if="pestana === 'kardex'" class="space-y-4">
            <!-- Carga manual al historial (equivalencias, revalidaciones, histórico). -->
            <div v-if="puedeCargarHistorial" class="tarjeta p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-base font-semibold">Agregar materia al historial</h3>
                        <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                            Para equivalencias, revalidaciones o kárdex histórico de otra institución. Se carga directo, sin acta.
                        </p>
                    </div>
                    <BotonAccion v-if="!mostrarCargaHistorial" variante="nuevo" texto="Agregar" @click="mostrarCargaHistorial = true" />
                </div>

                <form v-if="mostrarCargaHistorial" class="mt-5 grid gap-4 sm:grid-cols-3" @submit.prevent="agregarHistorial">
                    <div class="sm:col-span-3">
                        <CampoSelect
                            v-model="formHistorial.plan_materia_id"
                            etiqueta="Materia del plan"
                            requerido
                            :opciones="materiasDelPlan.map((m) => ({ valor: m.id, texto: m.etiqueta }))"
                            vacio="Selecciona…"
                            :error="formHistorial.errors.plan_materia_id"
                        />
                    </div>
                    <CampoSelect
                        v-model="formHistorial.observacion_asignatura_id"
                        etiqueta="Tipo de evaluación"
                        requerido
                        :opciones="opciones(observacionesAsignatura)"
                        vacio="Selecciona…"
                        :error="formHistorial.errors.observacion_asignatura_id"
                        ayuda="Catálogo oficial SEP: ordinario, extraordinario, equivalencia, revalidación…"
                    />
                    <CampoTexto
                        v-model="formHistorial.calificacion"
                        etiqueta="Calificación"
                        tipo="number"
                        requerido
                        :min="calificacionMinima"
                        :max="calificacionMaxima"
                        step="0.1"
                        :error="formHistorial.errors.calificacion"
                        :ayuda="`Escala ${calificacionMinima}–${calificacionMaxima}; aprueba desde ${minimoAprobatorio}.`"
                    />
                    <CampoSelect
                        v-if="calificacionCapturada"
                        v-model="formHistorial.estatus_id"
                        etiqueta="Estatus"
                        requerido
                        :opciones="opcionesEstatus"
                        vacio="Selecciona…"
                        :deshabilitado="reglaEstatus.bloqueado"
                        :error="formHistorial.errors.estatus_id"
                        :ayuda="
                            reglaEstatus.bloqueado
                                ? 'Lo determina la calificación (no se puede cambiar).'
                                : 'Con calificación 0: elige si reprobó o no se presentó.'
                        "
                    />
                    <CampoSelect
                        v-model="formHistorial.ciclo_id"
                        etiqueta="Ciclo"
                        requerido
                        :opciones="ciclos.map((c) => ({ valor: c.id, texto: c.clave }))"
                        vacio="Selecciona…"
                        :error="formHistorial.errors.ciclo_id"
                    />
                    <div class="flex items-end gap-2 sm:col-span-3">
                        <BotonPrincipal :procesando="formHistorial.processing" texto="Agregar al historial" icono="crear" />
                        <button
                            type="button"
                            class="rounded-lg border px-4 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="mostrarCargaHistorial = false; formHistorial.reset();"
                        >
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>

            <div v-if="historialPorPeriodo.length" class="space-y-4">
                <article v-for="g in historialPorPeriodo" :key="g.periodo" class="tarjeta overflow-hidden">
                    <header
                        class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <div class="flex items-center gap-3">
                            <span
                                class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-sm font-semibold"
                                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)', color: 'var(--color-acento)' }"
                            >
                                {{ g.periodo || '—' }}
                            </span>
                            <div>
                                <h3 class="text-sm font-semibold">{{ g.titulo }}</h3>
                                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ g.materias }} materia(s) · {{ g.creditos }} créditos
                                    <span v-if="g.reprobadas" class="text-red-600"> · {{ g.reprobadas }} reprobada(s)</span>
                                </p>
                            </div>
                        </div>
                        <div v-if="g.promedio" class="text-right">
                            <p class="text-[10px] uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Promedio</p>
                            <p class="text-lg font-semibold" :style="{ color: colorPromedio(g.promedio) }">{{ g.promedio }}</p>
                        </div>
                    </header>

                    <ul class="divide-y divide-borde" :style="{ borderColor: 'var(--color-borde)' }">
                        <li
                            v-for="renglon in g.renglones"
                            :key="renglon.id"
                            class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3"
                        >
                            <span
                                class="grid h-10 w-10 shrink-0 place-items-center rounded-lg text-sm font-semibold"
                                :style="{ backgroundColor: fondoCalificacion(renglon), color: colorCalificacion(renglon) }"
                            >
                                {{ renglon.calificacion ?? '—' }}
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">
                                    <span class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ renglon.clave_en_plan }}</span>
                                    · {{ renglon.materia }}
                                </p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                                    <span>{{ renglon.estatus }}</span>
                                    <span v-if="renglon.ciclo">· Ciclo {{ renglon.ciclo }}</span>
                                    <span v-if="renglon.tipo_evaluacion">· {{ renglon.tipo_evaluacion }}</span>
                                    <span
                                        v-if="renglon.observacion_asignatura && renglon.observacion_asignatura !== 'NORMAL / ORDINARIO'"
                                        class="rounded px-1.5 py-0.5 text-[10px]"
                                        :style="{ backgroundColor: 'var(--color-fondo)' }"
                                    >
                                        {{ renglon.observacion_asignatura }}
                                    </span>
                                    <span
                                        v-if="!g.idsQueCuentan.has(renglon.id)"
                                        class="rounded px-1.5 py-0.5 text-[10px] font-medium"
                                        style="background-color: color-mix(in srgb, #dc2626 12%, transparent); color: #dc2626"
                                        title="Hay un intento con mejor calificación; este no cuenta para el promedio"
                                    >
                                        no promedia
                                    </span>
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <span v-if="renglon.acta_folio" class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ renglon.acta_folio }}
                                </span>
                                <span
                                    v-else
                                    class="rounded px-1.5 py-0.5 text-[10px]"
                                    :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }"
                                >
                                    Manual
                                </span>
                                <BotonAccion
                                    v-if="renglon.manual && puedeCargarHistorial"
                                    variante="eliminar"
                                    texto="Retirar del historial"
                                    @click="quitarHistorial(renglon.id)"
                                />
                            </div>
                        </li>
                    </ul>
                </article>
            </div>

            <p v-else class="tarjeta px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Sin materias asentadas en el kárdex todavía.
            </p>
        </section>

        <!-- Carga por ciclo -->
        <section v-else-if="pestana === 'carga'" class="space-y-4">
            <article v-for="bloque in carga" :key="bloque.ciclo" class="tarjeta overflow-hidden">
                <div class="flex items-center justify-between gap-3 border-b px-6 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                    <h3 class="text-sm font-semibold">
                        Ciclo {{ bloque.ciclo }}
                        <span v-if="bloque.ciclo_nombre" class="font-normal" :style="{ color: 'var(--color-suave)' }"> · {{ bloque.ciclo_nombre }}</span>
                    </h3>
                    <span class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ bloque.materias.length }} materia(s)</span>
                </div>
                <ul>
                    <li
                        v-for="materia in bloque.materias"
                        :key="materia.id"
                        class="flex flex-wrap items-start justify-between gap-3 border-t px-6 py-3 text-sm"
                        :class="materia.de_baja ? 'opacity-50' : ''"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <span class="min-w-0">
                            <span class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ materia.clave_en_plan }}</span>
                            · {{ materia.materia }}
                            <span class="mt-0.5 block text-xs" :style="{ color: 'var(--color-suave)' }">
                                Grupo {{ materia.grupo ?? '—' }}
                                <span v-if="materia.campus"> · {{ materia.campus }}</span>
                                <span v-if="materia.docente"> · {{ materia.docente }}</span>
                            </span>
                        </span>
                        <span class="flex shrink-0 items-center gap-3">
                            <span
                                v-if="materia.tipo === 'recursamiento'"
                                class="rounded-full px-2 py-0.5 text-xs"
                                style="background-color: color-mix(in srgb, #f59e0b 18%, transparent)"
                            >recursa</span>
                            <span class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ materia.situacion }}</span>
                            <span class="font-medium">{{ materia.calificacion_final ?? '—' }}</span>
                        </span>
                    </li>
                </ul>
            </article>

            <p v-if="!carga.length" class="tarjeta px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No tiene materias inscritas.
            </p>
        </section>

        <!-- Carreras -->
        <section v-else-if="pestana === 'carreras'" class="space-y-4">
            <article
                v-for="carrera in carreras"
                :key="carrera.id"
                class="tarjeta p-5"
                :class="carrera.estatus === 'baja' ? 'opacity-70' : ''"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ carrera.matricula }}
                            <span v-if="carrera.es_actual" class="ml-1 rounded-full px-2 py-0.5" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)', color: 'var(--color-acento)' }">
                                viendo esta
                            </span>
                        </p>
                        <p class="mt-0.5 font-medium">{{ carrera.carrera }}</p>
                        <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                            {{ carrera.plan }}
                            <span v-if="carrera.campus"> · {{ carrera.campus }}</span>
                            <span v-if="carrera.generacion"> · generación {{ carrera.generacion }}</span>
                        </p>
                        <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                            Ingresó {{ carrera.fecha_ingreso }} · {{ carrera.materias_en_kardex }} materias en kárdex
                        </p>
                    </div>

                    <div class="flex flex-col items-end gap-2">
                        <span class="rounded-full px-2 py-0.5 text-xs capitalize" :style="{ backgroundColor: colorEstatusCarrera[carrera.estatus] }">
                            {{ carrera.estatus }}
                        </span>
                        <span class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ carrera.situacion }}</span>

                        <div class="flex gap-3 text-sm">
                            <a v-if="!carrera.es_actual" :href="`/escolar/alumnos/${carrera.id}`" :style="{ color: 'var(--color-acento)' }">
                                Abrir
                            </a>
                            <button
                                v-if="puedeEditar && carrera.estatus !== 'baja'"
                                type="button"
                                class="boton-baja"
                                @click="bajando = bajando === carrera.id ? null : carrera.id"
                            >
                                Dar de baja
                            </button>
                            <button
                                v-else-if="puedeEditar"
                                type="button"
                                :style="{ color: 'var(--color-acento)' }"
                                @click="reactivar(carrera.id, carrera.matricula)"
                            >
                                Reactivar
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Qué tipo de baja: el catálogo de la escuela manda -->
                <div
                    v-if="bajando === carrera.id"
                    class="mt-4 flex flex-wrap items-end gap-3 rounded-lg border-l-2 py-3 pl-3"
                    style="border-color: #dc2626"
                >
                    <div class="min-w-56">
                        <CampoSelect
                            v-model="situacionBaja"
                            etiqueta="Tipo de baja"
                            :opciones="situacionesDeBaja.map((s) => ({ valor: s.id, texto: s.nombre }))"
                        />
                    </div>
                    <button
                        type="button"
                        class="rounded-lg px-3 py-2 text-sm font-medium text-white"
                        style="background-color: #dc2626"
                        @click="confirmarBaja(carrera.id)"
                    >
                        Confirmar baja
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="bajando = null"
                    >
                        Cancelar
                    </button>
                    <p class="w-full text-xs" :style="{ color: 'var(--color-suave)' }">
                        Su kárdex se conserva; la matrícula solo deja de estar activa.
                    </p>
                </div>
            </article>

            <!-- Agregar otra carrera -->
            <section v-if="puedeMatricular" class="tarjeta p-6">
                <h3 class="text-base font-semibold">Agregar otra carrera</h3>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Para quien ya es alumno de la casa —la egresada que empieza la maestría, quien suma
                    una segunda licenciatura—. Genera una matrícula nueva con la regla de la escuela;
                    no hay que darlo de alta como aspirante otra vez.
                </p>

                <div v-if="ofertasDisponibles.length" class="mt-4">
                    <div v-if="!agregando">
                        <button
                            type="button"
                            class="rounded-lg px-4 py-2 text-sm font-medium"
                            :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                            @click="agregando = true"
                        >
                            Matricular en otra oferta
                        </button>
                    </div>

                    <div v-else class="grid gap-3 sm:grid-cols-3">
                        <div class="sm:col-span-2">
                            <CampoSelect
                                v-model="formCarrera.oferta_id"
                                etiqueta="Oferta"
                                :opciones="ofertasDisponibles.map((o) => ({ valor: o.id, texto: o.etiqueta }))"
                                vacio="Elige la carrera, plan y campus…"
                                :error="formCarrera.errors.oferta_id"
                            />
                        </div>
                        <CampoTexto v-model="formCarrera.generacion" etiqueta="Generación" :error="formCarrera.errors.generacion" />

                        <div class="flex gap-2 sm:col-span-3">
                            <button
                                type="button"
                                :disabled="!formCarrera.oferta_id || formCarrera.processing"
                                class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                                @click="agregarCarrera"
                            >
                                {{ formCarrera.processing ? 'Generando matrícula…' : 'Matricular' }}
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border px-4 py-2 text-sm"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                @click="agregando = false"
                            >
                                Cancelar
                            </button>
                        </div>
                    </div>
                </div>

                <p v-else class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Ya está matriculada en todas las ofertas abiertas de la escuela.
                </p>
            </section>

            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                Una matrícula no se elimina: su kárdex es historia escolar y las actas donde aparece
                quedarían sin dueño. Se da de baja.
            </p>
        </section>

        <!-- Padres / tutores -->
        <section v-else-if="pestana === 'tutores'" class="space-y-4">
            <div class="tarjeta p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="max-w-2xl">
                        <h2 class="text-base font-semibold">Padres y tutores</h2>
                        <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                            Al vincular a un padre o tutor, esa persona pasa a ser usuario del sistema
                            (con rol de padre de familia) y podrá ver la información de este alumno una
                            vez que se le habilite el acceso.
                        </p>
                    </div>
                    <BotonAccion
                        v-if="puedeEditar && !agregandoTutor"
                        variante="nuevo"
                        texto="Agregar"
                        @click="agregandoTutor = true"
                    />
                </div>

                <!-- Alta -->
                <form v-if="agregandoTutor" class="mt-5 border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="vincularTutor">
                    <!-- Buscar un padre/tutor ya registrado (p. ej. de un hermano). -->
                    <div class="relative mb-5 max-w-lg">
                        <label class="mb-1 block text-sm font-medium">Buscar padre/tutor existente</label>
                        <div class="flex items-center gap-2">
                            <input
                                v-model="tutorBusqueda"
                                type="text"
                                :disabled="!!tutorElegido"
                                placeholder="Nombre, CURP o correo…"
                                class="w-full rounded-lg border px-3 py-2 text-sm disabled:opacity-60"
                                :style="{ borderColor: 'var(--color-borde)' }"
                            />
                            <button
                                v-if="tutorElegido"
                                type="button"
                                class="shrink-0 text-sm"
                                :style="{ color: 'var(--color-acento)' }"
                                @click="limpiarTutorElegido"
                            >
                                Quitar
                            </button>
                        </div>
                        <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                            Si ya está registrado, selecciónalo y no captures sus datos. Si no, llénalos abajo.
                        </p>

                        <ul
                            v-if="tutorResultados.length && !tutorElegido"
                            class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border shadow-lg"
                            :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)' }"
                        >
                            <li
                                v-for="p in tutorResultados"
                                :key="p.id"
                                class="cursor-pointer px-3 py-2 text-sm hover:bg-fondo"
                                @click="elegirTutor(p)"
                            >
                                <span class="font-medium">{{ p.nombre }}</span>
                                <span v-if="p.curp" class="ml-2 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ p.curp }}</span>
                                <span v-if="p.email" class="ml-2 text-xs" :style="{ color: 'var(--color-suave)' }">{{ p.email }}</span>
                            </li>
                        </ul>
                    </div>

                    <div
                        v-if="tutorElegido"
                        class="mb-5 rounded-lg border p-3 text-sm"
                        :style="{ borderColor: 'var(--color-acento)', backgroundColor: 'color-mix(in srgb, var(--color-acento) 8%, transparent)' }"
                    >
                        Se vinculará a <strong>{{ tutorElegido.nombre }}</strong> (ya registrado).
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <template v-if="!tutorElegido">
                            <CampoTexto v-model="formTutor.nombre" etiqueta="Nombre(s)" requerido :error="formTutor.errors.nombre" />
                            <CampoTexto v-model="formTutor.primer_apellido" etiqueta="Primer apellido" requerido :error="formTutor.errors.primer_apellido" />
                            <CampoTexto v-model="formTutor.segundo_apellido" etiqueta="Segundo apellido" :error="formTutor.errors.segundo_apellido" />
                            <CampoTexto v-model="formTutor.curp" etiqueta="CURP" mono :error="formTutor.errors.curp" ayuda="Si ya existe, se reutiliza esa persona." />
                            <CampoTexto v-model="formTutor.email" etiqueta="Correo" tipo="email" :error="formTutor.errors.email" ayuda="Con él entrará a la plataforma." />
                            <CampoTexto v-model="formTutor.celular" etiqueta="Celular" :error="formTutor.errors.celular" />
                        </template>
                        <CampoSelect
                            v-model="formTutor.parentesco"
                            etiqueta="Parentesco"
                            :opciones="[
                                { valor: 'padre', texto: 'Padre' },
                                { valor: 'madre', texto: 'Madre' },
                                { valor: 'tutor', texto: 'Tutor' },
                                { valor: 'otro', texto: 'Otro' },
                            ]"
                            :error="formTutor.errors.parentesco"
                        />
                    </div>

                    <div class="mt-4 flex flex-wrap gap-5 text-sm">
                        <label class="flex items-center gap-2">
                            <input v-model="formTutor.puede_ver_academico" type="checkbox" class="rounded" />
                            Puede ver lo académico (historial y avance)
                        </label>
                        <label class="flex items-center gap-2">
                            <input v-model="formTutor.puede_ver_finanzas" type="checkbox" class="rounded" />
                            Puede ver lo financiero (pagos y facturas)
                        </label>
                    </div>

                    <div class="mt-5 flex gap-2">
                        <BotonAccion variante="nuevo" texto="Vincular" :disabled="formTutor.processing" @click="vincularTutor" />
                        <button
                            type="button"
                            class="rounded-lg border px-4 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="cerrarFormTutor"
                        >
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>

            <!-- Listado -->
            <div v-if="tutores.length" class="tarjeta overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                            <tr>
                                <th class="px-4 py-3 font-medium">Nombre</th>
                                <th class="px-4 py-3 font-medium">Parentesco</th>
                                <th class="px-4 py-3 font-medium">CURP</th>
                                <th class="px-4 py-3 font-medium">Correo</th>
                                <th class="px-4 py-3 font-medium">Puede ver</th>
                                <th class="px-4 py-3 font-medium text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="t in tutores" :key="t.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-4 py-3 font-medium">{{ t.nombre }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-0.5 text-xs" :style="{ backgroundColor: 'var(--color-borde)' }">
                                        {{ etiquetaParentesco[t.parentesco] ?? t.parentesco }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ t.curp ?? '—' }}</td>
                                <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ t.email ?? 'sin correo' }}</td>
                                <td class="px-4 py-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                                    <span v-if="t.puede_ver_academico">Académico</span>
                                    <span v-if="t.puede_ver_academico && t.puede_ver_finanzas"> · </span>
                                    <span v-if="t.puede_ver_finanzas">Finanzas</span>
                                    <span v-if="!t.puede_ver_academico && !t.puede_ver_finanzas">—</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            v-if="t.suplantable"
                                            type="button"
                                            class="rounded-lg border px-2.5 py-1 text-xs"
                                            :style="{ borderColor: 'var(--color-borde)' }"
                                            title="Entrar como este padre/tutor para ver lo que ve. Queda en bitácora."
                                            @click="verComoTutor(t.suplantable)"
                                        >
                                            Ver como
                                        </button>
                                        <BotonAccion v-if="puedeEditar" variante="eliminar" solo-icono @click="desvincularTutor(t.id, t.nombre)" />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <p v-else class="tarjeta px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Este alumno no tiene padres o tutores vinculados.
            </p>
        </section>

        <!-- Facturación -->
        <section v-else-if="pestana === 'facturacion'" class="tarjeta p-6">
            <h2 class="text-base font-semibold">Datos de facturación</h2>
            <p class="mt-1 max-w-2xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Indica si el alumno quiere factura y a nombre de quién se emite. El receptor puede ser
                el propio alumno o un tercero (un padre, una empresa).
            </p>

            <label class="mt-4 flex items-center gap-2 text-sm">
                <input v-model="factForm.quiere_factura" type="checkbox" class="rounded" />
                <span class="font-medium">El alumno quiere factura</span>
            </label>

            <div v-if="factForm.quiere_factura" class="mt-4 space-y-4">
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="factForm.es_tercero" type="checkbox" class="rounded" />
                    La factura va a nombre de un tercero (no del alumno)
                </label>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <CampoTexto v-model="factForm.rfc" etiqueta="RFC del receptor" mono :error="factForm.errors.rfc" />
                    <CampoTexto v-model="factForm.razon_social" etiqueta="Nombre / razón social" :error="factForm.errors.razon_social" ayuda="Tal cual en la Constancia de Situación Fiscal." />
                    <CampoTexto v-model="factForm.cp" etiqueta="CP fiscal" :error="factForm.errors.cp" />
                    <CampoSelect
                        v-model="factForm.regimen_fiscal"
                        etiqueta="Régimen fiscal"
                        :opciones="catalogosFacturacion.regimenes.map((r) => ({ valor: r.clave, texto: r.texto }))"
                        :error="factForm.errors.regimen_fiscal"
                    />
                    <CampoSelect
                        v-model="factForm.uso_cfdi"
                        etiqueta="Uso de CFDI"
                        :opciones="catalogosFacturacion.usos_cfdi.map((u) => ({ valor: u.clave, texto: u.texto }))"
                        :error="factForm.errors.uso_cfdi"
                    />
                    <CampoTexto v-model="factForm.correo_fiscal" etiqueta="Correo para la factura" tipo="email" :error="factForm.errors.correo_fiscal" />
                </div>
            </div>
            <p v-else class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">
                El alumno no requiere factura. Sus pagos se registran sin CFDI.
            </p>

            <div class="mt-5">
                <button
                    type="button"
                    :disabled="factForm.processing"
                    class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="guardarFacturacion"
                >
                    {{ factForm.processing ? 'Guardando…' : 'Guardar datos de facturación' }}
                </button>
            </div>
        </section>

        <!-- Titulación: datos del título para ESTA carrera (alimentan el XML SEP) -->
        <!-- `emiteTitulo` otra vez: la pestaña ya no se ofrece sin él, pero
             `pestana` es un valor suelto y basta recordarlo de otra visita. -->
        <section v-else-if="pestana === 'titulacion' && emiteTitulo" class="space-y-5">
            <!-- Encabezado con resumen de completitud -->
            <div class="tarjeta p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="max-w-xl">
                        <h2 class="text-base font-semibold">Datos para el título electrónico</h2>
                        <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                            Los captura administración para <span class="font-medium" :style="{ color: 'var(--color-contenido)' }">{{ alumno.carrera }}</span>.
                            Son por carrera: si la persona tiene otra, se capturan aparte. Alimentan el XML que se envía a la SEP.
                        </p>
                    </div>
                    <div class="text-right">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium"
                            :style="bloquesCompletos === 3
                                ? { backgroundColor: 'color-mix(in srgb, #16a34a 15%, transparent)', color: '#15803d' }
                                : { backgroundColor: 'color-mix(in srgb, #d97706 15%, transparent)', color: '#b45309' }"
                        >
                            <svg v-if="bloquesCompletos === 3" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            {{ bloquesCompletos }} de 3 bloques completos
                        </span>
                        <div class="mt-2 h-1.5 w-40 overflow-hidden rounded-full" :style="{ backgroundColor: 'var(--color-borde)' }">
                            <div class="h-full rounded-full transition-all" :style="{ width: `${(bloquesCompletos / 3) * 100}%`, backgroundColor: bloquesCompletos === 3 ? '#16a34a' : 'var(--color-acento)' }" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modalidad de titulación (nodo Expedición) -->
            <TarjetaSeccion titulo="Modalidad de titulación" descripcion="Cómo y cuándo se tituló." :icono="ICONOS.documento">
                <template #insignia>
                    <span class="rounded-full px-2.5 py-1 text-xs font-medium" :style="estiloInsignia(modalidadCompleta)">{{ modalidadCompleta ? 'Completo' : 'Falta capturar' }}</span>
                </template>
                <div class="grid gap-4 sm:grid-cols-2">
                    <CampoSelect
                        v-model="formModalidad.modalidad_titulacion_id"
                        etiqueta="Modalidad" requerido
                        :opciones="catalogosTitulo.modalidades.map((m) => ({ valor: m.id, texto: m.descripcion }))"
                        vacio="Selecciona…"
                        :error="formModalidad.errors.modalidad_titulacion_id"
                    />
                    <CampoTexto v-model="formModalidad.fecha_expedicion" etiqueta="Fecha de expedición" tipo="date" requerido :error="formModalidad.errors.fecha_expedicion" />
                    <CampoTexto v-model="formModalidad.fecha_terminacion_carrera" etiqueta="Fecha de terminación de la carrera" tipo="date" requerido :error="formModalidad.errors.fecha_terminacion_carrera" />
                    <CampoTexto v-model="formModalidad.fecha_examen_profesional" etiqueta="Fecha de examen profesional" tipo="date" ayuda="Según la modalidad." :error="formModalidad.errors.fecha_examen_profesional" />
                    <CampoTexto v-model="formModalidad.fecha_exencion_examen" etiqueta="Fecha de exención de examen" tipo="date" ayuda="Según la modalidad." :error="formModalidad.errors.fecha_exencion_examen" />
                </div>
                <!-- Entidad de expedición: automática, del campus. -->
                <div class="mt-4 flex items-center gap-2 rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONOS.ubicacion" /></svg>
                    Entidad de expedición: <span class="font-medium" :style="{ color: 'var(--color-contenido)' }">{{ alumno.campus_entidad ?? 'sin entidad en el campus' }}</span>
                    <span>· se toma del campus, no se captura.</span>
                </div>
                <template v-if="puedeEditar" #pie>
                    <BotonPrincipal tipo="button" :procesando="formModalidad.processing" texto="Guardar modalidad" @click="guardarModalidad" />
                </template>
            </TarjetaSeccion>

            <!-- Servicio social (nodo Expedición) -->
            <TarjetaSeccion titulo="Servicio social" descripcion="Cumplimiento y su fundamento legal." :icono="ICONOS.personas">
                <template #insignia>
                    <span class="rounded-full px-2.5 py-1 text-xs font-medium" :style="estiloInsignia(servicioSocialCompleto)">{{ servicioSocialCompleto ? 'Completo' : 'Falta capturar' }}</span>
                </template>
                <div class="grid gap-4 sm:grid-cols-2">
                    <CampoSelect
                        v-model="formServicioSocial.cumplio_servicio_social"
                        etiqueta="¿Cumplió el servicio social?" requerido
                        :opciones="[{ valor: true, texto: 'Sí' }, { valor: false, texto: 'No' }]"
                        vacio="Selecciona…"
                        :error="formServicioSocial.errors.cumplio_servicio_social"
                    />
                    <CampoSelect
                        v-model="formServicioSocial.fundamento_legal_ss_id"
                        etiqueta="Fundamento legal" requerido
                        :opciones="catalogosTitulo.fundamentos.map((f) => ({ valor: f.id, texto: f.descripcion }))"
                        vacio="Selecciona…"
                        :error="formServicioSocial.errors.fundamento_legal_ss_id"
                    />
                </div>
                <template v-if="puedeEditar" #pie>
                    <BotonPrincipal tipo="button" :procesando="formServicioSocial.processing" texto="Guardar servicio social" @click="guardarServicioSocial" />
                </template>
            </TarjetaSeccion>

            <!-- Antecedente (nodo Antecedente) -->
            <TarjetaSeccion titulo="Antecedente académico" descripcion="Estudios previos con que ingresó (p. ej. bachillerato para una licenciatura)." :icono="ICONOS.birrete">
                <template #insignia>
                    <span class="rounded-full px-2.5 py-1 text-xs font-medium" :style="estiloInsignia(antecedenteCompleto)">{{ antecedenteCompleto ? 'Completo' : 'Falta capturar' }}</span>
                </template>
                <div class="grid gap-4 sm:grid-cols-2">
                    <CampoTexto v-model="formAntecedente.institucion_procedencia" etiqueta="Institución de procedencia" requerido :error="formAntecedente.errors.institucion_procedencia" />
                    <CampoSelect
                        v-model="formAntecedente.nivel_antecedente_id"
                        etiqueta="Tipo de estudio antecedente" requerido
                        :opciones="catalogosTitulo.nivelesAntecedente.map((n) => ({ valor: n.id, texto: n.nombre }))"
                        vacio="Selecciona…"
                        :error="formAntecedente.errors.nivel_antecedente_id"
                    />
                    <CampoSelect
                        v-model="formAntecedente.entidad_federativa_id"
                        etiqueta="Entidad federativa" requerido
                        :opciones="catalogosTitulo.entidades.map((e) => ({ valor: e.id, texto: e.nombre }))"
                        vacio="Selecciona…"
                        :error="formAntecedente.errors.entidad_federativa_id"
                    />
                    <CampoTexto
                        v-model="formAntecedente.no_cedula"
                        etiqueta="Número de cédula"
                        :requerido="cedulaRequerida"
                        :ayuda="cedulaRequerida ? 'Obligatorio para Licenciatura o Maestría.' : 'Opcional según el nivel.'"
                        :error="formAntecedente.errors.no_cedula"
                    />
                    <CampoTexto v-model="formAntecedente.fecha_inicio" etiqueta="Fecha de inicio" tipo="date" :error="formAntecedente.errors.fecha_inicio" />
                    <CampoTexto v-model="formAntecedente.fecha_terminacion" etiqueta="Fecha de terminación" tipo="date" requerido :error="formAntecedente.errors.fecha_terminacion" />
                </div>
                <template v-if="puedeEditar" #pie>
                    <BotonPrincipal tipo="button" :procesando="formAntecedente.processing" texto="Guardar antecedente" @click="guardarAntecedente" />
                </template>
            </TarjetaSeccion>
        </section>

        <!-- Datos -->
        <section v-else class="tarjeta p-6">
            <form v-if="puedeEditar" @submit.prevent="guardar">
                <h2 class="text-base font-semibold">Identidad</h2>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Son datos de la PERSONA: corregirlos alcanza también a sus otras matrículas.
                    La CURP autollena fecha, género y entidad, y el correo es el usuario de acceso.
                </p>

                <div class="mt-5">
                    <CamposIdentidad
                        :form="form"
                        :generos="generos"
                        :entidades="entidades"
                        :entidad-extranjero="entidadExtranjero"
                        :paises="paises"
                        :mexico-id="mexicoId"
                        :persona-id="persona.id"
                        con-rfc
                        correo-requerido
                        curp-requerido
                    />
                </div>

                <h2 class="mt-8 text-base font-semibold">Situación escolar</h2>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Aplica solo a esta matrícula, no a las otras carreras de la persona.
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    <CampoSelect
                        v-model="form.situacion_id"
                        etiqueta="Situación"
                        requerido
                        :opciones="situaciones.map((s) => ({ valor: s.id, texto: s.nombre }))"
                        vacio="Selecciona…"
                        :error="form.errors.situacion_id"
                    />
                    <CampoSelect
                        v-model="form.estatus"
                        etiqueta="Estatus"
                        requerido
                        :opciones="[
                            { valor: 'activo', texto: 'Activo' },
                            { valor: 'egresado', texto: 'Egresado' },
                            { valor: 'baja', texto: 'Baja' },
                        ]"
                        :error="form.errors.estatus"
                    />
                    <CampoTexto v-model="form.generacion" etiqueta="Generación" :error="form.errors.generacion" />
                    <CampoTexto
                        v-model="form.periodo_actual"
                        etiqueta="Periodo actual"
                        tipo="number"
                        :error="form.errors.periodo_actual"
                        ayuda="El grado en que va el alumno; lo usa la inscripción masiva."
                    />
                </div>

                <BotonPrincipal :procesando="form.processing" texto="Guardar cambios" class="mt-6" />
            </form>

            <p v-else class="text-sm" :style="{ color: 'var(--color-suave)' }">
                Solo consulta: no tienes permiso para editar alumnos. Los datos de la persona se ven en el encabezado.
            </p>
        </section>
    </AppLayout>
</template>
