<script setup lang="ts">
/**
 * El portal del alumno: si ya puede empezar y QUÉ LE FALTA.
 *
 * ── Se enseñan los DOS lados ───────────────────────────────────────────────
 * Lo que falta y lo que ya se cumple. A quien sólo se le dice lo que le falta no
 * le consta que el sistema haya mirado lo demás, y la primera reacción es ir a
 * ventanilla — que es lo que esta pantalla viene a evitar.
 *
 * ── Y se dice QUÉ REGLA se aplicó ──────────────────────────────────────────
 * «No eres elegible» no se puede discutir con nadie. «Según la regla de
 * Enfermería, generación 2022 en adelante» sí.
 */
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import BitacoraDeHoras from '@/Components/BitacoraDeHoras.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

interface Proceso {
    tipo: string;
    tipo_id: number;
    elegible: boolean;
    obligatorio: boolean | null;
    impedimentos: string[];
    cumplidos: string[];
    avance: Record<string, any>;
    regla: { nombre: string; alcance: string } | null;
    version: number | null;
    horas_requeridas: number | null;
    expediente: Record<string, any> | null;
}

const props = defineProps<{
    matriculas: { id: number; matricula: string | null; programa: string | null; campus: string | null }[];
    elegida: number | null;
    procesos: Proceso[];
}>();

/*
 * Sólo se dibuja tarjeta de lo que la escuela SÍ configuró.
 *
 * Con una tarjeta por tipo, el alumno veía OCHO y siete decían exactamente lo
 * mismo —«tu programa no tiene configurado esto»—, ahogando la única que le
 * habla de su servicio social. Es la regla de vacíos del proyecto: repetir un
 * aviso que no se puede accionar enseña a no leer la pantalla.
 *
 * Los demás NO se callan —eso parecería que el sistema los perdió—: se nombran
 * juntos en una línea al final, que es toda la información que tienen.
 */
const configurados = computed(() => props.procesos.filter((p) => p.regla !== null));

const sinConfigurar = computed(() => props.procesos.filter((p) => p.regla === null).map((p) => p.tipo));

function cambiarMatricula(id: number | string | null): void {
    router.get('/mi-servicio-social', { matricula: id ?? undefined }, { preserveState: false });
}

const procesando = ref(false);
const errores = ref<Record<string, string>>({});
const subiendo = ref<{ expediente: number; documento: number | null; momento: string } | null>(null);
const cancelando = ref<number | null>(null);
const motivo = ref('');
const archivo = ref<File | null>(null);
const entregando = ref<{ expediente: number; informe: any } | null>(null);
const archivoInforme = ref<File | null>(null);

/*
 * Al cambiar de programa se cierran los paneles y se vacía lo escrito: Inertia
 * reutiliza el componente y sólo intercambia las props, así que sin esto el
 * formulario de subir quedaría abierto sobre el expediente del otro programa.
 */
watch(() => props.elegida, () => {
    subiendo.value = null;
    cancelando.value = null;
    motivo.value = '';
    archivo.value = null;
    entregando.value = null;
    archivoInforme.value = null;
    errores.value = {};
});

function abrir(tipoId: number): void {
    procesando.value = true;

    router.post('/mi-servicio-social/abrir', { matricula: props.elegida, tipo_proceso_id: tipoId }, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onFinish: () => (procesando.value = false),
    });
}

function enviar(id: number): void {
    procesando.value = true;

    router.post(`/mi-servicio-social/${id}/enviar`, {}, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onFinish: () => (procesando.value = false),
    });
}

function cancelar(): void {
    if (cancelando.value === null) return;

    procesando.value = true;

    router.post(`/mi-servicio-social/${cancelando.value}/cancelar`, { motivo: motivo.value }, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onSuccess: () => {
            cancelando.value = null;
            motivo.value = '';
        },
        onFinish: () => (procesando.value = false),
    });
}

function subir(): void {
    if (subiendo.value === null || archivo.value === null) return;

    procesando.value = true;

    router.post(
        `/mi-servicio-social/${subiendo.value.expediente}/documentos`,
        {
            documento_id: subiendo.value.documento,
            momento: subiendo.value.momento,
            archivo: archivo.value,
        },
        {
            preserveScroll: true,
            forceFormData: true,
            onError: (e) => (errores.value = e),
            onSuccess: () => {
                subiendo.value = null;
                archivo.value = null;
            },
            onFinish: () => (procesando.value = false),
        },
    );
}

function entregarInforme(): void {
    if (entregando.value === null || archivoInforme.value === null) return;

    procesando.value = true;

    router.post(
        `/procesos/expedientes/${entregando.value.expediente}/informes/${entregando.value.informe.id}`,
        { archivo: archivoInforme.value },
        {
            preserveScroll: true,
            forceFormData: true,
            onError: (e) => (errores.value = e),
            onSuccess: () => {
                entregando.value = null;
                archivoInforme.value = null;
            },
            onFinish: () => (procesando.value = false),
        },
    );
}

const MOMENTOS: Record<string, string> = {
    solicitud: 'Al solicitar',
    durante: 'Durante el proceso',
    liberacion: 'Para liberar',
};
</script>

<template>
    <Head title="Mi servicio social" />

    <AppLayout titulo="Mi servicio social y prácticas">
        <!-- Quien estudia dos programas hace DOS procesos, con reglas que
             pueden ser distintas: se elige de cuál se habla. -->
        <section v-if="matriculas.length > 1" class="tarjeta mb-4 p-4">
            <div class="max-w-md">
                <CampoSelect
                    :model-value="elegida"
                    etiqueta="¿De cuál de tus programas?"
                    :opciones="matriculas.map((m) => ({ valor: m.id, texto: `${m.programa ?? 'Programa'} · ${m.matricula ?? ''}` }))"
                    ayuda="Cada programa lleva su propio servicio social, y puede exigir cosas distintas."
                    @update:model-value="cambiarMatricula"
                />
            </div>
        </section>

        <p v-if="!matriculas.length" class="tarjeta px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
            Todavía no tienes una matrícula activa, así que no hay proceso que mostrar.
        </p>

        <TarjetaSeccion
            v-for="p in configurados"
            :key="p.tipo_id"
            :titulo="p.tipo"
            class="mb-6"
        >
            <template #insignia>
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        v-if="p.obligatorio === false"
                        class="rounded-full px-2 py-0.5 text-[11px]"
                        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 14%, transparent)', color: 'var(--color-suave)' }"
                    >Optativo</span>
                    <!--
                        Con solicitud abierta manda SU ESTADO, no la
                        elegibilidad: «Ya puedes empezar» encima de una
                        solicitud ya enviada se contradice consigo mismo, y el
                        alumno acaba sin saber en qué punto está. La
                        elegibilidad sólo informa mientras no haya empezado.

                        `sin-capitalizar` porque el texto ya viene escrito: la
                        píldora capitaliza CADA palabra y salía «Todavía No».
                    -->
                    <PildoraEstado
                        :texto="p.expediente ? p.expediente.estado_texto : (p.elegible ? 'Ya puedes empezar' : 'Todavía no')"
                        :color="p.expediente ? p.expediente.estado_color : (p.elegible ? '#16a34a' : '#b45309')"
                        sin-capitalizar
                    />
                </div>
            </template>

            <!-- Qué regla se aplicó. Sin esto, «todavía no» no se puede
                 discutir con nadie. -->
            <p v-if="p.regla" class="mb-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                Según <strong>{{ p.regla.nombre }}</strong> ({{ p.regla.alcance }})<span v-if="p.version">, versión {{ p.version }}</span>.
                <span v-if="p.horas_requeridas"> Son {{ p.horas_requeridas }} horas.</span>
            </p>

            <div v-if="!p.expediente" class="grid gap-4 sm:grid-cols-2">
                <div v-if="p.impedimentos.length">
                    <p class="mb-2 text-sm font-medium" :style="{ color: '#b45309' }">Lo que falta</p>
                    <ul class="space-y-1.5">
                        <li v-for="(m, i) in p.impedimentos" :key="i" class="flex gap-2 text-sm">
                            <span :style="{ color: '#b45309' }">•</span>
                            <span>{{ m }}</span>
                        </li>
                    </ul>
                </div>

                <div v-if="p.cumplidos.length">
                    <p class="mb-2 text-sm font-medium" :style="{ color: '#16a34a' }">Lo que ya cumples</p>
                    <ul class="space-y-1.5">
                        <li v-for="(c, i) in p.cumplidos" :key="i" class="flex gap-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                            <span :style="{ color: '#16a34a' }">✓</span>
                            <span>{{ c }}</span>
                        </li>
                    </ul>
                </div>

                <!--
                    Ni impedimentos ni cumplidos: la regla no pide nada que
                    comprobar de antemano. Se DICE, porque dos columnas vacías
                    se leen como que algo falló.
                -->
                <p
                    v-if="!p.impedimentos.length && !p.cumplidos.length"
                    class="text-sm sm:col-span-2"
                    :style="{ color: 'var(--color-suave)' }"
                >
                    Tu programa no pone requisitos previos para empezar.
                </p>
            </div>

            <!--
                Su solicitud, cuando ya la abrió. Va DENTRO de la tarjeta de su
                proceso y no en una sección aparte: «¿puedo?» y «¿cómo voy?» son
                la misma pregunta, y separarlas obligaría a cruzarlas de memoria.
            -->
            <div
                v-if="p.expediente"
                class="mt-4 rounded-lg px-4 py-3"
                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 8%, transparent)' }"
            >
                <!-- Sin repetir la píldora: la de la cabecera ya dice el
                     estado, y decirlo dos veces en la misma tarjeta no informa
                     de nada nuevo. -->
                <p class="text-sm font-medium">Tu solicitud</p>

                <p
                    v-if="p.expediente.motivo_estado"
                    class="mt-2 text-sm"
                    :style="{ color: '#b45309' }"
                >{{ p.expediente.motivo_estado }}</p>

                <p v-if="p.expediente.organizacion" class="mt-2 text-sm">
                    En <strong>{{ p.expediente.organizacion }}</strong>,
                    del {{ p.expediente.fecha_inicio }} al {{ p.expediente.fecha_fin_programada }}.
                </p>

                <!-- Los papeles: los suyos y los que le faltan, juntos. -->
                <ul v-if="p.expediente.documentos.length" class="mt-3 space-y-1.5">
                    <li
                        v-for="d in p.expediente.documentos"
                        :key="`${d.documento_id}-${d.momento}`"
                        class="flex flex-wrap items-center justify-between gap-2 text-sm"
                    >
                        <span class="min-w-0">
                            {{ d.nombre }}
                            <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                · {{ MOMENTOS[d.momento] ?? d.momento }}<span v-if="!d.obligatorio"> · opcional</span>
                            </span>
                            <span v-if="d.observaciones" class="mt-0.5 block text-xs" :style="{ color: '#b45309' }">
                                {{ d.observaciones }}
                            </span>
                        </span>

                        <a
                            v-if="d.entregado"
                            class="text-xs underline"
                            :href="`/mi-servicio-social/${p.expediente.id}/documentos/${d.id}`"
                        >{{ d.estado ?? 'Sin revisar' }} · ver</a>

                        <span v-else class="text-xs" :style="{ color: '#b45309' }">Falta</span>
                    </li>
                </ul>

                <p
                    v-if="p.expediente.faltantes.length"
                    class="mt-3 text-sm"
                    :style="{ color: '#b45309' }"
                >
                    Te falta subir: {{ p.expediente.faltantes.join(', ') }}.
                </p>

                <div v-if="p.expediente.puede_editar" class="mt-3 flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-borde px-3 py-1.5 text-sm"
                        @click="subiendo = { expediente: p.expediente.id, documento: null, momento: 'solicitud' }"
                    >Subir un documento</button>

                    <button
                        type="button"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium text-white"
                        :style="{ backgroundColor: 'var(--color-acento)' }"
                        :disabled="procesando"
                        @click="enviar(p.expediente.id)"
                    >Enviar la solicitud</button>

                    <button
                        type="button"
                        class="rounded-lg border px-3 py-1.5 text-sm"
                        :style="{ borderColor: '#b91c1c', color: '#b91c1c' }"
                        @click="cancelando = p.expediente.id"
                    >Cancelar</button>
                </div>
            </div>

            <!--
                Todavía no la ha abierto y ya puede: el botón. Sin él, un alumno
                elegible no tendría por dónde empezar y acabaría en ventanilla,
                que es lo que esta pantalla viene a evitar.
            -->
            <div v-else-if="p.elegible" class="mt-4">
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-white"
                    :style="{ backgroundColor: 'var(--color-acento)' }"
                    :disabled="procesando"
                    @click="abrir(p.tipo_id)"
                >Empezar mi {{ p.tipo.toLowerCase() }}</button>
                <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                    Se abre como borrador: puedes juntar tus papeles con calma y enviarla después.
                </p>
            </div>

            <!--
                Su CONSTANCIA, arriba de todo cuando ya está liberado: es lo
                único que le interesa a partir de ese momento, y enterrarla
                debajo de la bitácora la haría buscar.
            -->
            <div
                v-if="p.expediente?.liberacion"
                class="mt-4 rounded-lg px-4 py-3"
                :style="{ backgroundColor: 'color-mix(in srgb, #16a34a 10%, transparent)' }"
            >
                <p class="text-sm font-medium" :style="{ color: '#15803d' }">
                    Ya está liberado · folio {{ p.expediente.liberacion.folio }}
                </p>
                <p class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                    {{ p.expediente.liberacion.liberado_en }}<span v-if="p.expediente.liberacion.horas"> · {{ p.expediente.liberacion.horas }} horas acreditadas</span>
                </p>
                <a
                    class="mt-2 inline-block text-sm underline"
                    :href="`/procesos/expedientes/${p.expediente.id}/liberaciones/${p.expediente.liberacion.id}/constancia`"
                    target="_blank"
                >Descargar mi constancia</a>
            </div>

            <!--
                Sus HORAS y sus INFORMES, dentro de la misma tarjeta: «¿cuánto
                llevo?» y «¿qué me falta entregar?» son la misma pregunta, y
                separarlas obligaría a cruzarlas de memoria.
            -->
            <div v-if="p.expediente && p.expediente.horas.requeridas" class="mt-4">
                <BitacoraDeHoras
                    :expediente-id="p.expediente.id"
                    :horas="p.expediente.horas"
                    :puede-capturar="true"
                    :puede-revisar="false"
                    :pide-ubicacion="p.expediente.pide_ubicacion"
                />
            </div>

            <div v-if="p.expediente?.informes.length" class="mt-4">
                <p class="mb-2 text-sm font-medium">Tus informes</p>
                <ul class="space-y-2">
                    <li
                        v-for="i in p.expediente.informes"
                        :key="i.id"
                        class="flex flex-wrap items-start justify-between gap-2 border-b pb-2 text-sm last:border-0"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <div class="min-w-0">
                            <span class="font-medium">{{ i.tipo }}<span v-if="i.numero > 1"> n.º {{ i.numero }}</span></span>
                            <span class="mt-0.5 block text-xs" :style="{ color: 'var(--color-suave)' }">
                                <template v-if="i.fecha_limite">Para el {{ i.fecha_limite }}</template>
                                <template v-else>Sin fecha límite</template>
                                <span v-if="i.entregado_en"> · lo entregaste el {{ i.entregado_en }}</span>
                                <span v-if="i.tarde" :style="{ color: '#b45309' }"> · fuera de plazo</span>
                                <span v-else-if="i.vencido" :style="{ color: '#b91c1c' }"> · se te pasó la fecha</span>
                            </span>
                            <!-- Lo que le dijeron que corrija: es lo único que
                                 puede usar para no mandar lo mismo otra vez. -->
                            <span v-if="i.retroalimentacion" class="mt-0.5 block text-xs" :style="{ color: '#b45309' }">
                                {{ i.retroalimentacion }}
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <a v-if="i.nombre_original" class="underline" :href="`/procesos/expedientes/${p.expediente.id}/informes/${i.id}/archivo`">Ver</a>
                            <button
                                v-if="i.entregable"
                                type="button"
                                class="underline"
                                @click="entregando = { expediente: p.expediente.id, informe: i }"
                            >{{ i.entregado_en ? 'Reemplazar' : 'Entregar' }}</button>
                            <PildoraEstado
                                :texto="i.estado_texto"
                                :color="i.estado === 'aceptado' ? '#16a34a' : (i.estado === 'rechazado' ? '#b91c1c' : (i.estado === 'entregado' ? '#0284c7' : '#64748b'))"
                                sin-capitalizar
                            />
                        </div>
                    </li>
                </ul>
            </div>

            <!-- El avance de créditos, cuando la regla lo pide. -->
            <div v-if="p.avance?.porcentaje_creditos != null" class="mt-4">
                <div class="mb-1 flex items-center justify-between text-xs" :style="{ color: 'var(--color-suave)' }">
                    <span>Créditos</span>
                    <span class="tabular-nums">{{ p.avance.creditos }} de {{ p.avance.creditos_del_plan }} · {{ p.avance.porcentaje_creditos }} %</span>
                </div>
                <div class="h-2 w-full overflow-hidden rounded-full" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 16%, transparent)' }">
                    <div
                        class="h-full rounded-full"
                        :style="{ width: `${Math.min(100, Number(p.avance.porcentaje_creditos))}%`, backgroundColor: 'var(--color-acento)' }"
                    />
                </div>
            </div>
        </TarjetaSeccion>

        <!--
            Lo que la escuela no ha configurado, junto y en una línea. Nombrarlo
            es lo único que se puede decir de ello; repetirlo en una tarjeta por
            tipo sólo escondía la que sí informa.
        -->
        <p
            v-if="matriculas.length && sinConfigurar.length"
            class="tarjeta px-6 py-4 text-sm"
            :style="{ color: 'var(--color-suave)' }"
        >
            <template v-if="configurados.length">
                Tu programa no tiene configurado: {{ sinConfigurar.join(', ') }}.
                Si te dijeron que te toca alguno, pregunta en servicios escolares.
            </template>
            <template v-else>
                Tu programa todavía no tiene configurado ningún proceso
                ({{ sinConfigurar.join(', ') }}), así que por ahora no hay nada que empezar.
                Pregunta en servicios escolares.
            </template>
        </p>

        <p v-if="matriculas.length && !procesos.length" class="tarjeta px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
            Tu escuela todavía no ha encendido ningún proceso.
        </p>

        <Modal v-if="subiendo" etiqueta="Subir un documento" ancho="max-w-lg" @cerrar="subiendo = null">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="subir">
                    <h2 class="text-base font-semibold">Subir un documento</h2>

                    <CampoSelect
                        v-model="subiendo.momento"
                        etiqueta="¿Para qué momento?"
                        :opciones="Object.entries(MOMENTOS).map(([valor, texto]) => ({ valor, texto }))"
                        :error="errores.momento"
                    />

                    <CampoSelect
                        v-model="subiendo.documento"
                        etiqueta="Documento"
                        requerido
                        :opciones="(procesos.find((x) => x.expediente?.id === subiendo?.expediente)?.expediente?.documentos ?? [])
                            .filter((d: any) => d.momento === subiendo?.momento)
                            .map((d: any) => ({ valor: d.documento_id, texto: d.nombre }))"
                        vacio="Elige cuál…"
                        :error="errores.documento_id"
                    />

                    <label class="block text-sm">
                        <span class="mb-1 block">Archivo</span>
                        <input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png"
                            class="w-full text-sm"
                            @change="archivo = ($event.target as HTMLInputElement).files?.[0] ?? null"
                        />
                        <span class="mt-1 block text-xs" :style="{ color: 'var(--color-suave)' }">
                            PDF o imagen, hasta 10 MB.
                        </span>
                        <span v-if="errores.archivo" class="mt-1 block text-xs" :style="{ color: '#b91c1c' }">
                            {{ errores.archivo }}
                        </span>
                    </label>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="procesando" texto="Subir" icono="crear" :deshabilitado="!archivo || !subiendo.documento" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">Cancelar</button>
                    </div>
                </form>
            </template>
        </Modal>

        <Modal v-if="entregando" etiqueta="Entregar informe" ancho="max-w-lg" @cerrar="entregando = null">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="entregarInforme">
                    <h2 class="text-base font-semibold">{{ entregando.informe.tipo }}</h2>

                    <p v-if="entregando.informe.retroalimentacion" class="rounded-lg px-4 py-3 text-sm" :style="{ backgroundColor: 'color-mix(in srgb, #b45309 10%, transparent)', color: '#b45309' }">
                        {{ entregando.informe.retroalimentacion }}
                    </p>

                    <label class="block text-sm">
                        <span class="mb-1 block">Archivo</span>
                        <input
                            type="file"
                            accept=".pdf,.doc,.docx"
                            class="w-full text-sm"
                            @change="archivoInforme = ($event.target as HTMLInputElement).files?.[0] ?? null"
                        />
                        <span class="mt-1 block text-xs" :style="{ color: 'var(--color-suave)' }">PDF o Word, hasta 20 MB.</span>
                        <span v-if="errores.archivo" class="mt-1 block text-xs" :style="{ color: '#b91c1c' }">{{ errores.archivo }}</span>
                    </label>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="procesando" texto="Entregar" icono="crear" :deshabilitado="!archivoInforme" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">Cancelar</button>
                    </div>
                </form>
            </template>
        </Modal>

        <Modal v-if="cancelando" etiqueta="Cancelar la solicitud" ancho="max-w-lg" @cerrar="cancelando = null">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="cancelar">
                    <h2 class="text-base font-semibold">Cancelar tu solicitud</h2>

                    <CampoTextarea
                        v-model="motivo"
                        etiqueta="¿Por qué la cancelas?"
                        requerido
                        :filas="3"
                        ayuda="Queda anotado. Después puedes abrir otra cuando quieras."
                        :error="errores.motivo"
                    />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="procesando" texto="Cancelar la solicitud" icono="eliminar" :deshabilitado="motivo.trim().length < 5" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">Mejor no</button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
