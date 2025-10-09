<?php return array(
    'root' => array(
        'name' => 'urlund/wp-test-plugin',
        'pretty_version' => '1.0.16',
        'version' => '1.0.16.0',
        'reference' => null,
        'type' => 'wordpress-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'urlund/wp-plugin-updater' => array(
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => 'd81b8593e70378226b2cc4d701c8097087c99a5a',
            'type' => 'library',
            'install_path' => __DIR__ . '/../urlund/wp-plugin-updater',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
        'urlund/wp-test-plugin' => array(
            'pretty_version' => '1.0.16',
            'version' => '1.0.16.0',
            'reference' => null,
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
