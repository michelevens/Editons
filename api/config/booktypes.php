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
        // The Discover questions, in this genre's own terms.
        'questions' => [
            'premise' => 'What is the argument, in a sentence?',
            'audience' => 'Who needs to hear it, and what do they already believe?',
            'promise' => 'What can they do afterwards that they could not before?',
        ],
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
        // The Discover questions, in this genre's own terms.
        'questions' => [
            'premise' => 'Which part of your life is this, and why that part?',
            'audience' => 'Who is reading it who was not there?',
            'promise' => 'What should they understand by the end that they did not at the start?',
        ],
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
        // The Discover questions, in this genre's own terms.
        'questions' => [
            'premise' => 'What happens, and to whom?',
            'audience' => 'Who reads this kind of book, and what do they come for?',
            'promise' => 'What should the last page leave them with?',
        ],
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
        // The Discover questions, in this genre's own terms.
        'questions' => [
            'premise' => 'What is the method or the claim?',
            'audience' => 'Who is the reader, and what is their job?',
            'promise' => 'What should they be doing differently on Monday?',
        ],
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
        // The Discover questions, in this genre's own terms.
        'questions' => [
            'premise' => 'What does this teach, and for which version or tool?',
            'audience' => 'What does the reader already know before chapter one?',
            'promise' => 'What should they be able to build or fix afterwards?',
        ],
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
        // The Discover questions, in this genre's own terms.
        'questions' => [
            'premise' => 'What is the thesis, stated as a claim somebody could dispute?',
            'audience' => 'Which field is this for, and which conversation in it?',
            'promise' => 'What does it establish that the literature did not?',
        ],
        'chapter_prompt' => 'What claim does this chapter establish, and on what evidence?',
    ],

    /*
     * Degree work. Lengths and structures vary by institution and field, and
     * the student's own regulations govern; these are the common shape. The
     * examiner leads the review, and coaches rather than writes.
     */
    'masters_thesis' => [
        'name' => "Master's Thesis",
        'blurb' => "A master's thesis or dissertation, in any field.",
        'words' => [15000, 40000],
        'chapters' => [5, 7],
        'parts' => false,
        'front_matter' => ['Abstract', 'Declaration', 'Acknowledgements'],
        'back_matter' => ['References', 'Appendices'],
        'agents' => ['masters_examiner', 'fact_checker', 'consistency', 'copy'],
        'questions' => [
            'premise' => 'What is your research question, stated precisely enough to be answered?',
            'audience' => 'Which field is this for, and which debate in it does it join?',
            'promise' => 'What will the thesis show, and how will a reader be able to check it?',
        ],
        'chapter_prompt' => 'Which part of the research question does this chapter answer, and on what evidence?',
    ],

    'phd_dissertation' => [
        'name' => 'PhD Dissertation',
        'blurb' => 'A doctoral dissertation or thesis, in any field.',
        'words' => [70000, 100000],
        'chapters' => [6, 10],
        'parts' => false,
        // The lists are generated from captions at export; see Numbering.
        'front_matter' => ['Abstract', 'Declaration', 'Acknowledgements', 'List of Tables', 'List of Figures', 'List of Abbreviations'],
        'back_matter' => ['References', 'Appendices'],
        'agents' => ['doctoral_examiner', 'fact_checker', 'consistency', 'copy'],
        'questions' => [
            'premise' => 'What is your thesis, stated as a claim an examiner could dispute?',
            'audience' => 'Which field and which conversation in it does this change?',
            'promise' => 'What is the original contribution to knowledge, in one sentence?',
        ],
        'chapter_prompt' => 'What does this chapter contribute to the thesis, and how would you defend it at the viva?',
    ],

    // Learned from writing complete samples: a thesis by publication and a
    // project thesis are built differently enough from the classic five- or
    // eight-chapter monograph that a student starting from the wrong
    // skeleton spends weeks undoing it.
    'phd_publication' => [
        'name' => 'PhD Thesis by Publication',
        'blurb' => 'A doctoral thesis built around three or more papers, with framing and linking chapters.',
        'words' => [60000, 90000],
        'chapters' => [7, 11],
        'parts' => false,
        'front_matter' => ['Abstract', 'Declaration', 'Statement of Contribution', 'Publications Arising from This Thesis', 'Acknowledgements', 'List of Tables', 'List of Figures'],
        'back_matter' => ['References', 'Appendices'],
        'agents' => ['doctoral_examiner', 'fact_checker', 'consistency', 'copy'],
        'questions' => [
            'premise' => 'What single argument do the papers make together?',
            'audience' => 'Which field and which journals are the papers written for?',
            'promise' => 'What does the thesis as a whole contribute that no one paper does?',
        ],
        'chapter_prompt' => 'Where does this chapter sit in the argument across the papers, and what does it hand to the next?',
    ],

    'masters_project' => [
        'name' => "Master's Project Thesis",
        'blurb' => 'A design, build or practice-based project with its written thesis: requirements, design, evaluation.',
        'words' => [12000, 30000],
        'chapters' => [6, 8],
        'parts' => false,
        'front_matter' => ['Abstract', 'Declaration', 'Acknowledgements'],
        'back_matter' => ['References', 'Appendix A: Requirements Specification', 'Appendix B: Evaluation Materials', 'Appendix C: Artefact Availability'],
        'agents' => ['masters_examiner', 'fact_checker', 'consistency', 'copy'],
        'questions' => [
            'premise' => 'What problem does the artefact address, and for whom?',
            'audience' => 'Which field is this for, and what design knowledge does it add?',
            'promise' => 'How will you show the artefact works, and what counts as success?',
        ],
        'chapter_prompt' => 'Which requirement or research question does this chapter serve, and what evidence does it give?',
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
        // The Discover questions, in this genre's own terms.
        'questions' => [
            'premise' => 'What happens in the story?',
            'audience' => 'What age is this for, and is it read aloud or alone?',
            'promise' => 'What do you want them feeling at the end?',
        ],
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
        // The Discover questions, in this genre's own terms.
        'questions' => [
            'premise' => 'What ties these recipes together?',
            'audience' => 'Who is cooking from this, and how much do they already know?',
            'promise' => 'What should they be able to cook confidently afterwards?',
        ],
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
        // The Discover questions, in this genre's own terms.
        'questions' => [
            'premise' => 'What is this collection circling around?',
            'audience' => 'Who is it for, and how will they come across it?',
            'promise' => 'What should stay with them after they close it?',
        ],
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
        // The Discover questions, in this genre's own terms.
        'questions' => [
            'premise' => 'Whose life is this, and which part of it?',
            'audience' => 'Who is reading, and what do they already think they know?',
            'promise' => 'What should they understand about this person that they did not?',
        ],
        'chapter_prompt' => 'What period does this cover, and what does it reveal that the last chapter did not?',
    ],

    'educational' => [
        'name' => 'Educational',
        'blurb' => 'A textbook or course book, written to be taught from.',
        // Textbooks and workbooks are written in and laid flat: letter size.
        // Every other type opens on Book::DEFAULT_LAYOUT's 6 x 9.
        'trim' => '8.5 x 11 in',
        'words' => [50000, 90000],
        'chapters' => [10, 18],
        'parts' => true,
        'front_matter' => ['Preface', 'How to Use This Book'],
        'back_matter' => ['Glossary', 'Answer Key', 'Index'],
        'agents' => ['accessibility', 'consistency', 'fact_checker'],
        // The Discover questions, in this genre's own terms.
        'questions' => [
            'premise' => 'What subject does this cover, and at what level?',
            'audience' => 'Who is teaching from it, and who is learning from it?',
            'promise' => 'What should a learner be able to do by the end?',
        ],
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
        // The Discover questions, in this genre's own terms.
        'questions' => [
            'premise' => 'What is the thread running through the readings?',
            'audience' => 'Who is reading this, and at what point in their day?',
            'promise' => 'What should they carry out of each reading?',
        ],
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
        // The Discover questions, in this genre's own terms.
        'questions' => [
            'premise' => 'What problem does this solve?',
            'audience' => 'Who has that problem, and what have they already tried?',
            'promise' => 'What does their life look like if it works?',
        ],
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
        // The Discover questions, in this genre's own terms.
        'questions' => [
            'premise' => 'What is the story, and what does the art carry that the words do not?',
            'audience' => 'Who reads this, and in what format will they read it?',
            'promise' => 'What should the final page land as?',
        ],
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
        // The Discover questions, in this genre's own terms.
        'questions' => [
            'premise' => 'What holds these pieces together?',
            'audience' => 'Who is reading, and will they read it straight through?',
            'promise' => 'What should the collection add up to?',
        ],
        'chapter_prompt' => 'Whose piece is this, and why does it belong beside the others?',
    ],

];
