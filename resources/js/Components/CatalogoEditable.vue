<script setup lang="ts">
/**
 * Un catálogo TENANT-CONFIG editable desde pantalla: lista, alta, edición,
 * apagado y borrado.
 *
 * ── Salió aquí al aparecer el TERCER consumidor ────────────────────────────
 * Vivía dentro de `Escolar/CatalogosConducta.vue`, con las URLs de disciplina
 * escritas dentro y con UNA sola bandera propia por catálogo. El módulo de
 * servicio social trae catálogos con CUATRO banderas, así que copiarlo habría
 * dejado dos pantallas que hacen lo mismo y divergen a la primera corrección
 * —es exactamente lo que le pasó a `NavAcademico` y `NavEscolar` antes de
 * fundirse en `PestanasSeccion`—.
 *
 * Lo que se generalizó es justo eso: `base` (el prefijo de las rutas) y
 * `extras` (N campos propios en vez de uno).
 *
 * ── Lo que NO se puede eliminar se APAGA ───────────────────────────────────
 * Un tipo que algo usa deja registros colgando si se borra; apagarlo lo retira
 * de todos los desplegables sin tocar lo capturado. Y el botón de eliminar no
 * se dibuja siquiera cuando está en uso — el servidor lo vuelve a comprobar.
 */
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import MenuAcciones from '@/Components/MenuAcciones.vue';
import Modal from '@/Components/Modal.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

export interface ExtraCatalogo {
    campo: string;
    tipo: 'entero' | 'bandera';
    etiqueta: string;
    ayuda: string;
    /** Sólo `bandera`: qué decir en la insignia cuando está encendida. */
    insignia?: string;
    /**
     * Si se puede TOCAR desde aquí. Por omisión sí.
     *
     * En falso se sigue viendo en la lista —su insignia dice cómo está— pero no
     * aparece en el formulario. Hace falta para las banderas que son una
     * decisión de SEGURIDAD y no de captura: qué categoría de señal es
     * reservada, por ejemplo. Ofrecerlas para editar sería poner a un
     * administrativo a decidir, desde una casilla y sin contexto, quién puede
     * ver los adeudos de un alumno.
     *
     * Es la misma línea que `niveles_estudio.protegido`: se ve, se ordena y se
     * apaga; lo que no se toca es lo que otras partes del sistema dan por
     * cierto.
     */
    editable?: boolean;
}

export interface ItemCatalogo {
    id: number;
    clave: string;
    nombre: string;
    descripcion: string | null;
    activo: boolean;
    en_uso: boolean;
    [extra: string]: unknown;
}

export interface Catalogo {
    clave: string;
    etiqueta: string;
    singular: string;
    extras: ExtraCatalogo[];
    items: ItemCatalogo[];
}

const props = defineProps<{
    catalogos: Catalogo[];
    /** Prefijo de las rutas, sin barra final. P. ej. `/procesos/catalogos`. */
    base: string;
    /** Si esta persona puede tocar algo. Con falso, sólo lee. */
    puedeEditar: boolean;
    /** Cuántas columnas en pantalla ancha. */
    columnas?: number;
}>();

const editando = ref<{ catalogo: Catalogo; item: ItemCatalogo | null } | null>(null);

/*
 * ── Un `ref` llano y NO `useForm` ─────────────────────────────────────────
 * `useForm` fija sus campos al construirse: los que se agregan después no
 * viajan en `data()`. Aquí los campos dependen del CATÁLOGO —un tipo de proceso
 * trae cuatro banderas y un sector ninguna—, así que sólo se saben al abrir el
 * diálogo. Con `useForm` el formulario se quedaba mudo: se pulsaba «Agregar» y
 * no salía ni una petición, sin un solo error en la consola.
 *
 * Los campos propios viajan por su NOMBRE REAL de columna, que es lo que el
 * servidor valida. Con un nombre genérico habría que numerarlos y que el
 * servidor supiera en qué orden llegan.
 */
const datos = ref<Record<string, unknown>>({});
const errores = ref<Record<string, string>>({});
const procesando = ref(false);

const catalogoActivo = computed(() => editando.value?.catalogo ?? null);

/*
 * Lo que el formulario ofrece. Una bandera con `editable: false` se sigue
 * VIENDO en la lista —su insignia dice cómo está— y no se puede tocar desde
 * * aquí: son las que otras partes del sistema dan por ciertas.
 */
const extrasEditables = computed(
    () => (catalogoActivo.value?.extras ?? []).filter((e) => e.editable !== false),
);

const rejilla = computed(() => (props.columnas === 1 ? '' : 'lg:grid-cols-2'));

function vacio(extra: ExtraCatalogo): number | boolean {
    return extra.tipo === 'bandera' ? false : 1;
}

function abrir(catalogo: Catalogo, item: ItemCatalogo | null): void {
    editando.value = { catalogo, item };
    errores.value = {};

    const valores: Record<string, unknown> = {
        clave: item?.clave ?? '',
        nombre: item?.nombre ?? '',
        descripcion: item?.descripcion ?? '',
    };

    for (const extra of catalogo.extras) {
        valores[extra.campo] = item ? (item[extra.campo] ?? vacio(extra)) : vacio(extra);
    }

    datos.value = valores;
}

function cerrar(): void {
    editando.value = null;
    errores.value = {};
}

function guardar(): void {
    if (!editando.value) {
        return;
    }

    const { catalogo, item } = editando.value;
    const destino = item ? `${props.base}/${catalogo.clave}/${item.id}` : `${props.base}/${catalogo.clave}`;

    procesando.value = true;

    router[item ? 'put' : 'post'](destino, { ...datos.value }, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onSuccess: () => cerrar(),
        onFinish: () => (procesando.value = false),
    });
}

function alternar(catalogo: Catalogo, item: ItemCatalogo): void {
    router.patch(
        `${props.base}/${catalogo.clave}/${item.id}/activo`,
        { activo: !item.activo },
        { preserveScroll: true },
    );
}

function eliminar(catalogo: Catalogo, item: ItemCatalogo): void {
    if (!confirm(`¿Eliminar «${item.nombre}»? Sólo se puede si nadie lo usa.`)) {
        return;
    }

    router.delete(`${props.base}/${catalogo.clave}/${item.id}`, { preserveScroll: true });
}

/** El color del nivel de gravedad, mismo criterio que el listado que lo usa. */
function colorNivel(n: number): string {
    if (n >= 3) {
        return '#dc2626';
    }

    return n === 2 ? '#d97706' : '#16a34a';
}
</script>

<template>
    <div class="grid gap-6" :class="rejilla">
        <TarjetaSeccion
            v-for="catalogo in catalogos"
            :key="catalogo.clave"
            :titulo="catalogo.etiqueta"
            sin-relleno
        >
            <template v-if="puedeEditar" #insignia>
                <button
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-xs font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="abrir(catalogo, null)"
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

                            <!-- Las banderas propias, como insignias: es lo que
                                 hace visible que dos filas del mismo catálogo se
                                 comportan distinto. -->
                            <template v-for="extra in catalogo.extras" :key="extra.campo">
                                <span
                                    v-if="extra.tipo === 'entero'"
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :style="{
                                        backgroundColor: `color-mix(in srgb, ${colorNivel(item[extra.campo] as number)} 14%, transparent)`,
                                        color: colorNivel(item[extra.campo] as number),
                                    }"
                                >{{ extra.insignia ?? extra.etiqueta }} {{ item[extra.campo] }}</span>
                                <span
                                    v-else-if="item[extra.campo]"
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)', color: 'var(--color-acento)' }"
                                >{{ extra.insignia ?? extra.etiqueta }}</span>
                            </template>

                            <span v-if="!item.activo" class="text-xs" :style="{ color: 'var(--color-suave)' }">· apagado</span>
                        </p>
                        <p v-if="item.descripcion" class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">{{ item.descripcion }}</p>
                    </div>

                    <div v-if="puedeEditar" class="flex shrink-0 items-center gap-2">
                        <!-- Encender y apagar se queda A LA VISTA: no es
                             destructivo y es a lo que se entra a esta pantalla.
                             Lo que se pliega es editar y eliminar. -->
                        <button
                            type="button"
                            class="rounded-lg border px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-40"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            :disabled="item.activo && item.en_uso"
                            :title="item.activo && item.en_uso ? 'Hay registros que lo usan; no se puede apagar' : undefined"
                            @click="alternar(catalogo, item)"
                        >{{ item.activo ? 'Apagar' : 'Encender' }}</button>

                        <MenuAcciones
                            :opciones="[
                                { variante: 'editar', clave: 'editar' },
                                {
                                    variante: 'eliminar',
                                    clave: 'eliminar',
                                    deshabilitado: item.en_uso,
                                    motivo: item.en_uso ? 'Hay registros que lo usan; apágalo para retirarlo de los desplegables' : undefined,
                                },
                            ]"
                            @elegir="(que) => que === 'editar' ? abrir(catalogo, item) : eliminar(catalogo, item)"
                        />
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
        @cerrar="cerrar"
    >
        <template #default>
            <form class="space-y-4 p-6" @submit.prevent="guardar">
                <h2 class="text-base font-semibold">
                    {{ editando.item ? 'Editar' : 'Agregar' }} {{ catalogoActivo?.singular }}
                </h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    <CampoTexto v-model="datos.clave" etiqueta="Clave" marcador="p. ej. servicio_social" :error="errores.clave" requerido />
                    <CampoTexto v-model="datos.nombre" etiqueta="Nombre" :error="errores.nombre" requerido />
                </div>

                <CampoTextarea
                    v-model="datos.descripcion"
                    etiqueta="Descripción"
                    :filas="2"
                    ayuda="Opcional: para qué es este tipo."
                    :error="errores.descripcion"
                />

                <template v-for="extra in extrasEditables" :key="extra.campo">
                    <CampoTexto
                        v-if="extra.tipo === 'entero'"
                        v-model.number="datos[extra.campo]"
                        :etiqueta="extra.etiqueta"
                        tipo="number"
                        paso="1"
                        :ayuda="extra.ayuda"
                        :error="errores[extra.campo]"
                        requerido
                    />
                    <label v-else class="flex items-start gap-3 text-sm">
                        <input v-model="datos[extra.campo]" type="checkbox" class="mt-0.5 h-4 w-4" />
                        <span>
                            <span class="font-medium">{{ extra.etiqueta }}</span>
                            <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">{{ extra.ayuda }}</span>
                        </span>
                    </label>
                </template>

                <div class="flex items-center gap-3 pt-2">
                    <BotonPrincipal :procesando="procesando" :texto="editando.item ? 'Guardar' : 'Agregar'" icono="crear" />
                    <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">Cancelar</button>
                </div>
            </form>
        </template>
    </Modal>
</template>
