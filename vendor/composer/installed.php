<?php return array(
    'root' => array(
        'name' => 'urlund/wp-test-plugin',
        'pretty_version' => '1.0.12',
        'version' => '1.0.12.0',
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
            'reference' => '154fc50b68c5dd606e76d158b0d1d3dd935c70e6',
            'type' => 'library',
            'install_path' => __DIR__ . '/../urlund/wp-plugin-updater',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
        'urlund/wp-test-plugin' => array(
            'pretty_version' => '1.0.12',
            'version' => '1.0.12.0',
            'reference' => null,
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
