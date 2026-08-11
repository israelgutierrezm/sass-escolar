<?php

declare(strict_types=1);

namespace App\Credencial;

use App\Models\Academico\Institucion;
use App\Models\Identidad\CredencialRol;
use GdImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Los tres fondos que trae el sistema, dibujados al vuelo.
 *
 * ── Qué cambia de una escuela a otra ───────────────────────────────────────
 * Sólo el logo, el nombre de la institución y el color de acento —el del tema
 * que ya eligió la escuela—. Es lo que pidió el cliente: tres formatos donde lo
 * único que varía es la identidad, con los huecos de los datos ya definidos.
 *
 * ── Por qué se dibujan y no son imágenes guardadas ─────────────────────────
 * Porque tienen que adaptarse a dos cosas que no se saben de antemano: el
 * tamaño del lienzo —hay credenciales horizontales y verticales— y el color de
 * la escuela. Tres PNG fijos obligarían a tener uno por combinación, o a
 * estirarlos, que es como se ve mal un logo.
 */
class Disenos
{
    /** Los tres, con su nombre para la pantalla y una descripción honesta. */
    public const CATALOGO = [
        'clasico' => [
            'nombre' => 'Clásico',
            'descripcion' => 'Banda de color arriba con el logo, y el resto en blanco. El que más se parece a una credencial escolar de toda la vida.',
        ],
        'moderno' => [
            'nombre' => 'Moderno',
            'descripcion' => 'Color en diagonal cubriendo la mitad superior. Da más presencia a la foto.',
        ],
        'minimo' => [
            'nombre' => 'Mínimo',
            'descripcion' => 'Fondo blanco con una línea de color al pie. El que menos tinta gasta si se imprime.',
        ],
    ];

    /** Dibuja el fondo del diseño elegido para esa cara. */
    public function fondo(CredencialRol $config, string $cara): GdImage
    {
        $ancho = $config->ancho;
        $alto = $config->alto;

        $lienzo = imagecreatetruecolor($ancho, $alto);
        imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));

        [$r, $g, $b] = $this->acento();
        $color = imagecolorallocate($lienzo, $r, $g, $b);

        match ($config->diseno) {
            'moderno' => $this->moderno($lienzo, $ancho, $alto, $color),
            'minimo' => $this->minimo($lienzo, $ancho, $alto, $color),
            default => $this->clasico($lienzo, $ancho, $alto, $color),
        };

        /*
         * El reverso NO lleva la identidad repetida.
         *
         * En las credenciales reales el logo va una vez: repetirlo detrás roba
         * el espacio libre del reverso, que es lo que la escuela va a querer
         * usar para las leyendas, la firma o el QR si decide ponerlos ahí.
         */
        if ($cara === 'anverso') {
            $this->identidad($lienzo, $ancho, $alto, $config->diseno);
        }

        return $lienzo;
    }

    private function clasico(GdImage $lienzo, int $ancho, int $alto, int $color): void
    {
        imagefilledrectangle($lienzo, 0, 0, $ancho, (int) round($alto * 0.22), $color);
    }

    private function moderno(GdImage $lienzo, int $ancho, int $alto, int $color): void
    {
        imagefilledpolygon($lienzo, [
            0, 0,
            $ancho, 0,
            $ancho, (int) round($alto * 0.38),
            0, (int) round($alto * 0.52),
        ], $color);
    }

    private function minimo(GdImage $lienzo, int $ancho, int $alto, int $color): void
    {
        imagefilledrectangle($lienzo, 0, (int) round($alto * 0.94), $ancho, $alto, $color);
    }

    /**
     * El logo de la escuela y su nombre, sobre la banda del diseño.
     *
     * Si no hay logo cargado se escribe sólo el nombre: una credencial sin logo
     * sigue siendo válida, y dejar un recuadro vacío esperándolo se ve peor.
     */
    private function identidad(GdImage $lienzo, int $ancho, int $alto, string $diseno): void
    {
        // En «mínimo» la banda está al pie, así que la identidad va arriba en
        // el color del texto y no encima de la franja.
        $sobreColor = $diseno !== 'minimo';
        $margen = (int) round($ancho * 0.05);
        $y = $sobreColor ? (int) round($alto * 0.06) : (int) round($alto * 0.06);
        $altoLogo = (int) round($alto * 0.10);

        $x = $margen;
        $logo = $this->logo();

        if ($logo !== null) {
            $imagen = @imagecreatefromstring($logo);

            if ($imagen !== false) {
                $escala = $altoLogo / imagesy($imagen);
                $anchoLogo = (int) round(imagesx($imagen) * $escala);
                imagecopyresampled($lienzo, $imagen, $x, $y, 0, 0, $anchoLogo, $altoLogo, imagesx($imagen), imagesy($imagen));
                imagedestroy($imagen);
                $x += $anchoLogo + (int) round($ancho * 0.02);
            }
        }

        $nombre = $this->nombreDeLaEscuela();

        if (blank($nombre)) {
            return;
        }

        $fuente = resource_path('fuentes/OpenSans.ttf');

        if (! is_file($fuente)) {
            return;
        }

        /*
         * El tamaño se ajusta al ANCHO que queda, no al alto del lienzo.
         *
         * Calcularlo del alto parecía razonable y se rompe en cuanto la
         * credencial es vertical: en una de 638×1011, el 4.5% del alto son 45 px
         * y «Instituto Politécnico Nacional» se salía del lienzo por la derecha.
         * Se vio renderizando, no leyendo el código. Ahora se parte del tamaño
         * deseado y se encoge hasta que quepa.
         */
        $disponible = $ancho - $x - $margen;
        $tamano = max(9, (int) round($alto * 0.045));

        while ($tamano > 9 && $this->anchoDelTexto($fuente, $tamano, $nombre) > $disponible) {
            $tamano--;
        }

        $blanco = imagecolorallocate($lienzo, 255, 255, 255);
        $tinta = imagecolorallocate($lienzo, 17, 24, 39);

        imagettftext(
            $lienzo, $tamano, 0,
            $x, $y + $altoLogo - (int) round($tamano * 0.2),
            $sobreColor ? $blanco : $tinta,
            $fuente, $nombre,
        );
    }

    /** Cuánto mide ese texto a ese tamaño, para poder encogerlo hasta que quepa. */
    private function anchoDelTexto(string $fuente, int $tamano, string $texto): int
    {
        $caja = imagettfbbox($tamano, 0, $fuente, $texto);

        return (int) abs($caja[2] - $caja[0]);
    }

    /**
     * El acento del tema de la escuela, en RGB.
     *
     * Se reusa el color que ya eligió en su tema en vez de pedirle otro: la
     * credencial es parte de su identidad, no un elemento aparte que haya que
     * volver a decidir. Sin tema configurado, un azul sobrio.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function acento(): array
    {
        $hex = ltrim((string) ($this->tokenDelTema('acento') ?? '#1E3A8A'), '#');

        if (strlen($hex) !== 6) {
            $hex = '1E3A8A';
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * El color del tema activo de la escuela.
     *
     * `tema_tokens` guarda un color por FILA —es la decisión de arquitectura de
     * los temas—, así que se pide el token por su clave y no un objeto de
     * colores.
     */
    private function tokenDelTema(string $token): ?string
    {
        return DB::table('tema_tokens')
            ->join('temas', 'temas.id', '=', 'tema_tokens.tema_id')
            ->where('temas.es_default', true)
            ->where('tema_tokens.token', $token)
            ->value('tema_tokens.valor');
    }

    /**
     * El logo de la institución, leído del disco PRIVADO.
     *
     * `instituciones.logo_url` no es una URL pese al nombre: guarda la ruta en
     * el disco `local`, y se sirve por un controlador que exige sesión. Aquí se
     * lee el archivo directo porque la credencial se compone en el servidor.
     */
    private function logo(): ?string
    {
        $ruta = Institucion::query()->value('logo_url');

        return filled($ruta) && Storage::disk('local')->exists($ruta)
            ? Storage::disk('local')->get($ruta)
            : null;
    }

    /** El nombre A MOSTRAR, igual que en el membrete y el login. */
    private function nombreDeLaEscuela(): ?string
    {
        $institucion = Institucion::query()->first();

        return $institucion === null
            ? null
            : ($institucion->nombre_mostrar ?: $institucion->nombre);
    }
}
