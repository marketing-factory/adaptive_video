<?php

$EM_CONF['adaptive_video'] = [
    'title' => 'Adaptive Video',
    'description' => 'Delivers FAL videos as adaptive HLS streams for a fast start and smooth playback on any connection. Videos are encoded asynchronously with ffmpeg in a background worker; until a stream is ready, the original file is played. Works with <f:media> and the Text & Media content element, uses native HLS or hls.js and supports poster images from b13/poster-image.',
    'category' => 'fe',
    'author' => 'Ingo Schmitt',
    'author_email' => 'ingo.schmitt@marketing-factory.de',
    'author_company' => 'Marketing Factory Digital GmbH',
    'state' => 'stable',
    'version' => '1.0.0',
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
