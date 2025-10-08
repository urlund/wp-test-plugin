<?php return array(
    'root' => array(
        'name' => 'urlund/wp-test-plugin',
        'pretty_version' => 'dev-main',
        'version' => 'dev-main',
        'reference' => '5b6bae301ccb1d571eb239e7f5ef78480c367dc0',
        'type' => 'wordpress-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'urlund/wp-plugin-updater' => array(
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => '1367febe3bf6ba5d04dfb31f29c9668b4b8b73d4',
            'type' => 'library',
            'install_path' => __DIR__ . '/../urlund/wp-plugin-updater',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
        'urlund/wp-test-plugin' => array(
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => '5b6bae301ccb1d571eb239e7f5ef78480c367dc0',
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
