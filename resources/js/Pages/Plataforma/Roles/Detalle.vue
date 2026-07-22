<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Permiso {
    clave: string;
    etiqueta: string;
    descripcion: string;
}

const props = defineProps<{
    rol: {
        id: number;
        clave: string;
        nombre: string;
        tiempo_sesion: number | null;
        rol_padre_id: number | null;
        padre: string | null;
        protegido: boolean;
        es_faceta: boolean;
        personas: number;
    };
    catalogo: { dominio: string; permisos: Permiso[] }[];
    ambito: string;
    facetaNombre: string;
    propios: string[];
    heredados: string[];
    padresPosibles: { id: number; nombre: string }[];
    asignados: { id: number; persona_id: number; persona: string | null; campus: string | null; activo: boolean }[];
    esMiRolActivo: boolean;
}>();

const datos = useForm({
    nombre: props.rol.nombre,
    tiempo_sesion: props.rol.tiempo_sesion,
    rol_padre_id: props.rol.rol_padre_id,
});

const permisos = useForm({ permisos: [...props.propios] });

function guardarDatos(): void {
    datos.put(`/plataforma/roles/${props.rol.id}`, { preserveScroll: true });
}

function guardarPermisos(): void {
    permisos.put(`/plataforma/roles/${props.rol.id}/permisos`, { preserveScroll: true });
}

function esHeredado(clave: string): boolean {
    return props.heredados.includes(clave);
}

/** Marca o desmarca un dominio completo, sin tocar los heredados. */
function alternarDominio(dominio: { permisos: Permiso[] }, marcar: boolean): void {
    const claves = dominio.permisos.filter((p) => !esHeredado(p.clave)).map((p) => p.clave);

    permisos.permisos = marcar
        ? [...new Set([...permisos.permisos, ...claves])]
        : permisos.permisos.filter((c) => !claves.includes(c));
}

function dominioCompleto(dominio: { permisos: Permiso[] }): boolean {
    return dominio.permisos.every((p) => esHeredado(p.clave) || permisos.permisos.includes(p.clave));
}

const totalEfectivos = computed(
    () => new Set([...permisos.permisos, ...props.heredados]).size,
);

function eliminar(): void {
    router.delete(`/plataforma/roles/${props.rol.id}`);
}

// Asignación de personas.
const busqueda = ref('');
const resultados = ref<{ id: number; nombre: string }[]>([]);
const asignacion = useForm({ persona_id: null as number | null, campus_id: null as number | null });
let temporizador: ReturnType<typeof setTimeout> | undefined;

function buscar(): void {
    clearTimeout(temporizador);

    temporizador = setTimeout(async () => {
        if (busqueda.value.trim().length < 2) {
            resultados.value = [];
            return;
        }

        const respuesta = await fetch(`/plataforma/roles/personas?q=${encodeURIComponent(busqueda.value)}`, {
            headers: { Accept: 'application/json' },
        });
        resultados.value = await respuesta.json();
    }, 300);
}

function asignar(personaId: number): void {
    asignacion.persona_id = personaId;
    asignacion.post(`/plataforma/roles/${props.rol.id}/asignaciones`, {
        preserveScroll: true,
        onSuccess: () => {
            busqueda.value = '';
            resultados.value = [];
            asignacion.reset();
        },
    });
}

function desasignar(id: number): void {
    router.delete(`/plataforma/roles/${props.rol.id}/asignaciones/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Rol · ${rol.nombre}`" />

    <AppLayout :titulo="rol.nombre">
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-sm">{{ rol.clave }}</span>
                        <span v-if="rol.protegido" class="rounded px-2 py-0.5 text-xs" :style="{ backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                            del sistema
                        </span>
                        <span v-if="esMiRolActivo" class="rounded bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">
                            tu rol activo
                        </span>
                    </div>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        <template v-if="rol.padre">Cuelga de {{ rol.padre }} y hereda sus permisos. </template>
                        <template v-else>Es una faceta: lo que la persona ES. </template>
                        {{ totalEfectivos }} permisos efectivos · {{ rol.personas }} personas.
                    </p>
                </div>
                <a href="/plataforma/roles" class="text-sm" :style="{ color: 'var(--color-acento)' }">← Roles</a>
            </div>

            <p v-if="rol.protegido" class="mt-4 rounded-lg px-4 py-3 text-sm" :style="{ backgroundColor: 'var(--color-borde)' }">
                Hay código que conoce esta faceta por su clave <span class="font-mono">{{ rol.clave }}</span>, así que
                no se renombra ni se elimina. Su nombre visible y sus permisos sí son configurables.
            </p>

            <form class="mt-5 grid gap-4 border-t pt-5 sm:grid-cols-4" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="guardarDatos">
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Nombre</span>
                    <input v-model="datos.nombre" type="text" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Minutos de sesión</span>
                    <input v-model.number="datos.tiempo_sesion" type="number" min="5" max="1440" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label v-if="!rol.protegido" class="text-sm">
                    <span class="mb-1 block font-medium">Cuelga de</span>
                    <select v-model="datos.rol_padre_id" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option :value="null">— Nada: es una faceta —</option>
                        <option v-for="p in padresPosibles" :key="p.id" :value="p.id">{{ p.nombre }}</option>
                    </select>
                </label>
                <div class="flex items-end gap-2">
                    <button type="submit" :disabled="datos.processing" class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }">
                        Guardar
                    </button>
                    <button v-if="!rol.protegido" type="button" class="rounded-lg border px-4 py-2 text-sm text-red-600" :style="{ borderColor: 'var(--color-borde)' }" @click="eliminar">
                        Eliminar
                    </button>
                </div>
            </form>
        </section>

        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold">Qué puede hacer</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Los permisos <strong>heredados</strong> aparecen marcados y bloqueados: explican por
                        qué este rol puede algo que aquí no palomeaste. Se cambian en
                        {{ rol.padre ?? 'el rol padre' }}.
                    </p>
                    <!--
                        Solo se ofrecen los permisos de SU faceta. Si un
                        administrativo pudiera concederse los del docente, el
                        conmutador de rol dejaría de tener sentido: nadie
                        conmutaría, y el alcance por asignación quedaría
                        colgando de un permiso que no le toca.
                    -->
                    <p class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Aquí solo salen los permisos de <strong>{{ facetaNombre }}</strong>. Los de otros
                        oficios —docente, alumno, aspirante— no se pueden mezclar: para que una persona los
                        tenga, se le da además ese rol y conmuta entre ellos.
                    </p>
                </div>
                <button
                    type="button"
                    :disabled="permisos.processing"
                    class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="guardarPermisos"
                >
                    Guardar permisos
                </button>
            </div>

            <p v-if="esMiRolActivo" class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Estás editando el rol con el que operas. Si te quitas «Administrar roles» te quedarías
                fuera de esta pantalla, así que el sistema no te dejará guardarlo.
            </p>

            <div class="mt-5 space-y-6">
                <div v-for="dominio in catalogo" :key="dominio.dominio">
                    <div class="flex items-center justify-between border-b pb-2" :style="{ borderColor: 'var(--color-borde)' }">
                        <h3 class="text-sm font-semibold">{{ dominio.dominio }}</h3>
                        <label class="flex items-center gap-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                            <input
                                type="checkbox"
                                class="rounded"
                                :checked="dominioCompleto(dominio)"
                                @change="alternarDominio(dominio, ($event.target as HTMLInputElement).checked)"
                            />
                            Todo el dominio
                        </label>
                    </div>

                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <label
                            v-for="permiso in dominio.permisos"
                            :key="permiso.clave"
                            class="flex gap-3 rounded-lg border p-3"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            :class="esHeredado(permiso.clave) ? 'opacity-70' : ''"
                        >
                            <input
                                type="checkbox"
                                class="mt-0.5 rounded"
                                :value="permiso.clave"
                                :checked="esHeredado(permiso.clave) || permisos.permisos.includes(permiso.clave)"
                                :disabled="esHeredado(permiso.clave)"
                                @change="
                                    ($event.target as HTMLInputElement).checked
                                        ? permisos.permisos.push(permiso.clave)
                                        : (permisos.permisos = permisos.permisos.filter((c) => c !== permiso.clave))
                                "
                            />
                            <span class="text-sm">
                                <span class="block font-medium">
                                    {{ permiso.etiqueta }}
                                    <span v-if="esHeredado(permiso.clave)" class="text-xs font-normal" :style="{ color: 'var(--color-suave)' }">
                                        — heredado
                                    </span>
                                </span>
                                <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ permiso.descripcion }}
                                </span>
                                <span class="block font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ permiso.clave }}
                                </span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>
        </section>

        <section class="tarjeta p-6">
            <h2 class="text-base font-semibold">Quién tiene este rol</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Una persona puede tener el mismo rol en varios campus. Sin campus, su alcance es toda la
                escuela.
            </p>

            <div class="mt-4">
                <input
                    v-model="busqueda"
                    type="search"
                    placeholder="Buscar persona por nombre o CURP…"
                    class="w-full max-w-md rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @input="buscar"
                />

                <ul v-if="resultados.length" class="mt-2 max-w-md rounded-lg border" :style="{ borderColor: 'var(--color-borde)' }">
                    <li
                        v-for="r in resultados"
                        :key="r.id"
                        class="flex items-center justify-between border-b px-3 py-2 text-sm last:border-b-0"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        {{ r.nombre }}
                        <button type="button" class="text-xs font-medium" :style="{ color: 'var(--color-acento)' }" @click="asignar(r.id)">
                            Asignar
                        </button>
                    </li>
                </ul>
            </div>

            <ul v-if="asignados.length" class="mt-4 divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                <li v-for="a in asignados" :key="a.id" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                    <span>
                        {{ a.persona }}
                        <span v-if="a.campus" class="ml-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                            solo en {{ a.campus }}
                        </span>
                        <span v-else class="ml-2 text-xs" :style="{ color: 'var(--color-suave)' }">toda la escuela</span>
                        <span v-if="!a.activo" class="ml-2 text-xs text-amber-700">inactivo</span>
                    </span>
                    <button type="button" class="text-xs font-medium text-red-600" @click="desasignar(a.id)">
                        Retirar
                    </button>
                </li>
            </ul>

            <p v-else class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">Nadie tiene este rol todavía.</p>
        </section>
    </AppLayout>
</template>
