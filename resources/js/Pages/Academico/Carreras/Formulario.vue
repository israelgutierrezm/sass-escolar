<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

const props = defineProps<{
    carrera: Record<string, any> | null;
    niveles: { id: number; nombre: string }[];
}>();

const esEdicion = computed(() => props.carrera !== null);

const form = useForm({
    identificador: props.carrera?.identificador ?? '',
    clave: props.carrera?.clave ?? '',
    nombre: props.carrera?.nombre ?? '',
    nivel_estudios_id: props.carrera?.nivel_estudios_id ?? null,
    imagen_url: props.carrera?.imagen_url ?? '',
    // Al crear se asume que sí: es lo normal en una carrera con RVOE. Se apaga
    // a propósito para lo que no lo tiene.
    emite_documentos_oficiales: props.carrera?.emite_documentos_oficiales ?? true,
});

const opcionesNivel = computed(() => props.niveles.map((n) => ({ valor: n.id, texto: n.nombre })));

function enviar(): void {
    esEdicion.value ? form.put(`/academico/carreras/${props.carrera!.id}`) : form.post('/academico/carreras');
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar carrera' : 'Nueva carrera'" />

    <AppLayout :titulo="esEdicion ? 'Editar carrera' : 'Nueva carrera'">
        <PestanasSeccion />

        <form class="space-y-6" @submit.prevent="enviar">
            <TarjetaSeccion titulo="Datos de la carrera" descripcion="Identificación y nivel de estudios." :icono="ICONOS.birrete">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <CampoTexto
                        v-model="form.identificador"
                        etiqueta="Identificador"
                        requerido
                        :error="form.errors.identificador"
                        ayuda="ID estable, se conserva entre migraciones."
                    />
                    <CampoTexto
                        v-model="form.clave"
                        etiqueta="Clave (SEP)"
                        requerido
                        mono
                        :error="form.errors.clave"
                        ayuda="Clave oficial de la carrera ante la SEP (cveCarrera del título). El «Identificador» es interno."
                    />
                    <CampoTexto v-model="form.nombre" etiqueta="Nombre" requerido :error="form.errors.nombre" />
                    <CampoSelect
                        v-model="form.nivel_estudios_id"
                        etiqueta="Nivel de estudios"
                        requerido
                        :opciones="opcionesNivel"
                        vacio="Selecciona…"
                        :error="form.errors.nivel_estudios_id"
                    />
                </div>
            </TarjetaSeccion>

            <!--
                No toda carrera termina en papel oficial: diplomados, cursos y
                educación continua viven en este mismo catálogo y no tienen RVOE
                que respalde una emisión. Declararlo aquí es lo que evita que sus
                alumnos aparezcan como candidatos en los lotes y con una pestaña
                de titulación que ofrece un trámite inexistente.
            -->
            <TarjetaSeccion
                titulo="Emisión oficial"
                descripcion="Qué documentos con validez ante la SEP llega a emitir esta carrera."
                :icono="ICONOS.escudo"
            >
                <div class="space-y-3">
                    <!--
                        Una sola casilla, no dos.
                        Certificado y título van juntos: el certificado acredita
                        las materias y el título haberla terminado, y no hay
                        titulación sin certificado ni certificado que no acabe en
                        título. Separarlos sólo permitía media configuración —uno
                        apagado y el otro no— y que el alumno saliera en un lote
                        y no en el otro sin que nadie supiera por qué.
                    -->
                    <label class="flex items-start gap-2.5 text-sm">
                        <input v-model="form.emite_documentos_oficiales" type="checkbox" class="mt-1">
                        <span>
                            Emite certificado y título electrónicos
                            <span class="block text-xs text-suave">
                                Sus alumnos entran a los lotes de certificación y de titulación, y ven
                                la pestaña de titulación en su expediente.
                            </span>
                        </span>
                    </label>

                    <p
                        v-if="!form.emite_documentos_oficiales"
                        class="rounded-lg border-l-4 border-l-amber-500 p-3 text-sm"
                        style="background-color: color-mix(in srgb, #f59e0b 8%, transparent)"
                    >
                        Esta carrera no emitirá documentos oficiales. Se sigue cursando y calificando
                        igual, pero sus alumnos no aparecerán en lotes de certificación ni de
                        titulación. Es lo que corresponde a diplomados y educación continua, que no
                        tienen RVOE detrás.
                    </p>
                </div>

                <template #pie>
                    <div class="flex items-center gap-3">
                        <BotonPrincipal :procesando="form.processing" :texto="esEdicion ? 'Guardar cambios' : 'Crear carrera'" />
                        <a
                            href="/academico/carreras"
                            class="rounded-lg border border-borde px-5 py-2.5 text-sm text-contenido hover:bg-fondo"
                        >
                            Cancelar
                        </a>
                    </div>
                </template>
            </TarjetaSeccion>
        </form>
    </AppLayout>
</template>
