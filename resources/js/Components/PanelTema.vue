<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Panel lateral de apariencia: elegir un tema predefinido y —si el tema lo
 * permite— ajustar colores puntuales. Los ajustes se guardan por usuario en
 * `usuario_tema_override`, una fila por token.
 */
const props = defineProps<{ abierto: boolean }>();
const emit = defineEmits<{ cerrar: [] }>();

const page = usePage<any>();
const tema = computed(() => page.props.tema);

const guardando = ref(false);

/** Tokens que tiene sentido dejar personalizar; el resto los fija el tema. */
const personalizables = [
    { token: 'acento', etiqueta: 'Color de acento' },
    { token: 'barra_lateral', etiqueta: 'Barra lateral' },
    { token: 'barra_lateral_activo', etiqueta: 'Resaltado activo' },
];

function elegirTema(temaId: number): void {
    guardando.value = true;

    router.put(
        '/preferencias/tema',
        { tema_id: temaId },
        { preserveScroll: true, onFinish: () => (guardando.value = false) },
    );
}

function personalizar(token: string, valor: string): void {
    router.put('/preferencias/tema/color', { token, valor }, { preserveScroll: true });
}

function restablecer(): void {
    router.delete('/preferencias/tema/personalizacion', { preserveScroll: true });
}
</script>

<template>
    <!-- Velo -->
    <Transition
        enter-active-class="transition-opacity duration-300"
        enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-200"
        leave-to-class="opacity-0"
    >
        <div v-if="abierto" class="fixed inset-0 z-40 bg-slate-900/40 backdrop-blur-[2px]" @click="emit('cerrar')" />
    </Transition>

    <!-- Panel -->
    <Transition
        enter-active-class="transition-transform duration-300 ease-out"
        enter-from-class="translate-x-full"
        leave-active-class="transition-transform duration-200 ease-in"
        leave-to-class="translate-x-full"
    >
        <aside
            v-if="abierto"
            class="fixed right-0 top-0 z-50 flex h-full w-80 flex-col shadow-2xl"
            :style="{ backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
        >
            <header
                class="flex items-center justify-between border-b px-5 py-4"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <div>
                    <h2 class="text-sm font-semibold">Apariencia</h2>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Se guarda en tu cuenta
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded-lg p-1.5 transition hover:bg-black/5"
                    aria-label="Cerrar"
                    @click="emit('cerrar')"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </header>

            <div class="flex-1 space-y-6 overflow-y-auto p-5">
                <!-- Temas -->
                <section>
                    <h3 class="text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        Tema
                    </h3>

                    <div class="mt-3 space-y-2">
                        <button
                            v-for="opcion in tema.disponibles"
                            :key="opcion.id"
                            type="button"
                            :disabled="guardando"
                            class="flex w-full items-center gap-3 rounded-xl border p-3 text-left transition duration-200 hover:scale-[1.01]"
                            :style="{
                                borderColor:
                                    opcion.clave === tema.clave ? 'var(--color-acento)' : 'var(--color-borde)',
                                boxShadow:
                                    opcion.clave === tema.clave
                                        ? '0 0 0 1px var(--color-acento)'
                                        : 'none',
                            }"
                            @click="elegirTema(opcion.id)"
                        >
                            <!-- Miniatura del tema -->
                            <span
                                class="flex h-9 w-12 shrink-0 overflow-hidden rounded-md ring-1 ring-black/10"
                                :style="{ backgroundColor: opcion.muestra.fondo }"
                            >
                                <span class="h-full w-1/3" :style="{ backgroundColor: opcion.muestra.barra_lateral }" />
                                <span class="m-1 h-2 w-2 self-end rounded-full" :style="{ backgroundColor: opcion.muestra.acento }" />
                            </span>

                            <span class="flex-1">
                                <span class="block text-sm font-medium">{{ opcion.nombre }}</span>
                                <span v-if="opcion.es_default" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    Predeterminado de la escuela
                                </span>
                            </span>

                            <svg
                                v-if="opcion.clave === tema.clave"
                                class="h-5 w-5"
                                :style="{ color: 'var(--color-acento)' }"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke-width="2"
                                stroke="currentColor"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                            </svg>
                        </button>
                    </div>
                </section>

                <!-- Personalización -->
                <section v-if="tema.permite_override">
                    <h3 class="text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        Ajustes propios
                    </h3>
                    <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                        Sobrescriben el tema solo para ti.
                    </p>

                    <div class="mt-3 space-y-3">
                        <label
                            v-for="campo in personalizables"
                            :key="campo.token"
                            class="flex items-center justify-between gap-3 text-sm"
                        >
                            <span>{{ campo.etiqueta }}</span>
                            <input
                                type="color"
                                :value="tema.tokens[campo.token]"
                                class="h-8 w-14 cursor-pointer rounded border-0 bg-transparent p-0"
                                @change="personalizar(campo.token, ($event.target as HTMLInputElement).value)"
                            />
                        </label>
                    </div>

                    <button
                        type="button"
                        class="mt-4 w-full rounded-lg border px-3 py-2 text-xs transition hover:bg-black/5"
                        :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                        @click="restablecer"
                    >
                        Restablecer colores del tema
                    </button>
                </section>

                <p v-else class="rounded-lg p-3 text-xs" :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }">
                    Este tema no admite ajustes personales: sus colores están fijados para garantizar el
                    contraste.
                </p>
            </div>
        </aside>
    </Transition>
</template>
