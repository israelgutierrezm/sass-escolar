<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

/*
 * La portada de un examen, antes de presentarlo.
 *
 * Existe en vez de mandar directo a resolver porque las reglas —cuántos
 * intentos, cuánto tiempo, con cuál se queda— hay que leerlas ANTES de arrancar
 * el reloj, no descubrirlas cuando ya corre.
 */
interface IntentoAlumno {
    id: number;
    numero: number;
    entregado_en: string | null;
    en_curso: boolean;
    resultado: { puntos_obtenidos: number; puntos_posibles: number; en_diez: number | null } | null;
}

const props = defineProps<{
    actividad: {
        id: number;
        titulo: string;
        instrucciones: string | null;
        puntos: number;
        cierra_en: string | null;
        abierta: boolean;
    };
    materia: { id: number; nombre: string };
    examen: {
        intentos_permitidos: number;
        minutos_limite: number | null;
        total_reactivos: number;
        intento_que_cuenta: string;
    };
    intentos: IntentoAlumno[];
    puede_iniciar: boolean;
    intento_en_curso: number | null;
}>();

const cuenta: Record<string, string> = {
    mejor: 'Cuenta tu mejor intento',
    ultimo: 'Cuenta tu último intento',
    primero: 'Cuenta tu primer intento',
};

function iniciar(): void {
    router.post(`/mis-cursos/examenes/${props.actividad.id}/iniciar`);
}
</script>

<template>
    <Head :title="actividad.titulo" />

    <AppLayout titulo="Examen">
        <section class="tarjeta p-6">
            <BotonVolver :href="`/mis-cursos/${materia.id}`" :texto="materia.nombre" class="mb-4" />

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-contenido">{{ actividad.titulo }}</h2>
                    <p class="mt-0.5 text-sm text-suave">{{ materia.nombre }}</p>
                </div>
                <PildoraEstado
                    :texto="actividad.abierta ? 'Abierto' : 'Cerrado'"
                    :color="actividad.abierta ? '#16a34a' : '#dc2626'"
                />
            </div>

            <p v-if="actividad.instrucciones" class="mt-4 whitespace-pre-line text-sm">
                {{ actividad.instrucciones }}
            </p>

            <dl class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-xs text-suave">Reactivos</dt>
                    <dd class="mt-0.5 text-sm font-medium text-contenido">{{ examen.total_reactivos }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-suave">Vale</dt>
                    <dd class="mt-0.5 text-sm font-medium text-contenido">{{ actividad.puntos }} puntos</dd>
                </div>
                <div>
                    <dt class="text-xs text-suave">Tiempo</dt>
                    <dd class="mt-0.5 text-sm font-medium text-contenido">
                        {{ examen.minutos_limite ? `${examen.minutos_limite} minutos` : 'Sin límite' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-suave">Intentos</dt>
                    <dd class="mt-0.5 text-sm font-medium text-contenido">
                        {{ intentos.length }} de {{ examen.intentos_permitidos }}
                    </dd>
                </div>
            </dl>

            <p class="mt-4 text-xs text-suave">
                {{ cuenta[examen.intento_que_cuenta] }}.
                <span v-if="actividad.cierra_en"> Cierra el {{ actividad.cierra_en }}.</span>
                <span v-if="examen.minutos_limite">
                    El tiempo corre desde que lo abres, aunque cierres la ventana.
                </span>
            </p>

            <div class="mt-5 flex flex-wrap gap-2">
                <button
                    v-if="intento_en_curso"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-white"
                    :style="{ backgroundColor: '#d97706' }"
                    @click="router.get(`/mis-cursos/intentos/${intento_en_curso}`)"
                >
                    Continuar el intento en curso
                </button>
                <button
                    v-else-if="puede_iniciar"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-white"
                    :style="{ backgroundColor: 'var(--color-acento)' }"
                    @click="iniciar"
                >
                    {{ intentos.length ? 'Presentar otro intento' : 'Comenzar examen' }}
                </button>
                <p v-else class="text-sm text-suave">
                    {{ actividad.abierta ? 'Ya usaste todos tus intentos.' : 'Este examen ya cerró.' }}
                </p>
            </div>
        </section>

        <section v-if="intentos.length" class="tarjeta overflow-hidden">
            <header class="px-6 py-4">
                <h2 class="text-base font-semibold text-contenido">Tus intentos</h2>
            </header>

            <ul class="divide-y divide-borde border-t border-borde">
                <li v-for="i in intentos" :key="i.id" class="flex flex-wrap items-center gap-4 px-6 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="text-sm font-medium text-contenido">Intento {{ i.numero }}</span>
                        <span v-if="i.entregado_en" class="ml-2 text-xs text-suave">
                            entregado el {{ i.entregado_en }}
                        </span>
                        <span v-else class="ml-2 text-xs" :style="{ color: '#d97706' }">sin entregar</span>
                    </span>

                    <span v-if="i.resultado" class="text-sm font-semibold tabular-nums text-contenido">
                        {{ i.resultado.puntos_obtenidos }} / {{ i.resultado.puntos_posibles }}
                        <span v-if="i.resultado.en_diez !== null" class="ml-1 text-xs text-suave">
                            ({{ i.resultado.en_diez }})
                        </span>
                    </span>
                    <span v-else-if="i.entregado_en" class="text-xs text-suave">
                        El resultado todavía no se publica
                    </span>

                    <a
                        v-if="i.entregado_en"
                        :href="`/mis-cursos/intentos/${i.id}`"
                        class="rounded-lg border px-3 py-1.5 text-xs font-medium"
                        :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                    >
                        Ver
                    </a>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
