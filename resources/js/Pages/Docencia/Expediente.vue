<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

interface Documento {
    id: number;
    documento: string | null;
    descripcion: string | null;
    estado: string | null;
    estado_clave: string | null;
    vigencia: string | null;
    vencido: boolean;
    observaciones: string | null;
}

const props = defineProps<{
    persona: Record<string, any>;
    docente: { clave_profesor: string | null; cedula_profesional: string | null; tipo: string | null; situacion: string | null; campus: string[] };
    documentos: Documento[];
    tiposDocumento: { id: number; nombre: string }[];
    sexos: { id: number; nombre: string }[];
    generos: { id: number; nombre: string }[];
}>();

const form = useForm({
    nombre: props.persona.nombre ?? '',
    primer_apellido: props.persona.primer_apellido ?? '',
    segundo_apellido: props.persona.segundo_apellido ?? '',
    curp: props.persona.curp ?? '',
    rfc: props.persona.rfc ?? '',
    fecha_nacimiento: props.persona.fecha_nacimiento ?? '',
    sexo_id: props.persona.sexo_id ?? null,
    genero_id: props.persona.genero_id ?? null,
    email: props.persona.email ?? '',
    celular: props.persona.celular ?? '',
});

function guardar(): void {
    form.put('/docencia/expediente', { preserveScroll: true });
}

// --- Documentos ---
const formDoc = useForm({
    documento_id: null as number | null,
    archivo: null as File | null,
    descripcion: '',
    vigencia: '',
});

const entradaArchivo = ref<HTMLInputElement | null>(null);

function elegirArchivo(evento: Event): void {
    const archivos = (evento.target as HTMLInputElement).files;
    formDoc.archivo = archivos && archivos.length > 0 ? archivos[0] : null;
}

function subir(): void {
    formDoc.post('/docencia/expediente/documentos', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            formDoc.reset();
            if (entradaArchivo.value) {
                entradaArchivo.value.value = '';
            }
        },
    });
}

function eliminar(doc: Documento): void {
    if (!confirm(`¿Eliminar "${doc.documento}"?`)) {
        return;
    }

    router.delete(`/docencia/expediente/documentos/${doc.id}`, { preserveScroll: true });
}

function colorEstado(clave: string | null): string {
    return {
        aceptado: 'color-mix(in srgb, #16a34a 18%, transparent)',
        rechazado: 'color-mix(in srgb, #dc2626 18%, transparent)',
    }[clave ?? ''] ?? 'color-mix(in srgb, #f59e0b 18%, transparent)';
}

/* Foto de perfil */
const formFoto = useForm({ foto: null as File | null });
const entradaFoto = ref<HTMLInputElement | null>(null);

function subirFoto(evento: Event): void {
    const archivos = (evento.target as HTMLInputElement).files;

    if (!archivos || archivos.length === 0) {
        return;
    }

    formFoto.foto = archivos[0];
    formFoto.post(`/personas/${props.persona.persona_id}/foto`, {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => {
            formFoto.reset();
            if (entradaFoto.value) entradaFoto.value.value = '';
        },
    });
}

function quitarFoto(): void {
    if (!confirm('Quitar la foto?')) return;
    router.delete(`/personas/${props.persona.persona_id}/foto`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Mi expediente" />

    <AppLayout titulo="Mi expediente">
        <!-- Lo que administra la escuela, no el docente -->
        <section class="tarjeta p-6">
            <h2 class="text-base font-semibold">Mi registro docente</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Estos datos los administra control escolar. Si algo está mal, pídeles la corrección.
            </p>

            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-4">
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">Clave de profesor</dt>
                    <dd class="mt-0.5 font-mono">{{ docente.clave_profesor ?? '—' }}</dd>
                </div>
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">Cédula profesional</dt>
                    <dd class="mt-0.5 font-mono">{{ docente.cedula_profesional ?? '—' }}</dd>
                </div>
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">Tipo</dt>
                    <dd class="mt-0.5">{{ docente.tipo ?? '—' }}</dd>
                </div>
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">Situación</dt>
                    <dd class="mt-0.5">{{ docente.situacion ?? '—' }}</dd>
                </div>
                <div v-if="docente.campus.length" class="sm:col-span-4">
                    <dt :style="{ color: 'var(--color-suave)' }">Campus</dt>
                    <dd class="mt-0.5">{{ docente.campus.join(', ') }}</dd>
                </div>
            </dl>
        </section>

        <!-- Lo que sí mantiene el docente -->
        <form class="tarjeta p-6" @submit.prevent="guardar">
            <h2 class="text-base font-semibold">Mis datos</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Manténlos al día: de aquí salen tus datos en actas y documentos oficiales.
            </p>

                <div class="flex flex-col items-center gap-2">
                    <img
                        v-if="persona.foto"
                        :src="persona.foto"
                        alt=""
                        class="h-24 w-24 rounded-full object-cover"
                    />
                    <span
                        v-else
                        class="flex h-24 w-24 items-center justify-center rounded-full text-2xl font-semibold"
                        :style="{
                            backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)',
                            color: 'var(--color-acento)',
                        }"
                    >
                        {{ (persona.nombre?.[0] ?? '') + (persona.primer_apellido?.[0] ?? '') }}
                    </span>

                    <div v-if="true" class="flex gap-2 text-xs">
                        <label class="cursor-pointer" :style="{ color: 'var(--color-acento)' }">
                            {{ persona.foto ? 'Cambiar' : 'Subir foto' }}
                            <input
                                ref="entradaFoto"
                                type="file"
                                accept="image/*"
                                class="hidden"
                                @change="subirFoto"
                            />
                        </label>
                        <button
                            v-if="persona.foto"
                            type="button"
                            :style="{ color: 'var(--color-suave)' }"
                            @click="quitarFoto"
                        >
                            Quitar
                        </button>
                    </div>
                    <p v-if="formFoto.errors.foto" class="text-xs text-red-600">{{ formFoto.errors.foto }}</p>
                </div>

            <div class="mt-5 grid gap-4 sm:grid-cols-3">
                <CampoTexto v-model="form.nombre" etiqueta="Nombre(s)" requerido :error="form.errors.nombre" />
                <CampoTexto v-model="form.primer_apellido" etiqueta="Primer apellido" requerido :error="form.errors.primer_apellido" />
                <CampoTexto v-model="form.segundo_apellido" etiqueta="Segundo apellido" :error="form.errors.segundo_apellido" />

                <CampoTexto v-model="form.curp" etiqueta="CURP" mono :error="form.errors.curp" />
                <CampoTexto v-model="form.rfc" etiqueta="RFC" mono :error="form.errors.rfc" />
                <CampoTexto
                    v-model="form.fecha_nacimiento"
                    etiqueta="Fecha de nacimiento"
                    tipo="date"
                    :error="form.errors.fecha_nacimiento"
                />

                <CampoSelect
                    v-model="form.sexo_id"
                    etiqueta="Sexo"
                    requerido
                    :opciones="sexos.map((s) => ({ valor: s.id, texto: s.nombre }))"
                    vacio="Selecciona…"
                    :error="form.errors.sexo_id"
                />
                <CampoSelect
                    v-model="form.genero_id"
                    etiqueta="Género"
                    :opciones="generos.map((g) => ({ valor: g.id, texto: g.nombre }))"
                    vacio="Prefiero no decirlo"
                    :error="form.errors.genero_id"
                />
                <div></div>

                <CampoTexto v-model="form.email" etiqueta="Correo personal" tipo="email" :error="form.errors.email" />
                <CampoTexto
                    :model-value="persona.correo_institucional ?? '—'"
                    etiqueta="Correo institucional"
                    deshabilitado
                    ayuda="Lo asigna la escuela."
                />
                <CampoTexto v-model="form.celular" etiqueta="Celular" :error="form.errors.celular" />
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="mt-5 rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
            >
                {{ form.processing ? 'Guardando…' : 'Guardar mis datos' }}
            </button>
        </form>

        <!-- Documentos -->
        <section class="tarjeta overflow-hidden">
            <div class="border-b px-6 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                <h2 class="text-base font-semibold">Mis documentos</h2>
                <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Título, cédula, comprobantes. Cada carga queda pendiente de revisión; volver a subir
                    el mismo tipo reemplaza el archivo anterior.
                </p>
            </div>

            <ul v-if="documentos.length">
                <li
                    v-for="doc in documentos"
                    :key="doc.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div>
                        <p class="font-medium">{{ doc.documento }}</p>
                        <p v-if="doc.descripcion" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ doc.descripcion }}
                        </p>
                        <p v-if="doc.vigencia" class="text-xs" :class="doc.vencido ? 'text-red-600' : ''" :style="doc.vencido ? {} : { color: 'var(--color-suave)' }">
                            Vigencia {{ doc.vigencia }}<span v-if="doc.vencido"> — vencido</span>
                        </p>
                        <p v-if="doc.observaciones" class="mt-0.5 text-xs italic text-amber-700">
                            {{ doc.observaciones }}
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <span class="rounded-full px-2 py-0.5 text-xs" :style="{ backgroundColor: colorEstado(doc.estado_clave) }">
                            {{ doc.estado }}
                        </span>
                        <a
                            :href="`/docencia/expediente/documentos/${doc.id}/descargar`"
                            class="text-sm"
                            :style="{ color: 'var(--color-acento)' }"
                        >
                            Descargar
                        </a>
                        <button
                            v-if="doc.estado_clave !== 'aceptado'"
                            type="button"
                            class="text-sm transition hover:text-red-600"
                            :style="{ color: 'var(--color-suave)' }"
                            @click="eliminar(doc)"
                        >
                            Eliminar
                        </button>
                    </div>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no has cargado documentos.
            </p>

            <form class="border-t px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="subir">
                <div class="grid gap-3 sm:grid-cols-4">
                    <CampoSelect
                        v-model="formDoc.documento_id"
                        etiqueta="Tipo de documento"
                        :opciones="tiposDocumento.map((t) => ({ valor: t.id, texto: t.nombre }))"
                        vacio="Selecciona…"
                        :error="formDoc.errors.documento_id"
                    />
                    <div>
                        <label class="block text-sm font-medium">Archivo</label>
                        <input
                            ref="entradaArchivo"
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png"
                            class="mt-1 w-full text-sm"
                            @change="elegirArchivo"
                        />
                        <p v-if="formDoc.errors.archivo" class="mt-1 text-xs text-red-600">{{ formDoc.errors.archivo }}</p>
                        <p v-else class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">PDF o imagen, máx. 5 MB.</p>
                    </div>
                    <CampoTexto
                        v-model="formDoc.vigencia"
                        etiqueta="Vigencia"
                        tipo="date"
                        :error="formDoc.errors.vigencia"
                        ayuda="Solo si vence."
                    />
                    <div class="flex items-end">
                        <button
                            type="submit"
                            :disabled="formDoc.processing || !formDoc.documento_id || !formDoc.archivo"
                            class="w-full rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                            :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        >
                            {{ formDoc.processing ? 'Subiendo…' : 'Subir' }}
                        </button>
                    </div>
                </div>
            </form>
        </section>
    </AppLayout>
</template>
