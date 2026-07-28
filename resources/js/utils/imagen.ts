/**
 * Prepara una imagen para subirla: si es un raster grande, la reduce a un lado
 * máximo y la reencoda a PNG, para que el archivo resultante sea liviano y NO
 * choque con el límite de subida de PHP (upload_max_filesize / post_max_size),
 * que devuelve un críptico «failed to upload».
 *
 * No toca SVG (es vector) ni GIF (puede ser animado), ni imágenes ya pequeñas:
 * en esos casos devuelve el archivo original tal cual. Si algo falla al
 * rasterizar (formato no soportado por el navegador, p. ej. HEIC), también
 * devuelve el original y deja que el backend valide el formato.
 */
export async function prepararImagen(
    archivo: File,
    ladoMaximo = 512,
    umbralBytes = 400 * 1024,
): Promise<File> {
    // Vector o animación: no se rasterizan.
    if (archivo.type === 'image/svg+xml' || archivo.type === 'image/gif') {
        return archivo;
    }

    // Ya es liviana: no vale la pena reprocesarla.
    if (archivo.size <= umbralBytes) {
        return archivo;
    }

    try {
        const bitmap = await createImageBitmap(archivo);
        const escala = Math.min(1, ladoMaximo / Math.max(bitmap.width, bitmap.height));
        const ancho = Math.max(1, Math.round(bitmap.width * escala));
        const alto = Math.max(1, Math.round(bitmap.height * escala));

        const lienzo = document.createElement('canvas');
        lienzo.width = ancho;
        lienzo.height = alto;
        const ctx = lienzo.getContext('2d');
        if (!ctx) {
            return archivo;
        }
        ctx.drawImage(bitmap, 0, 0, ancho, alto);
        bitmap.close?.();

        const blob = await new Promise<Blob | null>((resolver) => lienzo.toBlob(resolver, 'image/png'));
        if (!blob) {
            return archivo;
        }

        const nombre = archivo.name.replace(/\.[^.]+$/, '') + '.png';
        return new File([blob], nombre, { type: 'image/png' });
    } catch {
        return archivo;
    }
}
