<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * La credencial de quien está en sesión.
 *
 * ── Grande y centrada ─────────────────────────────────────────────────────
 * Esta pantalla se abre casi siempre para ENSEÑARLE la credencial a alguien —en
 * la puerta, en la biblioteca, en un examen—, con el teléfono en la mano y a un
 * brazo de distancia de quien la revisa. Todo lo demás sobra: la imagen ocupa
 * lo que puede y los botones se quedan al pie.
 *
 * ── Una por matrícula ─────────────────────────────────────────────────────
 * Quien estudia dos carreras tiene dos, y elige entre las suyas. Es la misma
 * regla del historial académico: el alumno es la matrícula, no la persona.
 */

interface Credencial {
    clave: string;
    etiqueta: string;
}

const props = defineProps<{
    credenciales: Credencial[];
    elegida: string;
    tiene_reverso: boolean;
    firma: { nombre?: string; cargo?: string };
}>();

const cara = ref<'anverso' | 'reverso'>('anverso');

/*
 * La marca de tiempo obliga al navegador a volver a pedir la imagen al cambiar
 * de credencial o de cara. Sin ella, dos caras de la misma dirección con
 * distinto parámetro se resuelven bien, pero volver a la anterior serviría la
 * copia guardada — y la credencial se compone con los datos de HOY.
 */
const version = ref(Date.now());

const url = computed(
    () => `/mi-credencial/${cara.value}.png?credencial=${props.elegida}&v=${version.value}`,
);

function cambiar(clave: string): void {
    router.get('/mi-credencial', { credencial: clave }, { preserveState: false });
}

function descargar(): void {
    window.location.href = `/mi-credencial/${cara.value}.png?credencial=${props.elegida}&descargar=1`;
}

const hayFirma = computed(() => Boolean(props.firma.nombre || props.firma.cargo));
</script>

<template>
    <Head title="Mi credencial" />

    <AppLayout titulo="Mi credencial">
        <div class="mx-auto max-w-xl space-y-4">
            <!-- El selector sólo aparece cuando hay de dónde elegir: con una
                 sola credencial, un desplegable de un elemento es ruido. -->
            <div v-if="credenciales.length > 1" class="flex flex-wrap gap-2">
                <button
                    v-for="c in credenciales"
                    :key="c.clave"
                    type="button"
                    class="rounded-full border px-3 py-1.5 text-sm"
                    :class="c.clave === elegida ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-borde'"
                    @click="cambiar(c.clave)"
                >
                    {{ c.etiqueta }}
                </button>
            </div>

            <div class="tarjeta p-4">
                <img :src="url" alt="Mi credencial" class="mx-auto w-full rounded-lg" />

                <p v-if="hayFirma" class="mt-3 text-center text-xs" :style="{ color: 'var(--color-suave)' }">
                    Emitida por {{ firma.nombre }}<span v-if="firma.cargo">, {{ firma.cargo }}</span>
                </p>
            </div>

            <div class="flex flex-wrap items-center justify-center gap-2">
                <div v-if="tiene_reverso" class="flex rounded-lg border border-borde p-0.5">
                    <button
                        v-for="c in (['anverso', 'reverso'] as const)"
                        :key="c"
                        type="button"
                        class="rounded-md px-4 py-1.5 text-sm capitalize"
                        :class="cara === c ? 'bg-indigo-500 text-white' : ''"
                        @click="cara = c"
                    >
                        {{ c }}
                    </button>
                </div>

                <button
                    type="button"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                    @click="descargar"
                >
                    Descargar {{ tiene_reverso ? cara : 'imagen' }}
                </button>
            </div>

            <p class="text-center text-xs" :style="{ color: 'var(--color-suave)' }">
                Se dibuja con los datos que la escuela tiene guardados hoy. Si algo no coincide, avisa en
                control escolar.
            </p>
        </div>
    </AppLayout>
</template>
