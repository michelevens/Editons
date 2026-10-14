<?php

/*
|--------------------------------------------------------------------------
| Book types
|--------------------------------------------------------------------------
|
| What kind of book somebody is writing, and what that implies. Configuration
| rather than a class per genre, so a new type is an entry here instead of an
| architectural change.
|
| The `words` and `chapters` ranges are published trade norms — the lengths
| agents and acquiring editors expect, and what the major self-publishing
| guides quote. They are NOT market research, and nothing here pretends to be:
| the UI labels them "typical", gives a range rather than a number, and lets
| the author overwrite it. A precise figure invented from nothing would be
| worse than no figure at all.
|
| `agents` names the editorial reviewers that matter most for that kind of
| book. It does not restrict the others; it decides what a full review runs
| first and what the journey suggests.
|
*/

return [

    'nonfiction' => [
        'name' => 'Nonfiction',
        'blurb' => 'Practical or informational writing with an argument to make.',
        'words' => [50000, 80000],
        'chapters' => [10, 20],
        'parts' => true,
        'front_matter' => ['Introduction'],
        'back_matter' => ['Conclusion', 'References', 'About the Author'],
        'agents' => ['fact_checker', 'developmental', 'copy'],
        'chapter_prompt' => 'What does the reader need to understand here, and what should they be able to do afterwards?',
    ],

    'memoir' => [
        'name' => 'Memoir',
        'blurb' => 'Your own life, told as a story rather than a record.',
        'words' => [70000, 90000],
        'chapters' => [15, 25],
        'parts' => true,
        'front_matter' => ['Author’s Note'],
        'back_matter' => ['Acknowledgements'],
        // Memoir names real people, which is where the legal exposure is.
        'agents' => ['line', 'developmental', 'subject_matter'],
        'chapter_prompt' => 'Whose scene is this, and what changes by the end of it?',
    ],

    'fiction' => [
        'name' => 'Fiction',
        'blurb' => 'A novel or novella.',
        'words' => [70000, 100000],
        'chapters' => [20, 40],
        'parts' => true,
        'front_matter' => [],
        'back_matter' => ['Acknowledgements'],
        'agents' => ['developmental', 'line', 'consistency'],
        'chapter_prompt' => 'What does the viewpoint character want in this scene, and what stops them?',
    ],

    'business' => [
        'name' => 'Business',
        'blurb' => 'A professional book built around a method or an argument.',
        'words' => [40000, 60000],
        'chapters' => [8, 14],
        'parts' => true,
        'front_matter' => ['Introduction'],
        'back_matter' => ['Conclusion', 'Notes', 'About the Author'],
        'agents' => ['fact_checker', 'developmental', 'copy'],
        'chapter_prompt' => 'What is the one idea here, and what evidence carries it?',
    ],

    'technical' => [
        'name' => 'Technical',
        'blurb' => 'Documentation, a manual, or a book that teaches a skill.',
        'words' => [40000, 70000],
        'chapters' => [10, 20],
        'parts' => true,
        'front_matter' => ['Preface', 'How to Use This Book'],
        'back_matter' => ['Glossary', 'Index'],
        'agents' => ['consistency', 'accessibility', 'copy'],
        'chapter_prompt' => 'What can the reader do after this chapter that they could not before?',
    ],

    'academic' => [
        'name' => 'Academic',
        'blurb' => 'A thesis, monograph or scholarly work.',
        'words' => [70000, 100000],
        'chapters' => [6, 10],
        'parts' => true,
        'front_matter' => ['Abstract', 'Acknowledgements'],
        'back_matter' => ['Bibliography', 'Appendices', 'Index'],
        'agents' => ['fact_checker', 'consistency', 'subject_matter'],
        'chapter_prompt' => 'What claim does this chapter establish, and on what evidence?',
    ],

    'childrens' => [
        'name' => "Children's",
        'blurb' => 'A picture book or early reader.',
        'words' => [500, 10000],
        'chapters' => [1, 12],
        'parts' => false,
        'front_matter' => [],
        'back_matter' => [],
        'agents' => ['accessibility', 'line', 'copy'],
        'chapter_prompt' => 'What happens on this spread, and what will the picture show?',
    ],

    'cookbook' => [
        'name' => 'Cookbook',
        'blurb' => 'Recipes, usually grouped by course or season.',
        'words' => [25000, 50000],
        'chapters' => [6, 12],
        'parts' => false,
        'front_matter' => ['Introduction', 'Before You Start'],
        'back_matter' => ['Index'],
        'agents' => ['consistency', 'copy', 'accessibility'],
        'chapter_prompt' => 'Which recipes belong together here, and why?',
    ],

    'poetry' => [
        'name' => 'Poetry',
        'blurb' => 'A collection, usually arranged in sections.',
        'words' => [5000, 20000],
        'chapters' => [3, 8],
        'parts' => false,
        'front_matter' => [],
        'back_matter' => ['Notes', 'Acknowledgements'],
        'agents' => ['line', 'consistency'],
        'chapter_prompt' => 'What holds these poems together as a section?',
    ],

];
