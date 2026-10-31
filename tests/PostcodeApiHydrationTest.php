<?php

declare(strict_types=1);

namespace MCWS\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use MCWS_Chile_Address;

class PostcodeApiHydrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/includes/class-mcws-chile-address.php';

        $mcws_reflection = new ReflectionClass('MCWS_Chile_Address');

        foreach (array('cities', 'postal_codes') as $mcws_property_name) {
            $mcws_property = $mcws_reflection->getProperty($mcws_property_name);
            $mcws_property->setAccessible(true);
            $mcws_property->setValue(null, array('CL' => array()));
        }

        $mcws_api_payload = array(
            'CL-RM' => array(
                'Santiago' => array('name' => 'Santiago', 'postal_code' => '8320000'),
                'Ñuñoa' => array('name' => 'Ñuñoa', 'postal_code' => '7750000'),
            ),
            'CL-VS' => array(
                'Viña del Mar' => array('name' => 'Viña del Mar', 'postal_code' => '2520000'),
            ),
        );

        $mcws_hydrate = $mcws_reflection->getMethod('hydrate_from_api_payload');
        $mcws_hydrate->setAccessible(true);
        $mcws_hydrate->invoke(null, $mcws_api_payload);
    }

    public function test_resolves_postal_code_from_api(): void
    {
        $this->assertSame(
            '8320000',
            MCWS_Chile_Address::resolve_postal_code('CL-RM', 'Santiago', 'CL'),
            'Debe resolver el codigo postal cargado desde la API'
        );
    }

    public function test_normalizes_commune_with_enie(): void
    {
        $this->assertSame(
            '7750000',
            MCWS_Chile_Address::resolve_postal_code('CL-RM', 'Ñuñoa', 'CL'),
            'Debe normalizar comuna con enie al cargar desde la API'
        );
    }

    public function test_resolves_commune_with_accent(): void
    {
        $this->assertSame(
            '2520000',
            MCWS_Chile_Address::resolve_postal_code('CL-VS', 'Viña del Mar', 'CL'),
            'Debe resolver comuna con acento cargada desde la API'
        );
    }

    public function test_empty_for_missing_commune_after_api_hydration(): void
    {
        $this->assertSame(
            '',
            MCWS_Chile_Address::resolve_postal_code('CL-RM', 'Comuna No Existente', 'CL'),
            'Debe devolver vacio si la comuna no vino en la API'
        );
    }
}
