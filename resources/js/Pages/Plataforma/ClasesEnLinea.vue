<script setup lang="ts">
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * Clases en línea: qué proveedor está encendido y con cuántas licencias.
 *
 * ── Se dice qué ES una cuenta en cada proveedor ────────────────────────────
 * Es la diferencia que hace que esta pantalla se entienda o no. En Zoom cada
 * fila es una licencia que sostiene UNA clase a la vez, así que hay que comprar
 * tantas como clases simultáneas; en Meet una sola cuenta aguanta todas las que
 * sean. Sin decirlo aquí, quien configura o compra licencias de más o se queda
 * corto y lo descubre el primer lunes a las nueve.
 *
 * ── Las credenciales entran y no salen ─────────────────────────────────────
 * El servidor manda si un campo está puesto, nunca su valor, y el formulario
 * envía vacío lo que no se tocó. Por eso el marcador dice «guardado» en vez de
 * mostrar puntos suspensivos que parecerían el secreto de verdad.
 */
interface Campo {
    nombre: string;
    etiqueta: string;
    requerido: boolean;
    ayuda: string | null;
    puesto: boolean;
}

interface Cuenta {
    id: number;
    etiqueta: string;
    identificador: string;
    activa: boolean;
    proximas: number;
}

interface Proveedor {
    clave: string;
    nombre: string;
    descripcion: string;
    color: string;
    activa: boolean;
    completa: boolean;
    una_reunion_por_cuenta: boolean;
    que_es_una_cuenta: string;
    campo_cuenta: { etiqueta: string; ayuda: string };
    campos: Campo[];
    cuentas: Cuenta[];
}

interface DestinoGrabacion {
    clave: string;
    nombre: string;
    descripcion: string;
    color: string;
    necesita_cuenta: boolean;
    activo: boolean;
    completo: boolean;
    campos: Campo[];
}

const props = defineProps<{
    proveedores: Proveedor[];
    grabaciones: { destinos: DestinoGrabacion[]; url_aviso: string };
}>();

/** Qué proveedor tiene abierto el formulario de credenciales. */
const configurando = ref<string | null>(null);

const credenciales = useForm<{ activa: boolean; credenciales: Record<string, string> }>({
    activa: false,
    credenciales: {},
});

function abrirConfiguracion(p: Proveedor): void {
    configurando.value = configurando.value === p.clave ? null : p.clave;
    credenciales.clearErrors();
    credenciales.activa = p.activa;
    // En blanco siempre: lo vacío significa «no lo toqué».
    credenciales.credenciales = Object.fromEntries(p.campos.map((c) => [c.nombre, '']));
}

function guardar(p: Proveedor): void {
    credenciales.put(`/plataforma/clases-en-linea/${p.clave}`, {
        preserveScroll: true,
        onSuccess: () => { configurando.value = null; },
    });
}

/** Encender o apagar sin abrir el formulario, cuando ya hay credenciales. */
function alternar(p: Proveedor): void {
    router.put(
        `/plataforma/clases-en-linea/${p.clave}`,
        { activa: !p.activa, credenciales: {} },
        { preserveScroll: true },
    );
}

const alta = useForm({ etiqueta: '', identificador: '' });
const agregandoEn = ref<string | null>(null);

function agregarCuenta(p: Proveedor): void {
    alta.post(`/plataforma/clases-en-linea/${p.clave}/cuentas`, {
        preserveScroll: true,
        onSuccess: () => { alta.reset(); agregandoEn.value = null; },
    });
}

function alternarCuenta(c: Cuenta): void {
    router.put(`/plataforma/clases-en-linea/cuentas/${c.id}`, { activa: !c.activa }, { preserveScroll: true });
}

function quitarCuenta(c: Cuenta): void {
    const aviso = c.proximas > 0
        ? `«${c.etiqueta}» tiene ${c.proximas} clase(s) programadas. Se apagará en vez de borrarse. ¿Continuar?`
        : `¿Quitar «${c.etiqueta}»?`;

    if (!confirm(aviso)) return;

    router.delete(`/plataforma/clases-en-linea/cuentas/${c.id}`, { preserveScroll: true });
}

function activasDe(p: Proveedor): number {
    return p.cuentas.filter((c) => c.activa).length;
}

/*
 * Dónde se guardan las grabaciones. UNO a la vez: encender uno apaga los
 * demás, y el servidor lo vuelve a imponer. Con dos habría que decidir qué
 * enlace se le enseña al alumno y se pagaría dos veces el mismo archivo.
 */
const destinoAbierto = ref<string | null>(null);

const archivado = useForm<{ activo: boolean; credenciales: Record<string, string> }>({
    activo: false,
    credenciales: {},
});

function abrirDestino(d: DestinoGrabacion): void {
    destinoAbierto.value = destinoAbierto.value === d.clave ? null : d.clave;
    archivado.clearErrors();
    archivado.activo = d.activo;
    archivado.credenciales = Object.fromEntries(d.campos.map((c) => [c.nombre, '']));
}

function guardarDestino(d: DestinoGrabacion): void {
    archivado.put('/plataforma/clases-en-linea/destinos/' + d.clave, {
        preserveScroll: true,
        onSuccess: () => { destinoAbierto.value = null; },
    });
}

/** El destino sin credenciales (el disco propio) se enciende de un clic. */
function usarDestino(d: DestinoGrabacion): void {
    router.put(
        '/plataforma/clases-en-linea/destinos/' + d.clave,
        { activo: !d.activo, credenciales: {} },
        { preserveScroll: true },
    );
}

function copiarUrlAviso(): void {
    navigator.clipboard?.writeText(props.grabaciones.url_aviso);
}
</script>

<template>
    <Head title="Clases en línea" />

    <AppLayout titulo="Clases en línea">
        <p class="mb-4 max-w-3xl text-sm text-suave">
            El docente programa la clase desde su materia y al alumno le aparece el botón para
            entrar, sin que nadie copie ni pegue un enlace. Aquí se decide con qué se dan esas
            clases y cuántas pueden ocurrir a la vez.
        </p>

        <section
            v-for="p in proveedores"
            :key="p.clave"
            class="tarjeta mb-4 overflow-hidden"
        >
            <header class="flex flex-wrap items-start gap-3 px-5 py-4">
                <span
                    class="mt-0.5 h-9 w-9 shrink-0 rounded-lg"
                    :style="{ backgroundColor: `color-mix(in srgb, ${p.color} 18%, transparent)`, border: `1px solid ${p.color}` }"
                />

                <div class="min-w-0 flex-1">
                    <span class="flex flex-wrap items-center gap-2">
                        <strong class="text-contenido">{{ p.nombre }}</strong>
                        <span
                            class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                            :style="p.activa
                                ? { backgroundColor: 'color-mix(in srgb, #16a34a 14%, transparent)', color: '#15803d' }
                                : { backgroundColor: 'color-mix(in srgb, var(--color-suave) 14%, transparent)', color: 'var(--color-suave)' }"
                        >
                            {{ p.activa ? 'Encendido' : 'Apagado' }}
                        </span>
                        <span
                            v-if="!p.completa"
                            class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                            :style="{ backgroundColor: 'color-mix(in srgb, #d97706 14%, transparent)', color: '#b45309' }"
                        >
                            Sin credenciales
                        </span>
                    </span>
                    <p class="mt-0.5 text-sm text-suave">{{ p.descripcion }}</p>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <button
                        v-if="p.completa"
                        type="button"
                        class="rounded-lg border px-2.5 py-1 text-xs"
                        :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-contenido)' }"
                        @click="alternar(p)"
                    >
                        {{ p.activa ? 'Apagar' : 'Encender' }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border px-2.5 py-1 text-xs"
                        :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-contenido)' }"
                        @click="abrirConfiguracion(p)"
                    >
                        {{ configurando === p.clave ? 'Cerrar' : 'Credenciales' }}
                    </button>
                </div>
            </header>

            <!-- Credenciales -->
            <form
                v-if="configurando === p.clave"
                class="border-t px-5 py-4"
                :style="{ borderColor: 'var(--color-borde)' }"
                @submit.prevent="guardar(p)"
            >
                <div v-for="campo in p.campos" :key="campo.nombre" class="mb-3">
                    <label class="mb-1 flex flex-wrap items-baseline gap-2 text-sm font-medium text-contenido">
                        {{ campo.etiqueta }}
                        <span v-if="campo.requerido" class="text-red-500">*</span>
                        <!-- «Guardado» y no unos puntos suspensivos: un marcador
                             que parece el secreto invita a borrarlo para volver
                             a escribirlo entero. -->
                        <span v-if="campo.puesto" class="text-[11px] font-normal" :style="{ color: '#15803d' }">
                            ✓ guardado — déjalo vacío para no cambiarlo
                        </span>
                    </label>
                    <textarea
                        v-if="campo.nombre === 'cuenta_servicio_json'"
                        v-model="credenciales.credenciales[campo.nombre]"
                        rows="4"
                        class="w-full rounded-lg border px-3 py-2 font-mono text-xs"
                        :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                        placeholder="{ &quot;type&quot;: &quot;service_account&quot;, ... }"
                    />
                    <input
                        v-else
                        v-model="credenciales.credenciales[campo.nombre]"
                        type="password"
                        autocomplete="off"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                    />
                    <p v-if="campo.ayuda" class="mt-1 text-xs text-suave">{{ campo.ayuda }}</p>
                </div>

                <label class="flex items-center gap-2 text-sm text-contenido">
                    <input v-model="credenciales.activa" type="checkbox" />
                    Encendido: se ofrece al programar una clase
                </label>
                <p v-if="credenciales.errors.activa" class="mt-1 text-xs text-red-600">
                    {{ credenciales.errors.activa }}
                </p>

                <div class="mt-4 flex items-center gap-2">
                    <button
                        type="submit"
                        class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        :disabled="credenciales.processing"
                    >
                        Guardar
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border px-4 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="configurando = null"
                    >
                        Cancelar
                    </button>
                </div>
            </form>

            <!-- El pool -->
            <div class="border-t px-5 py-4" :style="{ borderColor: 'var(--color-borde)' }">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-sm font-semibold text-contenido">
                        {{ p.una_reunion_por_cuenta ? 'Licencias' : 'Cuentas' }}
                        <span class="ml-1 font-normal text-suave">{{ activasDe(p) }} activa(s)</span>
                    </h3>
                    <button
                        type="button"
                        class="text-xs font-medium"
                        :style="{ color: 'var(--color-acento)' }"
                        @click="agregandoEn = agregandoEn === p.clave ? null : p.clave"
                    >
                        {{ agregandoEn === p.clave ? 'Cancelar' : '+ Agregar' }}
                    </button>
                </div>

                <!-- Lo que hace distinto a este proveedor, dicho donde importa. -->
                <p class="mt-1 max-w-2xl text-xs text-suave">{{ p.que_es_una_cuenta }}</p>

                <p
                    v-if="p.una_reunion_por_cuenta && activasDe(p) > 0"
                    class="mt-1.5 text-xs"
                    :style="{ color: 'var(--color-acento)' }"
                >
                    Con {{ activasDe(p) }} puedes tener {{ activasDe(p) }}
                    {{ activasDe(p) === 1 ? 'clase' : 'clases' }} al mismo tiempo.
                </p>

                <form v-if="agregandoEn === p.clave" class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="agregarCuenta(p)">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-suave">Nombre</label>
                        <input
                            v-model="alta.etiqueta"
                            type="text"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                            :placeholder="p.una_reunion_por_cuenta ? 'Licencia 1' : 'Cuenta principal'"
                        />
                        <p v-if="alta.errors.etiqueta" class="mt-1 text-xs text-red-600">{{ alta.errors.etiqueta }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-suave">{{ p.campo_cuenta.etiqueta }}</label>
                        <input
                            v-model="alta.identificador"
                            type="text"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                            placeholder="clases@escuela.mx"
                        />
                        <p class="mt-1 text-xs text-suave">{{ p.campo_cuenta.ayuda }}</p>
                        <p v-if="alta.errors.identificador" class="mt-1 text-xs text-red-600">{{ alta.errors.identificador }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <button
                            type="submit"
                            class="rounded-lg px-3.5 py-2 text-sm font-medium disabled:opacity-50"
                            :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                            :disabled="alta.processing"
                        >
                            Agregar
                        </button>
                    </div>
                </form>

                <p v-if="!p.cuentas.length" class="mt-3 text-sm text-suave">
                    Todavía no hay ninguna. Sin al menos una, este proveedor no aparece al programar
                    una clase aunque esté encendido.
                </p>

                <ul v-else class="mt-3 space-y-1.5">
                    <li
                        v-for="c in p.cuentas"
                        :key="c.id"
                        class="flex flex-wrap items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)', opacity: c.activa ? 1 : 0.55 }"
                    >
                        <span class="min-w-0 flex-1">
                            <strong class="text-contenido">{{ c.etiqueta }}</strong>
                            <span class="ml-2 font-mono text-xs text-suave">{{ c.identificador }}</span>
                            <span v-if="c.proximas > 0" class="ml-2 text-xs text-suave">
                                · {{ c.proximas }} clase(s) por venir
                            </span>
                        </span>
                        <button
                            type="button"
                            class="rounded-lg border px-2.5 py-1 text-xs"
                            :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-contenido)' }"
                            @click="alternarCuenta(c)"
                        >
                            {{ c.activa ? 'Apagar' : 'Encender' }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border px-2.5 py-1 text-xs text-red-600"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="quitarCuenta(c)"
                        >
                            Quitar
                        </button>
                    </li>
                </ul>
            </div>
        </section>

        <!-- ===== Dónde se guardan las grabaciones ===== -->
        <section class="tarjeta mt-6 overflow-hidden">
            <header class="px-5 py-4">
                <h2 class="text-base font-semibold text-contenido">Guardar las grabaciones</h2>
                <p class="mt-1 max-w-3xl text-sm text-suave">
                    Zoom da unos pocos GB por licencia y, cuando se llenan, deja de grabar o borra
                    lo viejo; las de Meet quedan en el Drive de la cuenta que organiza, donde nadie
                    las encuentra. Traerlas a un sitio de la escuela es lo que las vuelve
                    consultables, y el enlace queda colgado de la clase.
                </p>
                <p class="mt-2 max-w-3xl text-xs text-suave">
                    <strong class="text-contenido">Se guarda en uno solo.</strong> Cambiar de sitio
                    no mueve lo ya guardado: lo viejo se sigue abriendo donde está.
                </p>
            </header>

            <div
                v-for="d in grabaciones.destinos"
                :key="d.clave"
                class="border-t px-5 py-4"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <div class="flex flex-wrap items-start gap-3">
                    <span
                        class="mt-0.5 h-8 w-8 shrink-0 rounded-lg"
                        :style="{ backgroundColor: 'color-mix(in srgb, ' + d.color + ' 18%, transparent)', border: '1px solid ' + d.color }"
                    />
                    <div class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center gap-2">
                            <strong class="text-sm text-contenido">{{ d.nombre }}</strong>
                            <span
                                v-if="d.activo"
                                class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :style="{ backgroundColor: 'color-mix(in srgb, #16a34a 14%, transparent)', color: '#15803d' }"
                            >
                                En uso
                            </span>
                            <span
                                v-else-if="d.necesita_cuenta && !d.completo"
                                class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 14%, transparent)', color: 'var(--color-suave)' }"
                            >
                                Sin conectar
                            </span>
                        </span>
                        <p class="mt-0.5 text-sm text-suave">{{ d.descripcion }}</p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <button
                            v-if="!d.necesita_cuenta || d.completo"
                            type="button"
                            class="rounded-lg border px-2.5 py-1 text-xs"
                            :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-contenido)' }"
                            @click="usarDestino(d)"
                        >
                            {{ d.activo ? 'Dejar de usar' : 'Usar éste' }}
                        </button>
                        <button
                            v-if="d.necesita_cuenta"
                            type="button"
                            class="rounded-lg border px-2.5 py-1 text-xs"
                            :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-contenido)' }"
                            @click="abrirDestino(d)"
                        >
                            {{ destinoAbierto === d.clave ? 'Cerrar' : 'Conectar' }}
                        </button>
                    </div>
                </div>

                <form
                    v-if="destinoAbierto === d.clave"
                    class="mt-3 rounded-lg border p-4"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @submit.prevent="guardarDestino(d)"
                >
                    <div v-for="campo in d.campos" :key="campo.nombre" class="mb-3">
                        <label class="mb-1 flex flex-wrap items-baseline gap-2 text-sm font-medium text-contenido">
                            {{ campo.etiqueta }}
                            <span v-if="campo.requerido" class="text-red-500">*</span>
                            <span v-if="campo.puesto" class="text-[11px] font-normal" :style="{ color: '#15803d' }">
                                guardado — déjalo vacío para no cambiarlo
                            </span>
                        </label>
                        <textarea
                            v-if="campo.nombre === 'cuenta_servicio_json'"
                            v-model="archivado.credenciales[campo.nombre]"
                            rows="3"
                            class="w-full rounded-lg border px-3 py-2 font-mono text-xs"
                            :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                        />
                        <input
                            v-else
                            v-model="archivado.credenciales[campo.nombre]"
                            type="password"
                            autocomplete="off"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                        />
                        <p v-if="campo.ayuda" class="mt-1 text-xs text-suave">{{ campo.ayuda }}</p>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-contenido">
                        <input v-model="archivado.activo" type="checkbox" />
                        Guardar aquí las grabaciones nuevas
                    </label>
                    <p v-if="archivado.errors.activo" class="mt-1 text-xs text-red-600">{{ archivado.errors.activo }}</p>

                    <button
                        type="submit"
                        class="mt-3 rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        :disabled="archivado.processing"
                    >
                        Guardar
                    </button>
                </form>
            </div>

            <!-- Lo que hay que pegar en Zoom. Copiar mal esta dirección es el
                 error más fácil de cometer y el más difícil de diagnosticar:
                 todo parece bien y las grabaciones no llegan nunca. -->
            <div class="border-t px-5 py-4" :style="{ borderColor: 'var(--color-borde)' }">
                <h3 class="text-sm font-semibold text-contenido">Para que Zoom avise</h3>
                <p class="mt-1 max-w-3xl text-xs text-suave">
                    En tu app de Zoom, activa el evento
                    <strong class="text-contenido">recording.completed</strong> y pega esta
                    dirección. Copia también su «Secret Token» al campo de credenciales de Zoom,
                    arriba: sin él los avisos se rechazan.
                </p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <code
                        class="rounded-lg border px-3 py-1.5 text-xs"
                        :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-contenido)' }"
                    >{{ grabaciones.url_aviso }}</code>
                    <button
                        type="button"
                        class="rounded-lg border px-2.5 py-1 text-xs"
                        :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-contenido)' }"
                        @click="copiarUrlAviso"
                    >
                        Copiar
                    </button>
                </div>
                <p class="mt-2 max-w-3xl text-xs text-suave">
                    Ojo: Zoom sólo entrega por API las grabaciones
                    <strong class="text-contenido">en la nube</strong>. Si el docente graba «en este
                    equipo», el archivo se queda en su computadora y no hay nada que traer.
                </p>
            </div>
        </section>
    </AppLayout>
</template>
