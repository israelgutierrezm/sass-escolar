<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import { toast } from 'vue-sonner';
import type { PropsCompartidas } from '@/tipos';

/**
 * Repartir los alumnos entre los tutores educativos.
 *
 * ── Por qué es masivo ──────────────────────────────────────────────────────
 * Una tutoría no se asigna sola: se reparte una generación completa al empezar
 * el ciclo. Con altas de una en una, repartir cien alumnos entre cinco tutores
 * son cien formularios. Aquí se filtra, se palomea y se asigna de un golpe.
 *
 * ── Los sin tutor van primero ──────────────────────────────────────────────
 * Ordenar por nombre repartiría el trabajo pendiente por toda la lista. Lo que
 * hay que atender va arriba, y arriba del todo está cuántos son.
 */
interface AlumnoFila {
    id: number;
    nombre: string;
    matricula: string | null;
    carrera: string | null;
    grupos: string[];
    tutor: string | null;
    tutoria_id: number | null;
    sesiones: number;
    ultima_sesion: string | null;
}

const props = defineProps<{
    ciclos: { id: number; nombre: string }[];
    cicloSeleccionado: number | null;
    tutores: { id: number; nombre: string; tutorados: number }[];
    alumnos: AlumnoFila[];
    resumen: { total: number; sin_tutor: number };
    carreras: string[];
    grupos: string[];
}>();

/**
 * Leer bitácoras es un permiso APARTE de repartir tutorías.
 *
 * Quien coordina el reparto necesita saber CUÁNTAS sesiones hubo —para
 * detectar al tutor que no está viendo a nadie—, pero no lo que se habló en
 * ellas: ahí hay notas de situación personal del alumno. La ruta lo comprueba
 * en el servidor; esto sólo evita ofrecer un enlace que iba a dar 403.
 */
const puedeLeerBitacoras = computed(
    () => (usePage<PropsCompartidas>().props.auth.usuario?.permisos ?? []).includes('ver-bitacoras-tutoria'),
);

/* ── Filtros ───────────────────────────────────────────────────────────── */

const busqueda = ref('');
const soloSinTutor = ref(false);

/*
 * Filtrar por carrera o por grupo ES el reparto por carrera o por grupo.
 *
 * No hace falta un botón de «asignar a toda la carrera»: se acota la lista y
 * se palomea el encabezado, que ya alcanza sólo a los VISIBLES. Un botón
 * aparte tendría que decidir por su cuenta a quién incluye —¿también a los que
 * ya tienen tutor?— sin que se vea antes de pulsarlo, y aquí se ve.
 */
const carrera = ref('');
const grupo = ref('');

const visibles = computed(() => {
    const q = busqueda.value.trim().toLowerCase();

    return props.alumnos.filter((a) => {
        if (soloSinTutor.value && a.tutor !== null) return false;
        if (carrera.value !== '' && a.carrera !== carrera.value) return false;
        if (grupo.value !== '' && ! a.grupos.includes(grupo.value)) return false;
        if (q === '') return true;

        return (
            a.nombre.toLowerCase().includes(q) ||
            (a.matricula ?? '').toLowerCase().includes(q) ||
            (a.carrera ?? '').toLowerCase().includes(q)
        );
    });
});

/** El ciclo recarga desde el servidor: cambia a quién tiene asignado quién. */
function cambiarCiclo(id: number | null): void {
    router.get('/escolar/tutorias', id === null ? {} : { ciclo_id: id }, {
        preserveState: false,
        preserveScroll: true,
    });
}

/* ── Selección ─────────────────────────────────────────────────────────── */

const elegidos = ref<number[]>([]);

/*
 * «Todos» alcanza a los VISIBLES, no a los 500 de la escuela. Una casilla que
 * selecciona lo que no se está viendo es la forma más rápida de reasignar a
 * media escuela sin querer.
 */
const todosVisibles = computed({
    get: () => visibles.value.length > 0 && visibles.value.every((a) => elegidos.value.includes(a.id)),
    set: (valor: boolean) => {
        const ids = visibles.value.map((a) => a.id);
        elegidos.value = valor
            ? [...new Set([...elegidos.value, ...ids])]
            : elegidos.value.filter((id) => !ids.includes(id));
    },
});

/* ── Asignar ───────────────────────────────────────────────────────────── */

const form = useForm({
    tutor_persona_id: null as number | null,
    ciclo_id: props.cicloSeleccionado,
    alumnos: [] as number[],
});

/** Cuántos de los elegidos YA tienen tutor: se les va a cambiar, no a poner. */
const conTutor = computed(
    () => props.alumnos.filter((a) => elegidos.value.includes(a.id) && a.tutor !== null).length,
);

function asignar(): void {
    if (form.tutor_persona_id === null) {
        toast.error('Elige a quién se los vas a asignar.');

        return;
    }

    if (elegidos.value.length === 0) {
        toast.error('No has palomeado a ningún alumno.');

        return;
    }

    form.ciclo_id = props.cicloSeleccionado;
    form.alumnos = [...elegidos.value];
    form.post('/escolar/tutorias', {
        preserveScroll: true,
        onSuccess: () => {
            elegidos.value = [];
            form.reset('tutor_persona_id');
        },
    });
}

function quitar(a: AlumnoFila): void {
    if (!confirm(`¿Quitarle el tutor a ${a.nombre}?`)) return;

    router.delete(`/escolar/tutorias/${a.tutoria_id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Tutorías" />

    <AppLayout titulo="Tutorías">
        <section class="tarjeta mb-4 p-6">
            <div class="flex flex-wrap items-end gap-4">
                <div class="w-48">
                    <CampoSelect
                        :model-value="cicloSeleccionado"
                        etiqueta="Ciclo"
                        vacio="Sin ciclo"
                        :opciones="ciclos.map((c) => ({ valor: c.id, texto: c.nombre }))"
                        @update:model-value="(v) => cambiarCiclo(v === '' || v === null ? null : Number(v))"
                    />
                </div>

                <div class="flex-1">
                    <p class="text-sm">
                        <strong class="tabular-nums">{{ resumen.total }}</strong> alumnos ·
                        <strong
                            class="tabular-nums"
                            :style="{ color: resumen.sin_tutor > 0 ? '#d97706' : '#16a34a' }"
                        >
                            {{ resumen.sin_tutor }}
                        </strong>
                        sin tutor
                    </p>
                    <!--
                        Se dice qué significa el ciclo vacío: sin esto, quien
                        entra por primera vez no sabe si «Sin ciclo» es un
                        filtro o un estado.
                    -->
                    <p class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                        Las tutorías se llevan por ciclo. «Sin ciclo» es para escuelas que no los usan
                        en la tutoría: valen para todo el tiempo que el alumno esté inscrito.
                    </p>
                </div>
            </div>
        </section>

        <!--
            Asignar: primero a quién, luego a cuántos. Se queda pegado arriba al
            desplazarse porque la lista es larga y el botón vive aquí.
        -->
        <section class="tarjeta mb-4 p-5 lg:sticky lg:top-4 lg:z-10">
            <div class="flex flex-wrap items-end gap-4">
                <div class="w-72">
                    <CampoSelect
                        v-model="form.tutor_persona_id"
                        etiqueta="Asignar a"
                        vacio="Elige un tutor…"
                        :opciones="tutores.map((t) => ({ valor: t.id, texto: `${t.nombre} (${t.tutorados})` }))"
                        :error="form.errors.tutor_persona_id"
                    />
                    <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                        Entre paréntesis, cuántos lleva ya.
                    </p>
                </div>

                <BotonPrincipal
                    :procesando="form.processing"
                    :deshabilitado="elegidos.length === 0 || form.tutor_persona_id === null"
                    :texto="elegidos.length === 0 ? 'Asignar' : `Asignar a ${elegidos.length}`"
                    cargando="Asignando…"
                    icono="ninguno"
                    @click="asignar"
                />

                <!--
                    Se avisa de la reasignación ANTES de pulsar. Cambiarle el
                    tutor a alguien que ya lo tenía es legítimo y frecuente; lo
                    que no puede pasar es que ocurra sin que nadie se entere.
                -->
                <p v-if="conTutor > 0" class="text-xs text-amber-700">
                    {{ conTutor }} de los seleccionados ya tienen tutor: se les cambiará.
                </p>
            </div>

            <p v-if="!tutores.length" class="mt-3 text-sm text-amber-700">
                No hay nadie con el rol de tutor educativo. Asígnaselo primero a alguien desde
                Usuarios; aquí sólo aparecen quienes lo tienen.
            </p>
        </section>

        <section class="tarjeta overflow-hidden">
            <div class="flex flex-wrap items-center gap-4 border-b px-5 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                <div class="w-64">
                    <CampoTexto v-model="busqueda" etiqueta="" marcador="Buscar por nombre, matrícula o carrera" />
                </div>
                <div v-if="carreras.length > 1" class="w-56">
                    <CampoSelect
                        v-model="carrera"
                        etiqueta=""
                        vacio="Todas las carreras"
                        :opciones="carreras.map((c) => ({ valor: c, texto: c }))"
                    />
                </div>

                <div v-if="grupos.length" class="w-40">
                    <CampoSelect
                        v-model="grupo"
                        etiqueta=""
                        vacio="Todos los grupos"
                        :opciones="grupos.map((g) => ({ valor: g, texto: g }))"
                    />
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="soloSinTutor" type="checkbox" />
                    Sólo los que no tienen tutor
                </label>
                <span class="ml-auto flex items-center gap-4 text-xs" :style="{ color: 'var(--color-suave)' }">
                    <Link
                        v-if="puedeLeerBitacoras"
                        href="/escolar/tutorias/accesos"
                        :style="{ color: 'var(--color-acento)' }"
                    >
                        Quién ha abierto bitácoras
                    </Link>
                    {{ visibles.length }} en pantalla
                </span>
            </div>

            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr class="border-b" :style="{ borderColor: 'var(--color-borde)' }">
                        <th class="w-10 px-5 py-2">
                            <input v-model="todosVisibles" type="checkbox" :title="`Seleccionar los ${visibles.length} visibles`" />
                        </th>
                        <th class="py-2 font-medium">Alumno</th>
                        <th class="py-2 font-medium">Carrera</th>
                        <th class="py-2 font-medium">Tutor</th>
                        <!--
                            Asignar tutores no sirve de nada si nadie comprueba
                            que las sesiones ocurren: un alumno con tutor desde
                            marzo y cero sesiones es el caso que hay que ver, y
                            sin esta columna no se distingue del que va al día.
                        -->
                        <th class="py-2 font-medium">Sesiones</th>
                        <th class="w-10 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="a in visibles"
                        :key="a.id"
                        class="border-b last:border-0"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <td class="px-5 py-2.5">
                            <input v-model="elegidos" type="checkbox" :value="a.id" />
                        </td>
                        <td class="py-2.5">
                            <span class="font-medium">{{ a.nombre }}</span>
                            <span v-if="a.matricula" class="ml-2 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ a.matricula }}
                            </span>
                        </td>
                        <td class="py-2.5" :style="{ color: 'var(--color-suave)' }">
                            {{ a.carrera ?? '—' }}
                            <!-- El grupo, a la vista: filtrar por algo que no se
                                 ve en la fila hace dudar de si el filtro acertó. -->
                            <span v-if="a.grupos.length" class="ml-1 text-xs">
                                · {{ a.grupos.join(', ') }}
                            </span>
                        </td>
                        <td class="py-2.5">
                            <span v-if="a.tutor">{{ a.tutor }}</span>
                            <span v-else class="text-xs font-medium text-amber-700">Sin tutor</span>
                        </td>
                        <td class="py-2.5">
                            <!--
                                Se marca en ámbar el que TIENE tutor y ninguna
                                sesión: sin tutor asignado, cero sesiones es lo
                                esperado y pintarlo de alarma sería ruido.
                            -->
                            <!--
                                El CONTEO se ve siempre; abrir lo que se dijo,
                                sólo con permiso. Supervisar que la tutoría
                                ocurre no exige leer las notas personales del
                                alumno, y son dos oficios distintos: coordinar el
                                reparto y acompañar el caso.
                            -->
                            <component
                                :is="puedeLeerBitacoras ? Link : 'span'"
                                :href="puedeLeerBitacoras ? `/escolar/tutorias/${a.id}/bitacora` : undefined"
                                class="text-sm"
                                :style="{
                                    color: a.tutor && a.sesiones === 0
                                        ? '#d97706'
                                        : (puedeLeerBitacoras ? 'var(--color-acento)' : 'var(--color-contenido)'),
                                }"
                                :title="a.ultima_sesion ? `Última: ${a.ultima_sesion}` : 'Sin sesiones anotadas'"
                            >
                                {{ a.sesiones }}
                                <span v-if="a.ultima_sesion" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    · {{ a.ultima_sesion }}
                                </span>
                            </component>
                        </td>
                        <td class="py-2.5 pr-5">
                            <BotonAccion v-if="a.tutoria_id" variante="eliminar" texto="Quitar el tutor" @click="quitar(a)" />
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-if="!visibles.length" class="px-5 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Nada que mostrar con ese filtro.
            </p>
        </section>
    </AppLayout>
</template>
