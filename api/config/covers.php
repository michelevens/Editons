<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cover concepts
    |--------------------------------------------------------------------------
    |
    | Who proposes cover designs. 'anthropic' asks Claude to read the book's
    | premise, audience and chapters and propose three concepts; 'fake'
    | derives three from the book type with no API and no spend, so the
    | test suite and a fresh clone work without credentials. It follows the
    | editorial driver unless set on its own.
    |
    */

    'concepts' => [
        'driver' => env('COVER_CONCEPTS_DRIVER', env('EDITORIAL_DRIVER', 'fake') === 'anthropic' ? 'anthropic' : 'fake'),
        'apiKey' => env('ANTHROPIC_API_KEY'),
        'model' => env('COVER_CONCEPTS_MODEL', 'claude-opus-5-5'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cover artwork
    |--------------------------------------------------------------------------
    |
    | Who paints the picture behind the type. Claude designs; it does not
    | make images, so artwork comes from an image model. 'openai' uses the
    | Images API; 'fake' draws an abstract image locally. The title and
    | author are never painted into the picture: the cover renderer sets
    | them, in real type, so they are spelled right and stay editable.
    |
    */

    'artwork' => [
        'driver' => env('COVER_ARTWORK_DRIVER', 'fake'),
        'apiKey' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),
        // Portrait, the nearest the Images API offers to a 5:8 cover.
        'size' => env('OPENAI_IMAGE_SIZE', '1024x1536'),
        'quality' => env('OPENAI_IMAGE_QUALITY', 'high'),
        'timeout' => (int) env('OPENAI_IMAGE_TIMEOUT', 120),
    ],

];
