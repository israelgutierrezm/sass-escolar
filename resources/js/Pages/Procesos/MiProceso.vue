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
import { computed } from 'vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
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
                    <!-- `sin-capitalizar` porque el texto ya viene escrito: la
                         píldora capitaliza CADA palabra y salía «Todavía No». -->
                    <PildoraEstado
                        :texto="p.elegible ? 'Ya puedes empezar' : 'Todavía no'"
                        :color="p.elegible ? '#16a34a' : '#b45309'"
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

            <div class="grid gap-4 sm:grid-cols-2">
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
    </AppLayout>
</template>
