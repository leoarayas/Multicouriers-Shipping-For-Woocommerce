<?php

declare(strict_types=1);

namespace MCWS\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use MCWS_Chile_Address;

class PostcodeResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/includes/class-mcws-chile-address.php';

        $mcws_reflection = new ReflectionClass('MCWS_Chile_Address');
        $mcws_property = $mcws_reflection->getProperty('postal_codes');
        $mcws_property->setAccessible(true);
        $mcws_property->setValue(null, array(
            'CL' => array(
                'CL-RM' => array(
                    'SANTIAGO' => '8320000',
                    'SAN BERNARDO' => '8050000',
                ),
                'CL-VS' => array(
                    'VINA DEL MAR' => '2520000',
                ),
            ),
        ));
    }

    public function test_resolves_exact_commune_match(): void
    {
        $this->assertSame(
            '8320000',
            MCWS_Chile_Address::resolve_postal_code('CL-RM', 'Santiago', 'CL'),
            'Debe resolver match exacto de comuna'
        );
    }

    public function test_resolves_normalized_partial_match(): void
    {
        $this->assertSame(
            '2520000',
            MCWS_Chile_Address::resolve_postal_code('CL-VS', 'Viña', 'CL'),
            'Debe resolver match parcial (normalizado)'
        );
    }

    public function test_returns_empty_when_commune_does_not_exist(): void
    {
        $this->assertSame(
            '',
            MCWS_Chile_Address::resolve_postal_code('CL-RM', 'Comuna Inexistente', 'CL'),
            'Debe devolver vacio si no existe comuna'
        );
    }

    public function test_returns_empty_for_non_cl_country(): void
    {
        $this->assertSame(
            '',
            MCWS_Chile_Address::resolve_postal_code('CL-RM', 'Santiago', 'US'),
            'Debe devolver vacio para pais no CL'
        );
    }
}
