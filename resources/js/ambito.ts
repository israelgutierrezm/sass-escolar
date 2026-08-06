import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

/**
 * Con qué oficio está usando el sistema quien tiene la pantalla enfrente.
 *
 * Sirve para una sola cosa y conviene que siga siendo así: elegir CÓMO se dice
 * algo, nunca QUÉ se le deja hacer. Lo segundo lo decide el servidor —esconder
 * un botón no cierra una ruta— y basar un permiso en esto sería confundir la
 * redacción con la autorización.
 *
 * ── Por qué hace falta ─────────────────────────────────────────────────────
 * El sistema le hablaba a todos como si fueran personal: «Tu rol activo no
 * incluye ese permiso», «las tarjetas aparecen según los permisos que tenga».
 * A quien administra eso le dice exactamente qué revisar. A un padre de familia
 * le dice que hay una maquinaria detrás y no qué hacer al respecto —ni siquiera
 * sabe que tiene un «rol activo»—, y lo deja peor que un mensaje que no
 * explique nada.
 */
export function usaAmbito(): ComputedRef<string | null> {
    const page = usePage();

    return computed(() => (page.props as any)?.auth?.usuario?.rol_activo?.ambito ?? null);
}

/**
 * ¿Le podemos hablar de roles y permisos?
 *
 * Sólo al personal de la escuela: es su herramienta de trabajo y esos términos
 * son los que usa la pantalla donde se arreglan. Para todos los demás, el
 * mensaje tiene que decir qué pasó y a quién acudir, sin nombrar la mecánica.
 */
export function usaJergaAdministrativa(): ComputedRef<boolean> {
    const ambito = usaAmbito();

    return computed(() => ambito.value === 'administrativo');
}
