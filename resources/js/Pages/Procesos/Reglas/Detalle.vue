<script setup lang="ts">
/**
 * Una regla y sus versiones.
 *
 * ── La versión es lo que un expediente RECUERDA ────────────────────────────
 * Cambiar un requisito crea la siguiente; la anterior se conserva porque hay
 * expedientes que la citan. Por eso la pantalla habla de «publicar una versión»
 * y no de «editar la regla».
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

const props = defineProps<{
    regla: Record<string, any>;
    versiones: Record<string, any>[];
    catalogos: Record<string, any>;
    puedeEditar: boolean;
}>();

const errores = ref<Record<string, string>>({});
const procesando = ref(false);

const editorAbierto = ref(false);
const editandoVersion = ref<Record<string, any> | null>(null);
const datos = ref<Record<string, unknown>>({});

/** El molde de una versión nueva: lo que casi cualquier escuela deja así. */
function moldeVacio(): Record<string, unknown> {
    return {
        vigente_desde: new Date().toISOString().slice(0, 10),
        obligatorio: true,
        horas_requeridas: null,
        tolerancia_horas: 0,
        porcentaje_creditos_minimo: null,
        periodo_minimo: null,
        solicitud_desde: '',
        solicitud_hasta: '',
        plazo_maximo_dias: null,
        max_horas_dia: null,
        max_horas_semana: null,
        exige_seguro: false,
        exige_convenio_vigente: false,
        exige_no_adeudo: false,
        exige_aprobacion_coordinador: true,
        informes_parciales: 0,
        periodicidad_informe_dias: null,
        exige_informe_final: true,
        exige_evaluacion_supervisor: true,
        exige_evaluacion_estudiante: false,
        exige_carta_aceptacion: false,
        exige_carta_termino: false,
        emite_constancia: true,
        cuenta_para_titulacion: true,
        notas: '',
    };
}

function abrirVersion(v: Record<string, any> | null): void {
    errores.value = {};
    editandoVersion.value = v;

    datos.value = v
        ? {
            ...moldeVacio(),
            ...Object.fromEntries(Object.entries(v).filter(([k]) => k in moldeVacio())),
            vigente_desde: v.vigente_desde ?? '',
            solicitud_desde: v.solicitud_desde ?? '',
            solicitud_hasta: v.solicitud_hasta ?? '',
        }
        : moldeVacio();

    editorAbierto.value = true;
}

function guardarVersion(): void {
    procesando.value = true;

    const destino = editandoVersion.value
        ? `/procesos/reglas/${props.regla.id}/versiones/${editandoVersion.value.id}`
        : `/procesos/reglas/${props.regla.id}/versiones`;

    router[editandoVersion.value ? 'put' : 'post'](destino, { ...datos.value }, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onSuccess: () => (editorAbierto.value = false),
        onFinish: () => (procesando.value = false),
    });
}

/* ── Las tres listas de una versión ─────────────────────────────────────── */
const agregando = ref<{ version: Record<string, any>; lista: string } | null>(null);
const nuevo = ref<Record<string, unknown>>({});

/*
 * Cuando la lista no tiene nada que ofrecer no se dibuja el botón: hoy pasa con
 * las materias previas de una regla que no acota un plan. La pantalla explica
 * por qué, y dejar «Agregar» encendido sólo produciría un error de validación
 * que nadie sabría interpretar.
 */
const nadaQueAgregar = computed(
    () => agregando.value?.lista === 'materias' && !props.catalogos.materias.length,
);

function abrirAgregar(version: Record<string, any>, lista: string): void {
    errores.value = {};
    agregando.value = { version, lista };
    nuevo.value = lista === 'documentos'
        ? { documento_id: null, momento: 'solicitud', obligatorio: true, dias_vigencia: null }
        : lista === 'materias'
            ? { plan_materia_id: null }
            : { situacion_alumno_id: null };
}

function guardarRenglon(): void {
    if (!agregando.value) {
        return;
    }

    procesando.value = true;

    const { version, lista } = agregando.value;

    router.post(`/procesos/reglas/${props.regla.id}/versiones/${version.id}/${lista}`, { ...nuevo.value }, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onSuccess: () => (agregando.value = null),
        onFinish: () => (procesando.value = false),
    });
}

function quitar(version: Record<string, any>, lista: string, renglon: number): void {
    router.delete(`/procesos/reglas/${props.regla.id}/versiones/${version.id}/${lista}/${renglon}`, { preserveScroll: true });
}

/** Los requisitos de una versión, en una lista legible. */
function requisitos(v: Record<string, any>): string[] {
    const lista: string[] = [];

    lista.push(v.obligatorio ? 'Obligatorio para titularse' : 'Optativo');

    if (v.horas_requeridas) {
        lista.push(
            v.tolerancia_horas
                ? `${v.horas_requeridas} horas (se liberan con ${v.horas_minimas})`
                : `${v.horas_requeridas} horas`,
        );
    }

    if (v.porcentaje_creditos_minimo) lista.push(`${Number(v.porcentaje_creditos_minimo)} % de créditos`);
    if (v.periodo_minimo) lista.push(`a partir del periodo ${v.periodo_minimo}`);
    if (v.plazo_maximo_dias) lista.push(`concluir en ${v.plazo_maximo_dias} días`);
    if (v.max_horas_dia) lista.push(`máx. ${v.max_horas_dia} h/día`);
    if (v.max_horas_semana) lista.push(`máx. ${v.max_horas_semana} h/semana`);
    if (v.solicitud_desde || v.solicitud_hasta) {
        lista.push(`solicitud del ${v.solicitud_desde ?? '—'} al ${v.solicitud_hasta ?? '—'}`);
    }
    if (v.exige_seguro) lista.push('seguro');
    if (v.exige_convenio_vigente) lista.push('convenio vigente');
    if (v.exige_no_adeudo) lista.push('sin adeudos');
    if (v.informes_parciales) lista.push(`${v.informes_parciales} informe(s) parcial(es) cada ${v.periodicidad_informe_dias} días`);
    if (v.exige_informe_final) lista.push('informe final');
    if (v.exige_evaluacion_supervisor) lista.push('evaluación del supervisor');
    if (v.exige_evaluacion_estudiante) lista.push('autoevaluación');
    if (v.exige_carta_aceptacion) lista.push('carta de aceptación');
    if (v.exige_carta_termino) lista.push('carta de término');
    if (v.emite_constancia) lista.push('emite constancia');

    return lista;
}

const banderas = [
    ['obligatorio', 'Obligatorio para titularse', 'Optativo significa que el alumno puede hacerlo y que no le impide titularse.'],
    ['exige_seguro', 'Exige seguro', ''],
    ['exige_convenio_vigente', 'Exige convenio vigente', 'Sin convenio que ampare, no se le puede asignar a esa organización.'],
    ['exige_no_adeudo', 'Exige estar al corriente', 'Se lee de la situación financiera, la misma que usa la inscripción.'],
    ['exige_aprobacion_coordinador', 'Exige aprobación del coordinador', ''],
    ['exige_informe_final', 'Exige informe final', ''],
    ['exige_evaluacion_supervisor', 'Exige evaluación del supervisor', ''],
    ['exige_evaluacion_estudiante', 'Exige autoevaluación', ''],
    ['exige_carta_aceptacion', 'Exige carta de aceptación', ''],
    ['exige_carta_termino', 'Exige carta de término', ''],
    ['emite_constancia', 'Emite constancia al liberar', ''],
    ['cuenta_para_titulacion', 'Cuenta para titularse', 'Lo consulta la elegibilidad documental. No cambia nada del trámite de título.'],
] as const;
</script>

<template>
    <Head :title="regla.nombre" />

    <AppLayout :titulo="regla.nombre">
        <Link href="/procesos/reglas" class="mb-4 inline-block text-sm" :style="{ color: 'var(--color-acento)' }">
            ← Todas las reglas
        </Link>

        <TarjetaSeccion titulo="Alcance" :descripcion="regla.alcance">
            <template #insignia>
                <PildoraEstado :texto="regla.activa ? 'Activa' : 'Apagada'" :color="regla.activa ? '#16a34a' : 'var(--color-suave)'" />
            </template>

            <p class="text-sm">
                Proceso: <strong>{{ regla.tipo }}</strong>
            </p>
            <p v-if="regla.notas" class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">{{ regla.notas }}</p>
        </TarjetaSeccion>

        <TarjetaSeccion titulo="Versiones" descripcion="Cambiar un requisito crea la siguiente. La anterior se conserva porque hay expedientes que la citan." class="mt-6" sin-relleno>
            <template v-if="puedeEditar" #insignia>
                <button
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-xs font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="abrirVersion(null)"
                >Publicar versión</button>
            </template>

            <div v-if="!versiones.length" class="px-6 py-8 text-sm" :style="{ color: 'var(--color-suave)' }">
                Esta regla todavía <strong>no exige nada</strong>: tiene alcance y ninguna versión, así que
                ningún alumno la puede cumplir. Publica la primera.
            </div>

            <div
                v-for="v in versiones"
                :key="v.id"
                class="border-t px-6 py-4"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 font-medium">
                            <span>Versión {{ v.version }}</span>
                            <span class="text-xs" :style="{ color: 'var(--color-suave)' }">desde {{ v.vigente_desde }}</span>
                            <!--
                                Con varias publicadas, cuál manda HOY es la
                                pregunta. Sin decirlo hay que comparar fechas de
                                cabeza, y dos podrían estar «en vigor» a la vez.
                            -->
                            <span
                                v-if="v.es_la_vigente"
                                class="rounded-full px-2 py-0.5 text-[11px]"
                                :style="{ backgroundColor: 'color-mix(in srgb, #16a34a 16%, transparent)', color: '#15803d' }"
                            >Es la que rige hoy</span>
                            <span
                                v-else-if="!v.en_vigor"
                                class="rounded-full px-2 py-0.5 text-[11px]"
                                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 14%, transparent)', color: 'var(--color-suave)' }"
                            >Entra en vigor después</span>
                            <span
                                v-else
                                class="rounded-full px-2 py-0.5 text-[11px]"
                                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 14%, transparent)', color: 'var(--color-suave)' }"
                            >Sustituida</span>
                        </p>
                        <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ requisitos(v).join(' · ') }}
                        </p>
                    </div>

                    <BotonAccion v-if="puedeEditar" variante="editar" @click="abrirVersion(v)" />
                </div>

                <!-- Las tres listas -->
                <div class="mt-4 grid gap-4 lg:grid-cols-3">
                    <div v-for="lista in [
                        { clave: 'documentos', titulo: 'Documentos', filas: v.documentos },
                        { clave: 'materias', titulo: 'Materias previas', filas: v.materias },
                        { clave: 'situaciones', titulo: 'Situaciones permitidas', filas: v.situaciones },
                    ]" :key="lista.clave" class="rounded-lg border p-3" :style="{ borderColor: 'var(--color-borde)' }">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <p class="text-xs font-medium">{{ lista.titulo }}</p>
                            <button
                                v-if="puedeEditar"
                                type="button"
                                class="text-xs"
                                :style="{ color: 'var(--color-acento)' }"
                                @click="abrirAgregar(v, lista.clave)"
                            >Agregar</button>
                        </div>

                        <!-- El vacío se EXPLICA: en dos de las tres listas
                             significa «no se pide», y en la de situaciones
                             significa «se admite cualquiera», que no es lo mismo. -->
                        <p v-if="!lista.filas.length" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ lista.clave === 'situaciones' ? 'Se admite cualquier situación.' : 'No se pide ninguno.' }}
                        </p>

                        <ul v-else class="space-y-1">
                            <li v-for="f in lista.filas" :key="f.id" class="flex items-start justify-between gap-2 text-xs">
                                <span class="min-w-0">
                                    {{ f.nombre }}
                                    <span v-if="lista.clave === 'documentos'" :style="{ color: 'var(--color-suave)' }">
                                        · {{ f.momento_texto }}{{ f.obligatorio ? '' : ' (opcional)' }}{{ f.dias_vigencia ? ` · vigencia ${f.dias_vigencia} d` : '' }}
                                    </span>
                                </span>
                                <button
                                    v-if="puedeEditar"
                                    type="button"
                                    class="shrink-0"
                                    :style="{ color: '#dc2626' }"
                                    title="Quitar"
                                    @click="quitar(v, lista.clave, f.id)"
                                >×</button>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </TarjetaSeccion>

        <!-- Editor de versión -->
        <Modal v-if="editorAbierto" etiqueta="Versión de la regla" ancho="max-w-3xl" @cerrar="editorAbierto = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardarVersion">
                    <h2 class="text-base font-semibold">
                        {{ editandoVersion ? `Editar la versión ${editandoVersion.version}` : 'Publicar una versión' }}
                    </h2>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <CampoTexto v-model="datos.vigente_desde" etiqueta="Vigente desde" tipo="date" requerido :error="errores.vigente_desde" />
                        <CampoTexto
                            v-model.number="datos.horas_requeridas"
                            etiqueta="Horas requeridas"
                            tipo="number"
                            paso="1"
                            ayuda="En blanco si este proceso no se mide por horas."
                            :error="errores.horas_requeridas"
                        />
                        <CampoTexto
                            v-model.number="datos.tolerancia_horas"
                            etiqueta="Tolerancia de horas"
                            tipo="number"
                            paso="1"
                            ayuda="Cuántas de menos se aceptan al liberar."
                            :error="errores.tolerancia_horas"
                        />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <CampoTexto
                            v-model.number="datos.porcentaje_creditos_minimo"
                            etiqueta="% mínimo de créditos"
                            tipo="number"
                            paso="0.01"
                            :error="errores.porcentaje_creditos_minimo"
                        />
                        <CampoTexto v-model.number="datos.periodo_minimo" etiqueta="Periodo mínimo" tipo="number" paso="1" :error="errores.periodo_minimo" />
                        <CampoTexto v-model.number="datos.plazo_maximo_dias" etiqueta="Plazo máximo (días)" tipo="number" paso="1" :error="errores.plazo_maximo_dias" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-4">
                        <CampoTexto v-model="datos.solicitud_desde" etiqueta="Solicitud desde" tipo="date" :error="errores.solicitud_desde" />
                        <CampoTexto
                            v-model="datos.solicitud_hasta"
                            etiqueta="Solicitud hasta"
                            tipo="date"
                            ayuda="En blanco las dos = siempre abierta."
                            :error="errores.solicitud_hasta"
                        />
                        <CampoTexto v-model.number="datos.max_horas_dia" etiqueta="Máx. horas/día" tipo="number" paso="1" :error="errores.max_horas_dia" />
                        <CampoTexto v-model.number="datos.max_horas_semana" etiqueta="Máx. horas/semana" tipo="number" paso="1" :error="errores.max_horas_semana" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto
                            v-model.number="datos.informes_parciales"
                            etiqueta="Informes parciales"
                            tipo="number"
                            paso="1"
                            :error="errores.informes_parciales"
                        />
                        <CampoTexto
                            v-model.number="datos.periodicidad_informe_dias"
                            etiqueta="Cada cuántos días"
                            tipo="number"
                            paso="1"
                            ayuda="Obligatorio si pides parciales: sin esto no tendrían fecha límite."
                            :error="errores.periodicidad_informe_dias"
                        />
                    </div>

                    <div class="grid gap-2 sm:grid-cols-2">
                        <label v-for="[campo, etiqueta, ayuda] in banderas" :key="campo" class="flex items-start gap-2 text-sm">
                            <input v-model="datos[campo]" type="checkbox" class="mt-0.5 h-4 w-4" />
                            <span>
                                <span>{{ etiqueta }}</span>
                                <span v-if="ayuda" class="block text-xs" :style="{ color: 'var(--color-suave)' }">{{ ayuda }}</span>
                            </span>
                        </label>
                    </div>

                    <CampoTextarea v-model="datos.notas" etiqueta="Notas" :filas="2" />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="procesando" :texto="editandoVersion ? 'Guardar' : 'Publicar'" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">Cancelar</button>
                    </div>
                </form>
            </template>
        </Modal>

        <!-- Agregar a una lista -->
        <Modal v-if="agregando" etiqueta="Agregar" @cerrar="agregando = null">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardarRenglon">
                    <h2 class="text-base font-semibold">Agregar a la versión {{ agregando.version.version }}</h2>

                    <template v-if="agregando.lista === 'documentos'">
                        <CampoSelect
                            v-model="nuevo.documento_id"
                            etiqueta="Documento"
                            requerido
                            :opciones="catalogos.documentos.map((d: any) => ({ valor: d.id, texto: d.nombre }))"
                            vacio="Elige el documento…"
                            :error="errores.documento_id"
                        />
                        <CampoSelect
                            v-model="nuevo.momento"
                            etiqueta="Cuándo se pide"
                            requerido
                            :opciones="Object.entries(catalogos.momentos).map(([valor, texto]) => ({ valor, texto: texto as string }))"
                            ayuda="Pedirlo todo al solicitar frenaría el trámite por una carta que aún no existe."
                            :error="errores.momento"
                        />
                        <CampoTexto
                            v-model.number="nuevo.dias_vigencia"
                            etiqueta="Vigencia (días)"
                            tipo="number"
                            paso="1"
                            ayuda="En blanco si no caduca."
                            :error="errores.dias_vigencia"
                        />
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="nuevo.obligatorio" type="checkbox" class="h-4 w-4" />
                            <span>Obligatorio</span>
                        </label>
                    </template>

                    <template v-else-if="agregando.lista === 'materias'">
                        <p v-if="!catalogos.materias.length" class="text-sm" :style="{ color: '#b45309' }">
                            Esta regla no acota un plan de estudios, así que no hay materias que exigir:
                            una materia de otro plan dejaría a todos sin poder cumplirla.
                        </p>
                        <CampoSelect
                            v-else
                            v-model="nuevo.plan_materia_id"
                            etiqueta="Materia"
                            requerido
                            :opciones="catalogos.materias.map((m: any) => ({ valor: m.id, texto: m.nombre }))"
                            vacio="Elige la materia…"
                            :error="errores.plan_materia_id"
                        />
                    </template>

                    <template v-else>
                        <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                            Sin ninguna señalada se admite <strong>cualquier</strong> situación. En cuanto
                            agregues una, sólo se admitirán las que estén en la lista.
                        </p>
                        <CampoSelect
                            v-model="nuevo.situacion_alumno_id"
                            etiqueta="Situación"
                            requerido
                            :opciones="catalogos.situacionesAlumno.map((s: any) => ({ valor: s.id, texto: s.nombre }))"
                            vacio="Elige la situación…"
                            :error="errores.situacion_alumno_id"
                        />
                    </template>

                    <div class="flex items-center gap-3 pt-2">
                        <!--
                            Sin nada que elegir, «Agregar» sólo puede fallar: se ve
                            activo, se pulsa y devuelve un error de validación. Un
                            botón muerto no da error del que quejarse, así que se
                            esconde y queda sólo la salida que existe.
                        -->
                        <BotonPrincipal v-if="!nadaQueAgregar" :procesando="procesando" texto="Agregar" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            {{ nadaQueAgregar ? 'Cerrar' : 'Cancelar' }}
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
