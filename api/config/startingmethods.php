<?php

/*
|--------------------------------------------------------------------------
| Ways to start a book
|--------------------------------------------------------------------------
|
| The directive lists twelve. Several are real today, several need something
| that does not exist yet, and the difference is recorded here rather than
| hidden: a method that is offered but does not work is worse than one that
| says plainly what it still needs.
|
| `available` is the honest answer. `needs` says what is missing, and the
| screen shows it, so somebody choosing between methods knows which will
| actually take them somewhere.
|
| Several methods on the same book is the point: an author may import a
| manuscript, then answer the Discover questions, then scaffold the chapters
| they have not written. `started_with` records only the first, because that
| is what the journey uses to decide what to suggest next.
|
*/

return [

    'blank' => [
        'name' => 'Just start writing',
        'blurb' => 'One empty chapter. Nothing else in the way.',
        'icon' => 'doc',
        'available' => true,
        'needs' => null,
    ],

    'template' => [
        'name' => 'Choose a book template',
        'blurb' => 'Pick the kind of book, and get its usual structure ready to write into.',
        'icon' => 'tmpl',
        'available' => true,
        'needs' => null,
    ],

    'idea' => [
        'name' => 'Start with an idea',
        'blurb' => 'Say what the book is, who it is for, and what it promises. The chapters follow from that.',
        'icon' => 'sugg',
        'available' => true,
        'needs' => null,
    ],

    'import' => [
        'name' => 'Upload an existing manuscript',
        'blurb' => 'A Markdown file becomes chapters, keeping its headings and structure.',
        'icon' => 'exp',
        'available' => true,
        'needs' => null,
    ],

    'outline' => [
        'name' => 'Import an outline',
        'blurb' => 'A list of chapter titles becomes the binder, ready to write into.',
        'icon' => 'list',
        'available' => true,
        'needs' => null,
    ],

    'research' => [
        'name' => 'Start from research',
        'blurb' => 'Begin with the sources, and build the book around what they say.',
        'icon' => 'lib',
        // The Research Library exists and sources can be added to any book;
        // what does not exist is deriving a structure from them.
        'available' => false,
        'needs' => 'a way to turn a reading list into a shape for the book',
    ],

    'interview' => [
        'name' => 'Let the AI interview me',
        'blurb' => 'Answer questions about the book, and the answers become the outline.',
        'icon' => 'chat',
        'available' => false,
        'needs' => 'a real provider behind the editorial seam — the interview has to listen, not just ask',
    ],

    'dictate' => [
        'name' => 'Speak it',
        'blurb' => 'Dictate chapters and have them transcribed.',
        'icon' => 'mic',
        'available' => false,
        'needs' => 'a speech-to-text provider, and a per-minute cost this product has not set',
    ],

    'life_story' => [
        'name' => 'Tell my life story',
        'blurb' => 'A memoir, built from the periods and turning points you name.',
        'icon' => 'user',
        'available' => false,
        'needs' => 'the interview, which this is a particular shape of',
    ],

    'course' => [
        'name' => 'Turn a course into a book',
        'blurb' => 'Lessons become chapters, exercises become the end-of-chapter work.',
        'icon' => 'cap',
        'available' => false,
        'needs' => 'a lesson model, which the Course Builder would bring',
    ],

    'posts' => [
        'name' => 'Convert posts or notes',
        'blurb' => 'Articles, blog posts or notes, arranged into a book.',
        'icon' => 'note',
        // Markdown import covers the mechanics; what is missing is the
        // ordering and de-duplication that makes a collection into a book.
        'available' => false,
        'needs' => 'a way to order and de-duplicate pieces written separately',
    ],

    'world' => [
        'name' => 'Build a fictional world',
        'blurb' => 'Characters, places and rules first; the story after.',
        'icon' => 'hex',
        'available' => false,
        'needs' => 'a world model — Characters & Terms is the start of one',
    ],

];
