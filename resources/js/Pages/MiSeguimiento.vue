<script setup lang="ts">
/**
 * Lo que la escuela le ha señalado al alumno, y qué puede hacer.
 *
 * ── NUNCA un puntaje ni un nivel ──────────────────────────────────────────
 * Es la instrucción explícita del pedido. Un número opaco no le sirve para
 * actuar y sí para desanimarse; lo que sirve es «te faltan dos entregas en
 * Cálculo I» con el enlace a dónde ir.
 *
 * ── Y el tono importa ─────────────────────────────────────────────────────
 * Esto lo lee alguien sobre sí mismo. La página dice, con esas palabras, que
 * nada de esto es una sanción, que puede estar equivocado y que se puede
 * corregir. Sin eso, una lista de cosas que uno hace mal es sólo una lista de
 * cosas que uno hace mal.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

import CampoSelect from '@/Components/CampoSelect.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { colorPermanencia } from '@/utils/coloresPermanencia';

interface Senal {
    id: number;
    categoria: string | null;
    color: string | null;
    materia: string | null;
    desde: string | null;
    texto: string;
    a_donde: [string, string] | null;
}

const props = defineProps<{
    matriculas: { id: number; matricula: string; programa_academico: string | null }[];
    matricula: { id: number; matricula: string; programa_academico: string | null } | null;
    senales: Senal[];
    acompanamiento: { responsable: string | null; desde: string | null } | null;
}>();

const elegida = computed({
    get: () => props.matricula?.id ?? null,
    set: (id: number | null) =>
        router.get('/mi-seguimiento', id === null ? {} : { matricula: id },
            { preserveState: true, replace: true }),
});
</script>

<template>
    <Head title="Mi seguimiento" />

    <AppLayout titulo="Mi seguimiento">
        <!-- ── Qué es esta página ────────────────────────────────────────── -->
        <section class="tarjeta mb-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-semibold">Lo que tu escuela está mirando</h2>
                    <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                        Aquí aparece lo que el sistema observó de tu avance y que tu escuela decidió
                        compartir contigo. <strong>Nada de esto es una sanción</strong>, y ninguna de
                        estas líneas decide nada por sí sola: son cosas que conviene resolver a
                        tiempo. Si algo no cuadra, díselo a tu escuela — se puede corregir.
                    </p>
                </div>

                <!--
                    Con varios programas, se elige entre los SUYOS. Un id ajeno
                    cae en el propio: la ruta no acepta a nadie más.
                -->
                <CampoSelect
                    v-if="matriculas.length > 1"
                    v-model="elegida"
                    etiqueta="Programa"
                    :opciones="matriculas.map((m) => ({
                        valor: m.id,
                        texto: `${m.programa_academico ?? 'Programa'} · ${m.matricula}`,
                    }))"
                />
            </div>
        </section>

        <!-- ── Quién lo acompaña ─────────────────────────────────────────── -->
        <!--
            Se dice el NOMBRE y nada más: ni el folio, ni el estado, ni cuántas
            veces se ha hablado de él. Un expediente secreto sobre alguien es la
            versión vigilancia de esto; saber a quién acudir es la versión
            acompañamiento.
        -->
        <section
            v-if="acompanamiento"
            class="tarjeta mb-4 p-5"
            :style="{ borderColor: 'var(--color-acento)' }"
        >
            <h3 class="font-semibold">Hay alguien acompañándote</h3>
            <p class="mt-1 text-sm">
                <template v-if="acompanamiento.responsable">
                    <strong>{{ acompanamiento.responsable }}</strong> está al pendiente de tu caso
                    desde el {{ acompanamiento.desde }}. Puedes buscarle si necesitas algo o si
                    quieres explicar tu situación.
                </template>
                <template v-else>
                    Tu escuela abrió un seguimiento el {{ acompanamiento.desde }} y todavía está
                    asignando a quién le toca. En cuanto lo haga, aquí verás con quién hablar.
                </template>
            </p>
        </section>

        <!-- ── Lo señalado ───────────────────────────────────────────────── -->
        <section v-if="senales.length > 0" class="space-y-3">
            <article v-for="s in senales" :key="s.id" class="tarjeta p-5">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <PildoraEstado
                        v-if="s.categoria"
                        :texto="s.categoria"
                        :color="colorPermanencia(s.color)"
                    />
                    <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Desde el {{ s.desde }}
                    </span>
                </div>

                <p class="mt-2">{{ s.texto }}</p>

                <p v-if="s.materia" class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    En {{ s.materia }}.
                </p>

                <Link
                    v-if="s.a_donde"
                    :href="s.a_donde[0]"
                    class="mt-3 inline-block rounded-lg border border-borde px-3 py-1.5 text-sm"
                >
                    {{ s.a_donde[1] }}
                </Link>
            </article>
        </section>

        <!--
            El vacío se explica. «No hay nada» a secas se lee como que la página
            está rota, o peor, como que la escuela dejó de mirar.
        -->
        <section v-else class="tarjeta p-8 text-center">
            <p class="font-medium">No hay nada señalado ahora mismo.</p>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                <template v-if="matriculas.length === 0">
                    Todavía no tienes una inscripción activa, así que no hay nada que seguir.
                </template>
                <template v-else>
                    Eso quiere decir que ninguna de las cosas que tu escuela vigila se está
                    cumpliendo en tu caso. Si aun así necesitas ayuda con algo, no esperes a que
                    aparezca aquí: acude con tu escuela.
                </template>
            </p>
        </section>
    </AppLayout>
</template>
