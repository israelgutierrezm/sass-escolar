<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';

interface Candidato {
    id: number;
    matricula: string;
    nombre: string | null;
    carrera: string | null;
    periodo_actual: number | null;
    foto: string | null;
    sugerido: boolean;
}

const props = defineProps<{
    ciclos: { id: number; etiqueta: string }[];
    grupos: { id: number; etiqueta: string }[];
    seleccion: { ciclo_id: number | null; grupo_id: number | null };
    grupo: {
        id: number;
        clave: string;
        plan: string | null;
        ciclo: string | null;
        periodo_objetivo: number | null;
        materias: { clave_en_plan: string | null; nombre: string | null; periodo: number | null }[];
    } | null;
    candidatos: Candidato[];
    puedeInscribir: boolean;
}>();

const cicloId = ref(props.seleccion.ciclo_id);
const grupoId = ref(props.seleccion.grupo_id);

// Al cambiar de ciclo, el grupo elegido deja de aplicar.
watch(cicloId, (nuevo, viejo) => {
    if (nuevo !== viejo) {
        grupoId.value = null;
    }
});
watch([cicloId, grupoId], () => {
    router.get(
        '/escolar/inscripciones/masiva',
        { ciclo_id: cicloId.value, grupo_id: grupoId.value },
        { preserveState: true, replace: true },
    );
});

// Selección de alumnos a inscribir (por id de matrícula).
const seleccionados = ref<Set<number>>(new Set());

function alternar(id: number): void {
    seleccionados.value.has(id) ? seleccionados.value.delete(id) : seleccionados.value.add(id);
    seleccionados.value = new Set(seleccionados.value);
}

function seleccionarSugeridos(): void {
    props.candidatos.filter((c) => c.sugerido).forEach((c) => seleccionados.value.add(c.id));
    seleccionados.value = new Set(seleccionados.value);
}

function limpiar(): void {
    seleccionados.value = new Set();
}

// Buscador: filtra los candidatos por nombre o matrícula; los sugeridos primero.
const busqueda = ref('');
const filtrados = computed(() => {
    const q = busqueda.value.trim().toLowerCase();
    const lista = q
        ? props.candidatos.filter((c) => (c.nombre ?? '').toLowerCase().includes(q) || c.matricula.toLowerCase().includes(q))
        : props.candidatos;

    return [...lista].sort((a, b) => Number(b.sugerido) - Number(a.sugerido));
});

const totalSugeridos = computed(() => props.candidatos.filter((c) => c.sugerido).length);

const form = useForm<{ grupo_id: number | null; matricula_oferta_ids: number[] }>({
    grupo_id: null,
    matricula_oferta_ids: [],
});

function inscribir(): void {
    form.grupo_id = grupoId.value;
    form.matricula_oferta_ids = [...seleccionados.value];
    form.post('/escolar/inscripciones/masiva', {
        preserveScroll: true,
        onSuccess: () => limpiar(),
    });
}

function iniciales(nombre: string | null): string {
    return (nombre ?? '?')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0])
        .join('')
        .toUpperCase();
}
</script>

<template>
    <Head title="Inscripción masiva" />

    <AppLayout titulo="Inscripción masiva">
        <NavEscolar />

        <!-- Selección de ciclo y grupo -->
        <section class="tarjeta p-6">
            <div class="flex items-end justify-between gap-4">
                <div class="grid flex-1 gap-4 sm:grid-cols-2">
                    <CampoSelect
                        v-model="cicloId"
                        etiqueta="Ciclo"
                        :opciones="ciclos.map((c) => ({ valor: c.id, texto: c.etiqueta }))"
                        vacio="Selecciona un ciclo…"
                    />
                    <CampoSelect
                        v-model="grupoId"
                        etiqueta="Grupo"
                        :opciones="grupos.map((g) => ({ valor: g.id, texto: g.etiqueta }))"
                        :vacio="cicloId ? 'Selecciona un grupo…' : 'Elige un ciclo primero'"
                    />
                </div>
                <Link href="/escolar/inscripciones" class="shrink-0 text-sm" :style="{ color: 'var(--color-acento)' }">
                    Inscripción individual →
                </Link>
            </div>
        </section>

        <template v-if="grupo">
            <!-- Info del grupo -->
            <section class="tarjeta p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold">Grupo {{ grupo.clave }}</h2>
                        <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                            {{ grupo.plan ?? 'sin plan' }} · Ciclo {{ grupo.ciclo }}
                            <span v-if="grupo.periodo_objetivo"> · grado objetivo: periodo {{ grupo.periodo_objetivo }}</span>
                        </p>
                    </div>
                    <span class="rounded-full px-3 py-1 text-xs" :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }">
                        {{ grupo.materias.length }} materia(s)
                    </span>
                </div>
                <p class="mt-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                    Cada alumno seleccionado se inscribe en TODAS las materias del grupo. La materia que no pase la
                    validación (seriación, cupo, ya inscrito) se omite.
                </p>
            </section>

            <!-- Buscador + acciones -->
            <section class="tarjeta p-6">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <label class="mb-1 block text-sm font-medium">Buscar alumno</label>
                        <input
                            v-model="busqueda"
                            type="search"
                            placeholder="Nombre o matrícula…"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                        />
                    </div>
                    <div v-if="puedeInscribir" class="flex items-center gap-2">
                        <button
                            type="button"
                            :disabled="!totalSugeridos"
                            class="rounded-lg border px-3 py-2 text-sm disabled:opacity-40"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="seleccionarSugeridos"
                        >
                            Seleccionar sugeridos ({{ totalSugeridos }})
                        </button>
                        <button
                            type="button"
                            :disabled="!seleccionados.size"
                            class="rounded-lg border px-3 py-2 text-sm disabled:opacity-40"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="limpiar"
                        >
                            Limpiar
                        </button>
                    </div>
                </div>

                <!-- Tarjetas de alumnos -->
                <div v-if="filtrados.length" class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <label
                        v-for="c in filtrados"
                        :key="c.id"
                        class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition"
                        :style="{
                            borderColor: seleccionados.has(c.id) ? 'var(--color-acento)' : 'var(--color-borde)',
                            backgroundColor: seleccionados.has(c.id) ? 'color-mix(in srgb, var(--color-acento) 8%, transparent)' : 'transparent',
                        }"
                    >
                        <input type="checkbox" class="sr-only" :checked="seleccionados.has(c.id)" @change="alternar(c.id)" />
                        <img v-if="c.foto" :src="c.foto" :alt="c.nombre ?? ''" class="h-12 w-12 shrink-0 rounded-full object-cover" />
                        <span
                            v-else
                            class="grid h-12 w-12 shrink-0 place-items-center rounded-full text-sm font-semibold"
                            :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }"
                        >
                            {{ iniciales(c.nombre) }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium">{{ c.nombre }}</span>
                            <span class="block truncate font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ c.matricula }}</span>
                            <span class="mt-0.5 flex items-center gap-1.5">
                                <span v-if="c.periodo_actual" class="text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                    Periodo {{ c.periodo_actual }}
                                </span>
                                <span
                                    v-if="c.sugerido"
                                    class="rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                                    :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)', color: 'var(--color-acento)' }"
                                >
                                    Sugerido
                                </span>
                            </span>
                        </span>
                    </label>
                </div>

                <p v-else class="mt-5 rounded-lg border border-dashed px-4 py-8 text-center text-sm" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                    {{ candidatos.length ? 'Ningún alumno coincide con la búsqueda.' : 'No hay alumnos activos del plan sin grupo en este ciclo.' }}
                </p>

                <div v-if="puedeInscribir" class="mt-6 flex items-center gap-3 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <BotonPrincipal
                        :procesando="form.processing"
                        :deshabilitado="!seleccionados.size"
                        :texto="`Inscribir ${seleccionados.size || ''} al grupo`"
                        icono="crear"
                        tipo="button"
                        @click="inscribir"
                    />
                    <span class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ seleccionados.size }} alumno(s) seleccionados × {{ grupo.materias.length }} materias
                    </span>
                </div>
            </section>
        </template>

        <p v-else class="tarjeta px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
            Elige un ciclo y un grupo para ver a los alumnos sugeridos.
        </p>
    </AppLayout>
</template>
