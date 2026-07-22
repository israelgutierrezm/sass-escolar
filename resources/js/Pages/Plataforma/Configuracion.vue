<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Ajuste {
    clave: string;
    etiqueta: string;
    descripcion: string;
    tipo: 'booleano' | 'entero' | 'texto' | 'seleccion';
    opciones: Record<string, string>;
    min: number | null;
    max: number | null;
    consecuencia: string | null;
    valor: string | number | boolean | null;
    por_defecto: string | number | boolean | null;
}

const props = defineProps<{
    grupos: Record<string, Ajuste[]>;
    huella: {
        matriculas: number;
        inscripciones: number;
        kardex: number;
        personas_con_varias_matriculas: number;
    };
    puedeEditar: boolean;
}>();

// Se arranca del valor guardado; el formulario lleva TODOS los ajustes para que
// guardar sea una sola operación y no una por casilla.
const inicial: Record<string, any> = {};

for (const ajustes of Object.values(props.grupos)) {
    for (const a of ajustes) {
        inicial[a.clave] = a.valor;
    }
}

const form = useForm({ ajustes: inicial });

function guardar(): void {
    form.put('/plataforma/configuracion');
}

function cambiado(a: Ajuste): boolean {
    return form.ajustes[a.clave] !== a.valor;
}

const hayOperacion = props.huella.inscripciones > 0 || props.huella.kardex > 0;
</script>

<template>
    <Head title="Reglas de la escuela" />

    <AppLayout titulo="Configuración general">
        <section class="tarjeta p-6">
            <h2 class="text-base font-semibold">Las reglas con las que opera tu escuela</h2>
            <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Cuántos recursamientos permites, cuántos extraordinarios, si el adeudo detiene una
                inscripción. El sistema las aplica sola: aquí decides el número y si al llegar al límite
                se <strong>advierte</strong> o se <strong>bloquea</strong>.
            </p>

            <!--
                Configurar en blanco y configurar encima de un ciclo en curso no
                es lo mismo, y quien lo hace merece saber en cuál de las dos
                está. No se bloquea nada: la escuela manda.
            -->
            <div
                v-if="hayOperacion"
                class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800"
            >
                <strong>Ya hay operación registrada</strong> — {{ huella.matriculas }} matrículas,
                {{ huella.inscripciones }} inscripciones y {{ huella.kardex }} renglones de kárdex.
                Cambiar un límite aplica de aquí en adelante: <strong>no reevalúa el pasado</strong>.
                Quien ya lleva tres recursamientos no se da de baja porque hoy el máximo pase a dos.
            </div>
            <div v-else class="mt-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                <strong>Todavía no hay operación registrada.</strong> Es el mejor momento para dejar
                estas reglas como las quieres: a partir de aquí gobiernan todo lo que se capture.
            </div>
        </section>

        <section v-for="(ajustes, grupo) in grupos" :key="grupo" class="tarjeta p-6">
            <h3 class="text-base font-semibold">{{ grupo }}</h3>

            <div class="mt-4 space-y-5">
                <div
                    v-for="ajuste in ajustes"
                    :key="ajuste.clave"
                    class="grid gap-3 border-t pt-4 first:border-0 first:pt-0 sm:grid-cols-[1fr_auto]"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div>
                        <p class="text-sm font-medium">
                            {{ ajuste.etiqueta }}
                            <span v-if="cambiado(ajuste)" class="ml-2 text-xs text-amber-700">sin guardar</span>
                        </p>
                        <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                            {{ ajuste.descripcion }}
                        </p>
                        <p v-if="ajuste.consecuencia" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                            ⚠ {{ ajuste.consecuencia }}
                        </p>
                        <p class="mt-0.5 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ ajuste.clave }}
                        </p>
                    </div>

                    <div class="sm:w-56">
                        <label v-if="ajuste.tipo === 'booleano'" class="flex items-center gap-2 text-sm">
                            <input
                                v-model="form.ajustes[ajuste.clave]"
                                type="checkbox"
                                class="rounded"
                                :disabled="!puedeEditar"
                            />
                            {{ form.ajustes[ajuste.clave] ? 'Sí' : 'No' }}
                        </label>

                        <select
                            v-else-if="ajuste.tipo === 'seleccion'"
                            v-model="form.ajustes[ajuste.clave]"
                            :disabled="!puedeEditar"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <option v-for="(texto, valor) in ajuste.opciones" :key="valor" :value="valor">
                                {{ texto }}
                            </option>
                        </select>

                        <template v-else-if="ajuste.tipo === 'entero'">
                            <input
                                v-model.number="form.ajustes[ajuste.clave]"
                                type="number"
                                :min="ajuste.min ?? undefined"
                                :max="ajuste.max ?? undefined"
                                :disabled="!puedeEditar"
                                class="w-full rounded-lg border px-3 py-2 text-sm"
                                :style="{ borderColor: 'var(--color-borde)' }"
                            />
                            <span
                                v-if="form.ajustes[ajuste.clave] === 0"
                                class="text-xs"
                                :style="{ color: 'var(--color-suave)' }"
                            >
                                0 = sin límite
                            </span>
                        </template>

                        <input
                            v-else
                            v-model="form.ajustes[ajuste.clave]"
                            type="text"
                            :disabled="!puedeEditar"
                            class="w-full rounded-lg border px-3 py-2 font-mono text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                    </div>
                </div>
            </div>
        </section>

        <section v-if="puedeEditar" class="tarjeta p-6">
            <button
                type="button"
                :disabled="form.processing"
                class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="guardar"
            >
                Guardar reglas
            </button>
            <p class="mt-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                Se guardan todas de una vez. Aplican de inmediato a lo que se capture a partir de ahora.
            </p>
        </section>
    </AppLayout>
</template>
