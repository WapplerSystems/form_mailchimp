<?php
$EM_CONF[$_EXTKEY] = [
    'title' => 'Form MailChimp',
    'description' => 'Form Finishers for MailChimp sign in and sign out. Only composer!',
    'category' => 'misc',
    'state' => 'stable',
    'uploadfolder' => 0,
    'createDirs' => '',
    'clearCacheOnLoad' => 1,
    'author' => 'Sven Wappler',
    'author_email' => 'typo3YYYY@wappler.systems',
    'author_company' => 'WapplerSystems',
    'version' => '13.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
            'form' => '13.4.0-13.4.99'
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
