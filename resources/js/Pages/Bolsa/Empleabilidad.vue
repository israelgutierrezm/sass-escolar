<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

interface Renglon {
    egresados: number;
    colocados: number;
    porcentaje: number;
}

const props = defineProps<{
    resumen: {
        egresados: number;
        colocados: number;
        porcentaje: number;
        en_su_area: number;
        fuera_de_su_area: number;
        sin_ese_dato: number;
        sin_programa_academico_senalado: number;
        de_quien_no_ha_egresado: number;
        de_la_bolsa: number;
        de_seguimiento: number;
    };
    por_programa_academico: (Renglon & { programa_academico_id: number; programa_academico: string })[];
    por_generacion: (Renglon & { generacion: string })[];
    filtros: { generacion: string | null; programa_academico_id: number | null };
    generaciones: string[];
    programas_academicos: { id: number; nombre: string }[];
}>();

const generacion = ref(props.filtros.generacion);
const programaAcademicoId = ref(props.filtros.programa_academico_id);

function filtrar(): void {
    router.get(
        '/bolsa/empleabilidad',
        { generacion: generacion.value, programa_academico_id: programaAcademicoId.value },
        { preserveState: true, replace: true },
    );
}

/*
 * La barra se dibuja contra 100 y no contra el mayor de la serie.
 *
 * Es un PORCENTAJE: pintarlo relativo al mejor programa haría que un 12 % se
 * viera lleno con sólo ser el más alto, y la lectura de un vistazo diría lo
 * contrario del número que está al lado.
 */
function ancho(p: number): string {
    return `${Math.min(100, Math.max(0, p))}%`;
}

function color(p: number): string {
    if (p >= 60) return '#16a34a';
    if (p >= 30) return '#d97706';

    return '#dc2626';
}

const hayDatos = computed(() => props.resumen.egresados > 0);

/** Las colocaciones registradas que el indicador no cuenta, y por qué. */
const fuera = computed(() => props.resumen.sin_programa_academico_senalado + props.resumen.de_quien_no_ha_egresado);
</script>

<template>
    <Head title="Empleabilidad" />

    <AppLayout titulo="Empleabilidad">
        <p class="mb-4 text-sm" :style="{ color: 'var(--color-suave)' }">
            De los que egresaron, cuántos están colocados. Se cuenta por matrícula, así que quien
            egresó de dos programas académicos cuenta en las dos —cada programa reporta lo suyo—. Lo alimentan
            las <Link href="/bolsa/colocaciones" class="underline" :style="{ color: 'var(--color-acento)' }">colocaciones</Link>.
        </p>

        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <CampoSelect
                v-model="generacion"
                etiqueta=""
                :opciones="generaciones.map((g) => ({ valor: g, texto: `Generación ${g}` }))"
                vacio="Todas las generaciones"
                @update:model-value="filtrar"
            />
            <CampoSelect
                v-model="programaAcademicoId"
                etiqueta=""
                :opciones="programas_academicos.map((c) => ({ valor: c.id, texto: c.nombre }))"
                vacio="Todos los programas académicos"
                @update:model-value="filtrar"
            />
        </div>

        <div v-if="hayDatos" class="mb-4 grid gap-4 sm:grid-cols-3">
            <div class="tarjeta p-6">
                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">Colocados</p>
                <p class="mt-1 text-3xl font-semibold" :style="{ color: color(resumen.porcentaje) }">
                    {{ resumen.porcentaje }}%
                </p>
                <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                    {{ resumen.colocados }} de {{ resumen.egresados }} egresados
                </p>
            </div>

            <div class="tarjeta p-6">
                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">De su área</p>
                <p class="mt-1 text-3xl font-semibold">{{ resumen.en_su_area }}</p>
                <!--
                    Tres cifras y no dos: «no se preguntó» no es «no». Sumar el
                    hueco a los de otra área afirmaría algo que nadie dijo.
                -->
                <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                    {{ resumen.fuera_de_su_area }} en otra área ·
                    {{ resumen.sin_ese_dato }} sin ese dato
                </p>
            </div>

            <div class="tarjeta p-6">
                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">De dónde salieron</p>
                <p class="mt-1 text-3xl font-semibold">{{ resumen.de_la_bolsa }}</p>
                <!--
                    Sobre los MISMOS colocados de la izquierda. Contar todas las
                    colocaciones de la escuela ponía este número al lado de un
                    porcentaje calculado sobre otro conjunto.
                -->
                <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                    por la bolsa · {{ resumen.de_seguimiento }} por seguimiento
                </p>
            </div>
        </div>

        <!--
            Lo que NO entra en el indicador, con su razón y su salida. Sin esto,
            la diferencia entre las colocaciones registradas y las contadas es un
            misterio que hace desconfiar del número entero.
        -->
        <div
            v-if="fuera > 0"
            class="mb-4 rounded-lg border px-4 py-3 text-sm"
            :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
        >
            <p class="font-medium">
                <template v-if="fuera === 1">Hay una colocación que no entra en estas cifras:</template>
                <template v-else>Hay {{ fuera }} colocaciones que no entran en estas cifras:</template>
            </p>
            <ul class="mt-1 list-disc space-y-0.5 pl-5">
                <li v-if="resumen.sin_programa_academico_senalado > 0">
                    {{ resumen.sin_programa_academico_senalado }} sin programa_academico señalada — no se pueden atribuir a
                    ningún programa.
                    <Link href="/bolsa/colocaciones" class="underline" :style="{ color: 'var(--color-acento)' }">
                        Edítalas</Link> y elige con cuál de sus programas académicos van.
                </li>
                <li v-if="resumen.de_quien_no_ha_egresado > 0">
                    {{ resumen.de_quien_no_ha_egresado }} de quien todavía no egresa —una práctica
                    profesional, por ejemplo—. Entra sola en cuanto su matrícula pase a egresado.
                </li>
            </ul>
        </div>

        <TarjetaSeccion titulo="Por programa académico" sin-relleno class="mb-4">
            <ul v-if="por_programa_academico.length">
                <li
                    v-for="r in por_programa_academico"
                    :key="r.programa_academico_id"
                    class="border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="flex items-center justify-between gap-3">
                        <span class="min-w-0 truncate font-medium">{{ r.programa_academico }}</span>
                        <span class="shrink-0 text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ r.colocados }} de {{ r.egresados }} · {{ r.porcentaje }}%
                        </span>
                    </div>
                    <div
                        class="mt-1.5 h-1.5 overflow-hidden rounded-full"
                        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 20%, transparent)' }"
                    >
                        <div class="h-full rounded-full" :style="{ width: ancho(r.porcentaje), backgroundColor: color(r.porcentaje) }" />
                    </div>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay egresados con los que calcular esto.
            </p>
        </TarjetaSeccion>

        <TarjetaSeccion titulo="Por generación" sin-relleno>
            <ul v-if="por_generacion.length">
                <li
                    v-for="r in por_generacion"
                    :key="r.generacion"
                    class="border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="flex items-center justify-between gap-3">
                        <span class="font-medium">Generación {{ r.generacion }}</span>
                        <span class="shrink-0 text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ r.colocados }} de {{ r.egresados }} · {{ r.porcentaje }}%
                        </span>
                    </div>
                    <div
                        class="mt-1.5 h-1.5 overflow-hidden rounded-full"
                        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 20%, transparent)' }"
                    >
                        <div class="h-full rounded-full" :style="{ width: ancho(r.porcentaje), backgroundColor: color(r.porcentaje) }" />
                    </div>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay egresados con los que calcular esto.
            </p>
        </TarjetaSeccion>
    </AppLayout>
</template>
