<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import { ICONOS } from '@/iconos';

/**
 * Cuándo puede dar clase un docente.
 *
 * ── Por qué una semana y no una lista ──────────────────────────────────────
 * La disponibilidad se piensa por días: «los lunes y miércoles de 7 a 11». Una
 * lista plana de franjas obliga a reconstruir esa forma en la cabeza, y a
 * revisar dos veces si el jueves quedó vacío a propósito. En columnas por día
 * el hueco se ve.
 *
 * ── El ciclo se elige arriba ───────────────────────────────────────────────
 * Cambiar de «habitual» a un ciclo concreto cambia TODO lo que se está
 * editando, así que va donde no se confunda con un campo más: lo que se guarda
 * reemplaza la semana completa de esa selección.
 */
interface Franja {
    id?: number;
    ciclo_id: number | null;
    dia_semana: number;
    hora_inicio: string;
    hora_fin: string;
    modalidad: string;
    nota: string | null;
}

const props = withDefaults(defineProps<{
    franjas: Franja[];
    ciclos: { id: number; nombre: string }[];
    /** A dónde se manda la semana completa. */
    accion: string;
    puedeEditar?: boolean;
    /** Le habla a la persona misma —«tu disponibilidad»— o a quien la atiende. */
    tuteo?: boolean;
}>(), {
    puedeEditar: true,
    tuteo: false,
});

const DIAS = [
    { numero: 1, nombre: 'Lunes' },
    { numero: 2, nombre: 'Martes' },
    { numero: 3, nombre: 'Miércoles' },
    { numero: 4, nombre: 'Jueves' },
    { numero: 5, nombre: 'Viernes' },
    { numero: 6, nombre: 'Sábado' },
    { numero: 7, nombre: 'Domingo' },
];

const MODALIDADES = [
    { valor: 'ambas', texto: 'Presencial o en línea' },
    { valor: 'presencial', texto: 'Sólo presencial' },
    { valor: 'en_linea', texto: 'Sólo en línea' },
];

/** null = la disponibilidad habitual, la que vale para todos los ciclos. */
const cicloElegido = ref<number | null>(null);

/*
 * La semana que se está editando: sólo las franjas del ciclo elegido.
 *
 * Copia local y no las props directamente, porque se agregan y quitan renglones
 * antes de guardar. Se resincroniza al cambiar de ciclo y cuando el servidor
 * responde con datos nuevos.
 */
const semana = ref<Franja[]>([]);

function cargarDelCiclo(): void {
    semana.value = props.franjas
        .filter((f) => (f.ciclo_id ?? null) === cicloElegido.value)
        .map((f) => ({ ...f }));
}

cargarDelCiclo();
watch(cicloElegido, cargarDelCiclo);
watch(() => props.franjas, cargarDelCiclo);

const guardando = ref(false);

function franjasDe(dia: number): Franja[] {
    return semana.value.filter((f) => f.dia_semana === dia);
}

function agregar(dia: number): void {
    // 07:00–11:00 y no vacío: es la jornada más común y así se corrige un
    // horario en vez de escribirlo desde cero.
    semana.value.push({
        ciclo_id: cicloElegido.value,
        dia_semana: dia,
        hora_inicio: '07:00',
        hora_fin: '11:00',
        modalidad: 'ambas',
        nota: null,
    });
}

function quitar(franja: Franja): void {
    semana.value = semana.value.filter((f) => f !== franja);
}

/** Copiar el lunes al resto de días laborales: casi siempre es lo mismo. */
function repetirLunes(): void {
    const lunes = franjasDe(1);

    if (!lunes.length) {
        toast.info('Primero llena el lunes y luego lo copiamos al resto.');

        return;
    }

    semana.value = semana.value.filter((f) => f.dia_semana === 1 || f.dia_semana > 5);

    for (const dia of [2, 3, 4, 5]) {
        for (const f of lunes) {
            semana.value.push({ ...f, id: undefined, dia_semana: dia });
        }
    }

    toast.success('Se copió el lunes a los demás días entre semana.');
}

const horasSemanales = computed(() => {
    const minutos = semana.value.reduce((suma, f) => {
        const [hi, mi] = f.hora_inicio.split(':').map(Number);
        const [hf, mf] = f.hora_fin.split(':').map(Number);

        return suma + Math.max(0, hf * 60 + mf - (hi * 60 + mi));
    }, 0);

    return Math.round((minutos / 60) * 10) / 10;
});

function guardar(): void {
    guardando.value = true;

    router.put(props.accion, {
        ciclo_id: cicloElegido.value,
        franjas: semana.value.map((f) => ({
            dia_semana: f.dia_semana,
            hora_inicio: f.hora_inicio,
            hora_fin: f.hora_fin,
            modalidad: f.modalidad,
            nota: f.nota,
        })),
    }, {
        preserveScroll: true,
        onFinish: () => { guardando.value = false; },
    });
}
</script>

<template>
    <TarjetaSeccion
        :titulo="tuteo ? 'Mi disponibilidad' : 'Disponibilidad'"
        :descripcion="tuteo
            ? 'En qué horarios puedes dar clase. De aquí sale tu horario cuando la escuela lo arma.'
            : 'En qué horarios puede dar clase. Es el insumo con el que se arman los horarios.'"
        :icono="ICONOS.reloj"
    >
        <template #insignia>
            <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                {{ horasSemanales }} h a la semana
            </span>
        </template>

        <!-- Qué se está editando. Cambiarlo cambia toda la semana de abajo. -->
        <div class="mb-4 flex flex-wrap items-center gap-3 border-b pb-4" :style="{ borderColor: 'var(--color-borde)' }">
            <label class="text-sm font-medium">Aplica a</label>
            <select
                v-model="cicloElegido"
                class="rounded-lg border bg-transparent px-3 py-1.5 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <option :value="null">Todos los ciclos (habitual)</option>
                <option v-for="c in ciclos" :key="c.id" :value="c.id">Sólo {{ c.nombre }}</option>
            </select>

            <p v-if="cicloElegido !== null" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                Lo que guardes aquí sustituye a la disponibilidad habitual durante ese ciclo.
            </p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="dia in DIAS"
                :key="dia.numero"
                class="rounded-lg border p-3"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <div class="mb-2 flex items-center justify-between">
                    <h4 class="text-sm font-medium">{{ dia.nombre }}</h4>
                    <button
                        v-if="puedeEditar"
                        type="button"
                        class="text-xs"
                        :style="{ color: 'var(--color-acento)' }"
                        @click="agregar(dia.numero)"
                    >
                        + Agregar
                    </button>
                </div>

                <p
                    v-if="!franjasDe(dia.numero).length"
                    class="text-xs"
                    :style="{ color: 'var(--color-suave)' }"
                >
                    Sin disponibilidad.
                </p>

                <div v-for="(f, i) in franjasDe(dia.numero)" :key="i" class="mb-2 space-y-1.5">
                    <div class="flex items-center gap-1.5">
                        <input
                            v-model="f.hora_inicio"
                            type="time"
                            :disabled="!puedeEditar"
                            class="w-full rounded border bg-transparent px-2 py-1 text-xs"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                        <span class="text-xs" :style="{ color: 'var(--color-suave)' }">a</span>
                        <input
                            v-model="f.hora_fin"
                            type="time"
                            :disabled="!puedeEditar"
                            class="w-full rounded border bg-transparent px-2 py-1 text-xs"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                        <BotonAccion
                            v-if="puedeEditar"
                            variante="eliminar"
                            solo-icono
                            texto="Quitar"
                            @click="quitar(f)"
                        />
                    </div>
                    <select
                        v-model="f.modalidad"
                        :disabled="!puedeEditar"
                        class="w-full rounded border bg-transparent px-2 py-1 text-xs"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <option v-for="m in MODALIDADES" :key="m.valor" :value="m.valor">{{ m.texto }}</option>
                    </select>
                </div>
            </div>
        </div>

        <template v-if="puedeEditar" #pie>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <button
                    type="button"
                    class="text-sm"
                    :style="{ color: 'var(--color-acento)' }"
                    @click="repetirLunes"
                >
                    Copiar el lunes al resto de la semana
                </button>
                <BotonPrincipal tipo="button" :procesando="guardando" @click="guardar">
                    Guardar disponibilidad
                </BotonPrincipal>
            </div>
        </template>
    </TarjetaSeccion>
</template>
