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
    /** Por qué le toca: null = por su rol; si no, el recorte que lo alcanzó. */
    ambito: string | null;
    campos: number;
    contestados: number;
    completo: boolean;
}

const props = withDefaults(defineProps<{
    formularios: FormularioAsignado[];
    /**
     * Para que el texto diga lo que corresponde a cada caso. Un docente no
     * tiene programa, y prometerle un recorte por carrera que no existe manda
     * a buscar una configuración que nunca va a encontrar.
     */
    titular: 'aspirante' | 'alumno' | 'docente' | 'tutor' | 'persona';
    /** Dónde se contesta cada uno. Sin ella el panel sólo informa. */
    baseCaptura?: string;
    puedeCapturar?: boolean;
    /**
     * Le habla a la persona misma, no a quien la atiende.
     *
     * El componente vive en dos sitios muy distintos: la ficha administrativa
     * —donde se habla DE alguien— y el autoservicio —donde se habla CON él—.
     * «Los bloques que la escuela le pide, según su rol» leído en tu propia
     * pantalla suena a que se refiere a otra persona.
     */
    tuteo?: boolean;
    /**
     * Cómo se llama el bloque en ESTA pantalla.
     *
     * «Formularios» es la palabra de quien los configura. A un padre de familia
     * no le dice nada —y peor, delata que alguien se los cargó desde una
     * administración—; lo que él tiene enfrente son datos que la escuela le
     * pide. Cada pantalla lo nombra según su contexto, y el default queda para
     * las fichas administrativas, donde el término sí es el correcto.
     */
    titulo?: string;
}>(), {
    puedeCapturar: false,
    tuteo: false,
    titulo: 'Formularios',
    baseCaptura: undefined,
});

const pendientesObligatorios = computed(
    () => props.formularios.filter((f) => f.obligatorio && !f.completo).length,
);

/** Sólo aspirante y alumno se recortan por carrera; los demás, por rol y ya. */
const porCarrera = computed(() => props.titular === 'aspirante' || props.titular === 'alumno');

const descripcion = computed(() => {
    if (props.tuteo) {
        // Sin hablar de roles ni de bloques: al interesado le importa qué le
        // piden, no cómo está organizado por dentro el sistema que se lo pide.
        return 'Lo que la escuela necesita que llenes.';
    }

    return porCarrera.value
        ? 'Los bloques de datos que la escuela le pide, según su rol y su programa.'
        : 'Los bloques de datos que la escuela le pide, según su rol.';
});

const vacio = computed(() => {
    if (props.tuteo) {
        return 'La escuela no te pide nada por ahora.';
    }

    return porCarrera.value
        ? 'No le toca ninguno. Los formularios se asignan por rol —y opcionalmente por carrera— desde el constructor de cada uno; mientras no haya asignaciones, a nadie le aparecen.'
        : 'No le toca ninguno. Los formularios se asignan por rol desde el constructor de cada uno; mientras no haya asignaciones, a nadie le aparecen.';
});

/** Por qué le toca, dicho en la persona que corresponde. */
function motivo(f: FormularioAsignado): string {
    const posesivo = props.tuteo ? 'tu' : 'su';

    return `Por ${posesivo} ${f.ambito ?? 'rol'}`;
}

function avance(f: FormularioAsignado): number {
    return f.campos === 0 ? 0 : Math.round((f.contestados / f.campos) * 100);
}
</script>

<template>
    <TarjetaSeccion
        :titulo="titulo"
        :descripcion="descripcion"
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
                        <p class="mt-0.5 text-xs text-suave">{{ motivo(f) }}</p>
                    </div>

                    <div class="flex shrink-0 items-center gap-3">
                        <span class="text-sm tabular-nums" :class="f.completo ? 'text-emerald-600' : 'text-suave'">
                            {{ f.contestados }} de {{ f.campos }}
                        </span>
                        <!-- El panel decía qué falta y no dejaba hacer nada al
                             respecto: había que buscar dónde contestarlo. -->
                        <a
                            v-if="puedeCapturar && baseCaptura && f.campos"
                            :href="`${baseCaptura}/${f.id}`"
                            class="rounded-lg border px-3 py-1 text-xs transition hover:bg-fondo"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >{{ f.contestados ? 'Continuar' : 'Contestar' }}</a>
                    </div>
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

                <!-- Uno sin preguntas no se puede contestar, y desde aquí es
                     invisible por qué el avance no se mueve. A quien lo
                     configura se le dice «formulario», que es donde tiene que
                     ir a arreglarlo; a los demás, «apartado». -->
                <p v-else class="mt-1 text-xs text-amber-700">
                    {{ tuteo ? 'Este apartado todavía no tiene preguntas.' : 'Este formulario todavía no tiene preguntas.' }}
                </p>
            </li>
        </ul>

        <p v-else class="text-sm text-suave">{{ vacio }}</p>
    </TarjetaSeccion>
</template>
