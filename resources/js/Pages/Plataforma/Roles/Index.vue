<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface RolResumen {
    id: number;
    clave: string;
    nombre: string;
    protegido: boolean;
    personas: number;
    permisos: number;
}

interface Faceta extends RolResumen {
    hijos: RolResumen[];
}

const props = defineProps<{
    facetas: Faceta[];
    catalogo: { dominio: string; permisos: { clave: string; etiqueta: string; descripcion: string }[] }[];
    rolActivo: number | null;
}>();

const creando = ref(false);

const form = useForm({
    name: '',
    nombre: '',
    tiempo_sesion: null as number | null,
    rol_padre_id: props.facetas[0]?.id ?? null,
});

function crear(): void {
    form.post('/plataforma/roles', {
        onSuccess: () => {
            form.reset();
            creando.value = false;
        },
    });
}

// La clave se deriva del nombre mientras nadie la toque a mano: es un
// identificador técnico y hacer que alguien lo invente en cada alta solo
// produce claves inconsistentes.
const claveTocada = ref(false);

function alEscribirNombre(): void {
    if (claveTocada.value) return;

    form.name = form.nombre
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
}

const totalPermisos = props.catalogo.reduce((s, d) => s + d.permisos.length, 0);
</script>

<template>
    <Head title="Roles y permisos" />

    <AppLayout titulo="Roles y permisos">
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-3xl">
                    <h2 class="text-base font-semibold">El organigrama de tu escuela</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Los roles de primer nivel son <strong>facetas</strong>: lo que una persona ES
                        —administrativo, docente, alumno—. Los que cuelgan de ellas son puestos, y
                        <strong>heredan</strong> sus permisos: no hay que volver a palomear lo que ya
                        tiene la faceta. Los roles que trae el sistema son un ejemplo; bórralos y arma los
                        tuyos si tu organigrama es otro.
                    </p>
                    <p class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Los <strong>permisos</strong> ({{ totalPermisos }}) no se crean aquí: son llaves
                        que el código comprueba, y una inventada desde esta pantalla no restringiría nada.
                        Lo que decides es qué rol lleva cuáles.
                    </p>
                </div>

                <button
                    v-if="!creando"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="creando = true"
                >
                    Nuevo rol
                </button>
            </div>

            <form v-if="creando" class="mt-5 border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="crear">
                <div class="grid gap-4 sm:grid-cols-4">
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Nombre</span>
                        <input
                            v-model="form.nombre"
                            type="text"
                            required
                            placeholder="Coordinador de becas"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @input="alEscribirNombre"
                        />
                        <span v-if="form.errors.nombre" class="text-xs text-red-600">{{ form.errors.nombre }}</span>
                    </label>

                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Clave</span>
                        <input
                            v-model="form.name"
                            type="text"
                            required
                            class="w-full rounded-lg border px-3 py-2 font-mono text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @input="claveTocada = true"
                        />
                        <span v-if="form.errors.name" class="text-xs text-red-600">{{ form.errors.name }}</span>
                    </label>

                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Cuelga de</span>
                        <select
                            v-model="form.rol_padre_id"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <option v-for="f in facetas" :key="f.id" :value="f.id">{{ f.nombre }}</option>
                            <option :value="null">— Nada: es una faceta nueva —</option>
                        </select>
                        <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            Hereda los permisos de esa faceta.
                        </span>
                    </label>

                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Minutos de sesión</span>
                        <input
                            v-model.number="form.tiempo_sesion"
                            type="number"
                            min="5"
                            max="1440"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                        <span class="text-xs" :style="{ color: 'var(--color-suave)' }">En blanco, el de por omisión.</span>
                    </label>
                </div>

                <div class="mt-4 flex gap-2">
                    <button type="submit" :disabled="form.processing" class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }">
                        Crear
                    </button>
                    <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="creando = false">
                        Cancelar
                    </button>
                </div>
            </form>
        </section>

        <section v-for="faceta in facetas" :key="faceta.id" class="tarjeta overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-base font-semibold">{{ faceta.nombre }}</h3>
                        <span class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ faceta.clave }}</span>
                        <span
                            v-if="faceta.protegido"
                            class="rounded px-2 py-0.5 text-xs"
                            :style="{ backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                            title="El código conoce esta faceta por su clave: no se renombra ni se borra, pero sus permisos sí se configuran."
                        >
                            del sistema
                        </span>
                        <span v-if="faceta.id === rolActivo" class="rounded bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">
                            tu rol activo
                        </span>
                    </div>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ faceta.permisos }} permisos propios · {{ faceta.personas }} personas
                    </p>
                </div>
                <a :href="`/plataforma/roles/${faceta.id}`" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }">
                    Configurar
                </a>
            </header>

            <table v-if="faceta.hijos.length" class="w-full text-sm">
                <tbody>
                    <tr v-for="hijo in faceta.hijos" :key="hijo.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                        <td class="py-3 pl-12 pr-4">
                            <span class="font-medium">{{ hijo.nombre }}</span>
                            <span class="ml-2 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ hijo.clave }}</span>
                            <span v-if="hijo.id === rolActivo" class="ml-2 rounded bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">
                                tu rol activo
                            </span>
                        </td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">
                            {{ hijo.permisos }} propios + los de {{ faceta.nombre }}
                        </td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ hijo.personas }} personas</td>
                        <td class="px-6 py-3 text-right">
                            <a :href="`/plataforma/roles/${hijo.id}`" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }">
                                Configurar
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="border-t px-6 py-4 text-sm" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                Sin puestos colgando de esta faceta.
            </p>
        </section>
    </AppLayout>
</template>
