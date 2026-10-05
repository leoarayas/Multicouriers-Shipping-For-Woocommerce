<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/class-mcws-chile-address.php';

// Reset the internal caches so the test starts clean.
$mcws_reflection = new ReflectionClass('MCWS_Chile_Address');

foreach (array('cities', 'postal_codes') as $mcws_property_name) {
    $mcws_property = $mcws_reflection->getProperty($mcws_property_name);
    $mcws_property->setAccessible(true);
    $mcws_property->setValue(null, array('CL' => array()));
}

// Simulate the payload returned by https://app.multicouriers.cl/api/chile/cities
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

mcws_assert_same(
    '8320000',
    MCWS_Chile_Address::resolve_postal_code('CL-RM', 'Santiago', 'CL'),
    'Debe resolver el codigo postal cargado desde la API'
);

mcws_assert_same(
    '7750000',
    MCWS_Chile_Address::resolve_postal_code('CL-RM', 'Ñuñoa', 'CL'),
    'Debe normalizar comuna con enie al cargar desde la API'
);

mcws_assert_same(
    '2520000',
    MCWS_Chile_Address::resolve_postal_code('CL-VS', 'Viña del Mar', 'CL'),
    'Debe resolver comuna con acento cargada desde la API'
);

mcws_assert_same(
    '',
    MCWS_Chile_Address::resolve_postal_code('CL-RM', 'Comuna Inexistente', 'CL'),
    'Debe devolver vacio si la comuna no esta en la API'
);

mcws_assert_not_empty(
    MCWS_Chile_Address::get_postal_codes('CL'),
    'get_postal_codes debe quedar poblado tras hidratar la API'
);

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI test output.
fwrite(STDOUT, "OK: postcode-api-hydration-test.php" . PHP_EOL);
