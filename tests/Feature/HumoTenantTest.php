<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TenantTestCase;

class HumoTenantTest extends TenantTestCase
{
    public function test_el_esquema_de_pruebas_existe(): void
    {
        $this->assertTrue(Schema::connection('mysql')->hasTable('avisos'));
        $this->assertTrue(Schema::connection('mysql')->hasTable('avisos_destinos'));
        $this->assertTrue(Schema::connection('mysql')->hasTable('avisos_lecturas'));
        $this->assertTrue(Schema::connection('central')->hasTable('niveles_estudio'));
    }
}
