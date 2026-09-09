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

    /**
     * c_UsoCFDI completo (CFDI 4.0). Se lista entero, no un subconjunto: es lo
     * que permite validar contra él sin rechazar un uso legítimo. Ojo, «P01 Por
     * definir» de 3.3 ya NO existe en 4.0 —lo reemplazó S01—; incluirlo era
     * ofrecer un código que el SAT rechaza.
     */
    public static function usosCfdi(): array
    {
        return [
            ['clave' => 'G01', 'texto' => 'G01 · Adquisición de mercancías'],
            ['clave' => 'G02', 'texto' => 'G02 · Devoluciones, descuentos o bonificaciones'],
            ['clave' => 'G03', 'texto' => 'G03 · Gastos en general'],
            ['clave' => 'I01', 'texto' => 'I01 · Construcciones'],
            ['clave' => 'I02', 'texto' => 'I02 · Mobiliario y equipo de oficina por inversiones'],
            ['clave' => 'I03', 'texto' => 'I03 · Equipo de transporte'],
            ['clave' => 'I04', 'texto' => 'I04 · Equipo de cómputo y accesorios'],
            ['clave' => 'I05', 'texto' => 'I05 · Dados, troqueles, moldes, matrices y herramental'],
            ['clave' => 'I06', 'texto' => 'I06 · Comunicaciones telefónicas'],
            ['clave' => 'I07', 'texto' => 'I07 · Comunicaciones satelitales'],
            ['clave' => 'I08', 'texto' => 'I08 · Otra maquinaria y equipo'],
            ['clave' => 'D01', 'texto' => 'D01 · Honorarios médicos, dentales y gastos hospitalarios'],
            ['clave' => 'D02', 'texto' => 'D02 · Gastos médicos por incapacidad o discapacidad'],
            ['clave' => 'D03', 'texto' => 'D03 · Gastos funerales'],
            ['clave' => 'D04', 'texto' => 'D04 · Donativos'],
            ['clave' => 'D05', 'texto' => 'D05 · Intereses por créditos hipotecarios (casa habitación)'],
            ['clave' => 'D06', 'texto' => 'D06 · Aportaciones voluntarias al SAR'],
            ['clave' => 'D07', 'texto' => 'D07 · Primas por seguros de gastos médicos'],
            ['clave' => 'D08', 'texto' => 'D08 · Gastos de transportación escolar obligatoria'],
            ['clave' => 'D09', 'texto' => 'D09 · Depósitos en cuentas de ahorro, primas de planes de pensiones'],
            ['clave' => 'D10', 'texto' => 'D10 · Pagos por servicios educativos (colegiaturas)'],
            ['clave' => 'S01', 'texto' => 'S01 · Sin efectos fiscales'],
            ['clave' => 'CP01', 'texto' => 'CP01 · Pagos'],
            ['clave' => 'CN01', 'texto' => 'CN01 · Nómina'],
        ];
    }

    /** @return array<int, string> */
    public static function clavesUsosCfdi(): array
    {
        return array_map(fn (array $u) => $u['clave'], self::usosCfdi());
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

    /**
     * c_RegimenFiscal completo. Igual que los usos: entero para poder validar
     * contra él. Conviven los de persona moral (601, 603…) y física (605, 612,
     * 616, 626…) porque el receptor de una escuela es de los dos tipos.
     */
    public static function regimenesFiscales(): array
    {
        return [
            ['clave' => '601', 'texto' => '601 · General de Ley Personas Morales'],
            ['clave' => '603', 'texto' => '603 · Personas Morales con Fines no Lucrativos'],
            ['clave' => '605', 'texto' => '605 · Sueldos y Salarios e Ingresos Asimilados a Salarios'],
            ['clave' => '606', 'texto' => '606 · Arrendamiento'],
            ['clave' => '607', 'texto' => '607 · Régimen de Enajenación o Adquisición de Bienes'],
            ['clave' => '608', 'texto' => '608 · Demás ingresos'],
            ['clave' => '610', 'texto' => '610 · Residentes en el Extranjero sin Establecimiento Permanente'],
            ['clave' => '611', 'texto' => '611 · Ingresos por Dividendos (socios y accionistas)'],
            ['clave' => '612', 'texto' => '612 · Personas Físicas con Actividades Empresariales y Profesionales'],
            ['clave' => '614', 'texto' => '614 · Ingresos por intereses'],
            ['clave' => '615', 'texto' => '615 · Régimen de los ingresos por obtención de premios'],
            ['clave' => '616', 'texto' => '616 · Sin obligaciones fiscales'],
            ['clave' => '620', 'texto' => '620 · Sociedades Cooperativas de Producción que difieren ingresos'],
            ['clave' => '621', 'texto' => '621 · Incorporación Fiscal'],
            ['clave' => '622', 'texto' => '622 · Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras'],
            ['clave' => '623', 'texto' => '623 · Opcional para Grupos de Sociedades'],
            ['clave' => '624', 'texto' => '624 · Coordinados'],
            ['clave' => '625', 'texto' => '625 · Actividades Empresariales por Plataformas Tecnológicas'],
            ['clave' => '626', 'texto' => '626 · Régimen Simplificado de Confianza (RESICO)'],
        ];
    }

    /** @return array<int, string> */
    public static function clavesRegimenes(): array
    {
        return array_map(fn (array $r) => $r['clave'], self::regimenesFiscales());
    }
}
