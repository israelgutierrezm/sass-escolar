<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import { ICONOS } from '@/iconos';

/**
 * Qué materias PUEDE impartir un docente.
 *
 * Su perfil, no su carga: lo que está dando este ciclo se ve en «Materias».
 * Sin esto, al armar un horario habría que ofrecer cualquier docente para
 * cualquier materia, y nadie podría contestar «¿a quién le doy Cálculo si falta
 * el titular?».
 *
 * ── Por qué preferencia y no sólo una palomita ─────────────────────────────
 * Quien puede dar seis materias suele querer dar dos. Con sólo «puede», el
 * reparto elige al azar entre ellas y produce horarios correctos que nadie
 * quiere. La preferencia no restringe: ordena.
 */
interface Aptitud {
    asignatura_id: number;
    nombre: string;
    clave: string;
    preferencia: number;
}

const props = withDefaults(defineProps<{
    asignaturas: Aptitud[];
    catalogo: { id: number; nombre: string; clave: string }[];
    accion: string;
    puedeEditar?: boolean;
}>(), { puedeEditar: true });

const PREFERENCIAS = [
    { valor: 1, texto: 'La prefiere' },
    { valor: 0, texto: 'La puede dar' },
    { valor: -1, texto: 'Sólo si no hay de otra' },
];

const elegidas = ref<Aptitud[]>([]);

function cargar(): void {
    elegidas.value = props.asignaturas.map((a) => ({ ...a }));
}

cargar();
watch(() => props.asignaturas, cargar);

const guardando = ref(false);
const busqueda = ref('');

/** Lo que todavía no tiene, filtrado por lo que se teclee. */
const disponibles = computed(() => {
    const yaEstan = new Set(elegidas.value.map((a) => a.asignatura_id));
    const texto = busqueda.value.trim().toLowerCase();

    return props.catalogo
        .filter((a) => !yaEstan.has(a.id))
        .filter((a) => !texto || `${a.nombre} ${a.clave}`.toLowerCase().includes(texto))
        .slice(0, 30);
});

function agregar(asignatura: { id: number; nombre: string; clave: string }): void {
    elegidas.value.push({
        asignatura_id: asignatura.id,
        nombre: asignatura.nombre,
        clave: asignatura.clave,
        preferencia: 0,
    });
    busqueda.value = '';
}

function quitar(aptitud: Aptitud): void {
    elegidas.value = elegidas.value.filter((a) => a.asignatura_id !== aptitud.asignatura_id);
}

function guardar(): void {
    guardando.value = true;

    router.put(props.accion, {
        asignaturas: elegidas.value.map((a) => ({
            asignatura_id: a.asignatura_id,
            preferencia: a.preferencia,
        })),
    }, {
        preserveScroll: true,
        onFinish: () => { guardando.value = false; },
    });
}
</script>

<template>
    <TarjetaSeccion
        titulo="Materias que puede impartir"
        descripcion="Su perfil, no su carga de este ciclo. Con esto se le puede proponer una materia al armar horarios."
        :icono="ICONOS.libro"
    >
        <template #insignia>
            <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                {{ elegidas.length }} {{ elegidas.length === 1 ? 'materia' : 'materias' }}
            </span>
        </template>

        <ul v-if="elegidas.length" class="divide-y divide-borde">
            <li
                v-for="a in elegidas"
                :key="a.asignatura_id"
                class="flex flex-wrap items-center justify-between gap-3 py-2.5 first:pt-0"
            >
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium">{{ a.nombre }}</p>
                    <p class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ a.clave }}</p>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <select
                        v-model.number="a.preferencia"
                        :disabled="!puedeEditar"
                        class="rounded border bg-transparent px-2 py-1 text-xs"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <option v-for="p in PREFERENCIAS" :key="p.valor" :value="p.valor">{{ p.texto }}</option>
                    </select>
                    <BotonAccion
                        v-if="puedeEditar"
                        variante="eliminar"
                        solo-icono
                        texto="Quitar"
                        @click="quitar(a)"
                    />
                </div>
            </li>
        </ul>

        <p v-else class="text-sm" :style="{ color: 'var(--color-suave)' }">
            Todavía no se le ha registrado ninguna materia. Sin esto no se le puede proponer nada al armar horarios.
        </p>

        <!-- Buscador: el catálogo puede tener cientos y un desplegable no sirve. -->
        <div v-if="puedeEditar" class="mt-4 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
            <input
                v-model="busqueda"
                type="search"
                placeholder="Buscar una materia para agregarla…"
                class="w-full rounded-lg border bg-transparent px-3 py-2 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
            />

            <ul v-if="busqueda" class="mt-2 max-h-56 overflow-y-auto">
                <li v-for="a in disponibles" :key="a.id">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 rounded px-2 py-1.5 text-left text-sm transition hover:bg-fondo"
                        @click="agregar(a)"
                    >
                        <span class="truncate">{{ a.nombre }}</span>
                        <span class="shrink-0 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ a.clave }}</span>
                    </button>
                </li>
                <li v-if="!disponibles.length" class="px-2 py-1.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Nada que coincida, o ya está en la lista.
                </li>
            </ul>
        </div>

        <template v-if="puedeEditar" #pie>
            <div class="flex justify-end">
                <BotonPrincipal tipo="button" :procesando="guardando" @click="guardar">
                    Guardar materias
                </BotonPrincipal>
            </div>
        </template>
    </TarjetaSeccion>
</template>
