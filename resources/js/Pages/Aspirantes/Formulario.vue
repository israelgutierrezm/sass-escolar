<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Opcion {
    id: number;
    nombre: string;
}

interface AspiranteEditable {
    id: number;
    nombre: string;
    primer_apellido: string;
    segundo_apellido: string | null;
    curp: string | null;
    fecha_nacimiento: string | null;
    sexo_id: number | null;
    genero_id: number | null;
    entidad_nacimiento_id: number | null;
    email: string | null;
    celular: string | null;
    oferta_interes_id: number | null;
    campus_id: number | null;
    situacion_id: number | null;
    origen: string | null;
    acepto_terminos: boolean;
}

const props = defineProps<{
    aspirante: AspiranteEditable | null;
    sexos: Opcion[];
    generos: Opcion[];
    entidades: Opcion[];
    situaciones: Opcion[];
    campus: Opcion[];
    ofertas: { id: number; etiqueta: string }[];
}>();

const esEdicion = computed(() => props.aspirante !== null);

const form = useForm({
    nombre: props.aspirante?.nombre ?? '',
    primer_apellido: props.aspirante?.primer_apellido ?? '',
    segundo_apellido: props.aspirante?.segundo_apellido ?? '',
    curp: props.aspirante?.curp ?? '',
    fecha_nacimiento: props.aspirante?.fecha_nacimiento ?? '',
    sexo_id: props.aspirante?.sexo_id ?? null,
    genero_id: props.aspirante?.genero_id ?? null,
    entidad_nacimiento_id: props.aspirante?.entidad_nacimiento_id ?? null,
    email: props.aspirante?.email ?? '',
    celular: props.aspirante?.celular ?? '',
    oferta_interes_id: props.aspirante?.oferta_interes_id ?? null,
    campus_id: props.aspirante?.campus_id ?? null,
    situacion_id: props.aspirante?.situacion_id ?? props.situaciones[0]?.id ?? null,
    origen: props.aspirante?.origen ?? '',
    acepto_terminos: props.aspirante?.acepto_terminos ?? false,
});

function enviar(): void {
    if (esEdicion.value) {
        form.put(`/aspirantes/${props.aspirante!.id}`);

        return;
    }

    form.post('/aspirantes');
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar aspirante' : 'Nuevo aspirante'" />

    <AppLayout :titulo="esEdicion ? 'Editar aspirante' : 'Nuevo aspirante'">
        <form class="space-y-6" @submit.prevent="enviar">
            <!-- Identidad -->
            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-base font-semibold text-slate-800">Datos de la persona</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Si la CURP ya está registrada, se reutiliza esa persona en lugar de duplicarla.
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Nombre(s) *</label>
                        <input
                            v-model="form.nombre"
                            type="text"
                            required
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        />
                        <p v-if="form.errors.nombre" class="mt-1 text-xs text-red-600">{{ form.errors.nombre }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Primer apellido *</label>
                        <input
                            v-model="form.primer_apellido"
                            type="text"
                            required
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        />
                        <p v-if="form.errors.primer_apellido" class="mt-1 text-xs text-red-600">
                            {{ form.errors.primer_apellido }}
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Segundo apellido</label>
                        <input
                            v-model="form.segundo_apellido"
                            type="text"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">CURP</label>
                        <input
                            v-model="form.curp"
                            type="text"
                            maxlength="18"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm uppercase focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        />
                        <p v-if="form.errors.curp" class="mt-1 text-xs text-red-600">{{ form.errors.curp }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Fecha de nacimiento</label>
                        <input
                            v-model="form.fecha_nacimiento"
                            type="date"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        />
                        <p v-if="form.errors.fecha_nacimiento" class="mt-1 text-xs text-red-600">
                            {{ form.errors.fecha_nacimiento }}
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Sexo *</label>
                        <select
                            v-model="form.sexo_id"
                            required
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        >
                            <option :value="null" disabled>Selecciona…</option>
                            <option v-for="sexo in sexos" :key="sexo.id" :value="sexo.id">{{ sexo.nombre }}</option>
                        </select>
                        <p v-if="form.errors.sexo_id" class="mt-1 text-xs text-red-600">{{ form.errors.sexo_id }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Género</label>
                        <select
                            v-model="form.genero_id"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        >
                            <option :value="null">Sin especificar</option>
                            <option v-for="genero in generos" :key="genero.id" :value="genero.id">
                                {{ genero.nombre }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Entidad de nacimiento</label>
                        <select
                            v-model="form.entidad_nacimiento_id"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        >
                            <option :value="null">Sin especificar</option>
                            <option v-for="entidad in entidades" :key="entidad.id" :value="entidad.id">
                                {{ entidad.nombre }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Correo</label>
                        <input
                            v-model="form.email"
                            type="email"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        />
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Celular</label>
                        <input
                            v-model="form.celular"
                            type="tel"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        />
                    </div>
                </div>
            </section>

            <!-- Proceso de admisión -->
            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-base font-semibold text-slate-800">Proceso de admisión</h2>
                <p class="mt-1 text-sm text-slate-500">
                    La matrícula NO se genera aquí: se asigna al convertirlo en alumno.
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Oferta de interés</label>
                        <select
                            v-model="form.oferta_interes_id"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        >
                            <option :value="null">Sin definir</option>
                            <option v-for="oferta in ofertas" :key="oferta.id" :value="oferta.id">
                                {{ oferta.etiqueta }}
                            </option>
                        </select>
                        <p v-if="!ofertas.length" class="mt-1 text-xs text-amber-600">
                            No hay ofertas abiertas registradas todavía.
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Campus</label>
                        <select
                            v-model="form.campus_id"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        >
                            <option :value="null">Sin definir</option>
                            <option v-for="sede in campus" :key="sede.id" :value="sede.id">{{ sede.nombre }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Situación *</label>
                        <select
                            v-model="form.situacion_id"
                            required
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        >
                            <option v-for="situacion in situaciones" :key="situacion.id" :value="situacion.id">
                                {{ situacion.nombre }}
                            </option>
                        </select>
                        <p v-if="form.errors.situacion_id" class="mt-1 text-xs text-red-600">
                            {{ form.errors.situacion_id }}
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Origen</label>
                        <input
                            v-model="form.origen"
                            type="text"
                            placeholder="Campaña, referido, web…"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        />
                    </div>
                </div>

                <label class="mt-4 flex items-center gap-2 text-sm text-slate-600">
                    <input
                        v-model="form.acepto_terminos"
                        type="checkbox"
                        class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                    />
                    Aceptó los términos del proceso de admisión
                </label>
            </section>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {{ form.processing ? 'Guardando…' : esEdicion ? 'Guardar cambios' : 'Registrar aspirante' }}
                </button>
                <a
                    href="/aspirantes"
                    class="rounded-lg border border-slate-300 px-5 py-2.5 text-sm text-slate-700 transition hover:bg-slate-50"
                >
                    Cancelar
                </a>
            </div>
        </form>
    </AppLayout>
</template>
