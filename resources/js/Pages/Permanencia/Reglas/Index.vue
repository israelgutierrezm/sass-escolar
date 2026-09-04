<script setup lang="ts">
/**
 * Las reglas de alerta: qué vigila la escuela y con qué umbral.
 *
 * ── Lo que esta pantalla tiene que dejar claro ────────────────────────────
 *  1. **Cuántas están encendidas.** Ocho reglas escritas se leen como ocho
 *     reglas funcionando, y una escuela que crea estar vigilando algo y no lo
 *     esté es peor que una que sepa que no. El aviso va arriba del todo.
 *  2. **Qué mide cada una, en una frase.** La condición se arma en el servidor
 *     con los MISMOS campos con los que se decide, así que no puede decir algo
 *     distinto de lo que pasará.
 *  3. **Que el umbral es una decisión de la escuela.** Los que vienen de
 *     fábrica son un punto de partida, no una recomendación.
 *
 * ── El formulario usa `ref` llano y no `useForm` ──────────────────────────
 * `useForm` fija sus campos al construirse, y aquí los campos cambian según lo
 * que se esté haciendo —crear una regla trae el alcance y la métrica; emitir una
 * versión trae umbral y ventana—. Con `useForm` el formulario quedaría mudo: se
 * pulsa y no sale ni una petición, sin un solo error en la consola. Es el
 * defecto que ya se pagó en la fase 1 del módulo formativo.
 */
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoCheckbox from '@/Components/CampoCheckbox.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import MenuAcciones, { type OpcionAccion } from '@/Components/MenuAcciones.vue';
import Modal from '@/Components/Modal.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { COLOR_SEVERIDAD, colorPermanencia } from '@/utils/coloresPermanencia';
import { hoyLocal } from '@/utils/fechas';

interface Version {
    id: number;
    version: number;
    rige: boolean;
    vigente_desde: string | null;
    vigente_hasta: string | null;
    condicion: string;
    metrica: string;
    comparador: string;
    umbral: number | null;
    umbral_fuente: string;
    ventana_tipo: string;
    ventana_valor: number | null;
    cobertura_minima: number;
    severidad: string;
    peso: number;
    cooldown_dias: number;
    sla_horas: number | null;
    avisa_al_alumno: boolean;
    avisa_a_la_escuela: boolean;
    plantilla_aviso: string | null;
    notas: string | null;
}

interface Regla {
    id: number;
    nombre: string;
    descripcion: string | null;
    activa: boolean;
    proveedor: string;
    categoria: { id: number; clave: string; nombre: string; color: string; sensible: boolean } | null;
    alcance: string;
    ejes: Record<string, number | string | null>;
    versiones: Version[];
    /** Cuánto de lo suyo se descarta. Null si hay muy pocas revisadas. */
    calibracion: {
        revisadas: number;
        descartadas: number | null;
        proporcion: number | null;
        suficientes: boolean;
        preocupa: boolean;
    } | null;
    sin_version_vigente: boolean;
}

interface Metrica {
    clave: string;
    proveedor: string;
    etiqueta: string;
    descripcion: string;
    unidad: string;
    direccion: string;
    cobertura: string;
    por_materia: boolean;
    comparador_sugerido: string;
}

const props = defineProps<{
    reglas: Regla[];
    encendidas: number;
    metricas: Metrica[];
    catalogos: Record<string, Array<Record<string, unknown>> | string[]>;
    puedeEditar: boolean;
}>();

const abierta = ref<number | null>(null);
const editando = ref<Regla | null | 'nueva'>(null);
const versionando = ref<Regla | null>(null);
const datos = ref<Record<string, unknown>>({});
const errores = ref<Record<string, string>>({});
const procesando = ref(false);

const metricaElegida = computed(() =>
    props.metricas.find((m) => m.clave === datos.value.metrica) ?? null,
);

/*
 * El aviso de que el comparador mira al lado contrario.
 *
 * NO bloquea: hay reglas legítimas al revés —«promedio por encima de X» para
 * una beca de excelencia—, así que impedirlo sería cerrar un caso real por
 * proteger de un error de captura. Lo que sí hace falta es DECIRLO, porque el
 * error produce una regla que no se dispara nunca y que nadie descubre hasta
 * que alguien pregunta por qué no hay alertas.
 */
const comparadorAlReves = computed(() => {
    const m = metricaElegida.value;
    const c = datos.value.comparador as string | undefined;

    if (!m || !c) return false;

    return m.direccion === 'sube'
        ? ['<', '<='].includes(c)
        : ['>', '>='].includes(c);
});

function nueva(): void {
    editando.value = 'nueva';
    errores.value = {};
    datos.value = {
        nombre: '',
        descripcion: '',
        categoria_id: null,
        metrica: null,
        campus_id: null,
        nivel_estudios_id: null,
        programa_academico_id: null,
        plan_id: null,
        ciclo_id: null,
        situacion_alumno_id: null,
        modalidad: null,
        generacion_desde: null,
        generacion_hasta: null,
        notas: '',
        // La versión 1 viaja junto: una regla sin versión no mide nada, y
        // crearla en dos pasos deja reglas a medias que se ven completas.
        vigente_desde: hoyLocal(),
        comparador: '>=',
        umbral: null,
        umbral_fuente: 'fijo',
        ventana_tipo: 'ciclo',
        ventana_valor: null,
        cobertura_minima: 1,
        severidad: 'medio',
        peso: 2,
        frecuencia: 'diaria',
        cooldown_dias: 14,
        sla_horas: null,
        avisa_al_alumno: false,
        avisa_a_la_escuela: false,
    };
}

function editarAlcance(regla: Regla): void {
    editando.value = regla;
    errores.value = {};
    datos.value = {
        nombre: regla.nombre,
        descripcion: regla.descripcion ?? '',
        categoria_id: regla.categoria?.id ?? null,
        metrica: regla.versiones[0]?.metrica ?? null,
        ...regla.ejes,
        notas: '',
    };
}

function emitirVersion(regla: Regla): void {
    const v = regla.versiones.find((x) => x.rige) ?? regla.versiones[0];

    versionando.value = regla;
    errores.value = {};
    datos.value = {
        vigente_desde: hoyLocal(),
        comparador: v?.comparador ?? '>=',
        umbral: v?.umbral ?? null,
        umbral_fuente: v?.umbral_fuente ?? 'fijo',
        ventana_tipo: v?.ventana_tipo ?? 'ciclo',
        ventana_valor: v?.ventana_valor ?? null,
        cobertura_minima: v?.cobertura_minima ?? 1,
        severidad: v?.severidad ?? 'medio',
        peso: v?.peso ?? 2,
        frecuencia: 'diaria',
        cooldown_dias: v?.cooldown_dias ?? 14,
        sla_horas: v?.sla_horas ?? null,
        avisa_al_alumno: v?.avisa_al_alumno ?? false,
        avisa_a_la_escuela: v?.avisa_a_la_escuela ?? false,
        plantilla_aviso: v?.plantilla_aviso ?? '',
        notas: '',
        metrica: v?.metrica ?? null,
    };
}

function cerrar(): void {
    editando.value = null;
    versionando.value = null;
    errores.value = {};
}

function guardar(): void {
    procesando.value = true;

    const opciones = {
        onError: (e: Record<string, string>) => (errores.value = e),
        onSuccess: () => cerrar(),
        onFinish: () => (procesando.value = false),
        preserveScroll: true,
    };

    if (versionando.value) {
        router.post(`/permanencia/reglas/${versionando.value.id}/versiones`, datos.value, opciones);
    } else if (editando.value === 'nueva') {
        router.post('/permanencia/reglas', datos.value, opciones);
    } else if (editando.value) {
        router.put(`/permanencia/reglas/${editando.value.id}`, datos.value, opciones);
    }
}

function alternar(regla: Regla): void {
    router.patch(`/permanencia/reglas/${regla.id}/activa`, { activa: !regla.activa }, { preserveScroll: true });
}

function eliminar(regla: Regla): void {
    router.delete(`/permanencia/reglas/${regla.id}`, { preserveScroll: true });
}

/*
 * Las acciones del renglon. `MenuAcciones` emite la CLAVE y no ejecuta nada:
 * quien lo usa decide. Y recibe la lista ya filtrada por permiso --con la lista
 * vacia no dibuja ni el boton--, que es como el componente esta pensado.
 */
function acciones(regla: Regla): OpcionAccion[] {
    if (!props.puedeEditar) return [];

    return [
        { clave: 'alcance', variante: 'editar', texto: 'Editar el alcance' },
        { clave: 'version', variante: 'crear', texto: 'Emitir una version' },
        {
            clave: 'eliminar',
            variante: 'eliminar',
            // Una regla que ya levanto alertas es historia: sus alertas la
            // nombran. El servidor lo vuelve a comprobar.
            deshabilitado: regla.versiones.length > 1,
            motivo: 'Tiene varias versiones: apagala en vez de borrarla.',
        },
    ];
}

function elegir(regla: Regla, clave: string): void {
    if (clave === 'alcance') editarAlcance(regla);
    if (clave === 'version') emitirVersion(regla);
    if (clave === 'eliminar') eliminar(regla);
}

const lista = (clave: string) => (props.catalogos[clave] ?? []) as Array<Record<string, unknown>>;

/** Del catalogo del servidor al par que `CampoSelect` espera. */
const comoOpciones = (filas: Array<Record<string, unknown>>, campo = 'nombre') =>
    filas.map((f) => ({ valor: f.id as number, texto: String(f[campo] ?? '') }));
const textos = (clave: string) => (props.catalogos[clave] ?? []) as string[];
</script>

<template>
    <Head title="Reglas de alerta" />

    <AppLayout titulo="Reglas de alerta">
        <section class="tarjeta mb-4 p-5">
            <h2 class="font-semibold">Qué vigila esta escuela</h2>
            <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Cada regla mide una cosa concreta sobre cada alumno y levanta una señal cuando cruza
                el umbral que tú pongas. Una señal <strong>no es un castigo ni una baja</strong>:
                es una revisión pendiente para una persona.
            </p>
            <p class="mt-2 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Los umbrales que vienen de fábrica son un <strong>punto de partida</strong>, no una
                recomendación: cópialos de tu reglamento antes de encender nada.
            </p>

            <!--
                El aviso que evita que una escuela se crea configurada. Es la
                misma decisión que la escalera de cobranza: reglas escritas se
                leen como reglas funcionando.
            -->
            <p
                v-if="encendidas === 0 && reglas.length > 0"
                class="mt-3 rounded-lg border px-3 py-2 text-sm"
                :style="{ borderColor: 'var(--color-ambar)', color: 'var(--color-ambar)' }"
            >
                <strong>Ninguna regla está encendida todavía.</strong> Están escritas pero no se
                evalúan, así que no se está levantando ninguna señal. Revisa sus umbrales y
                enciende las que correspondan a tu reglamento.
            </p>
        </section>

        <div class="mb-4 flex items-center justify-between gap-3">
            <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                {{ reglas.length }} regla{{ reglas.length === 1 ? '' : 's' }} ·
                {{ encendidas }} encendida{{ encendidas === 1 ? '' : 's' }}
            </p>
            <BotonPrincipal v-if="puedeEditar" texto="Nueva regla" icono="crear" type="button" @click="nueva" />
        </div>

        <div class="space-y-3">
            <article v-for="regla in reglas" :key="regla.id" class="tarjeta p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold">{{ regla.nombre }}</h3>
                            <PildoraEstado
                                v-if="regla.categoria"
                                :texto="regla.categoria.nombre"
                                :color="colorPermanencia(regla.categoria.color)"
                            />
                            <PildoraEstado v-if="regla.categoria?.sensible" texto="Reservada" :color="colorPermanencia('rosa')" />
                            <!--
                                La tasa de descarte va AQUÍ y no en un reporte
                                aparte: quien calibra el umbral tiene que verla
                                en la misma pantalla donde lo cambia. Escondida,
                                no la mira nadie hasta que ya nadie cree en la
                                bandeja.
                            -->
                            <PildoraEstado
                                v-if="regla.calibracion?.suficientes"
                                :texto="`${regla.calibracion.proporcion} % se descarta`"
                                :color="colorPermanencia(regla.calibracion.preocupa ? 'ambar' : 'gris')"
                                sin-capitalizar
                            />
                            <PildoraEstado
                                :texto="regla.activa ? 'Encendida' : 'Apagada'"
                                :color="colorPermanencia(regla.activa ? 'verde' : 'gris')"
                            />
                        </div>

                        <p v-if="regla.descripcion" class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                            {{ regla.descripcion }}
                        </p>

                        <p class="mt-2 text-sm">
                            <span :style="{ color: 'var(--color-suave)' }">Mide:</span>
                            <code class="ml-1 text-xs">{{ regla.versiones.find((v) => v.rige)?.condicion ?? '—' }}</code>
                        </p>
                        <p class="text-sm">
                            <span :style="{ color: 'var(--color-suave)' }">Alcanza:</span>
                            {{ regla.alcance }}
                        </p>

                        <p
                            v-if="regla.sin_version_vigente"
                            class="mt-2 text-sm"
                            :style="{ color: 'var(--color-ambar)' }"
                        >
                            No tiene ninguna versión vigente hoy, así que no mide nada aunque se encienda.
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <label v-if="puedeEditar" class="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                class="h-4 w-4"
                                :checked="regla.activa"
                                @change="alternar(regla)"
                            />
                            <span>Encendida</span>
                        </label>
                        <MenuAcciones v-if="puedeEditar" :opciones="acciones(regla)" @elegir="(c) => elegir(regla, c)" />
                    </div>
                </div>

                <button
                    type="button"
                    class="mt-3 text-xs underline"
                    :style="{ color: 'var(--color-suave)' }"
                    @click="abierta = abierta === regla.id ? null : regla.id"
                >
                    {{ abierta === regla.id ? 'Ocultar' : 'Ver' }} las
                    {{ regla.versiones.length }} versión{{ regla.versiones.length === 1 ? '' : 'es' }}
                </button>

                <div v-if="abierta === regla.id" class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[46rem] text-sm">
                        <thead>
                            <tr class="border-b border-borde text-left">
                                <th class="py-1 pr-3">v</th>
                                <th class="py-1 pr-3">Desde</th>
                                <th class="py-1 pr-3">Condición</th>
                                <th class="py-1 pr-3">Cobertura</th>
                                <th class="py-1 pr-3">Severidad</th>
                                <th class="py-1 pr-3">Enfriamiento</th>
                                <th class="py-1">Avisa</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="v in regla.versiones" :key="v.id" class="border-b border-borde/50">
                                <td class="py-1 pr-3">
                                    {{ v.version }}
                                    <PildoraEstado v-if="v.rige" texto="Rige hoy" color="verde" />
                                </td>
                                <td class="py-1 pr-3">{{ v.vigente_desde }}</td>
                                <td class="py-1 pr-3"><code class="text-xs">{{ v.condicion }}</code></td>
                                <td class="py-1 pr-3">{{ v.cobertura_minima }}</td>
                                <td class="py-1 pr-3">
                                    <PildoraEstado :texto="v.severidad" :color="colorPermanencia(COLOR_SEVERIDAD[v.severidad])" />
                                </td>
                                <td class="py-1 pr-3">{{ v.cooldown_dias }} d</td>
                                <td class="py-1">
                                    <span v-if="!v.avisa_al_alumno && !v.avisa_a_la_escuela">a nadie</span>
                                    <span v-else>
                                        {{ [v.avisa_al_alumno ? 'al alumno' : null, v.avisa_a_la_escuela ? 'a la escuela' : null].filter(Boolean).join(' y ') }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </article>

            <p v-if="reglas.length === 0" class="tarjeta p-6 text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay ninguna regla. Crea la primera con lo que tu reglamento ya exige
                —el porcentaje mínimo de asistencia suele ser el más claro— y déjala apagada hasta
                que hayas visto qué levanta.
            </p>
        </div>

        <!-- ── Alta y edición del alcance ────────────────────────────────── -->
        <Modal
            v-if="editando"
            etiqueta="Regla de alerta"
            ancho="max-w-3xl"
            :formulario="true"
            @cerrar="cerrar"
        >
            <template #default>
                <form class="space-y-4 p-6" @submit.prevent="guardar">
                    <h2 class="text-base font-semibold">
                        {{ editando === 'nueva' ? 'Nueva regla' : 'Editar el alcance' }}
                    </h2>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto v-model="datos.nombre" etiqueta="Nombre" :error="errores.nombre" requerido />
                        <CampoSelect
                            v-model="datos.categoria_id"
                            etiqueta="Categoría"
                            :opciones="comoOpciones(lista('categorias'))"
                            :error="errores.categoria_id"
                            requerido
                        />
                    </div>

                    <CampoTextarea
                        v-model="datos.descripcion"
                        etiqueta="Descripción"
                        :filas="2"
                        ayuda="Qué significa esta señal para quien la reciba. Se lee en la alerta."
                        :error="errores.descripcion"
                    />

                    <CampoSelect
                        v-model="datos.metrica"
                        etiqueta="Qué se mide"
                        :opciones="metricas.map((m) => ({ valor: m.clave, texto: m.etiqueta + ' · ' + m.proveedor }))"
                        :error="errores.metrica"
                        :deshabilitado="editando !== 'nueva'"
                        requerido
                    />
                    <p v-if="metricaElegida" class="-mt-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ metricaElegida.descripcion }}
                        <span class="block">Cobertura: {{ metricaElegida.cobertura }}.</span>
                    </p>
                    <p v-if="editando !== 'nueva'" class="-mt-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                        Lo que se mide no se cambia: emite una versión nueva para eso.
                    </p>

                    <fieldset class="rounded-lg border border-borde p-4">
                        <legend class="px-1 text-sm font-medium">A quién alcanza</legend>
                        <p class="mb-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                            Lo que dejes en blanco no acota. Sin nada elegido, la regla es de toda la
                            escuela.
                        </p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <CampoSelect v-model="datos.campus_id" etiqueta="Campus" :opciones="comoOpciones(lista('campus'))" :error="errores.campus_id" />
                            <CampoSelect v-model="datos.nivel_estudios_id" etiqueta="Nivel" :opciones="comoOpciones(lista('niveles'))" :error="errores.nivel_estudios_id" />
                            <CampoSelect v-model="datos.programa_academico_id" etiqueta="Programa" :opciones="comoOpciones(lista('programas'))" :error="errores.programa_academico_id" />
                            <CampoSelect v-model="datos.plan_id" etiqueta="Plan" :opciones="comoOpciones(lista('planes'))" :error="errores.plan_id" />
                            <CampoSelect v-model="datos.ciclo_id" etiqueta="Ciclo" :opciones="comoOpciones(lista('ciclos'), 'clave')" :error="errores.ciclo_id" />
                            <CampoSelect v-model="datos.situacion_alumno_id" etiqueta="Situación del alumno" :opciones="comoOpciones(lista('situaciones'))" :error="errores.situacion_alumno_id" />
                            <CampoTexto v-model.number="datos.generacion_desde" etiqueta="Generación desde" tipo="number" paso="1" :error="errores.generacion_desde" />
                            <CampoTexto v-model.number="datos.generacion_hasta" etiqueta="Generación hasta" tipo="number" paso="1" :error="errores.generacion_hasta" />
                        </div>
                    </fieldset>

                    <fieldset v-if="editando === 'nueva'" class="rounded-lg border border-borde p-4">
                        <legend class="px-1 text-sm font-medium">Cuándo se levanta</legend>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <CampoSelect
                                v-model="datos.comparador"
                                etiqueta="Comparador"
                                :opciones="textos('comparadores').map((c) => ({ valor: c, texto: c }))"
                                :error="errores.comparador"
                                requerido
                            />
                            <CampoTexto v-model.number="datos.umbral" etiqueta="Umbral" tipo="number" :error="errores.umbral" />
                            <CampoTexto
                                v-model.number="datos.cobertura_minima"
                                etiqueta="Cobertura mínima"
                                tipo="number"
                                paso="1"
                                ayuda="Cuántos datos hacen falta para opinar."
                                :error="errores.cobertura_minima"
                                requerido
                            />
                        </div>
                        <p v-if="comparadorAlReves" class="mt-2 text-sm" :style="{ color: 'var(--color-ambar)' }">
                            Ese comparador mira al lado contrario del problema: en «{{ metricaElegida?.etiqueta }}»
                            lo preocupante es que
                            {{ metricaElegida?.direccion === 'sube' ? 'suba' : 'baje' }}.
                            Se puede guardar así —hay reglas legítimas al revés— pero comprueba que sea
                            lo que quieres.
                        </p>

                        <div class="mt-4 grid gap-4 sm:grid-cols-3">
                            <CampoSelect
                                v-model="datos.ventana_tipo"
                                etiqueta="Ventana"
                                :opciones="textos('ventanas').map((v) => ({ valor: v, texto: v }))"
                                :error="errores.ventana_tipo"
                                requerido
                            />
                            <CampoTexto
                                v-if="datos.ventana_tipo === 'ultimos_dias'"
                                v-model.number="datos.ventana_valor"
                                etiqueta="Días"
                                tipo="number"
                                paso="1"
                                :error="errores.ventana_valor"
                            />
                            <CampoSelect
                                v-model="datos.severidad"
                                etiqueta="Severidad"
                                :opciones="textos('severidades').map((s) => ({ valor: s, texto: s }))"
                                :error="errores.severidad"
                                requerido
                            />
                        </div>

                        <div class="mt-4 grid gap-4 sm:grid-cols-3">
                            <CampoTexto v-model.number="datos.peso" etiqueta="Peso" tipo="number" paso="1" ayuda="Cuánto aporta al riesgo compuesto." :error="errores.peso" requerido />
                            <CampoTexto v-model.number="datos.cooldown_dias" etiqueta="Enfriamiento (días)" tipo="number" paso="1" ayuda="Tras cerrarse, cuánto no se vuelve a levantar." :error="errores.cooldown_dias" requerido />
                            <CampoTexto v-model.number="datos.sla_horas" etiqueta="SLA (horas)" tipo="number" paso="1" ayuda="En cuánto se espera el primer contacto." :error="errores.sla_horas" />
                        </div>

                        <p class="mt-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                            La regla nace <strong>apagada</strong> y sin avisar a nadie. Mira unas semanas
                            qué levanta, calibra el umbral, y sólo entonces enciende los avisos.
                        </p>
                    </fieldset>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="procesando" :texto="editando === 'nueva' ? 'Crear apagada' : 'Guardar'" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>

        <!-- ── Emitir una versión ────────────────────────────────────────── -->
        <Modal
            v-if="versionando"
            etiqueta="Nueva versión"
            ancho="max-w-2xl"
            :formulario="true"
            @cerrar="cerrar"
        >
            <template #default>
                <form class="space-y-4 p-6" @submit.prevent="guardar">
                    <h2 class="text-base font-semibold">Nueva versión de «{{ versionando.nombre }}»</h2>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        La versión que rige hoy se cierra el día anterior. <strong>Las alertas ya
                        abiertas conservan la suya</strong>, así que se podrá seguir explicando con
                        qué umbral se levantaron.
                    </p>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <CampoTexto v-model="datos.vigente_desde" etiqueta="Vigente desde" tipo="date" :error="errores.vigente_desde" requerido />
                        <CampoSelect
                            v-model="datos.comparador"
                            etiqueta="Comparador"
                            :opciones="textos('comparadores').map((c) => ({ valor: c, texto: c }))"
                            :error="errores.comparador"
                            requerido
                        />
                        <CampoTexto v-model.number="datos.umbral" etiqueta="Umbral" tipo="number" :error="errores.umbral" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <CampoTexto v-model.number="datos.cobertura_minima" etiqueta="Cobertura mínima" tipo="number" paso="1" :error="errores.cobertura_minima" requerido />
                        <CampoSelect
                            v-model="datos.severidad"
                            etiqueta="Severidad"
                            :opciones="textos('severidades').map((s) => ({ valor: s, texto: s }))"
                            :error="errores.severidad"
                            requerido
                        />
                        <CampoTexto v-model.number="datos.cooldown_dias" etiqueta="Enfriamiento (días)" tipo="number" paso="1" :error="errores.cooldown_dias" requerido />
                    </div>

                    <!--
                        A quién se avisa. Iba en la tabla de versiones y no había
                        dónde ponerlo: los tres campos se validaban, se guardaban
                        y el formulario nunca los ofreció, así que estaban
                        apagados para siempre.
                    -->
                    <fieldset class="rounded-lg border border-borde p-4">
                        <legend class="px-1 text-sm font-medium">A quién se le avisa</legend>

                        <p class="mb-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                            Los avisos salen una vez al día, a la hora que la escuela fije en su
                            configuración. Al <strong>alumno</strong> sólo se le avisa de señales ya
                            validadas: de una sin revisar podría descartarse mañana. A la
                            <strong>escuela</strong> se le avisa de lo que espera en la bandeja, sin
                            el dato — ese aviso va a un rol entero.
                        </p>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <CampoCheckbox
                                v-model="datos.avisa_al_alumno"
                                etiqueta="Avisarle al alumno"
                                ayuda="Cuando la señal se valide."
                            />
                            <CampoCheckbox
                                v-model="datos.avisa_a_la_escuela"
                                etiqueta="Avisarle a la escuela"
                                ayuda="A quien puede validar señales."
                            />
                        </div>

                        <CampoTextarea
                            v-model="datos.plantilla_aviso"
                            class="mt-3"
                            etiqueta="Qué dice el aviso al alumno"
                            :filas="2"
                            :maximo="500"
                            marcador="Tu asistencia en {materia} va en {valor} % y se pide {umbral} %."
                            :error="errores.plantilla_aviso"
                        />

                        <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                            Se sustituyen <code>{alumno}</code>, <code>{materia}</code>,
                            <code>{regla}</code>, <code>{valor}</code> y <code>{umbral}</code>.
                            Cualquier otra cosa entre llaves se deja tal cual, para que se note.
                            Sin texto sale uno de respaldo que nombra la regla y remite al portal.
                        </p>
                    </fieldset>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="procesando" texto="Emitir la versión" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
