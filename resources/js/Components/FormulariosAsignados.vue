<script setup lang="ts">
import { computed } from 'vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

/**
 * Los formularios que le tocan a esta persona, y cuánto lleva contestado.
 *
 * Sale de `ResolutorFormularios`: rol —con su herencia— más el recorte por
 * nivel, carrera u oferta. Hasta ahora las asignaciones se configuraban y no
 * se veían por ningún lado, así que nadie podía comprobar si lo que había
 * configurado alcanzaba a quien creía.
 *
 * Por eso cada bloque dice POR QUÉ le toca: revisar la configuración desde el
 * constructor es adivinar; verla aplicada sobre una persona concreta es lo que
 * la comprueba.
 */
interface FormularioAsignado {
    id: number;
    clave: string;
    titulo: string;
    obligatorio: boolean;
    motivo: string;
    campos: number;
    contestados: number;
    completo: boolean;
}

const props = defineProps<{
    formularios: FormularioAsignado[];
    /** Para que el texto vacío diga lo que corresponde a cada caso. */
    titular: 'aspirante' | 'alumno';
}>();

const pendientesObligatorios = computed(
    () => props.formularios.filter((f) => f.obligatorio && !f.completo).length,
);

function avance(f: FormularioAsignado): number {
    return f.campos === 0 ? 0 : Math.round((f.contestados / f.campos) * 100);
}
</script>

<template>
    <TarjetaSeccion
        titulo="Formularios"
        descripcion="Los bloques de datos que la escuela le pide, según su rol y su programa."
        :icono="ICONOS.tareaCheck"
    >
        <template #insignia>
            <span
                v-if="formularios.length"
                class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                :style="pendientesObligatorios
                    ? { backgroundColor: 'color-mix(in srgb, #f59e0b 14%, transparent)', color: '#b45309' }
                    : { backgroundColor: 'color-mix(in srgb, #16a34a 12%, transparent)', color: '#16a34a' }"
            >
                {{ pendientesObligatorios
                    ? `Faltan ${pendientesObligatorios} obligatorio(s)`
                    : 'Todo lo obligatorio está' }}
            </span>
        </template>

        <ul v-if="formularios.length" class="divide-y divide-borde">
            <li v-for="f in formularios" :key="f.id" class="py-3 first:pt-0 last:pb-0">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-contenido">
                            {{ f.titulo }}
                            <span
                                v-if="f.obligatorio"
                                class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-700"
                            >Obligatorio</span>
                        </p>
                        <!-- Por qué le toca: es lo que permite comprobar que la
                             asignación configurada alcanza a quien se creía. -->
                        <p class="mt-0.5 text-xs text-suave">{{ f.motivo }}</p>
                    </div>

                    <span class="shrink-0 text-sm tabular-nums" :class="f.completo ? 'text-emerald-600' : 'text-suave'">
                        {{ f.contestados }} de {{ f.campos }}
                    </span>
                </div>

                <div
                    v-if="f.campos"
                    class="mt-2 h-1.5 w-full overflow-hidden rounded-full"
                    style="background-color: var(--color-fondo)"
                >
                    <div
                        class="h-full rounded-full transition-all"
                        :style="{
                            width: `${avance(f)}%`,
                            backgroundColor: f.completo ? '#16a34a' : 'var(--color-acento)',
                        }"
                    />
                </div>

                <!-- Un formulario sin preguntas no se puede contestar, y desde
                     aquí es invisible por qué el avance no se mueve. -->
                <p v-else class="mt-1 text-xs text-amber-700">
                    Este formulario todavía no tiene preguntas.
                </p>
            </li>
        </ul>

        <p v-else class="text-sm text-suave">
            No le toca ninguno. Los formularios se asignan por rol —y opcionalmente por carrera— desde
            el constructor de cada uno; mientras no haya asignaciones, a nadie le aparecen.
        </p>
    </TarjetaSeccion>
</template>
