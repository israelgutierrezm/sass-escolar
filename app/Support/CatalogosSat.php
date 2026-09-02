<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Catálogos del SAT (abreviados) para los desplegables de facturación.
 *
 * Son SUBCONJUNTOS de uso común, no el catálogo completo del SAT: se amplían
 * aquí sin tocar controladores. Viven en un solo lugar para que la
 * configuración de facturación y el alta de razones sociales muestren lo mismo.
 */
final class CatalogosSat
{
    /** @return array<string, array<int, array{clave: string, texto: string}>> */
    public static function todos(): array
    {
        return [
            'usos_cfdi' => self::usosCfdi(),
            'formas_pago' => self::formasPago(),
            'metodos_pago' => self::metodosPago(),
            'exportacion' => self::exportacion(),
            'objeto_impuesto' => self::objetoImpuesto(),
            'monedas' => self::monedas(),
            'regimenes_fiscales' => self::regimenesFiscales(),
            'niveles_iedu' => self::nivelesEducativosIedu(),
        ];
    }

    public static function usosCfdi(): array
    {
        return [
            ['clave' => 'G01', 'texto' => 'G01 · Adquisición de mercancías'],
            ['clave' => 'G03', 'texto' => 'G03 · Gastos en general'],
            ['clave' => 'D10', 'texto' => 'D10 · Pagos por servicios educativos'],
            ['clave' => 'P01', 'texto' => 'P01 · Por definir'],
            ['clave' => 'S01', 'texto' => 'S01 · Sin efectos fiscales'],
        ];
    }

    public static function formasPago(): array
    {
        return [
            ['clave' => '01', 'texto' => '01 · Efectivo'],
            ['clave' => '03', 'texto' => '03 · Transferencia electrónica'],
            ['clave' => '04', 'texto' => '04 · Tarjeta de crédito'],
            ['clave' => '28', 'texto' => '28 · Tarjeta de débito'],
            ['clave' => '99', 'texto' => '99 · Por definir'],
        ];
    }

    public static function metodosPago(): array
    {
        return [
            ['clave' => 'PUE', 'texto' => 'PUE · Pago en una sola exhibición'],
            ['clave' => 'PPD', 'texto' => 'PPD · Pago en parcialidades o diferido'],
        ];
    }

    public static function exportacion(): array
    {
        return [
            ['clave' => '01', 'texto' => '01 · No aplica'],
            ['clave' => '02', 'texto' => '02 · Definitiva'],
            ['clave' => '03', 'texto' => '03 · Temporal'],
        ];
    }

    public static function objetoImpuesto(): array
    {
        return [
            ['clave' => '01', 'texto' => '01 · No objeto de impuesto'],
            ['clave' => '02', 'texto' => '02 · Sí objeto de impuesto'],
            ['clave' => '03', 'texto' => '03 · Sí objeto, no obligado al desglose'],
            ['clave' => '04', 'texto' => '04 · Sí objeto, no causa impuesto'],
        ];
    }

    public static function monedas(): array
    {
        return [
            ['clave' => 'MXN', 'texto' => 'MXN · Peso mexicano'],
            ['clave' => 'USD', 'texto' => 'USD · Dólar estadounidense'],
        ];
    }

    /**
     * Los cinco niveles que el complemento IEDU admite.
     *
     * Es un catálogo del SAT y no de la escuela: son literales que viajan en el
     * XML y no se traducen ni se renombran. Que llegue hasta bachillerato NO es
     * un recorte nuestro — la deducción de colegiaturas no alcanza a la
     * educación superior, así que licenciatura, maestría y doctorado no tienen
     * a qué mapearse y quedan sin complemento.
     *
     * @return array<int, array{clave: string, texto: string}>
     */
    public static function nivelesEducativosIedu(): array
    {
        return [
            ['clave' => 'Preescolar', 'texto' => 'Preescolar'],
            ['clave' => 'Primaria', 'texto' => 'Primaria'],
            ['clave' => 'Secundaria', 'texto' => 'Secundaria'],
            ['clave' => 'Profesional técnico', 'texto' => 'Profesional técnico'],
            ['clave' => 'Bachillerato o su equivalente', 'texto' => 'Bachillerato o su equivalente'],
        ];
    }

    public static function regimenesFiscales(): array
    {
        return [
            ['clave' => '601', 'texto' => '601 · General de Ley Personas Morales'],
            ['clave' => '603', 'texto' => '603 · Personas Morales con Fines no Lucrativos'],
            ['clave' => '612', 'texto' => '612 · Personas Físicas con Actividades Empresariales y Profesionales'],
            ['clave' => '621', 'texto' => '621 · Incorporación Fiscal'],
            ['clave' => '626', 'texto' => '626 · Régimen Simplificado de Confianza (RESICO)'],
        ];
    }
}
