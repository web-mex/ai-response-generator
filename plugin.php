<?php
return array(
    'id' =>             'ai-response-generator:osticket',
    'version' =>        '0.1.1',
    'name' =>           'AI Response Generator',
    'description' =>    'Adds an AI-powered "Generate Response" button to the agent ticket view with configurable API settings and RAG. Maintained by Stefan Schneider / Web-Mex.',
    'author' =>         'Stefan Schneider / Web-Mex',
    'ost_version' =>    MAJOR_VERSION,
    'plugin' =>         'src/AIResponsePlugin.php:AIResponseGeneratorPlugin',
    'include_path' =>   '',
    'url' =>            'https://web-mex.de',
);
