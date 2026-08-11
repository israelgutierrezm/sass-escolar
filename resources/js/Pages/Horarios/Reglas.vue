<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import { ICONOS } from '@/iconos';

/**
 * Con qué criterios se arma un horario.
 *
 * ── Se ve el efecto mientras se escribe ────────────────────────────────────
 * «Bloques de 60 minutos» y «sesiones de hasta 3» no dicen gran cosa por
 * separado; lo que importa es cuántas clases caben al día y cómo queda partida
 * una materia. Eso se calcula aquí en vivo, porque descubrirlo después de
 * generar —cuando el motor no colocó nada— manda a revisar la disponibilidad de
 * los docentes, que no tiene nada que ver.
 */
interface Regla {
    id: number;
    nombre: string;
    ciclo_id: number | null;
    ciclo: string | null;
    campus_id: number | null;
    campus: string | null;
    dias: number[];
    hora_apertura: string;
    hora_cierre: string;
    minutos_bloque: number;
    bloques_min_por_sesion: number;
    bloques_max_por_sesion: number;
    max_sesiones_por_dia: number;
    horas_max_dia_docente: number | null;
    horas_max_semana_docente: number | null;
    minutos_descanso_docente: number;
    reparto: string;
    permite_huecos_grupo: boolean;
    activa: boolean;
    bloques_al_dia: number;
}

const props = defineProps<{
    reglas: Regla[];
    ciclos: { id: number; nombre: string }[];
    campus: { id: number; nombre: string }[];
}>();

const DIAS = [
    { numero: 1, corto: 'L' },
    { numero: 2, corto: 'M' },
    { numero: 3, corto: 'X' },
    { numero: 4, corto: 'J' },
    { numero: 5, corto: 'V' },
    { numero: 6, corto: 'S' },
    { numero: 7, corto: 'D' },
];

const editando = ref<number | null>(null);

const form = useForm({
    nombre: '',
    ciclo_id: null as number | null,
    campus_id: null as number | null,
    dias: [1, 2, 3, 4, 5] as number[],
    hora_apertura: '07:00',
    hora_cierre: '15:00',
    minutos_bloque: 60,
    bloques_min_por_sesion: 1,
    bloques_max_por_sesion: 2,
    max_sesiones_por_dia: 1,
    horas_max_dia_docente: null as number | null,
    horas_max_semana_docente: null as number | null,
    minutos_descanso_docente: 0,
    reparto: 'repartir',
    permite_huecos_grupo: false,
    activa: true,
});

function nueva(): void {
    editando.value = null;
    form.reset();
    form.clearErrors();
}

function editar(regla: Regla): void {
    editando.value = regla.id;
    form.clearErrors();

    Object.assign(form, {
        nombre: regla.nombre,
        ciclo_id: regla.ciclo_id,
        campus_id: regla.campus_id,
        dias: [...regla.dias],
        hora_apertura: regla.hora_apertura,
        hora_cierre: regla.hora_cierre,
        minutos_bloque: regla.minutos_bloque,
        bloques_min_por_sesion: regla.bloques_min_por_sesion,
        bloques_max_por_sesion: regla.bloques_max_por_sesion,
        max_sesiones_por_dia: regla.max_sesiones_por_dia,
        horas_max_dia_docente: regla.horas_max_dia_docente,
        horas_max_semana_docente: regla.horas_max_semana_docente,
        minutos_descanso_docente: regla.minutos_descanso_docente,
        reparto: regla.reparto,
        permite_huecos_grupo: regla.permite_huecos_grupo,
        activa: regla.activa,
    });
}

function guardar(): void {
    const opciones = { preserveScroll: true, onSuccess: () => nueva() };

    if (editando.value) {
        form.put(`/escolar/reglas-horario/${editando.value}`, opciones);
    } else {
        form.post('/escolar/reglas-horario', opciones);
    }
}

function eliminar(regla: Regla): void {
    if (!confirm(`¿Eliminar la regla «${regla.nombre}»?`)) return;

    router.delete(`/escolar/reglas-horario/${regla.id}`, { preserveScroll: true });
}

function alternarDia(dia: number): void {
    form.dias = form.dias.includes(dia)
        ? form.dias.filter((d) => d !== dia)
        : [...form.dias, dia].sort();
}

/* ── El efecto de lo que se está escribiendo ────────────────────────────── */

const minutosJornada = computed(() => {
    const [hi, mi] = form.hora_apertura.split(':').map(Number);
    const [hf, mf] = form.hora_cierre.split(':').map(Number);

    return (hf * 60 + mf) - (hi * 60 + mi);
});

const bloquesAlDia = computed(() => Math.floor(minutosJornada.value / (form.minutos_bloque || 1)));

/** Cómo quedaría partida una materia de 5 horas: el caso que más se pregunta. */
const ejemplo = computed(() => {
    const porBloque = form.minutos_bloque / 60;
    let pendientes = 5 / porBloque; // en bloques
    const sesiones: number[] = [];

    // Mismo criterio que el generador: la sesión más larga primero.
    while (pendientes > 0 && sesiones.length < 7) {
        const cabe = Math.min(form.bloques_max_por_sesion, pendientes);
        const sesion = Math.max(form.bloques_min_por_sesion, Math.floor(cabe)) || 1;

        sesiones.push(Math.min(sesion, pendientes));
        pendientes -= sesion;
    }

    return sesiones.map((s) => `${Math.round(s * porBloque * 10) / 10} h`).join(' + ');
});

const alcanceDe = (r: Regla): string => {
    if (r.ciclo && r.campus) return `${r.ciclo} · ${r.campus}`;
    if (r.ciclo) return `Ciclo ${r.ciclo}`;
    if (r.campus) return r.campus;

    return 'Toda la escuela';
};
</script>

<template>
    <Head title="Reglas de horario" />

    <AppLayout titulo="Reglas de horario">
        <PestanasSeccion />

        <p class="mb-4 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
            Con estos criterios se arman los horarios: la jornada, cuánto dura una clase y cuánta carga
            puede llevar un docente. Basta con una regla para toda la escuela; agrega otras sólo si algún
            ciclo o campus trabaja distinto.
        </p>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- Las que ya existen -->
            <section class="space-y-3 lg:col-span-2">
                <article
                    v-for="r in reglas"
                    :key="r.id"
                    class="tarjeta p-5"
                    :style="editando === r.id ? { outline: '2px solid var(--color-acento)' } : {}"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold">{{ r.nombre }}</h3>
                                <PildoraEstado v-if="!r.activa" texto="Inactiva" color="var(--color-suave)" />
                            </div>
                            <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-acento)' }">
                                {{ alcanceDe(r) }}
                            </p>
                        </div>

                        <div class="flex shrink-0 gap-1">
                            <BotonAccion variante="editar" texto="Editar" @click="editar(r)" />
                            <BotonAccion variante="eliminar" texto="Eliminar" @click="eliminar(r)" />
                        </div>
                    </div>

                    <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Jornada</dt>
                            <dd>
                                {{ r.hora_apertura }}–{{ r.hora_cierre }}
                                <span :style="{ color: 'var(--color-suave)' }">· {{ r.bloques_al_dia }} bloques</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Días</dt>
                            <dd>
                                <span v-for="d in DIAS" :key="d.numero" :style="{ opacity: r.dias.includes(d.numero) ? 1 : 0.25 }">
                                    {{ d.corto }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Clase</dt>
                            <dd>
                                {{ r.minutos_bloque }} min ·
                                sesiones de {{ r.bloques_min_por_sesion }} a {{ r.bloques_max_por_sesion }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Tope docente</dt>
                            <dd>
                                <template v-if="r.horas_max_dia_docente || r.horas_max_semana_docente">
                                    {{ r.horas_max_dia_docente ?? '—' }} h/día ·
                                    {{ r.horas_max_semana_docente ?? '—' }} h/sem
                                </template>
                                <span v-else :style="{ color: 'var(--color-suave)' }">Sin límite</span>
                            </dd>
                        </div>
                    </dl>
                </article>

                <p v-if="!reglas.length" class="tarjeta px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    Todavía no hay reglas. Sin al menos una no se puede generar horarios automáticamente
                    —capturarlos a mano sí, eso nunca dependió de esto—.
                </p>
            </section>

            <!-- El formulario -->
            <TarjetaSeccion
                :titulo="editando ? 'Editar regla' : 'Nueva regla'"
                descripcion="La jornada y los límites con los que se arma."
                :icono="ICONOS.ajustes"
            >
                <div class="space-y-3 text-sm">
                    <label class="block">
                        <span class="mb-1 block font-medium">Nombre</span>
                        <input v-model="form.nombre" type="text" placeholder="Jornada matutina" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                        <span v-if="form.errors.nombre" class="text-xs text-red-600">{{ form.errors.nombre }}</span>
                    </label>

                    <!-- El alcance: lo que decide cuándo aplica. -->
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">
                            <span class="mb-1 block font-medium">Ciclo</span>
                            <select v-model="form.ciclo_id" class="w-full rounded-lg border bg-transparent px-2 py-1.5" :style="{ borderColor: 'var(--color-borde)' }">
                                <option :value="null">Todos</option>
                                <option v-for="c in ciclos" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block font-medium">Campus</span>
                            <select v-model="form.campus_id" class="w-full rounded-lg border bg-transparent px-2 py-1.5" :style="{ borderColor: 'var(--color-borde)' }">
                                <option :value="null">Todos</option>
                                <option v-for="c in campus" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                            </select>
                        </label>
                    </div>

                    <div>
                        <span class="mb-1 block font-medium">Días con clase</span>
                        <div class="flex gap-1">
                            <button
                                v-for="d in DIAS"
                                :key="d.numero"
                                type="button"
                                class="h-8 w-8 rounded-lg border text-xs font-medium transition"
                                :style="form.dias.includes(d.numero)
                                    ? { borderColor: 'var(--color-acento)', backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }
                                    : { borderColor: 'var(--color-borde)', opacity: 0.6 }"
                                @click="alternarDia(d.numero)"
                            >
                                {{ d.corto }}
                            </button>
                        </div>
                        <span v-if="form.errors.dias" class="text-xs text-red-600">{{ form.errors.dias }}</span>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">
                            <span class="mb-1 block font-medium">Abre</span>
                            <input v-model="form.hora_apertura" type="time" class="w-full rounded-lg border bg-transparent px-2 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                        </label>
                        <label class="block">
                            <span class="mb-1 block font-medium">Cierra</span>
                            <input v-model="form.hora_cierre" type="time" class="w-full rounded-lg border bg-transparent px-2 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                        </label>
                    </div>
                    <span v-if="form.errors.hora_cierre" class="text-xs text-red-600">{{ form.errors.hora_cierre }}</span>

                    <label class="block">
                        <span class="mb-1 block font-medium">Cada clase dura (minutos)</span>
                        <input v-model.number="form.minutos_bloque" type="number" min="15" max="240" step="5" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                    </label>

                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">
                            <span class="mb-1 block font-medium">Sesión mínima</span>
                            <input v-model.number="form.bloques_min_por_sesion" type="number" min="1" max="8" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                        </label>
                        <label class="block">
                            <span class="mb-1 block font-medium">Sesión máxima</span>
                            <input v-model.number="form.bloques_max_por_sesion" type="number" min="1" max="8" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                        </label>
                    </div>
                    <span v-if="form.errors.bloques_max_por_sesion" class="text-xs text-red-600">{{ form.errors.bloques_max_por_sesion }}</span>

                    <!--
                        El efecto, mientras se escribe. Sin esto, «bloques de 60»
                        y «sesiones de hasta 3» no dicen si va a caber nada, y se
                        descubre al generar, cuando el motivo apunta a otro lado.
                    -->
                    <div class="rounded-lg p-3 text-xs" :style="{ backgroundColor: 'var(--color-fondo)' }">
                        <p v-if="bloquesAlDia < 1" class="text-red-600">
                            Con esa jornada no cabe ni una clase.
                        </p>
                        <template v-else>
                            <p><strong>{{ bloquesAlDia }}</strong> clases caben al día.</p>
                            <p class="mt-0.5" :style="{ color: 'var(--color-suave)' }">
                                Una materia de 5 h quedaría: {{ ejemplo }}
                            </p>
                        </template>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">
                            <span class="mb-1 block font-medium">Máx. h/día docente</span>
                            <input v-model.number="form.horas_max_dia_docente" type="number" min="1" max="24" placeholder="Sin límite" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                        </label>
                        <label class="block">
                            <span class="mb-1 block font-medium">Máx. h/semana</span>
                            <input v-model.number="form.horas_max_semana_docente" type="number" min="1" max="80" placeholder="Sin límite" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                        </label>
                    </div>

                    <label class="block">
                        <span class="mb-1 block font-medium">Descanso entre clases (min)</span>
                        <input v-model.number="form.minutos_descanso_docente" type="number" min="0" max="180" step="5" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                    </label>

                    <label class="fila-casilla">
                        <input v-model="form.activa" type="checkbox" />
                        <span>Activa</span>
                    </label>

                    <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
                        <button v-if="editando" type="button" class="text-sm" :style="{ color: 'var(--color-suave)' }" @click="nueva">
                            Cancelar
                        </button>
                        <BotonPrincipal tipo="button" :procesando="form.processing" class="ml-auto" @click="guardar">
                            {{ editando ? 'Guardar cambios' : 'Crear regla' }}
                        </BotonPrincipal>
                    </div>
                </div>
            </TarjetaSeccion>
        </div>
    </AppLayout>
</template>
