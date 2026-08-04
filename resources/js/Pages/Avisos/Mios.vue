<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { AvisoRecibido } from '@/tipos';

/**
 * Los avisos de la persona, todos juntos.
 *
 * Es el sitio de los INFORMATIVOS —que no interrumpen a nadie— y el archivo de
 * los otros dos: el crítico que ya confirmó y el importante que cerró siguen
 * aquí. Un aviso que se atendió y desapareció sin dejar dónde volver a leerlo
 * obliga a recordar de memoria a qué hora era la junta.
 */
interface AvisoConLectura extends AvisoRecibido {
    confirmado: string | null;
}

defineProps<{ avisos: AvisoConLectura[] }>();

const confirmando = ref<number | null>(null);

function confirmar(aviso: AvisoConLectura): void {
    confirmando.value = aviso.id;

    router.post(`/mis-avisos/${aviso.id}/confirmar`, {}, {
        preserveScroll: true,
        onFinish: () => (confirmando.value = null),
    });
}
</script>

<template>
    <Head title="Mis avisos" />

    <AppLayout titulo="Mis avisos">
        <section v-if="avisos.length" class="space-y-3">
            <article
                v-for="a in avisos"
                :key="a.id"
                class="tarjeta border-l-4 p-5"
                :style="{ borderLeftColor: a.color }"
            >
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span
                        class="rounded-full px-2.5 py-0.5 font-medium"
                        :style="{ backgroundColor: `color-mix(in srgb, ${a.color} 14%, transparent)`, color: a.color }"
                    >
                        {{ a.prioridad_etiqueta }}
                    </span>

                    <span v-if="a.confirmado" class="text-suave">
                        Confirmaste haberlo leído el {{ a.confirmado }}
                    </span>

                    <span v-if="a.vigente_hasta" class="text-suave">
                        · vigente hasta {{ a.vigente_hasta }}
                    </span>
                </div>

                <h2 class="mt-2 font-semibold text-contenido">{{ a.titulo }}</h2>
                <p class="mt-1 whitespace-pre-line text-sm leading-relaxed text-contenido">{{ a.cuerpo }}</p>

                <!--
                    El botón sólo aparece donde confirmar significa algo: en un
                    informativo no se pide constancia, y ofrecerla enseñaría a
                    pulsar «confirmo» por costumbre, que es lo que vacía de
                    valor la del aviso que sí la pide.
                -->
                <button
                    v-if="a.prioridad !== 'informativo' && ! a.confirmado"
                    type="button"
                    class="mt-3 rounded-lg px-3 py-1.5 text-xs font-medium text-white transition disabled:opacity-60"
                    :style="{ backgroundColor: a.color }"
                    :disabled="confirmando === a.id"
                    @click="confirmar(a)"
                >
                    Confirmo que lo leí
                </button>
            </article>
        </section>

        <p v-else class="tarjeta px-6 py-12 text-center text-sm text-suave">
            No tienes avisos por ahora. Aquí aparecerán los que la escuela dirija a tu grupo, a tu
            carrera o a ti.
        </p>
    </AppLayout>
</template>
