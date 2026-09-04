<script setup lang="ts">
/**
 * Los indicadores del módulo.
 *
 * ── Lo primero de la pantalla es la COBERTURA, y es deliberado ────────────
 * El sesgo dominante de este módulo no es demográfico: es de CAPTURA. Un
 * plantel que no pasa lista no produce señales de asistencia, y leído sin
 * cuidado el tablero dice que es el que mejor va. Poner las cifras arriba y la
 * cobertura al final invierte exactamente el orden en que hay que leerlas.
 *
 * ── Y ningún nombre ───────────────────────────────────────────────────────
 * Son conteos. Los desgloses con muy pocos alumnos dicen «muy pocos para
 * desglosar» y no el número: en una escuela chica, «2 casos de esta generación»
 * señala con el dedo.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

import PildoraEstado from '@/Components/PildoraEstado.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { colorPermanencia } from '@/utils/coloresPermanencia';

interface Fuente {
    clave: string;
    titulo: string;
    calidad: string;
    ultima_actualizacion: string | null;
}

const props = defineProps<{
    tablero: {
        ventana: { dias: number; desde: string };
        cobertura: {
            corrio_en: string | null;
            alumnos: number;
            reglas: number;
            sin_datos: number;
            proporcion_sin_datos: number | null;
            fuentes: Fuente[];
        };
        senales: {
            por_revisar: number;
            validadas_abiertas: number;
            levantadas: number;
            resueltas: number;
            obsoletas: number;
            por_categoria: { nombre: string; color: string; sensible: boolean; total: number }[];
        };
        calibracion: {
            regla: string;
            revisadas: number;
            descartadas: number | null;
            proporcion: number | null;
            suficientes: boolean;
            preocupa: boolean;
        }[];
        casos: Record<string, any>;
        desenlaces: Record<string, number>;
        por_campus: { campus: string; total: number | null; suficientes: boolean }[];
        minimo_por_grupo: number;
    };
    ventanas: number[];
    ventana: number;
    acotado: boolean;
}>();

const c = computed(() => props.tablero.cobertura);
const s = computed(() => props.tablero.senales);
const casos = computed(() => props.tablero.casos);
const d = computed(() => props.tablero.desenlaces);

/*
 * El motor lleva sin correr más de dos días. Sin este aviso, una cola vacía se
 * lee como ausencia de riesgo — que es el peor error que este módulo puede
 * inducir.
 */
const diasSinCorrer = computed<number | null>(() => {
    if (c.value.corrio_en === null) return null;

    const cuando = new Date(c.value.corrio_en.replace(' ', 'T'));

    return Math.floor((Date.now() - cuando.getTime()) / 86_400_000);
});

const corridaVieja = computed(() => diasSinCorrer.value === null || diasSinCorrer.value > 2);

function cambiarVentana(dias: number): void {
    router.get('/permanencia/tablero', { dias }, { preserveState: true, replace: true });
}

const porcentaje = (parte: number, total: number): string =>
    total === 0 ? '—' : `${Math.round((parte * 100) / total)} %`;
</script>

<template>
    <Head title="Indicadores de permanencia" />

    <AppLayout titulo="Indicadores de permanencia">
        <!-- ── Qué es y qué no es ────────────────────────────────────────── -->
        <section class="tarjeta mb-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-semibold">Cómo va el acompañamiento</h2>
                    <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                        Todo lo de esta pantalla son <strong>conteos</strong>, nunca personas. Los
                        desgloses con menos de {{ tablero.minimo_por_grupo }} alumnos no se enseñan:
                        en un grupo pequeño, una cifra señala a alguien concreto.
                    </p>
                    <p v-if="acotado" class="mt-2 text-sm" :style="{ color: 'var(--color-ambar)' }">
                        <strong>Estás viendo sólo tus planteles.</strong> Estas cifras no son las de
                        la escuela.
                    </p>
                </div>

                <div class="flex flex-wrap gap-1">
                    <button
                        v-for="v in ventanas"
                        :key="v"
                        type="button"
                        class="rounded-lg border border-borde px-3 py-1.5 text-sm"
                        :style="v === ventana
                            ? { borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }
                            : {}"
                        @click="cambiarVentana(v)"
                    >
                        {{ v }} días
                    </button>
                </div>
            </div>
        </section>

        <!-- ══ 1. LA COBERTURA, primero ═══════════════════════════════════ -->
        <section class="tarjeta mb-4 p-5">
            <h3 class="font-semibold">Con qué se está midiendo</h3>
            <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Va primero a propósito. <strong>Un plantel que no pasa lista no produce señales de
                asistencia</strong>, y sin este dato el tablero diría que es el que mejor va. Antes
                de leer cualquier cifra de abajo, mira cuánto se pudo medir.
            </p>

            <p
                v-if="corridaVieja"
                class="mt-3 rounded-lg p-3 text-sm"
                :style="{ color: 'var(--color-ambar)',
                          backgroundColor: 'color-mix(in srgb, var(--color-ambar) 10%, transparent)' }"
            >
                <template v-if="c.corrio_en === null">
                    <strong>El motor no ha evaluado nunca.</strong> Todo lo de abajo está en cero
                    porque no se ha medido nada, no porque no haya nada que mirar.
                </template>
                <template v-else>
                    <strong>El motor no evalúa desde hace {{ diasSinCorrer }} días</strong>
                    ({{ c.corrio_en }}). Lo que ves puede estar desactualizado.
                </template>
            </p>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <p class="text-2xl font-semibold">{{ c.alumnos }}</p>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">alumnos evaluados</p>
                </div>
                <div>
                    <p class="text-2xl font-semibold">{{ c.reglas }}</p>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        reglas encendidas
                        <span v-if="c.reglas === 0" class="block text-xs" :style="{ color: 'var(--color-ambar)' }">
                            Sin reglas no se levanta nada.
                        </span>
                    </p>
                </div>
                <div>
                    <p
                        class="text-2xl font-semibold"
                        :style="{ color: (c.proporcion_sin_datos ?? 0) > 30 ? 'var(--color-ambar)' : undefined }"
                    >
                        {{ c.proporcion_sin_datos === null ? '—' : `${c.proporcion_sin_datos} %` }}
                    </p>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        sin datos suficientes
                        <span class="block text-xs">
                            Mediciones que no se pudieron hacer. No son ceros: son huecos.
                        </span>
                    </p>
                </div>
                <div>
                    <p class="text-2xl font-semibold">{{ c.corrio_en ?? 'Nunca' }}</p>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">última evaluación</p>
                </div>
            </div>

            <!-- Lo que cada fuente DECLARA. Es lo que impide leer un 60 % de
                 asistencia como si fuera del semestre entero. -->
            <details class="mt-4">
                <summary class="cursor-pointer text-sm" :style="{ color: 'var(--color-suave)' }">
                    Qué significa cada fuente, y qué no
                </summary>
                <ul class="mt-3 space-y-3">
                    <li v-for="f in c.fuentes" :key="f.clave" class="text-sm">
                        <p class="font-medium">
                            {{ f.titulo }}
                            <span v-if="f.ultima_actualizacion" class="font-normal" :style="{ color: 'var(--color-suave)' }">
                                · último dato: {{ f.ultima_actualizacion }}
                            </span>
                            <span v-else class="font-normal" :style="{ color: 'var(--color-ambar)' }">
                                · sin ningún dato registrado
                            </span>
                        </p>
                        <p :style="{ color: 'var(--color-suave)' }">{{ f.calidad }}</p>
                    </li>
                </ul>
            </details>
        </section>

        <!-- ══ 2. LAS SEÑALES ═════════════════════════════════════════════ -->
        <section class="tarjeta mb-4 p-5">
            <h3 class="font-semibold">Señales</h3>

            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div>
                    <p class="text-2xl font-semibold">{{ s.por_revisar }}</p>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        requieren revisión
                    </p>
                </div>
                <div>
                    <p class="text-2xl font-semibold">{{ s.validadas_abiertas }}</p>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">validadas, abiertas</p>
                </div>
                <div>
                    <p class="text-2xl font-semibold">{{ s.levantadas }}</p>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        levantadas en {{ tablero.ventana.dias }} días
                    </p>
                </div>
                <div>
                    <p class="text-2xl font-semibold" :style="{ color: 'var(--color-verde)' }">
                        {{ s.resueltas }}
                    </p>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        la situación mejoró
                    </p>
                </div>
                <div>
                    <p class="text-2xl font-semibold">{{ s.obsoletas }}</p>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        se dejaron de vigilar
                        <!--
                            Aparte de las resueltas, y no es un matiz: con las dos
                            juntas, apagar una regla se leería como que doscientos
                            alumnos se recuperaron.
                        -->
                        <span class="block text-xs">
                            La regla se apagó o el alumno salió de su alcance. No mejoró nada.
                        </span>
                    </p>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <span
                    v-for="cat in s.por_categoria"
                    :key="cat.nombre"
                    class="rounded-lg border border-borde px-3 py-1.5 text-sm"
                >
                    <PildoraEstado :texto="cat.nombre" :color="colorPermanencia(cat.color)" />
                    <strong class="ml-1">{{ cat.total }}</strong>
                </span>
            </div>
        </section>

        <!-- ══ 3. LA CALIBRACIÓN ══════════════════════════════════════════ -->
        <section class="tarjeta mb-4 p-5">
            <h3 class="font-semibold">Qué tan bien calibradas están las reglas</h3>
            <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Una regla cuyas señales se descartan casi siempre le está haciendo perder el tiempo
                a quien revisa, y a la tercera semana <strong>nadie mira la bandeja</strong> — con lo
                que las buenas se pierden también. Bajo {{ tablero.minimo_por_grupo }} señales
                revisadas no se opina: un porcentaje sobre tres casos parece un dato y no lo es.
            </p>

            <div v-if="tablero.calibracion.length > 0" class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[36rem] text-sm">
                    <thead>
                        <tr class="border-b border-borde text-left">
                            <th class="p-2">Regla</th>
                            <th class="p-2">Revisadas</th>
                            <th class="p-2">Descartadas</th>
                            <th class="p-2">Proporción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in tablero.calibracion" :key="r.regla" class="border-b border-borde/60">
                            <td class="p-2">{{ r.regla }}</td>
                            <td class="p-2">{{ r.revisadas }}</td>
                            <td class="p-2">
                                <span v-if="r.suficientes">{{ r.descartadas }}</span>
                                <span v-else :style="{ color: 'var(--color-suave)' }">—</span>
                            </td>
                            <td class="p-2">
                                <span
                                    v-if="r.suficientes"
                                    :style="{ color: r.preocupa ? 'var(--color-ambar)' : undefined }"
                                >
                                    {{ r.proporcion }} %
                                    <span v-if="r.preocupa" class="text-xs">· revisa su umbral</span>
                                </span>
                                <span v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    muy pocas para opinar
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no se ha revisado ninguna señal en esta ventana, así que no hay nada que
                calibrar.
            </p>
        </section>

        <!-- ══ 4. EL ACOMPAÑAMIENTO ═══════════════════════════════════════ -->
        <section class="tarjeta mb-4 p-5">
            <h3 class="font-semibold">Acompañamiento</h3>

            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <p class="text-2xl font-semibold">{{ casos.abiertos }}</p>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">casos abiertos</p>
                </div>
                <div>
                    <p
                        class="text-2xl font-semibold"
                        :style="{ color: casos.fuera_de_plazo > 0 ? 'var(--color-rojo)' : undefined }"
                    >
                        {{ casos.fuera_de_plazo }}
                    </p>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        sin primer contacto en plazo
                    </p>
                </div>
                <div>
                    <p class="text-2xl font-semibold">
                        {{ casos.horas_primer_contacto === null ? '—' : `${casos.horas_primer_contacto} h` }}
                    </p>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        al primer contacto
                        <!--
                            El promedio se dice CON su denominador: «12 h» sobre
                            dos casos no es el tiempo de la escuela, y quien lo
                            lea sin el conteo se lo va a creer.
                        -->
                        <span class="block text-xs">
                            promedio de {{ casos.casos_con_contacto }} caso{{ casos.casos_con_contacto === 1 ? '' : 's' }}
                        </span>
                    </p>
                </div>
                <div>
                    <p class="text-2xl font-semibold">
                        {{ casos.dias_para_cerrar === null ? '—' : `${casos.dias_para_cerrar} d` }}
                    </p>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        en cerrarse
                        <span class="block text-xs">
                            promedio de {{ casos.casos_cerrados }} cerrado{{ casos.casos_cerrados === 1 ? '' : 's' }}
                        </span>
                    </p>
                </div>
            </div>

            <div v-if="Object.keys(casos.por_estado).length > 0" class="mt-4 flex flex-wrap gap-2">
                <span
                    v-for="(total, estado) in casos.por_estado"
                    :key="estado"
                    class="rounded-lg border border-borde px-3 py-1.5 text-sm"
                >
                    {{ estado }} <strong>{{ total }}</strong>
                </span>
            </div>
        </section>

        <!-- ══ 5. LOS DESENLACES ══════════════════════════════════════════ -->
        <section class="tarjeta mb-4 p-5">
            <h3 class="font-semibold">En qué terminó, y qué volvió</h3>
            <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                <strong>Dos cifras y no una.</strong> «Contó como éxito» es lo que declaró quien
                cerró el caso; «la señal mejoró» es lo que de verdad pasó. La diferencia entre las
                dos es información —la mejora puede tardar en reflejarse—, no un error: con una sola
                columna nadie puede saber si el indicador dice algo.
            </p>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <p class="text-2xl font-semibold">{{ d.cerrados }}</p>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        cerrados en {{ tablero.ventana.dias }} días
                    </p>
                </div>
                <div>
                    <p class="text-2xl font-semibold" :style="{ color: 'var(--color-verde)' }">
                        {{ porcentaje(d.exito, d.cerrados) }}
                    </p>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        contó como éxito
                        <span class="block text-xs">
                            {{ d.exito }} de {{ d.cerrados }} · {{ d.ni_uno_ni_otro }} ni una cosa ni otra
                        </span>
                    </p>
                </div>
                <div>
                    <p class="text-2xl font-semibold">
                        {{ porcentaje(d.senal_resuelta, d.cerrados_con_senal) }}
                    </p>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        la señal dejó de cumplirse
                        <span class="block text-xs">
                            {{ d.senal_resuelta }} de {{ d.cerrados_con_senal }} con señal atada
                        </span>
                    </p>
                </div>
                <div>
                    <p
                        class="text-2xl font-semibold"
                        :style="{ color: d.reaperturas > 0 ? 'var(--color-ambar)' : undefined }"
                    >
                        {{ d.reaperturas }}
                    </p>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        situaciones que volvieron
                        <span class="block text-xs">
                            Cerrar mucho y reabrir mucho no es resolver: es cerrar pronto.
                        </span>
                    </p>
                </div>
            </div>
        </section>

        <!-- ══ 6. POR CAMPUS ═════════════════════════════════════════════ -->
        <section class="tarjeta mb-4 p-5">
            <h3 class="font-semibold">Casos abiertos por plantel</h3>

            <div v-if="tablero.por_campus.length > 0" class="mt-3 space-y-2">
                <div
                    v-for="fila in tablero.por_campus"
                    :key="fila.campus"
                    class="flex items-center justify-between gap-3 border-b border-borde/60 pb-2 text-sm"
                >
                    <span>{{ fila.campus }}</span>
                    <span v-if="fila.suficientes" class="font-semibold">{{ fila.total }}</span>
                    <span v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        muy pocos para desglosar
                    </span>
                </div>
            </div>
            <p v-else class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">
                No se abrió ningún caso en esta ventana.
            </p>
        </section>

        <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
            Para ver el detalle con nombres —cada uno con su permiso y su alcance— entra a la
            <Link href="/permanencia/alertas" class="underline">bandeja de señales</Link>, a los
            <Link href="/permanencia/casos" class="underline">casos</Link> o a los
            <Link href="/reportes" class="underline">reportes</Link>.
        </p>
    </AppLayout>
</template>
