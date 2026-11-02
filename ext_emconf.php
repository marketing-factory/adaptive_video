<?php

$EM_CONF['adaptive_video'] = [
    'title' => 'Adaptive Video',
    'description' => 'Adaptive HLS streaming for FAL videos, encoded asynchronously with ffmpeg',
    'category' => 'fe',
    'author' => 'Ingo Schmitt',
    'author_email' => 'ingo.schmitt@marketing-factory.de',
    'author_company' => 'Marketing Factory Digital GmbH',
    'state' => 'alpha',
    'version' => '0.1.3',
    'constraints' => [
        'depends' => [
            'php' => '8.2.0-8.5.99',
            'typo3' => '13.4.0-14.99.99',
            'poster_image' => '2.0.0-2.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
