<script setup lang="ts">
/**
 * La bandeja de alertas: lo que el motor levantó y espera que alguien mire.
 *
 * ── Lo que esta pantalla tiene que dejar claro ────────────────────────────
 *  1. **Cuándo corrió el motor.** Una cola vacía significa cosas distintas si
 *     evaluó esta madrugada o si lleva nueve días sin correr. Sin ese dato,
 *     «no hay alertas» se lee como «no hay riesgo», que es el peor error que
 *     este módulo puede inducir.
 *  2. **Cuántas se descartan.** Una cola que se descarta entera no es una cola:
 *     es ruido, y quien la mira todos los días tiene que verlo antes de
 *     acostumbrarse a ignorarla.
 *  3. **Que una señal NO es una sanción.** Se dice arriba y con esas palabras.
 *
 * ── Lenguaje ─────────────────────────────────────────────────────────────
 * Nada de etiquetas que describan a la persona en vez de a la situación. Se
 * dice «requiere revisión», «señal de seguimiento», «apoyo recomendado». Una
 * prueba barre las cadenas del módulo contra una lista negra, y esta misma
 * nota tuvo que reescribirse porque la contenía al citarla.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import Paginacion from '@/Components/Paginacion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Alerta {
    id: number;
    alumno: string | null;
    matricula: string | null;
    campus: string | null;
    programa: string | null;
    materia: string | null;
    categoria: { id: number; clave: string; nombre: string; color: string; sensible: boolean } | null;
    severidad: string;
    estado_senal: string;
    estado_triage: string;
    regla: string | null;
    primera_vez_en: string | null;
    ultima_evaluacion_en: string | null;
    reservada: boolean;
    motivo?: string;
    valor_observado?: number | null;
    umbral?: number | null;
    cobertura?: number;
    condicion?: string | null;
}

const props = defineProps<{
    alertas: {
        data: Alerta[];
        links: { url: string | null; label: string; active: boolean }[];
        total?: number;
        from?: number | null;
        to?: number | null;
    };
    resumen: {
        por_revisar: number;
        validadas: number;
        por_severidad: Record<string, number>;
        por_categoria: Record<string, number>;
        descartadas_30_dias: number;
    };
    catalogos: Record<string, Array<Record<string, unknown>> | string[]>;
    filtros: Record<string, string | null>;
    ultimaCorrida: {
        cuando: string;
        hace_dias: number;
        alumnos: number;
        reglas: number;
        sin_datos: number;
        con_errores: boolean;
    } | null;
    puedeValidar: boolean;
}>();

const severidades: Record<string, string> = {
    informativo: 'gris',
    bajo: 'azul',
    medio: 'ambar',
    alto: 'naranja',
    critico: 'rojo',
};

const filtros = ref({ ...props.filtros });
const elegidas = ref<number[]>([]);
const descartando = ref(false);
const motivo = ref<number | null>(null);
const nota = ref('');
const procesando = ref(false);

const lista = (clave: string) => (props.catalogos[clave] ?? []) as Array<Record<string, unknown>>;
const textos = (clave: string) => (props.catalogos[clave] ?? []) as string[];

const comoOpciones = (filas: Array<Record<string, unknown>>) =>
    filas.map((f) => ({ valor: f.id as number, texto: String(f.nombre ?? '') }));

/*
 * El motor lleva sin correr más de dos días. Es el aviso que impide leer una
 * cola vacía como ausencia de riesgo: dos días es un fin de semana, tres ya es
 * que algo se rompió.
 */
const corridaVieja = computed(() => (props.ultimaCorrida?.hace_dias ?? 99) > 2);

function filtrar(): void {
    router.get('/permanencia/alertas', filtros.value, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function limpiar(): void {
    filtros.value = {};
    filtrar();
}

function alternar(id: number): void {
    elegidas.value = elegidas.value.includes(id)
        ? elegidas.value.filter((x) => x !== id)
        : [...elegidas.value, id];
}

function descartarVarias(): void {
    procesando.value = true;

    router.post(
        '/permanencia/alertas/descartar-varias',
        { alertas: elegidas.value, motivo_descarte_id: motivo.value, nota: nota.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                elegidas.value = [];
                descartando.value = false;
                motivo.value = null;
                nota.value = '';
            },
            onFinish: () => (procesando.value = false),
        },
    );
}
</script>

<template>
    <Head title="Alertas" />

    <AppLayout titulo="Alertas">
        <section class="tarjeta mb-4 p-5">
            <h2 class="font-semibold">Señales que requieren revisión</h2>
            <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Cada renglón es algo que una regla midió y que cruzó su umbral.
                <strong>Una señal no es una sanción ni una baja</strong>: es una revisión pendiente.
                Al abrirla verás exactamente qué se contó y con qué regla, para que puedas validarla
                o descartarla con conocimiento.
            </p>

            <!--
                Cuándo corrió el motor. Va arriba porque una cola vacía significa
                cosas distintas según la respuesta.
            -->
            <p
                v-if="ultimaCorrida"
                class="mt-3 text-sm"
                :style="{ color: corridaVieja || ultimaCorrida.con_errores ? 'var(--color-ambar)' : 'var(--color-suave)' }"
            >
                <template v-if="corridaVieja">
                    <strong>El motor no ha evaluado desde hace {{ ultimaCorrida.hace_dias }} días</strong>
                    ({{ ultimaCorrida.cuando }}). Lo que ves puede estar desactualizado.
                </template>
                <template v-else>
                    Última evaluación: {{ ultimaCorrida.cuando }} · {{ ultimaCorrida.alumnos }} alumnos ·
                    {{ ultimaCorrida.reglas }} reglas · {{ ultimaCorrida.sin_datos }} mediciones sin datos
                    suficientes.
                </template>
                <span v-if="ultimaCorrida.con_errores">
                    <strong>Alguna regla falló en la última corrida.</strong>
                </span>
            </p>
            <p v-else class="mt-3 text-sm" :style="{ color: 'var(--color-ambar)' }">
                <strong>El motor todavía no ha corrido nunca.</strong> Hasta que lo haga, esta bandeja
                estará vacía aunque haya reglas encendidas.
            </p>
        </section>

        <!-- ── Las cifras ────────────────────────────────────────────────── -->
        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="tarjeta p-4">
                <p class="text-2xl font-semibold">{{ resumen.por_revisar }}</p>
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">requieren revisión</p>
            </div>
            <div class="tarjeta p-4">
                <p class="text-2xl font-semibold">{{ resumen.validadas }}</p>
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">validadas, en seguimiento</p>
            </div>
            <div class="tarjeta p-4">
                <p class="text-2xl font-semibold">{{ resumen.por_severidad.critico ?? 0 }}</p>
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">de atención inmediata</p>
            </div>
            <div class="tarjeta p-4">
                <p class="text-2xl font-semibold">{{ resumen.descartadas_30_dias }}</p>
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    descartadas en 30 días
                    <span class="block text-xs">Si son muchas, hay una regla mal calibrada.</span>
                </p>
            </div>
        </div>

        <!-- ── Filtros ───────────────────────────────────────────────────── -->
        <section class="tarjeta mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <CampoTexto
                    v-model="filtros.busqueda"
                    etiqueta="Alumno o matrícula"
                    marcador="Buscar…"
                    @keyup.enter="filtrar"
                />
                <CampoSelect
                    v-model="filtros.categoria_id"
                    etiqueta="Categoría"
                    :opciones="comoOpciones(lista('categorias'))"
                    vacio="Todas"
                />
                <CampoSelect
                    v-model="filtros.severidad"
                    etiqueta="Severidad"
                    :opciones="textos('severidades').map((x) => ({ valor: x, texto: x }))"
                    vacio="Todas"
                />
                <CampoSelect
                    v-model="filtros.campus_id"
                    etiqueta="Campus"
                    :opciones="comoOpciones(lista('campus'))"
                    vacio="Todos"
                />
                <CampoSelect
                    v-model="filtros.regla_id"
                    etiqueta="Regla"
                    :opciones="comoOpciones(lista('reglas'))"
                    vacio="Todas"
                />
                <CampoSelect
                    v-model="filtros.estado_triage"
                    etiqueta="Revisión"
                    :opciones="[
                        { valor: 'nueva', texto: 'Requieren revisión' },
                        { valor: 'validada', texto: 'Validadas' },
                        { valor: 'descartada', texto: 'Descartadas' },
                    ]"
                    vacio="Requieren revisión"
                />
                <CampoSelect
                    v-model="filtros.estado_senal"
                    etiqueta="Estado de la señal"
                    :opciones="[
                        { valor: 'activa', texto: 'Sigue siendo cierta' },
                        { valor: 'resuelta', texto: 'La situación mejoró' },
                        { valor: 'obsoleta', texto: 'Se dejó de vigilar' },
                    ]"
                    vacio="Sólo las que siguen ciertas"
                />
                <div class="flex items-end gap-2">
                    <BotonPrincipal texto="Filtrar" icono="ninguno" tipo="button" @click="filtrar" />
                    <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="limpiar">
                        Limpiar
                    </button>
                </div>
            </div>
        </section>

        <!-- ── Acción masiva ─────────────────────────────────────────────── -->
        <div
            v-if="puedeValidar && elegidas.length > 0"
            class="tarjeta mb-3 flex flex-wrap items-center justify-between gap-3 p-4"
        >
            <p class="text-sm">
                <strong>{{ elegidas.length }}</strong> seleccionada{{ elegidas.length === 1 ? '' : 's' }}
            </p>
            <div class="flex items-center gap-2">
                <BotonPrincipal texto="Descartar las seleccionadas" icono="ninguno" tipo="button" @click="descartando = true" />
                <button type="button" class="rounded-lg border border-borde px-3 py-2 text-sm" @click="elegidas = []">
                    Quitar la selección
                </button>
            </div>
        </div>

        <!-- ── La cola ───────────────────────────────────────────────────── -->
        <div class="tarjeta overflow-x-auto">
            <table class="w-full min-w-[62rem] text-sm">
                <thead>
                    <tr class="border-b border-borde text-left">
                        <th v-if="puedeValidar" class="w-8 p-3"></th>
                        <th class="p-3">Alumno</th>
                        <th class="p-3">Señal</th>
                        <th class="p-3">Qué se observó</th>
                        <th class="p-3">Desde</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in alertas.data" :key="a.id" class="border-b border-borde/50">
                        <td v-if="puedeValidar" class="p-3">
                            <input
                                type="checkbox"
                                class="h-4 w-4"
                                :checked="elegidas.includes(a.id)"
                                :disabled="a.estado_triage !== 'nueva'"
                                @change="alternar(a.id)"
                            />
                        </td>
                        <td class="p-3">
                            <p class="font-medium">{{ a.alumno }}</p>
                            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ a.matricula }} · {{ a.programa }}
                                <span v-if="a.campus"> · {{ a.campus }}</span>
                            </p>
                        </td>
                        <td class="p-3">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <PildoraEstado
                                    v-if="a.categoria"
                                    :texto="a.categoria.nombre"
                                    :color="a.categoria.color"
                                />
                                <PildoraEstado :texto="a.severidad" :color="severidades[a.severidad] ?? 'gris'" />
                                <PildoraEstado
                                    v-if="a.estado_triage === 'validada'"
                                    texto="Validada"
                                    color="verde"
                                />
                                <PildoraEstado
                                    v-if="a.estado_senal === 'resuelta'"
                                    texto="Ya mejoró"
                                    color="verde"
                                />
                                <PildoraEstado
                                    v-if="a.estado_senal === 'obsoleta'"
                                    texto="Se dejó de vigilar"
                                    color="gris"
                                />
                            </div>
                            <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ a.regla }}<span v-if="a.materia"> · {{ a.materia }}</span>
                            </p>
                        </td>
                        <td class="p-3">
                            <template v-if="a.reservada">
                                <span :style="{ color: 'var(--color-suave)' }">Reservado</span>
                                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ a.motivo }}</p>
                            </template>
                            <template v-else>
                                <span class="font-medium">{{ a.valor_observado }}</span>
                                <span :style="{ color: 'var(--color-suave)' }"> · umbral {{ a.umbral }}</span>
                                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    sobre {{ a.cobertura }} dato{{ a.cobertura === 1 ? '' : 's' }}
                                </p>
                            </template>
                        </td>
                        <td class="p-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ a.primera_vez_en }}
                        </td>
                        <td class="p-3">
                            <Link
                                :href="`/permanencia/alertas/${a.id}`"
                                class="text-sm underline"
                            >Abrir</Link>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-if="alertas.data.length === 0" class="p-6 text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay señales que revisar con estos filtros.
                <span v-if="!ultimaCorrida || corridaVieja">
                    Ojo: el motor no ha evaluado recientemente, así que esto no significa que no haya nada.
                </span>
            </p>
        </div>

        <Paginacion
            :enlaces="alertas.links ?? []"
            :total="alertas.total"
            :desde="alertas.from"
            :hasta="alertas.to"
            class="mt-4"
        />

        <!-- ── Descartar varias ──────────────────────────────────────────── -->
        <Modal v-if="descartando" etiqueta="Descartar" ancho="max-w-lg" :formulario="true" @cerrar="descartando = false">
            <template #default>
                <form class="space-y-4 p-6" @submit.prevent="descartarVarias">
                    <h2 class="text-base font-semibold">
                        Descartar {{ elegidas.length }} señal{{ elegidas.length === 1 ? '' : 'es' }}
                    </h2>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        El motivo no es un trámite: es lo que permite saber si una regla está mal
                        calibrada. Elige el que de verdad corresponda.
                    </p>

                    <CampoSelect
                        v-model="motivo"
                        etiqueta="Motivo"
                        :opciones="comoOpciones(lista('motivos'))"
                        requerido
                    />

                    <CampoTextarea v-model="nota" etiqueta="Nota" :filas="2" ayuda="Opcional." />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="procesando" :deshabilitado="!motivo" texto="Descartar" icono="ninguno" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="descartando = false">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
