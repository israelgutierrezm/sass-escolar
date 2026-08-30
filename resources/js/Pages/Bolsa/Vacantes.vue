<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import Paginacion from '@/Components/Paginacion.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

/** El tablero de vacantes desde el lado de la escuela. */
interface Vacante {
    id: number;
    titulo: string;
    empresa: string | null;
    modalidad: string | null;
    jornada: string | null;
    situacion: string | null;
    situacion_clave: string | null;
    vencida: boolean;
    fecha_cierre: string | null;
    vacantes_disponibles: number;
    programas_academicos: string[];
    salario: string | null;
}

const props = defineProps<{
    vacantes: { data: Vacante[]; links: any[]; total: number; from: number | null; to: number | null };
    filtros: { busqueda: string; empresa_id: number | null; situacion_id: number | null };
    catalogos: Record<string, { id: number; nombre?: string; razon_social?: string }[]>;
}>();

const busqueda = ref(props.filtros.busqueda);
const empresaId = ref(props.filtros.empresa_id);
const situacionId = ref(props.filtros.situacion_id);

function filtrar(): void {
    router.get(
        '/bolsa/vacantes',
        { busqueda: busqueda.value, empresa_id: empresaId.value, situacion_id: situacionId.value },
        { preserveState: true, replace: true },
    );
}

/**
 * Una vacante vencida no está «abierta» aunque su situación lo diga.
 *
 * Es la trampa de este tablero: alguien publica con fecha de cierre, se le pasa
 * y la lista la sigue enseñando en verde. El color habla del estado REAL.
 */
function colorDe(v: Vacante): string {
    if (v.vencida || v.situacion_clave === 'cerrada') return '#dc2626';
    if (v.situacion_clave === 'abierta') return '#16a34a';

    return '#f59e0b';
}

function etiquetaDe(v: Vacante): string {
    return v.vencida && v.situacion_clave === 'abierta' ? 'Venció' : (v.situacion ?? '—');
}
</script>

<template>
    <Head title="Vacantes" />

    <AppLayout titulo="Vacantes">
        <div class="mb-4 grid gap-3 sm:grid-cols-4">
            <input
                v-model="busqueda"
                type="search"
                placeholder="Título de la vacante…"
                class="rounded-lg border px-3 py-2 text-sm sm:col-span-2"
                :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'transparent' }"
                @keyup.enter="filtrar"
            />
            <CampoSelect
                v-model="empresaId"
                etiqueta=""
                :opciones="(catalogos.empresas ?? []).map((e) => ({ valor: e.id, texto: e.razon_social ?? '' }))"
                vacio="Todas las empresas"
                @update:model-value="filtrar"
            />
            <CampoSelect
                v-model="situacionId"
                etiqueta=""
                :opciones="(catalogos.situaciones ?? []).map((s) => ({ valor: s.id, texto: s.nombre ?? '' }))"
                vacio="Cualquier situación"
                @update:model-value="filtrar"
            />
        </div>

        <div class="mb-3 flex justify-end">
            <Link
                href="/bolsa/vacantes/nueva"
                class="rounded-lg px-4 py-2 text-sm font-medium"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
            >
                Nueva vacante
            </Link>
        </div>

        <TarjetaSeccion titulo="Vacantes publicadas" sin-relleno>
            <ul v-if="vacantes.data.length">
                <li
                    v-for="v in vacantes.data"
                    :key="v.id"
                    class="flex flex-wrap items-start justify-between gap-3 border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="min-w-0">
                        <Link :href="`/bolsa/vacantes/${v.id}`" class="font-medium" :style="{ color: 'var(--color-acento)' }">
                            {{ v.titulo }}
                        </Link>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ v.empresa }}
                            <span v-if="v.modalidad"> · {{ v.modalidad }}</span>
                            <span v-if="v.jornada"> · {{ v.jornada }}</span>
                            <span v-if="v.salario"> · {{ v.salario }}</span>
                            <span v-if="v.vacantes_disponibles > 1"> · {{ v.vacantes_disponibles }} plazas</span>
                        </p>
                        <!--
                            Sin programas académicos señaladas NO es un dato faltante: es
                            «para todas». Se dice con palabras porque un hueco se
                            lee como captura incompleta.
                        -->
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            <template v-if="v.programas_academicos.length">Para {{ v.programas_academicos.join(', ') }}</template>
                            <template v-else>Abierta a todos los programas académicos</template>
                            <span v-if="v.fecha_cierre"> · cierra el {{ v.fecha_cierre }}</span>
                        </p>
                    </div>

                    <span
                        class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium"
                        :style="{
                            backgroundColor: `color-mix(in srgb, ${colorDe(v)} 14%, transparent)`,
                            color: colorDe(v),
                        }"
                    >
                        {{ etiquetaDe(v) }}
                    </span>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay vacantes publicadas.
            </p>
        </TarjetaSeccion>

        <Paginacion
            :enlaces="vacantes.links"
            :total="vacantes.total"
            :desde="vacantes.from"
            :hasta="vacantes.to"
            class="mt-4"
        />
    </AppLayout>
</template>
