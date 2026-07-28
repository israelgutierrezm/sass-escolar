<?php

declare(strict_types=1);

namespace Database\Seeders\Landlord;

use App\Models\Landlord\Pais;
use Illuminate\Database\Seeder;

/**
 * Catálogo de países (LANDLORD). Lista completa ISO 3166-1 (código alfa-3 +
 * nombre en español), para «país de nacimiento» cuando el aspirante es
 * extranjero. Idempotente por `clave_iso`: correrlo de nuevo no duplica.
 */
class PaisSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->paises() as [$iso, $nombre]) {
            Pais::query()->updateOrCreate(['clave_iso' => $iso], ['nombre' => $nombre]);
        }
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function paises(): array
    {
        return [
            ['AFG', 'Afganistán'], ['ALB', 'Albania'], ['DEU', 'Alemania'], ['AND', 'Andorra'],
            ['AGO', 'Angola'], ['ATG', 'Antigua y Barbuda'], ['SAU', 'Arabia Saudita'], ['DZA', 'Argelia'],
            ['ARG', 'Argentina'], ['ARM', 'Armenia'], ['AUS', 'Australia'], ['AUT', 'Austria'],
            ['AZE', 'Azerbaiyán'], ['BHS', 'Bahamas'], ['BGD', 'Bangladés'], ['BRB', 'Barbados'],
            ['BHR', 'Baréin'], ['BEL', 'Bélgica'], ['BLZ', 'Belice'], ['BEN', 'Benín'],
            ['BLR', 'Bielorrusia'], ['MMR', 'Birmania (Myanmar)'], ['BOL', 'Bolivia'], ['BIH', 'Bosnia y Herzegovina'],
            ['BWA', 'Botsuana'], ['BRA', 'Brasil'], ['BRN', 'Brunéi'], ['BGR', 'Bulgaria'],
            ['BFA', 'Burkina Faso'], ['BDI', 'Burundi'], ['BTN', 'Bután'], ['CPV', 'Cabo Verde'],
            ['KHM', 'Camboya'], ['CMR', 'Camerún'], ['CAN', 'Canadá'], ['QAT', 'Catar'],
            ['TCD', 'Chad'], ['CHL', 'Chile'], ['CHN', 'China'], ['CYP', 'Chipre'],
            ['COL', 'Colombia'], ['COM', 'Comoras'], ['COG', 'Congo'], ['COD', 'Congo (Rep. Dem.)'],
            ['PRK', 'Corea del Norte'], ['KOR', 'Corea del Sur'], ['CIV', 'Costa de Marfil'], ['CRI', 'Costa Rica'],
            ['HRV', 'Croacia'], ['CUB', 'Cuba'], ['DNK', 'Dinamarca'], ['DMA', 'Dominica'],
            ['ECU', 'Ecuador'], ['EGY', 'Egipto'], ['SLV', 'El Salvador'], ['ARE', 'Emiratos Árabes Unidos'],
            ['ERI', 'Eritrea'], ['SVK', 'Eslovaquia'], ['SVN', 'Eslovenia'], ['ESP', 'España'],
            ['USA', 'Estados Unidos de América'], ['EST', 'Estonia'], ['SWZ', 'Esuatini'], ['ETH', 'Etiopía'],
            ['PHL', 'Filipinas'], ['FIN', 'Finlandia'], ['FJI', 'Fiyi'], ['FRA', 'Francia'],
            ['GAB', 'Gabón'], ['GMB', 'Gambia'], ['GEO', 'Georgia'], ['GHA', 'Ghana'],
            ['GRD', 'Granada'], ['GRC', 'Grecia'], ['GTM', 'Guatemala'], ['GIN', 'Guinea'],
            ['GNB', 'Guinea-Bisáu'], ['GNQ', 'Guinea Ecuatorial'], ['GUY', 'Guyana'], ['HTI', 'Haití'],
            ['HND', 'Honduras'], ['HUN', 'Hungría'], ['IND', 'India'], ['IDN', 'Indonesia'],
            ['IRQ', 'Irak'], ['IRN', 'Irán'], ['IRL', 'Irlanda'], ['ISL', 'Islandia'],
            ['MHL', 'Islas Marshall'], ['SLB', 'Islas Salomón'], ['ISR', 'Israel'], ['ITA', 'Italia'],
            ['JAM', 'Jamaica'], ['JPN', 'Japón'], ['JOR', 'Jordania'], ['KAZ', 'Kazajistán'],
            ['KEN', 'Kenia'], ['KGZ', 'Kirguistán'], ['KIR', 'Kiribati'], ['KWT', 'Kuwait'],
            ['LAO', 'Laos'], ['LSO', 'Lesoto'], ['LVA', 'Letonia'], ['LBN', 'Líbano'],
            ['LBR', 'Liberia'], ['LBY', 'Libia'], ['LIE', 'Liechtenstein'], ['LTU', 'Lituania'],
            ['LUX', 'Luxemburgo'], ['MKD', 'Macedonia del Norte'], ['MDG', 'Madagascar'], ['MYS', 'Malasia'],
            ['MWI', 'Malaui'], ['MDV', 'Maldivas'], ['MLI', 'Malí'], ['MLT', 'Malta'],
            ['MAR', 'Marruecos'], ['MUS', 'Mauricio'], ['MRT', 'Mauritania'], ['MEX', 'México'],
            ['FSM', 'Micronesia'], ['MDA', 'Moldavia'], ['MCO', 'Mónaco'], ['MNG', 'Mongolia'],
            ['MNE', 'Montenegro'], ['MOZ', 'Mozambique'], ['NAM', 'Namibia'], ['NRU', 'Nauru'],
            ['NPL', 'Nepal'], ['NIC', 'Nicaragua'], ['NER', 'Níger'], ['NGA', 'Nigeria'],
            ['NOR', 'Noruega'], ['NZL', 'Nueva Zelanda'], ['OMN', 'Omán'], ['NLD', 'Países Bajos'],
            ['PAK', 'Pakistán'], ['PLW', 'Palaos'], ['PSE', 'Palestina'], ['PAN', 'Panamá'],
            ['PNG', 'Papúa Nueva Guinea'], ['PRY', 'Paraguay'], ['PER', 'Perú'], ['POL', 'Polonia'],
            ['PRT', 'Portugal'], ['GBR', 'Reino Unido'], ['CAF', 'República Centroafricana'], ['CZE', 'República Checa'],
            ['DOM', 'República Dominicana'], ['RWA', 'Ruanda'], ['ROU', 'Rumanía'], ['RUS', 'Rusia'],
            ['WSM', 'Samoa'], ['KNA', 'San Cristóbal y Nieves'], ['SMR', 'San Marino'], ['VCT', 'San Vicente y las Granadinas'],
            ['LCA', 'Santa Lucía'], ['STP', 'Santo Tomé y Príncipe'], ['SEN', 'Senegal'], ['SRB', 'Serbia'],
            ['SYC', 'Seychelles'], ['SLE', 'Sierra Leona'], ['SGP', 'Singapur'], ['SYR', 'Siria'],
            ['SOM', 'Somalia'], ['LKA', 'Sri Lanka'], ['ZAF', 'Sudáfrica'], ['SDN', 'Sudán'],
            ['SSD', 'Sudán del Sur'], ['SWE', 'Suecia'], ['CHE', 'Suiza'], ['SUR', 'Surinam'],
            ['THA', 'Tailandia'], ['TZA', 'Tanzania'], ['TJK', 'Tayikistán'], ['TLS', 'Timor Oriental'],
            ['TGO', 'Togo'], ['TON', 'Tonga'], ['TTO', 'Trinidad y Tobago'], ['TUN', 'Túnez'],
            ['TKM', 'Turkmenistán'], ['TUR', 'Turquía'], ['TUV', 'Tuvalu'], ['UKR', 'Ucrania'],
            ['UGA', 'Uganda'], ['URY', 'Uruguay'], ['UZB', 'Uzbekistán'], ['VUT', 'Vanuatu'],
            ['VAT', 'Vaticano'], ['VEN', 'Venezuela'], ['VNM', 'Vietnam'], ['YEM', 'Yemen'],
            ['DJI', 'Yibuti'], ['ZMB', 'Zambia'], ['ZWE', 'Zimbabue'],
        ];
    }
}
