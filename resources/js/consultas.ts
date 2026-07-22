/**
 * Consultas que NO son navegación.
 *
 * Inertia sirve para ir de una pantalla a otra; el eco de la CURP mientras se
 * teclea y la búsqueda de duplicados no cambian de pantalla, así que van por
 * `fetch` normal. Se centraliza aquí porque el token CSRF es fácil de olvidar
 * y el error que produce —419 sin mensaje— no se parece a su causa.
 */
export async function consultar<T>(url: string, datos: Record<string, unknown>): Promise<T> {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

    const respuesta = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': token,
        },
        body: JSON.stringify(datos),
    });

    if (!respuesta.ok) {
        throw new Error(`La consulta a ${url} respondió ${respuesta.status}`);
    }

    return respuesta.json() as Promise<T>;
}
