<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CamposIdentidad from '@/Components/CamposIdentidad.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';

interface Opcion {
    id: number;
    nombre: string;
}

const props = defineProps<{
    generos: Opcion[];
    entidades: Opcion[];
    entidadExtranjero: Opcion | null;
    paises: Opcion[];
    mexicoId: number | null;
    ofertas: { id: number; campus_id: number | null; etiqueta: string }[];
    campus: Opcion[];
}>();

const form = useForm({
    // Persona (bloque de identidad)
    nombre: '',
    primer_apellido: '',
    segundo_apellido: '',
    curp: '',
    rfc: '',
    fecha_nacimiento: '',
    genero_id: null as number | null,
    entidad_nacimiento_id: null as number | null,
    pais_nacimiento_id: null as number | null,
    email: '',
    correo_institucional: '',
    celular: '',
    telefono_local: '',
    // Alumno
    oferta_id: null as number | null,
    generacion: '',
    matricula: '',
});

// Filtro local (no se envía): acota las ofertas por campus para no buscar entre
// todas. Al cambiar de campus, si la oferta elegida ya no aplica, se limpia.
const campusFiltro = ref<number | null>(null);

const ofertasVisibles = computed(() =>
    props.ofertas
        .filter((o) => !campusFiltro.value || o.campus_id === campusFiltro.value)
        .map((o) => ({ valor: o.id, texto: o.etiqueta })),
);

watch(campusFiltro, () => {
    if (form.oferta_id && !ofertasVisibles.value.some((o) => o.valor === form.oferta_id)) {
        form.oferta_id = null;
    }
});

function enviar(): void {
    form.post('/escolar/alumnos');
}
</script>

<template>
    <Head title="Registrar alumno" />

    <AppLayout titulo="Registrar alumno">
        <form class="space-y-6" @submit.prevent="enviar">
            <section class="tarjeta p-6">
                <h2 class="text-base font-semibold">Datos de la persona</h2>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Si la CURP ya está registrada, se reutiliza esa persona en lugar de duplicarla.
                </p>

                <div class="mt-5">
                    <CamposIdentidad
                        :form="form"
                        :generos="generos"
                        :entidades="entidades"
                        :entidad-extranjero="entidadExtranjero"
                        :paises="paises"
                        :mexico-id="mexicoId"
                        correo-requerido
                        con-rfc
                    />
                </div>
            </section>

            <section class="tarjeta p-6">
                <h2 class="text-base font-semibold">Datos del alumno</h2>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Alta directa: se salta el proceso de admisión (revalidaciones, traslados). Al guardar se
                    genera la matrícula en la oferta elegida.
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <CampoSelect
                        v-model="campusFiltro"
                        etiqueta="Campus (para filtrar la oferta)"
                        vacio="Todos los campus"
                        :opciones="campus.map((c) => ({ valor: c.id, texto: c.nombre }))"
                    />
                    <CampoSelect
                        v-model="form.oferta_id"
                        etiqueta="Oferta (programa académico · plan · campus)"
                        requerido
                        vacio="Selecciona la oferta…"
                        :opciones="ofertasVisibles"
                        :error="form.errors.oferta_id"
                    />
                    <CampoTexto
                        v-model="form.generacion"
                        etiqueta="Generación"
                        :error="form.errors.generacion"
                        ayuda="Opcional. Ej. «2026-2030»."
                    />
                    <CampoTexto
                        v-model="form.matricula"
                        etiqueta="Boleta / matrícula"
                        mono
                        :error="form.errors.matricula"
                        ayuda="Opcional. Déjala vacía para que el sistema la genere."
                    />
                </div>
            </section>

            <div class="flex items-center gap-3">
                <BotonPrincipal :procesando="form.processing" texto="Registrar alumno" icono="crear" />
                <Link href="/escolar/alumnos" class="text-sm" :style="{ color: 'var(--color-acento)' }">Cancelar</Link>
            </div>
        </form>
    </AppLayout>
</template>
