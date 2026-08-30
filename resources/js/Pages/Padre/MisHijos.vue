<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Hijo {
    id: number;
    nombre: string;
    foto: string | null;
    parentesco: string;
    programas_academicos: string[];
    puede_ver_academico: boolean;
    puede_ver_finanzas: boolean;
    estado: {
        promedio: number | null;
        promedio_de: string | null;
        reprobadas: number | null;
        saldo: number | null;
        vencido: boolean;
    };
}

interface Autorizacion {
    id: number;
    titulo: string;
    detalle: string | null;
    tipo: string | null;
    alumno: string | null;
    fecha_limite: string | null;
    vencida: boolean;
    concedida: boolean | null;
    comentario: string | null;
    fecha_respuesta: string | null;
    puede_responder: boolean;
}

const props = defineProps<{ hijos: Hijo[]; autorizaciones: Autorizacion[] }>();

/*
 * Lo que falta contestar va arriba y lo resuelto se guarda detrás de un botón.
 *
 * Un padre que ya autorizó tres salidas no entra a releerlas: entra porque le
 * pidieron algo. Mezclarlas obliga a buscar lo pendiente entre lo hecho, que es
 * justo cuando se pasa un plazo.
 */
const pendientes = computed(() => props.autorizaciones.filter((a) => a.concedida === null && !a.vencida));
const resueltas = computed(() => props.autorizaciones.filter((a) => a.concedida !== null || a.vencida));

const verResueltas = ref(false);

const respuesta = useForm({ concedida: true, comentario: '' });
const respondiendo = ref<number | null>(null);

function responder(autorizacion: Autorizacion, concedida: boolean): void {
    respuesta.concedida = concedida;
    respondiendo.value = autorizacion.id;

    respuesta.put(`/mis-hijos/autorizaciones/${autorizacion.id}`, {
        preserveScroll: true,
        onFinish: () => {
            respondiendo.value = null;
            respuesta.reset();
        },
    });
}

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

/**
 * Un promedio se lee mejor con color que con un número a secas.
 *
 * El corte en 6 es el de aprobación en México; el de 8 no premia, sólo deja de
 * llamar la atención. Un padre que abre esto en el celular tiene que poder
 * decidir en un segundo si hay algo que atender.
 */
function colorPromedio(p: number | null): string | undefined {
    if (p === null) return undefined;
    if (p < 6) return '#dc2626';
    if (p < 8) return '#d97706';

    return '#16a34a';
}

</script>

<template>
    <Head title="Mis hijos" />

    <AppLayout titulo="Mis hijos">
        <p class="max-w-2xl text-sm" :style="{ color: 'var(--color-suave)' }">
            Aquí ves la información de los alumnos que la escuela tiene vinculados contigo.
        </p>

        <!--
            Lo que le piden contestar, antes que nada: tiene plazo, y lo que
            tiene plazo no puede estar debajo de lo que sólo informa.
        -->
        <section v-if="pendientes.length" class="tarjeta mt-4 overflow-hidden">
            <div class="border-b px-6 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                <h2 class="text-base font-semibold">
                    {{ pendientes.length === 1 ? 'Te piden una autorización' : `Te piden ${pendientes.length} autorizaciones` }}
                </h2>
            </div>

            <ul>
                <li
                    v-for="a in pendientes"
                    :key="a.id"
                    class="border-t px-6 py-4 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <p class="font-medium">{{ a.titulo }}</p>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ a.tipo }}<span v-if="a.alumno"> · {{ a.alumno }}</span>
                        <span v-if="a.fecha_limite"> · hasta el {{ a.fecha_limite }}</span>
                    </p>
                    <p v-if="a.detalle" class="mt-1 text-sm">{{ a.detalle }}</p>

                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <input
                            v-model="respuesta.comentario"
                            type="text"
                            placeholder="Comentario (opcional)"
                            class="min-w-0 flex-1 rounded-lg border px-3 py-1.5 text-sm"
                            :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'transparent' }"
                        />
                        <button
                            type="button"
                            class="rounded-lg px-4 py-1.5 text-sm font-medium text-white"
                            style="background-color: #16a34a"
                            :disabled="respondiendo === a.id"
                            @click="responder(a, true)"
                        >
                            Autorizo
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border px-4 py-1.5 text-sm font-medium"
                            style="border-color: #dc2626; color: #dc2626"
                            :disabled="respondiendo === a.id"
                            @click="responder(a, false)"
                        >
                            No autorizo
                        </button>
                    </div>
                </li>
            </ul>
        </section>

        <!--
            Lo ya contestado se conserva —es el acuse de lo que uno autorizó— pero
            plegado: no es a lo que se entra.
        -->
        <div v-if="resueltas.length" class="mt-3">
            <button
                type="button"
                class="text-xs font-medium"
                :style="{ color: 'var(--color-acento)' }"
                @click="verResueltas = !verResueltas"
            >
                {{ verResueltas ? 'Ocultar' : `Ver ${resueltas.length} autorización(es) ya contestada(s)` }}
            </button>

            <ul v-if="verResueltas" class="tarjeta mt-2 overflow-hidden">
                <li
                    v-for="a in resueltas"
                    :key="a.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="min-w-0">
                        <p class="font-medium">{{ a.titulo }}</p>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ a.alumno }}<span v-if="a.fecha_respuesta"> · {{ a.fecha_respuesta }}</span>
                        </p>
                        <p v-if="a.comentario" class="text-xs italic" :style="{ color: 'var(--color-suave)' }">
                            {{ a.comentario }}
                        </p>
                    </div>
                    <span
                        class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium"
                        :style="{
                            backgroundColor: `color-mix(in srgb, ${a.concedida === null ? '#f59e0b' : a.concedida ? '#16a34a' : '#dc2626'} 14%, transparent)`,
                            color: a.concedida === null ? '#b45309' : a.concedida ? '#16a34a' : '#dc2626',
                        }"
                    >
                        {{ a.concedida === null ? 'Sin contestar, ya venció' : a.concedida ? 'Autorizada' : 'No autorizada' }}
                    </span>
                </li>
            </ul>
        </div>

        <section v-if="hijos.length" class="cuadricula-listado">
            <Link
                v-for="hijo in hijos"
                :key="hijo.id"
                :href="`/mis-hijos/${hijo.id}`"
                class="tarjeta tarjeta-interactiva flex flex-col gap-3 p-5"
            >
                <div class="flex items-center gap-3">
                    <AvatarPersona :nombre="hijo.nombre" :foto="hijo.foto" />
                    <div class="min-w-0">
                        <!--
                            Sin `truncate`: es el nombre de su hijo y es el
                            título de la tarjeta. «Mateo Martínez Ramírez» pedía
                            179 px y tenía 175 —cortado por cuatro—, y con dos
                            apellidos largos se corta de verdad. En dos
                            renglones cabe.
                        -->
                        <h3 class="font-medium leading-tight">{{ hijo.nombre }}</h3>
                        <p class="text-xs capitalize" :style="{ color: 'var(--color-suave)' }">{{ hijo.parentesco }}</p>
                    </div>
                </div>

                <!--
                    Los programas académicos, una por renglón cuando son varias.
                    Unidas con «·» en una sola línea, dos programas académicos largas se
                    leían como una sola cosa rara y no se notaba que eran dos.
                    Que estudie dos es justo lo que hay que ver antes de entrar,
                    porque adentro se elige entre ellas.
                -->
                <div v-if="hijo.programas_academicos.length">
                    <p
                        v-if="hijo.programas_academicos.length > 1"
                        class="mb-1 inline-block rounded-full px-2 py-0.5 text-xs"
                        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }"
                    >
                        Estudia {{ hijo.programas_academicos.length }} programas_academicos
                    </p>
                    <!--
                        Y los programas académicos tampoco se truncan, por lo mismo que van
                        una por renglón: «Licenciatura en Administración de
                        Empresas» pide 271 px y tiene 187, así que dos programas académicos
                        que empiecen igual se cortarían al MISMO texto y
                        quedarían indistinguibles — que es justo lo que este
                        diseño venía a evitar.
                    -->
                    <ul class="space-y-0.5 text-sm leading-snug" :style="{ color: 'var(--color-suave)' }">
                        <li v-for="c in hijo.programas_academicos" :key="c">{{ c }}</li>
                    </ul>
                </div>
                <p v-else class="text-sm" :style="{ color: 'var(--color-suave)' }">Sin programas académicos registrados.</p>

                <!--
                    Antes aquí decía «Académico» y «Finanzas»: dos etiquetas que
                    describían PERMISOS, no información. Al padre le da igual
                    cómo se llama lo que puede ver; lo que quiere saber es si su
                    hijo debe algo o va mal. Y si no tiene el permiso, la señal
                    simplemente no viene del servidor.
                -->
                <div class="mt-auto space-y-2 border-t pt-3" :style="{ borderColor: 'var(--color-borde)' }">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm">
                        <!--
                            Con dos programas académicos se enseña el promedio MAS BAJO, que
                            es a lo que hay que atender, y se NOMBRA el programa académico.
                            Sin el nombre se leeria como si fuera el unico que
                            tiene -- y antes ni siquiera era uno de los suyos:
                            se promediaban las dos juntas y salia una cifra que
                            no era el promedio de ninguna.
                        -->
                        <span v-if="hijo.estado.promedio !== null" class="flex items-baseline gap-1.5">
                            <span class="text-xs" :style="{ color: 'var(--color-suave)' }">Promedio</span>
                            <strong class="tabular-nums" :style="{ color: colorPromedio(hijo.estado.promedio) }">
                                {{ hijo.estado.promedio }}
                            </strong>
                            <span
                                v-if="hijo.estado.promedio_de"
                                class="text-xs"
                                :style="{ color: 'var(--color-suave)' }"
                            >
                                en {{ hijo.estado.promedio_de }}
                            </span>
                        </span>

                        <span v-if="hijo.estado.saldo !== null" class="flex items-baseline gap-1.5">
                            <span class="text-xs" :style="{ color: 'var(--color-suave)' }">Saldo</span>
                            <strong
                                class="tabular-nums"
                                :style="{ color: hijo.estado.saldo > 0 ? (hijo.estado.vencido ? '#dc2626' : 'var(--color-contenido)') : '#16a34a' }"
                            >
                                {{ hijo.estado.saldo > 0 ? pesos.format(hijo.estado.saldo) : 'Al corriente' }}
                            </strong>
                        </span>
                    </div>

                    <!--
                        Lo que reclama atención se dice con palabras, no con un
                        color que hay que interpretar.
                    -->
                    <p v-if="hijo.estado.vencido" class="flex items-center gap-1.5 text-xs font-medium text-red-600">
                        <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                        Tiene un pago vencido
                    </p>

                    <p v-if="hijo.estado.reprobadas" class="text-xs font-medium text-red-600">
                        {{ hijo.estado.reprobadas }}
                        {{ hijo.estado.reprobadas === 1 ? 'materia reprobada' : 'materias reprobadas' }}
                    </p>

                    <p
                        v-if="!hijo.puede_ver_academico && !hijo.puede_ver_finanzas"
                        class="text-xs"
                        :style="{ color: 'var(--color-suave)' }"
                    >
                        La escuela no te ha dado acceso a sus calificaciones ni a su estado de cuenta.
                    </p>
                </div>
            </Link>
        </section>

        <p v-else class="tarjeta px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
            Todavía no tienes alumnos vinculados. Pídele a la escuela que te vincule con tus hijos.
        </p>
    </AppLayout>
</template>
