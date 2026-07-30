<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';

interface Publicacion {
    id: number;
    nombre: string;
    titulo: string;
    modo: string;
    formulario_id: number;
    token: string;
    url: string;
    formulario: string | null;
    origen: string | null;
    etapa: string | null;
    oferta: string | null;
    campus: string | null;
    asesor: string | null;
    activo: boolean;
    abierto: boolean;
    vigente_desde: string | null;
    vigente_hasta: string | null;
    visitas: number;
    envios: number;
}

const props = defineProps<{
    publicaciones: Publicacion[];
    formularios: { id: number; nombre: string }[];
    origenes: { id: number; nombre: string; autogestivo: boolean }[];
    etapas: { id: number; nombre: string }[];
    campus: { id: number; nombre: string }[];
    ofertas: { id: number; nombre: string }[];
    promotores: { persona_id: number; nombre: string | null }[];
}>();

const creando = ref(false);
const copiado = ref<number | null>(null);

const form = useForm({
    formulario_id: props.formularios[0]?.id ?? null,
    nombre: '',
    titulo: '',
    modo: 'captacion',
    bienvenida: '',
    gracias: '',
    origen_id: props.origenes.find((o) => o.autogestivo)?.id ?? null,
    etapa_crm_id: props.etapas[0]?.id ?? null,
    campus_id: null as number | null,
    oferta_id: null as number | null,
    asesor_persona_id: null as number | null,
    activo: true,
    vigente_desde: '',
    vigente_hasta: '',
});

function crear(): void {
    form.post('/promocion/publicaciones', {
        onSuccess: () => {
            form.reset();
            creando.value = false;
        },
    });
}

function alternarActivo(p: Publicacion): void {
    // Se reenvían los campos requeridos por la validación tal como están: esto
    // solo alterna la bandera, no es una edición.
    router.put(
        `/promocion/publicaciones/${p.id}`,
        {
            formulario_id: p.formulario_id,
            nombre: p.nombre,
            titulo: p.titulo,
            modo: p.modo,
            activo: !p.activo,
        },
        { preserveScroll: true },
    );
}

function snippet(p: Publicacion): string {
    return `<iframe src="${p.url}" style="width:100%;min-height:720px;border:0" title="${p.titulo}" loading="lazy"></iframe>`;
}

async function copiar(p: Publicacion): Promise<void> {
    await navigator.clipboard.writeText(snippet(p));
    copiado.value = p.id;
    setTimeout(() => (copiado.value = null), 2000);
}

// Con cero visitas no se puede hablar de conversión: 0 de 0 no es 0%, es
// "todavía no la vio nadie", que es un problema distinto.
function conversion(p: Publicacion): string {
    return p.visitas === 0 ? 'sin visitas' : `${Math.round((p.envios / p.visitas) * 100)}%`;
}
</script>

<template>
    <Head title="Formularios web" />

    <AppLayout titulo="Formularios para la web">
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-3xl">
                    <h2 class="text-base font-semibold">Que los aspirantes lleguen solos</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Publica un formulario y pégalo en la página de la escuela. Lo que llegue entra
                        directo al embudo, marcado como <strong>autogestivo</strong>, y si asignas un
                        promotor le cae con dueño desde el primer minuto — un prospecto sin dueño es al
                        que nadie llama.
                    </p>
                    <p class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                        En modo <strong>captación</strong> solo deja sus datos. En modo
                        <strong>inscripción</strong> además se le crea su cuenta para que continúe solo.
                    </p>
                </div>

                <BotonAccion v-if="!creando" variante="nuevo" texto="Publicar formulario" @click="creando = true" />
            </div>

            <form v-if="creando" class="mt-5 grid gap-4 border-t pt-5 sm:grid-cols-3" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="crear">
                <CampoSelect
                    v-model="form.formulario_id"
                    etiqueta="Formulario"
                    requerido
                    :opciones="formularios.map((f) => ({ valor: f.id, texto: f.nombre }))"
                    :error="form.errors.formulario_id"
                    ayuda="Apunta a una versión concreta: si publicas la v2, es otra publicación."
                />

                <CampoTexto
                    v-model="form.nombre"
                    etiqueta="Nombre interno"
                    requerido
                    marcador="Campaña feria marzo"
                    :error="form.errors.nombre"
                />

                <CampoSelect
                    v-model="form.modo"
                    etiqueta="Modo"
                    :opciones="[
                        { valor: 'captacion', texto: 'Captación de interés' },
                        { valor: 'inscripcion', texto: 'Inscripción autogestiva' },
                    ]"
                    :error="form.errors.modo"
                />

                <div class="sm:col-span-3">
                    <CampoTexto
                        v-model="form.titulo"
                        etiqueta="Título que ve el visitante"
                        requerido
                        marcador="Solicita informes de nuestras licenciaturas"
                        :error="form.errors.titulo"
                    />
                </div>

                <div class="sm:col-span-3">
                    <CampoTextarea v-model="form.bienvenida" etiqueta="Texto de bienvenida" :error="form.errors.bienvenida" />
                </div>

                <div class="sm:col-span-3">
                    <CampoTextarea
                        v-model="form.gracias"
                        etiqueta="Texto de agradecimiento"
                        marcador="Alguien de la escuela te contactará muy pronto."
                        :error="form.errors.gracias"
                    />
                </div>

                <CampoSelect
                    v-model="form.origen_id"
                    etiqueta="Origen que se les atribuye"
                    vacio="Autogestivo por omisión"
                    :opciones="origenes.map((o) => ({ valor: o.id, texto: o.nombre }))"
                    :error="form.errors.origen_id"
                />

                <CampoSelect
                    v-model="form.etapa_crm_id"
                    etiqueta="Entran en la etapa"
                    :opciones="etapas.map((e) => ({ valor: e.id, texto: e.nombre }))"
                    :error="form.errors.etapa_crm_id"
                />

                <CampoSelect
                    v-model="form.asesor_persona_id"
                    etiqueta="Promotor titular"
                    vacio="Sin asignar"
                    :opciones="promotores.map((p) => ({ valor: p.persona_id, texto: p.nombre }))"
                    :error="form.errors.asesor_persona_id"
                    ayuda="También es quien devengará la comisión si se inscriben."
                />

                <CampoSelect
                    v-model="form.oferta_id"
                    etiqueta="Oferta fija"
                    vacio="Que el visitante elija"
                    :opciones="ofertas.map((o) => ({ valor: o.id, texto: o.nombre }))"
                    :error="form.errors.oferta_id"
                />

                <CampoSelect
                    v-model="form.campus_id"
                    etiqueta="Campus"
                    vacio="Ninguno en particular"
                    :opciones="campus.map((c) => ({ valor: c.id, texto: c.nombre }))"
                    :error="form.errors.campus_id"
                />

                <CampoTexto v-model="form.vigente_desde" tipo="date" etiqueta="Recibe desde" :error="form.errors.vigente_desde" />

                <CampoTexto
                    v-model="form.vigente_hasta"
                    tipo="date"
                    etiqueta="Recibe hasta"
                    :error="form.errors.vigente_hasta"
                    ayuda="En blanco, sin fecha de cierre."
                />

                <div class="flex items-end gap-2 sm:col-span-3">
                    <BotonPrincipal :procesando="form.processing" texto="Publicar" />
                    <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="creando = false">
                        Cancelar
                    </button>
                </div>
            </form>
        </section>

        <section v-for="p in publicaciones" :key="p.id" class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-base font-semibold">{{ p.nombre }}</h3>
                        <span class="rounded px-2 py-0.5 text-xs" :class="p.modo === 'inscripcion' ? 'bg-emerald-50 text-emerald-700' : 'bg-fondo text-suave'">
                            {{ p.modo === 'inscripcion' ? 'inscripción autogestiva' : 'captación' }}
                        </span>
                        <span v-if="!p.abierto" class="rounded bg-amber-50 px-2 py-0.5 text-xs text-amber-800">
                            {{ p.activo ? 'fuera de vigencia' : 'desactivada' }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ p.formulario }} · entran en «{{ p.etapa }}»
                        <template v-if="p.asesor"> · titular {{ p.asesor }}</template>
                        <template v-else> · <span class="text-amber-700">sin promotor asignado</span></template>
                        <template v-if="p.oferta"> · {{ p.oferta }}</template>
                    </p>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ p.visitas }} visitas · {{ p.envios }} solicitudes · conversión {{ conversion(p) }}
                    </p>
                </div>

                <div class="flex gap-2">
                    <a :href="p.url" target="_blank" rel="noopener" class="rounded-lg border px-3 py-1.5 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        Verlo
                    </a>
                    <button type="button" class="rounded-lg border px-3 py-1.5 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="alternarActivo(p)">
                        {{ p.activo ? 'Desactivar' : 'Activar' }}
                    </button>
                    <BotonAccion
                        v-if="p.envios === 0"
                        variante="eliminar"
                        @click="router.delete(`/promocion/publicaciones/${p.id}`, { preserveScroll: true })"
                    />
                </div>
            </div>

            <div class="mt-4 rounded-lg border p-3" :style="{ borderColor: 'var(--color-borde)' }">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-xs font-medium" :style="{ color: 'var(--color-suave)' }">
                        Pega esto en tu página web
                    </span>
                    <button type="button" class="text-xs font-medium" :style="{ color: 'var(--color-acento)' }" @click="copiar(p)">
                        {{ copiado === p.id ? '¡Copiado!' : 'Copiar' }}
                    </button>
                </div>
                <code class="mt-2 block overflow-x-auto whitespace-pre text-xs" :style="{ color: 'var(--color-suave)' }">{{ snippet(p) }}</code>
            </div>
        </section>

        <section v-if="!publicaciones.length" class="tarjeta px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
            Todavía no hay formularios publicados. Necesitas al menos un formulario armado en
            <a href="/formularios" :style="{ color: 'var(--color-acento)' }">el constructor</a>.
        </section>
    </AppLayout>
</template>
