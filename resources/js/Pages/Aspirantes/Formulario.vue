<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CamposIdentidad from '@/Components/CamposIdentidad.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

interface Opcion {
    id: number;
    nombre: string;
}

interface AspiranteEditable {
    id: number;
    persona_id: number;
    nombre: string;
    primer_apellido: string;
    segundo_apellido: string | null;
    curp: string | null;
    rfc: string | null;
    fecha_nacimiento: string | null;
    genero_id: number | null;
    entidad_nacimiento_id: number | null;
    pais_nacimiento_id: number | null;
    email: string | null;
    correo_institucional: string | null;
    celular: string | null;
    telefono_local: string | null;
    oferta_interes_id: number | null;
    campus_id: number | null;
    situacion_id: number | null;
    origen_id: number | null;
    origen: string | null;
}

const props = defineProps<{
    aspirante: AspiranteEditable | null;
    generos: Opcion[];
    entidades: Opcion[];
    entidadExtranjero: Opcion | null;
    paises: Opcion[];
    mexicoId: number | null;
    situaciones: Opcion[];
    origenes: Opcion[];
    campus: Opcion[];
    ofertas: { id: number; etiqueta: string; campus_id: number | null }[];
}>();

const esEdicion = computed(() => props.aspirante !== null);

const form = useForm({
    nombre: props.aspirante?.nombre ?? '',
    primer_apellido: props.aspirante?.primer_apellido ?? '',
    segundo_apellido: props.aspirante?.segundo_apellido ?? '',
    curp: props.aspirante?.curp ?? '',
    rfc: props.aspirante?.rfc ?? '',
    fecha_nacimiento: props.aspirante?.fecha_nacimiento ?? '',
    genero_id: props.aspirante?.genero_id ?? null,
    entidad_nacimiento_id: props.aspirante?.entidad_nacimiento_id ?? null,
    pais_nacimiento_id: props.aspirante?.pais_nacimiento_id ?? null,
    email: props.aspirante?.email ?? '',
    correo_institucional: props.aspirante?.correo_institucional ?? '',
    celular: props.aspirante?.celular ?? '',
    telefono_local: props.aspirante?.telefono_local ?? '',
    oferta_interes_id: props.aspirante?.oferta_interes_id ?? null,
    campus_id: props.aspirante?.campus_id ?? null,
    situacion_id: props.aspirante?.situacion_id ?? props.situaciones[0]?.id ?? null,
    origen_id: props.aspirante?.origen_id ?? null,
    origen: props.aspirante?.origen ?? '',
});

// Al elegir un campus, solo se ofrecen las ofertas que de verdad se imparten
// ahí. Sin campus se muestran todas. Es el propósito del fan-out: que el campus
// filtre la oferta y no aparezcan programas que ese plantel no tiene.
const ofertasVisibles = computed(() =>
    props.ofertas.filter((o) => !form.campus_id || o.campus_id === form.campus_id),
);

// Si la oferta elegida ya no pertenece al campus seleccionado, se limpia: no
// tiene sentido conservar un interés en un programa que ese campus no ofrece.
watch(
    () => form.campus_id,
    () => {
        if (form.oferta_interes_id && !ofertasVisibles.value.some((o) => o.id === form.oferta_interes_id)) {
            form.oferta_interes_id = null;
        }
    },
);

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
            <TarjetaSeccion titulo="Datos de la persona" descripcion="Si la CURP ya está registrada, se reutiliza esa persona en lugar de duplicarla." :icono="ICONOS.persona">
                <CamposIdentidad
                    :form="form"
                    :generos="generos"
                    :entidades="entidades"
                    :entidad-extranjero="entidadExtranjero"
                    :paises="paises"
                    :mexico-id="mexicoId"
                    :persona-id="aspirante?.persona_id ?? null"
                    correo-requerido
                    con-rfc
                />
            </TarjetaSeccion>

            <TarjetaSeccion titulo="Proceso de admisión" descripcion="La matrícula NO se genera aquí: se asigna al convertirlo en alumno." :icono="ICONOS.birrete">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <!-- Primero el campus: la oferta de carrera depende de él.
                         Elegir una oferta de otro campus no tiene sentido, así
                         que la oferta se filtra y se habilita solo con campus. -->
                    <CampoSelect
                        v-model="form.campus_id"
                        etiqueta="Campus"
                        vacio="Sin definir"
                        :opciones="campus.map((c) => ({ valor: c.id, texto: c.nombre }))"
                        :error="form.errors.campus_id"
                    />

                    <CampoSelect
                        v-model="form.oferta_interes_id"
                        etiqueta="Oferta de interés"
                        vacio="Sin definir"
                        :deshabilitado="!form.campus_id"
                        :opciones="ofertasVisibles.map((o) => ({ valor: o.id, texto: o.etiqueta }))"
                        :error="form.errors.oferta_interes_id"
                        :ayuda="
                            !form.campus_id
                                ? 'Elige primero un campus para ver sus ofertas.'
                                : !ofertas.length
                                  ? 'No hay ofertas abiertas registradas todavía.'
                                  : !ofertasVisibles.length
                                    ? 'Ese campus no tiene ofertas abiertas.'
                                    : undefined
                        "
                    />

                    <CampoSelect
                        v-model="form.situacion_id"
                        etiqueta="Situación"
                        requerido
                        :opciones="situaciones.map((s) => ({ valor: s.id, texto: s.nombre }))"
                        :error="form.errors.situacion_id"
                    />

                    <div>
                        <CampoSelect
                            v-model="form.origen_id"
                            etiqueta="Cómo llegó"
                            vacio="Sin especificar"
                            :opciones="origenes.map((o) => ({ valor: o.id, texto: o.nombre }))"
                            :error="form.errors.origen_id"
                        />
                        <!-- El texto libre se conserva para no perder lo ya
                             capturado, pero deja de ser lo principal: el
                             catálogo es lo que el CRM sabe contar. -->
                        <CampoTexto
                            v-model="form.origen"
                            etiqueta=""
                            marcador="Detalle (campaña, quién refirió…)"
                            class="mt-2"
                        />
                    </div>
                </div>

                <!-- Los términos NO se aceptan desde aquí. Consentir el proceso
                     de admisión es un acto del interesado; quien captura no
                     puede hacerlo en su nombre. Lo firma el aspirante en su
                     portal. -->
                <p class="mt-4 text-xs" :style="{ color: 'var(--color-suave)' }">
                    Los términos del proceso los acepta el propio aspirante desde su portal; no pueden
                    aceptarse por él desde aquí.
                </p>
            </TarjetaSeccion>

            <div class="flex items-center gap-3">
                <BotonPrincipal :procesando="form.processing" :texto="esEdicion ? 'Guardar cambios' : 'Registrar aspirante'" />
                <a
                    href="/aspirantes"
                    class="rounded-lg border px-5 py-2.5 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    Cancelar
                </a>
            </div>
        </form>
    </AppLayout>
</template>
