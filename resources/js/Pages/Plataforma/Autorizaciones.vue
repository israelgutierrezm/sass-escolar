<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BuscadorRemoto from '@/Components/BuscadorRemoto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

/**
 * Lo que la escuela le pide autorizar a las familias.
 *
 * Se eligen ALUMNOS y el servidor crea una fila por cada familiar vinculado a
 * cada uno: quien autoriza es una persona concreta, y con «los padres» en
 * abstracto no se sabría quién respondió.
 */
interface Emision {
    titulo: string;
    tipo: string | null;
    fecha_limite: string | null;
    emitida_en: string;
    total: number;
    concedidas: number;
    negadas: number;
    pendientes: number;
}

const props = defineProps<{
    emisiones: Emision[];
    tipos: { id: number; nombre: string; descripcion: string | null }[];
}>();

const elegidos = ref<{ id: number; nombre: string; matricula: string | null }[]>([]);

const form = useForm({
    tipo_autorizacion_id: null as number | null,
    titulo: '',
    detalle: '',
    fecha_limite: '',
    alumnos: [] as number[],
});

const tipoElegido = computed(() => props.tipos.find((t) => t.id === form.tipo_autorizacion_id));

function agregar(alumno: { id: number; nombre: string; matricula: string | null }): void {
    if (elegidos.value.some((e) => e.id === alumno.id)) return;

    elegidos.value.push(alumno);
}

function quitar(id: number): void {
    elegidos.value = elegidos.value.filter((e) => e.id !== id);
}

function emitir(): void {
    form.alumnos = elegidos.value.map((e) => e.id);

    form.post('/plataforma/autorizaciones', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            elegidos.value = [];
        },
    });
}

/** Cuánto se ha contestado, para la barra de cada emisión. */
function avance(e: Emision): number {
    return e.total === 0 ? 0 : Math.round(((e.concedidas + e.negadas) / e.total) * 100);
}
</script>

<template>
    <Head title="Autorizaciones" />

    <AppLayout titulo="Autorizaciones">
        <p class="mb-4 max-w-2xl text-sm" :style="{ color: 'var(--color-suave)' }">
            Pídele a las familias que autoricen una salida, el uso de imagen o una
            actividad. Se manda a los familiares vinculados de cada alumno que elijas.
        </p>

        <TarjetaSeccion titulo="Pedir una autorización" class="mb-4">
            <form class="space-y-4" @submit.prevent="emitir">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-4">
                        <CampoSelect
                            v-model="form.tipo_autorizacion_id"
                            etiqueta="Tipo"
                            :opciones="tipos.map((t) => ({ valor: t.id, texto: t.nombre }))"
                            vacio="Selecciona…"
                            :error="form.errors.tipo_autorizacion_id"
                            :ayuda="tipoElegido?.descripcion ?? undefined"
                        />
                        <CampoTexto
                            v-model="form.titulo"
                            etiqueta="Qué se autoriza"
                            requerido
                            :error="form.errors.titulo"
                            ayuda="Lo que el padre va a leer. Ej.: «Visita al Museo de Antropología, 5 de octubre»."
                        />
                        <CampoTexto
                            v-model="form.fecha_limite"
                            etiqueta="Fecha límite"
                            tipo="date"
                            :error="form.errors.fecha_limite"
                            ayuda="Déjala vacía si el permiso no vence (uso de imagen)."
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Alumnos</label>
                        <!--
                            El MISMO buscador de destinatarios que usan avisos,
                            encuestas y el calendario. Un segundo buscador sería
                            otra forma de contestar la misma pregunta.
                        -->
                        <BuscadorRemoto
                            url="/buscar/alumnos"
                            etiqueta=""
                            marcador="Busca por nombre o matrícula…"
                            class="mt-1"
                            @elegido="agregar"
                        />
                        <p v-if="form.errors.alumnos" class="mt-1 text-xs text-red-600">{{ form.errors.alumnos }}</p>

                        <ul v-if="elegidos.length" class="mt-2 flex flex-wrap gap-2">
                            <li
                                v-for="a in elegidos"
                                :key="a.id"
                                class="flex items-center gap-2 rounded-full border px-3 py-1 text-xs"
                                :style="{ borderColor: 'var(--color-borde)' }"
                            >
                                {{ a.nombre }}
                                <button type="button" class="font-bold" @click="quitar(a.id)">×</button>
                            </li>
                        </ul>
                        <p v-else class="mt-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                            Todavía no eliges a nadie.
                        </p>
                    </div>
                </div>

                <CampoTexto
                    v-model="form.detalle"
                    etiqueta="Detalle"
                    :error="form.errors.detalle"
                    ayuda="Opcional: hora de salida y regreso, costo, qué llevar."
                />

                <BotonPrincipal
                    :procesando="form.processing"
                    :deshabilitado="!form.tipo_autorizacion_id || !form.titulo || elegidos.length === 0"
                    texto="Pedir autorización"
                    cargando="Enviando…"
                    icono="ninguno"
                />
            </form>
        </TarjetaSeccion>

        <TarjetaSeccion titulo="Lo que se ha pedido" sin-relleno>
            <ul v-if="emisiones.length">
                <li
                    v-for="(e, i) in emisiones"
                    :key="i"
                    class="border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <span class="font-medium">{{ e.titulo }}</span>
                        <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ e.tipo }}<span v-if="e.fecha_limite"> · hasta el {{ e.fecha_limite }}</span>
                        </span>
                    </div>

                    <!--
                        Los tres números, y no sólo «cuántas faltan»: quien mira
                        esto necesita saber si le NEGARON algo, que es distinto
                        de que no hayan contestado.
                    -->
                    <p class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ e.concedidas }} autorizaron · {{ e.negadas }} no autorizaron ·
                        <span :class="e.pendientes > 0 ? 'font-medium text-amber-700' : ''">
                            {{ e.pendientes }} sin contestar
                        </span>
                        de {{ e.total }}
                    </p>

                    <div class="mt-1 h-1.5 w-full rounded-full" :style="{ backgroundColor: 'var(--color-borde)' }">
                        <div
                            class="h-1.5 rounded-full"
                            :style="{ width: avance(e) + '%', backgroundColor: 'var(--color-acento)' }"
                        ></div>
                    </div>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no has pedido ninguna autorización.
            </p>
        </TarjetaSeccion>
    </AppLayout>
</template>
