<?php

return [
    // Node typesets equations for the exports (resources/node/math.cjs).
    'node_binary' => env('NODE_BINARY', 'node'),

    // Word (.docx) import, App\Import\DocxImporter. On by default; the flag
    // is there to switch it off without a deploy if a file type misbehaves.
    'docx_import' => (bool) env('DOCX_IMPORT', true),
];
