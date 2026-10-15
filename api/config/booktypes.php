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

    'biography' => [
        'name' => 'Biography',
        'blurb' => 'Somebody else’s life, researched and sourced.',
        'words' => [80000, 120000],
        'chapters' => [12, 25],
        'parts' => true,
        'front_matter' => ['Author’s Note'],
        'back_matter' => ['Notes', 'Bibliography', 'Index'],
        // Every claim is about a real person, which is both the research
        // burden and the legal exposure.
        'agents' => ['fact_checker', 'subject_matter', 'consistency'],
        'chapter_prompt' => 'What period does this cover, and what does it reveal that the last chapter did not?',
    ],

    'educational' => [
        'name' => 'Educational',
        'blurb' => 'A textbook or course book, written to be taught from.',
        'words' => [50000, 90000],
        'chapters' => [10, 18],
        'parts' => true,
        'front_matter' => ['Preface', 'How to Use This Book'],
        'back_matter' => ['Glossary', 'Answer Key', 'Index'],
        'agents' => ['accessibility', 'consistency', 'fact_checker'],
        'chapter_prompt' => 'What should a learner be able to do after this, and how will they know they can?',
    ],

    'devotional' => [
        'name' => 'Devotional',
        'blurb' => 'Short daily readings, usually dated or numbered.',
        /*
         * Many short entries rather than few long ones.
         *
         * Devotionals really do run from a month of readings to a full year,
         * but that is not written as [30, 365] here: suggestedChapters()
         * takes the midpoint, so that range made the default a 198-chapter
         * scaffold and a new author choosing "Devotional" would have watched
         * the app build 198 sections before they wrote a word.
         *
         * [30, 90] is the starting range instead — a month to a season,
         * defaulting to 60. Longer books are reached by typing the number,
         * which the new-book form already allows, and by adding entries as
         * they are written. A default somebody has to delete from is worse
         * than one they add to.
         */
        'words' => [20000, 45000],
        'chapters' => [30, 90],
        'parts' => true,
        'front_matter' => ['How to Use This Book'],
        'back_matter' => ['Index of Readings'],
        'agents' => ['line', 'consistency', 'copy'],
        'chapter_prompt' => 'What single thought should the reader carry into the day?',
    ],

    'self_help' => [
        'name' => 'Self-help',
        'blurb' => 'A book that asks the reader to change something and shows them how.',
        'words' => [40000, 70000],
        'chapters' => [8, 16],
        'parts' => true,
        'front_matter' => ['Introduction'],
        'back_matter' => ['Conclusion', 'Resources', 'About the Author'],
        'agents' => ['developmental', 'fact_checker', 'accessibility'],
        'chapter_prompt' => 'What is the reader meant to do differently after this chapter?',
    ],

    'graphic_novel' => [
        'name' => 'Graphic novel',
        'blurb' => 'A script for an illustrated book, written page by page.',
        // The word count is the script, not the finished book: dialogue and
        // panel direction, with the art carrying everything else.
        'words' => [5000, 25000],
        'chapters' => [5, 30],
        'parts' => true,
        'front_matter' => [],
        'back_matter' => ['Acknowledgements'],
        'agents' => ['developmental', 'consistency', 'line'],
        'chapter_prompt' => 'What happens on these pages, and what has to be shown rather than said?',
    ],

    'anthology' => [
        'name' => 'Anthology',
        'blurb' => 'A collection of pieces, often by more than one author.',
        'words' => [40000, 90000],
        'chapters' => [8, 30],
        'parts' => true,
        'front_matter' => ['Introduction'],
        'back_matter' => ['Contributors', 'Acknowledgements'],
        // Many hands means house style and attribution are the real risks.
        'agents' => ['consistency', 'copy', 'line'],
        'chapter_prompt' => 'Whose piece is this, and why does it belong beside the others?',
    ],

];
