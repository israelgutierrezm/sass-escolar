<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import EditorRubrica, { type RubricaEditable } from '@/Components/EditorRubrica.vue';

/**
 * El catálogo de rúbricas: las de la escuela y las de cada quien.
 *
 * ── Una sola pantalla para dos oficios ─────────────────────────────────────
 * Entra el docente a armar las suyas y entra quien administra las de la
 * escuela. Lo que cambia entre uno y otro no es la pantalla sino qué puede
 * tocar, y eso lo dice el servidor renglón por renglón (`puedo_editar`): la
 * interfaz no adivina el permiso, lo pinta.
 *
 * ── Se muestra la MATRIZ, no un resumen ────────────────────────────────────
 * Una rúbrica es su tabla de criterios por niveles. Listar «Ensayo — 4
 * criterios — 20 puntos» obliga a entrar a cada una para saber si es la que se
 * busca; desplegada, se reconoce de un vistazo. Va plegada por omisión porque
 * cinco matrices abiertas son una pared de texto.
 */
interface Nivel {
    id: number;
    titulo: string;
    descripcion: string | null;
    puntos: number;
}

interface Criterio {
    id: number;
    titulo: string;
    descripcion: string | null;
    maximo: number;
    niveles: Nivel[];
}

interface Rubrica {
    id: number;
    nombre: string;
    descripcion: string | null;
    ambito: string;
    dueno: string | null;
    mia: boolean;
    activa: boolean;
    total: number;
    actividades: number;
    en_uso: boolean;
    puedo_editar: boolean;
    criterios: Criterio[];
}

const props = defineProps<{
    rubricas: Rubrica[];
    puedo: { publicar: boolean; tenerPropias: boolean };
}>();

const deLaEscuela = computed(() => props.rubricas.filter((r) => r.ambito === 'plataforma'));
const mias = computed(() => props.rubricas.filter((r) => r.ambito === 'docente'));

/** Qué se está editando: null = nada, 'nueva' = una en blanco. */
const editando = ref<number | 'nueva' | null>(null);

const enEdicion = computed<RubricaEditable | null>(() => {
    if (editando.value === null || editando.value === 'nueva') return null;

    const r = props.rubricas.find((x) => x.id === editando.value);

    if (!r) return null;

    return {
        id: r.id,
        nombre: r.nombre,
        descripcion: r.descripcion,
        ambito: r.ambito,
        activa: r.activa,
        en_uso: r.en_uso,
        criterios: r.criterios.map((c) => ({
            titulo: c.titulo,
            descripcion: c.descripcion,
            niveles: c.niveles.map((n) => ({ titulo: n.titulo, descripcion: n.descripcion, puntos: n.puntos })),
        })),
    };
});

/** Cuál tiene la matriz desplegada. */
const desplegada = ref<number | null>(null);

function alternar(id: number): void {
    desplegada.value = desplegada.value === id ? null : id;
}

function duplicar(r: Rubrica, aPlataforma: boolean): void {
    router.post(`/rubricas/${r.id}/duplicar`, { a_plataforma: aPlataforma }, { preserveScroll: true });
}

function retirar(r: Rubrica): void {
    const aviso = r.en_uso || r.actividades > 0
        ? `«${r.nombre}» está en uso: se apagará en vez de borrarse. ¿Continuar?`
        : `¿Eliminar «${r.nombre}»?`;

    if (!confirm(aviso)) return;

    router.delete(`/rubricas/${r.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Rúbricas" />

    <AppLayout titulo="Rúbricas">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <p class="max-w-2xl text-sm text-suave">
                Con qué se califica un trabajo que no tiene respuesta correcta. Cada criterio se
                puntúa eligiendo un nivel, así que la nota deja de ser un número suelto y el alumno
                puede leer <strong class="text-contenido">antes de entregar</strong> qué se le va a
                mirar.
            </p>
            <button
                v-if="editando === null"
                type="button"
                class="rounded-lg px-3.5 py-2 text-sm font-medium"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="editando = 'nueva'"
            >
                + Nueva rúbrica
            </button>
        </div>

        <EditorRubrica
            v-if="editando !== null"
            :key="editando"
            class="mb-6"
            :rubrica="enEdicion"
            :puedo="puedo"
            @cerrar="editando = null"
        />

        <div v-if="!rubricas.length && editando === null" class="tarjeta px-6 py-14 text-center">
            <h2 class="text-base font-semibold text-contenido">Todavía no hay rúbricas</h2>
            <p class="mx-auto mt-1 max-w-md text-sm text-suave">
                Sin ellas, las actividades se siguen calificando con un número escrito a mano. No
                es un error: es lo que había antes, y para un ejercicio de respuesta única basta.
            </p>
        </div>

        <section v-for="grupo in [
            { clave: 'escuela', titulo: 'De la escuela', lista: deLaEscuela, vacio: 'La escuela todavía no publica ninguna.' },
            { clave: 'mias', titulo: 'Mías', lista: mias, vacio: 'Aquí van las que armes tú. Sólo las ves tú.' },
        ]" :key="grupo.clave">
            <h2 v-if="grupo.lista.length" class="mb-2 mt-6 text-xs font-semibold uppercase tracking-wide text-suave">
                {{ grupo.titulo }}
            </h2>

            <div v-if="grupo.lista.length" class="space-y-2">
                <article v-for="r in grupo.lista" :key="r.id" class="tarjeta overflow-hidden">
                    <div class="flex flex-wrap items-start gap-3 px-4 py-3">
                        <button
                            type="button"
                            class="mt-0.5 shrink-0 text-suave"
                            :title="desplegada === r.id ? 'Plegar' : 'Ver la matriz'"
                            @click="alternar(r.id)"
                        >
                            {{ desplegada === r.id ? '▾' : '▸' }}
                        </button>

                        <div class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2">
                                <strong class="text-contenido">{{ r.nombre }}</strong>

                                <!-- Apagada: no desaparece, deja de ofrecerse. -->
                                <span
                                    v-if="!r.activa"
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 14%, transparent)', color: 'var(--color-suave)' }"
                                    title="No se ofrece al crear actividades. Sigue explicando lo que ya calificó."
                                >
                                    Apagada
                                </span>

                                <span
                                    v-if="r.en_uso"
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :style="{ backgroundColor: 'color-mix(in srgb, #d97706 14%, transparent)', color: '#b45309' }"
                                    title="Ya calificó a alguien: sus criterios están congelados."
                                >
                                    En uso
                                </span>
                            </span>

                            <p v-if="r.descripcion" class="mt-0.5 text-sm text-suave">{{ r.descripcion }}</p>

                            <p class="mt-1 text-xs text-suave">
                                {{ r.criterios.length }}
                                {{ r.criterios.length === 1 ? 'criterio' : 'criterios' }}
                                · hasta <strong class="text-contenido">{{ r.total }}</strong> puntos
                                <template v-if="r.actividades > 0">
                                    · en {{ r.actividades }}
                                    {{ r.actividades === 1 ? 'actividad' : 'actividades' }}
                                </template>
                                <template v-if="r.ambito === 'plataforma' && r.dueno === null"> · de la escuela</template>
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                            <button
                                type="button"
                                class="rounded-lg border px-2.5 py-1 text-xs"
                                :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-contenido)' }"
                                @click="editando = r.id"
                            >
                                {{ r.puedo_editar ? 'Editar' : 'Ver' }}
                            </button>

                            <!-- Duplicar es cómo se «edita» una congelada, cómo
                                 se publica una propia y cómo un docente se hace
                                 su versión de la de la escuela. -->
                            <button
                                v-if="puedo.tenerPropias"
                                type="button"
                                class="rounded-lg border px-2.5 py-1 text-xs"
                                :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-contenido)' }"
                                title="Crea una copia mía. El original no cambia."
                                @click="duplicar(r, false)"
                            >
                                Copiar a las mías
                            </button>

                            <button
                                v-if="puedo.publicar && r.ambito === 'docente'"
                                type="button"
                                class="rounded-lg border px-2.5 py-1 text-xs"
                                :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                                title="Publica una copia para toda la escuela."
                                @click="duplicar(r, true)"
                            >
                                Publicar
                            </button>

                            <button
                                v-if="r.puedo_editar"
                                type="button"
                                class="rounded-lg border px-2.5 py-1 text-xs text-red-600"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                @click="retirar(r)"
                            >
                                {{ r.en_uso || r.actividades > 0 ? 'Apagar' : 'Eliminar' }}
                            </button>
                        </div>
                    </div>

                    <!-- La matriz. Se desplaza en horizontal dentro de su caja:
                         con seis niveles no cabe, y sin esto la página entera
                         se desplazaría de lado. -->
                    <div v-if="desplegada === r.id" class="overflow-x-auto border-t" :style="{ borderColor: 'var(--color-borde)' }">
                        <table class="w-full min-w-[40rem] text-sm">
                            <tbody>
                                <tr
                                    v-for="c in r.criterios"
                                    :key="c.id"
                                    class="border-b last:border-0"
                                    :style="{ borderColor: 'var(--color-borde)' }"
                                >
                                    <th class="w-52 px-4 py-3 text-left align-top">
                                        <span class="block font-medium text-contenido">{{ c.titulo }}</span>
                                        <span v-if="c.descripcion" class="mt-0.5 block text-xs font-normal text-suave">
                                            {{ c.descripcion }}
                                        </span>
                                        <span class="mt-1 block text-xs font-normal text-suave">hasta {{ c.maximo }}</span>
                                    </th>
                                    <td class="px-2 py-3">
                                        <div class="flex flex-wrap gap-2">
                                            <span
                                                v-for="n in c.niveles"
                                                :key="n.id"
                                                class="w-40 rounded-lg border px-2.5 py-2"
                                                :style="{ borderColor: 'var(--color-borde)' }"
                                            >
                                                <span class="flex items-baseline justify-between gap-2">
                                                    <strong class="text-xs text-contenido">{{ n.titulo }}</strong>
                                                    <span
                                                        class="text-xs font-semibold tabular-nums"
                                                        :style="{ color: 'var(--color-acento)' }"
                                                    >{{ n.puntos }}</span>
                                                </span>
                                                <span v-if="n.descripcion" class="mt-1 block text-[11px] leading-snug text-suave">
                                                    {{ n.descripcion }}
                                                </span>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>
        </section>
    </AppLayout>
</template>
