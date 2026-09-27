<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Core Extension Scanner CLI',
    'description' => 'Exposes the TYPO3 Core Extension Scanner of EXT:install as a Symfony Console command. Internal Core classes are intentionally used, so the extension is bound to the installed Core version.',
    'category' => 'cli',
    'author' => 'DKD Internet Service GmbH',
    'author_email' => 'info@dkd.de',
    'state' => 'beta',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.3.99',
            'install' => '13.4.0-14.3.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
