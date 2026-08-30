<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * Las cuentas donde la escuela recibe transferencias directas.
 *
 * Es la alternativa a la pasarela: no cobra comisión, pero alguien tiene que
 * validar cada comprobante. Aquí se cargan los datos que verá quien va a pagar.
 */
interface Cuenta {
    id: number;
    nombre: string;
    banco: string;
    titular: string;
    clabe: string | null;
    numero_cuenta: string | null;
    instrucciones: string | null;
    activa: boolean;
    programas_academicos: number[];
    alcance: string;
}

const props = defineProps<{
    cuentas: Cuenta[];
    programas_academicos: { id: number; nombre: string }[];
    puedeEditar: boolean;
}>();

const editando = ref<number | 'nueva' | null>(null);

const form = useForm({
    nombre: '',
    banco: '',
    titular: '',
    clabe: '',
    numero_cuenta: '',
    instrucciones: '',
    activa: true,
    programas_academicos: [] as number[],
});

function abrir(cuenta: Cuenta | null): void {
    editando.value = cuenta?.id ?? 'nueva';

    form.defaults({
        nombre: cuenta?.nombre ?? '',
        banco: cuenta?.banco ?? '',
        titular: cuenta?.titular ?? '',
        clabe: cuenta?.clabe ?? '',
        numero_cuenta: cuenta?.numero_cuenta ?? '',
        instrucciones: cuenta?.instrucciones ?? '',
        activa: cuenta?.activa ?? true,
        programas_academicos: [...(cuenta?.programas_academicos ?? [])],
    });

    form.reset();
}

function guardar(): void {
    const destino = editando.value === 'nueva'
        ? '/finanzas/cuentas-bancarias'
        : `/finanzas/cuentas-bancarias/${editando.value}`;

    const enviar = editando.value === 'nueva' ? form.post : form.put;

    enviar.call(form, destino, {
        preserveScroll: true,
        onSuccess: () => { editando.value = null; },
    });
}

function eliminar(cuenta: Cuenta): void {
    router.delete(`/finanzas/cuentas-bancarias/${cuenta.id}`, { preserveScroll: true });
}

function alternarProgramaAcademico(id: number): void {
    const i = form.programas_academicos.indexOf(id);
    i === -1 ? form.programas_academicos.push(id) : form.programas_academicos.splice(i, 1);
}
</script>

<template>
    <Head title="Cuentas bancarias" />

    <AppLayout titulo="Cuentas bancarias">
        <p class="mb-4 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
            Dónde recibe la escuela las transferencias directas, sin pasarela. Quien pague verá estos
            datos y subirá su comprobante; alguien de la escuela tendrá que validarlo para que el
            cargo quede pagado.
        </p>

        <div class="mb-4">
            <button
                v-if="puedeEditar && editando === null"
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="abrir(null)"
            >
                Agregar cuenta
            </button>
        </div>

        <!-- Alta o edición -->
        <section v-if="editando !== null" class="tarjeta mb-4 p-6">
            <h2 class="mb-4 font-semibold">{{ editando === 'nueva' ? 'Nueva cuenta' : 'Editar cuenta' }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="text-sm">
                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Nombre interno</span>
                    <input v-model="form.nombre" type="text" placeholder="Colegiaturas BBVA" class="w-full rounded-lg border bg-transparent px-3 py-2" :style="{ borderColor: 'var(--color-borde)' }" />
                    <span v-if="form.errors.nombre" class="text-xs text-red-600">{{ form.errors.nombre }}</span>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Banco</span>
                    <input v-model="form.banco" type="text" class="w-full rounded-lg border bg-transparent px-3 py-2" :style="{ borderColor: 'var(--color-borde)' }" />
                    <span v-if="form.errors.banco" class="text-xs text-red-600">{{ form.errors.banco }}</span>
                </label>
                <label class="text-sm sm:col-span-2">
                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Titular de la cuenta</span>
                    <input v-model="form.titular" type="text" class="w-full rounded-lg border bg-transparent px-3 py-2" :style="{ borderColor: 'var(--color-borde)' }" />
                    <span v-if="form.errors.titular" class="text-xs text-red-600">{{ form.errors.titular }}</span>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">CLABE (18 dígitos)</span>
                    <input v-model="form.clabe" type="text" inputmode="numeric" maxlength="18" class="w-full rounded-lg border bg-transparent px-3 py-2 font-mono" :style="{ borderColor: 'var(--color-borde)' }" />
                    <span v-if="form.errors.clabe" class="text-xs text-red-600">{{ form.errors.clabe }}</span>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Número de cuenta</span>
                    <input v-model="form.numero_cuenta" type="text" class="w-full rounded-lg border bg-transparent px-3 py-2 font-mono" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm sm:col-span-2">
                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">
                        Instrucciones para quien paga (opcional)
                    </span>
                    <textarea v-model="form.instrucciones" rows="2" placeholder="Pon tu matrícula en el concepto del pago." class="w-full rounded-lg border bg-transparent px-3 py-2" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
            </div>

            <!--
                Sin marcar nada vale para todas. Es el caso simple y el más
                común; obligar a marcar la lista entera haría que abrir una
                programa académico nueva dejara la cuenta fuera sin que nadie lo note.
            -->
            <div class="mt-4">
                <p class="mb-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                    ¿Para qué programas académicos? Sin marcar ninguna, vale para todas.
                </p>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="c in programas_academicos"
                        :key="c.id"
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs transition"
                        :style="form.programas_academicos.includes(c.id)
                            ? { backgroundColor: 'var(--color-acento)', borderColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }
                            : { borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                        @click="alternarProgramaAcademico(c.id)"
                    >
                        {{ c.nombre }}
                    </button>
                </div>
            </div>

            <label class="mt-4 flex items-center gap-2 text-sm">
                <input v-model="form.activa" type="checkbox" class="h-4 w-4 rounded" />
                Activa (se le ofrece a quien va a pagar)
            </label>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    :disabled="form.processing"
                    @click="guardar"
                >
                    {{ form.processing ? 'Guardando…' : 'Guardar' }}
                </button>
                <button type="button" class="text-sm" :style="{ color: 'var(--color-suave)' }" @click="editando = null">
                    Cancelar
                </button>
            </div>
        </section>

        <!-- Listado -->
        <div class="grid gap-3">
            <section v-for="c in cuentas" :key="c.id" class="tarjeta flex flex-wrap items-start justify-between gap-4 p-5">
                <div class="min-w-0">
                    <h3 class="flex flex-wrap items-center gap-2 font-semibold">
                        {{ c.nombre }}
                        <span
                            v-if="!c.activa"
                            class="rounded-full px-2 py-0.5 text-[11px] font-normal"
                            :style="{ backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                        >Inactiva</span>
                    </h3>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ c.banco }} · {{ c.titular }}
                    </p>
                    <p v-if="c.clabe" class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">CLABE {{ c.clabe }}</p>
                    <p v-else-if="c.numero_cuenta" class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">Cuenta {{ c.numero_cuenta }}</p>
                    <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">{{ c.alcance }}</p>
                </div>

                <div v-if="puedeEditar" class="flex gap-2">
                    <button type="button" class="text-sm" :style="{ color: 'var(--color-acento)' }" @click="abrir(c)">Editar</button>
                    <button type="button" class="text-sm text-red-600" @click="eliminar(c)">Eliminar</button>
                </div>
            </section>

            <p v-if="!cuentas.length" class="tarjeta px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay cuentas. Sin ellas, sólo se puede pagar con las pasarelas que estén activas.
            </p>
        </div>
    </AppLayout>
</template>
