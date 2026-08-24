<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

interface Extra {
    campo: string;
    tipo: 'entero' | 'bandera';
    etiqueta: string;
    ayuda: string;
}

interface Item {
    id: number;
    clave: string;
    nombre: string;
    descripcion: string | null;
    activo: boolean;
    en_uso: boolean;
    [extra: string]: unknown;
}

interface Catalogo {
    clave: string;
    etiqueta: string;
    singular: string;
    extra: Extra;
    items: Item[];
}

const props = defineProps<{ catalogos: Catalogo[] }>();

const editando = ref<{ catalogo: Catalogo; item: Item | null } | null>(null);

const form = useForm({
    clave: '',
    nombre: '',
    descripcion: '',
    // El valor de la bandera propia del catálogo (nivel o tiene_vigencia).
    valor: null as number | boolean | null,
});

const catalogoActivo = computed(() => editando.value?.catalogo ?? null);

function abrirAlta(catalogo: Catalogo): void {
    editando.value = { catalogo, item: null };
    form.reset();
    form.clearErrors();
    form.valor = catalogo.extra.tipo === 'bandera' ? false : 1;
}

function abrirEdicion(catalogo: Catalogo, item: Item): void {
    editando.value = { catalogo, item };
    form.clearErrors();
    form.clave = item.clave;
    form.nombre = item.nombre;
    form.descripcion = item.descripcion ?? '';
    form.valor = item[catalogo.extra.campo] as number | boolean;
}

function guardar(): void {
    if (!editando.value) return;

    const { catalogo, item } = editando.value;

    // La bandera propia viaja con el nombre real de su columna.
    const datos = form.transform((d) => ({
        clave: d.clave,
        nombre: d.nombre,
        descripcion: d.descripcion,
        [catalogo.extra.campo]: d.valor,
    }));

    const opciones = {
        preserveScroll: true,
        onSuccess: () => {
            editando.value = null;
            form.reset();
        },
    };

    if (item) {
        datos.put(`/escolar/incidencias/catalogos/${catalogo.clave}/${item.id}`, opciones);
    } else {
        datos.post(`/escolar/incidencias/catalogos/${catalogo.clave}`, opciones);
    }
}

function alternar(catalogo: Catalogo, item: Item): void {
    router.patch(
        `/escolar/incidencias/catalogos/${catalogo.clave}/${item.id}/activo`,
        { activo: !item.activo },
        { preserveScroll: true },
    );
}

function eliminar(catalogo: Catalogo, item: Item): void {
    if (!confirm(`¿Eliminar «${item.nombre}»? Sólo se puede si nadie lo usa.`)) return;

    router.delete(`/escolar/incidencias/catalogos/${catalogo.clave}/${item.id}`, { preserveScroll: true });
}

// El color por nivel de gravedad, mismo criterio que el listado de incidencias.
function colorNivel(n: number): string {
    if (n >= 3) return '#dc2626';
    if (n === 2) return '#d97706';
    return '#16a34a';
}
</script>

<template>
    <Head title="Catálogos de conducta" />

    <AppLayout titulo="Catálogos de conducta">
        <p class="mb-4 max-w-2xl text-sm" :style="{ color: 'var(--color-suave)' }">
            Los tipos de incidencia y de sanción que la escuela registra. Un tipo que ya se usó no se
            puede eliminar —dejaría registros colgando—, pero se puede apagar para retirarlo de los
            desplegables sin borrar lo capturado.
        </p>

        <div class="grid gap-6 lg:grid-cols-2">
            <TarjetaSeccion v-for="catalogo in catalogos" :key="catalogo.clave" :titulo="catalogo.etiqueta" sin-relleno>
                <template #insignia>
                    <button
                        type="button"
                        class="rounded-lg px-3 py-1.5 text-xs font-medium"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        @click="abrirAlta(catalogo)"
                    >
                        Agregar
                    </button>
                </template>

                <ul>
                    <li
                        v-for="item in catalogo.items"
                        :key="item.id"
                        class="flex flex-wrap items-start justify-between gap-3 border-t px-6 py-3 text-sm"
                        :style="{ borderColor: 'var(--color-borde)', opacity: item.activo ? 1 : 0.55 }"
                    >
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 font-medium">
                                <span>{{ item.nombre }}</span>
                                <span class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ item.clave }}</span>

                                <!-- La bandera propia del catálogo, como insignia. -->
                                <span
                                    v-if="catalogo.extra.tipo === 'entero'"
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :style="{
                                        backgroundColor: `color-mix(in srgb, ${colorNivel(item[catalogo.extra.campo] as number)} 14%, transparent)`,
                                        color: colorNivel(item[catalogo.extra.campo] as number),
                                    }"
                                >Nivel {{ item[catalogo.extra.campo] }}</span>
                                <span
                                    v-else-if="item[catalogo.extra.campo]"
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)', color: 'var(--color-acento)' }"
                                >Con vigencia</span>

                                <span v-if="!item.activo" class="text-xs" :style="{ color: 'var(--color-suave)' }">· apagado</span>
                            </p>
                            <p v-if="item.descripcion" class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">{{ item.descripcion }}</p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <button
                                type="button"
                                class="rounded-lg border px-3 py-1.5 text-xs"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                @click="alternar(catalogo, item)"
                            >{{ item.activo ? 'Apagar' : 'Encender' }}</button>
                            <button
                                type="button"
                                class="rounded-lg border px-3 py-1.5 text-xs"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                @click="abrirEdicion(catalogo, item)"
                            >Editar</button>
                            <button
                                v-if="!item.en_uso"
                                type="button"
                                class="rounded-lg border px-3 py-1.5 text-xs"
                                :style="{ borderColor: 'var(--color-borde)', color: '#dc2626' }"
                                @click="eliminar(catalogo, item)"
                            >Eliminar</button>
                        </div>
                    </li>

                    <li v-if="!catalogo.items.length" class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                        Todavía no hay ninguno.
                    </li>
                </ul>
            </TarjetaSeccion>
        </div>

        <Modal
            v-if="editando"
            :etiqueta="editando.item ? 'Editar' : 'Agregar'"
            :formulario="form"
            @cerrar="editando = null"
        >
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardar">
                    <h2 class="text-base font-semibold">
                        {{ editando.item ? 'Editar' : 'Agregar' }} {{ catalogoActivo?.singular }}
                    </h2>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto v-model="form.clave" etiqueta="Clave" marcador="p. ej. retardo" :error="form.errors.clave" requerido />
                        <CampoTexto v-model="form.nombre" etiqueta="Nombre" :error="form.errors.nombre" requerido />
                    </div>

                    <CampoTextarea
                        v-model="form.descripcion"
                        etiqueta="Descripción"
                        :filas="2"
                        ayuda="Opcional: para qué es este tipo."
                        :error="form.errors.descripcion"
                    />

                    <!-- La bandera propia del catálogo -->
                    <div v-if="catalogoActivo?.extra.tipo === 'entero'">
                        <CampoTexto
                            v-model.number="form.valor"
                            :etiqueta="catalogoActivo.extra.etiqueta"
                            tipo="number"
                            paso="1"
                            :ayuda="catalogoActivo.extra.ayuda"
                            :error="form.errors[catalogoActivo.extra.campo]"
                            requerido
                        />
                    </div>
                    <label v-else-if="catalogoActivo" class="flex items-start gap-3 text-sm">
                        <input type="checkbox" v-model="form.valor" class="mt-0.5 h-4 w-4" />
                        <span>
                            <span class="font-medium">{{ catalogoActivo.extra.etiqueta }}</span>
                            <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">{{ catalogoActivo.extra.ayuda }}</span>
                        </span>
                    </label>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="form.processing" :texto="editando.item ? 'Guardar' : 'Agregar'" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">Cancelar</button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
