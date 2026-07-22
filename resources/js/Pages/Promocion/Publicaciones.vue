<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

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

                <button
                    v-if="!creando"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="creando = true"
                >
                    Publicar formulario
                </button>
            </div>

            <form v-if="creando" class="mt-5 grid gap-4 border-t pt-5 sm:grid-cols-3" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="crear">
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Formulario</span>
                    <select v-model="form.formulario_id" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option v-for="f in formularios" :key="f.id" :value="f.id">{{ f.nombre }}</option>
                    </select>
                    <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Apunta a una versión concreta: si publicas la v2, es otra publicación.
                    </span>
                </label>

                <label class="text-sm">
                    <span class="mb-1 block font-medium">Nombre interno</span>
                    <input v-model="form.nombre" type="text" required placeholder="Campaña feria marzo" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>

                <label class="text-sm">
                    <span class="mb-1 block font-medium">Modo</span>
                    <select v-model="form.modo" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option value="captacion">Captación de interés</option>
                        <option value="inscripcion">Inscripción autogestiva</option>
                    </select>
                </label>

                <label class="text-sm sm:col-span-3">
                    <span class="mb-1 block font-medium">Título que ve el visitante</span>
                    <input v-model="form.titulo" type="text" required placeholder="Solicita informes de nuestras licenciaturas" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>

                <label class="text-sm sm:col-span-3">
                    <span class="mb-1 block font-medium">Texto de bienvenida</span>
                    <textarea v-model="form.bienvenida" rows="2" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }"></textarea>
                </label>

                <label class="text-sm sm:col-span-3">
                    <span class="mb-1 block font-medium">Texto de agradecimiento</span>
                    <textarea v-model="form.gracias" rows="2" placeholder="Alguien de la escuela te contactará muy pronto." class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }"></textarea>
                </label>

                <label class="text-sm">
                    <span class="mb-1 block font-medium">Origen que se les atribuye</span>
                    <select v-model="form.origen_id" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option :value="null">Autogestivo por omisión</option>
                        <option v-for="o in origenes" :key="o.id" :value="o.id">{{ o.nombre }}</option>
                    </select>
                </label>

                <label class="text-sm">
                    <span class="mb-1 block font-medium">Entran en la etapa</span>
                    <select v-model="form.etapa_crm_id" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option v-for="e in etapas" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                    </select>
                </label>

                <label class="text-sm">
                    <span class="mb-1 block font-medium">Promotor titular</span>
                    <select v-model="form.asesor_persona_id" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option :value="null">Sin asignar</option>
                        <option v-for="p in promotores" :key="p.persona_id" :value="p.persona_id">{{ p.nombre }}</option>
                    </select>
                    <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        También es quien devengará la comisión si se inscriben.
                    </span>
                </label>

                <label class="text-sm">
                    <span class="mb-1 block font-medium">Oferta fija</span>
                    <select v-model="form.oferta_id" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option :value="null">Que el visitante elija</option>
                        <option v-for="o in ofertas" :key="o.id" :value="o.id">{{ o.nombre }}</option>
                    </select>
                </label>

                <label class="text-sm">
                    <span class="mb-1 block font-medium">Campus</span>
                    <select v-model="form.campus_id" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option :value="null">Ninguno en particular</option>
                        <option v-for="c in campus" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                    </select>
                </label>

                <label class="text-sm">
                    <span class="mb-1 block font-medium">Recibe desde</span>
                    <input v-model="form.vigente_desde" type="date" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>

                <label class="text-sm">
                    <span class="mb-1 block font-medium">Recibe hasta</span>
                    <input v-model="form.vigente_hasta" type="date" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                    <span class="text-xs" :style="{ color: 'var(--color-suave)' }">En blanco, sin fecha de cierre.</span>
                </label>

                <div class="flex items-end gap-2 sm:col-span-3">
                    <button type="submit" :disabled="form.processing" class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }">
                        Publicar
                    </button>
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
                        <span class="rounded px-2 py-0.5 text-xs" :class="p.modo === 'inscripcion' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'">
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
                    <button
                        v-if="p.envios === 0"
                        type="button"
                        class="rounded-lg border px-3 py-1.5 text-sm text-red-600"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="router.delete(`/promocion/publicaciones/${p.id}`, { preserveScroll: true })"
                    >
                        Eliminar
                    </button>
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
