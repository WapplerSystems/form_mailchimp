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
    'version' => '14.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.4.99',
            'form' => '14.0.0-14.4.99'
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
