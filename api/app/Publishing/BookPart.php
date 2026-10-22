<?php

namespace App\Publishing;

/**
 * The parts of a book, after The Chicago Manual of Style, chapter 1
 * ("Books and Journals"): front matter, text, back matter.
 *
 * One case per conventional part. Each knows its Chicago name, which
 * division it belongs to, where it sits in the conventional order, whether
 * the app generates it or the author writes it, which page it opens on, how
 * its pages are numbered, and what it is called in the languages the product
 * publishes in.
 *
 * Two things this is deliberately NOT:
 *  - a page renderer. App\Publishing\Anatomy turns a book into an ordered
 *    plan; PrintBook, Exporter and DocumentRenderer draw it.
 *  - a replacement for ManuscriptSection::TYPES. `type` stays the coarse
 *    class the editor and the numbering have always used; `part` is the
 *    finer, conventional name. sectionType() maps one to the other.
 *
 * Language conventions that matter here:
 *  - French distinguishes a "Préface" (written by someone other than the
 *    author, i.e. Chicago's foreword) from an "Avant-propos" (the author's
 *    own, i.e. Chicago's preface). The same split is "Geleitwort"/"Vorwort"
 *    in German and "Prefas"/"Avanpwopo" in Haitian Creole.
 *  - Spanish "Índice" is the table of contents; the index is "Índice
 *    analítico". Spanish "Prólogo" is used both for a foreword and for a
 *    prologue; inference reads it as a prologue.
 *  - French calls the colophon "Achevé d'imprimer" and the back-cover copy
 *    the "Quatrième de couverture".
 */
enum BookPart: string
{
    // FRONT MATTER (Chicago 1.4: pages i, ii, iii, iv, then rectos)
    case HalfTitle = 'half_title';
    case SeriesPage = 'series_page';
    case Frontispiece = 'frontispiece';
    case TitlePage = 'title_page';
    case CopyrightPage = 'copyright_page';
    case Dedication = 'dedication';
    case Epigraph = 'epigraph';
    case TableOfContents = 'table_of_contents';
    case ListOfIllustrations = 'list_of_illustrations';
    case ListOfTables = 'list_of_tables';
    case Foreword = 'foreword';
    case Preface = 'preface';
    case Acknowledgments = 'acknowledgments';
    case Introduction = 'introduction';
    case Abbreviations = 'abbreviations';
    case Chronology = 'chronology';
    case Prologue = 'prologue';

    // TEXT
    case Part = 'part';
    case Chapter = 'chapter';
    case Conclusion = 'conclusion';
    case Epilogue = 'epilogue';
    case Afterword = 'afterword';

    // BACK MATTER
    case Appendix = 'appendix';
    case Notes = 'notes';
    case Glossary = 'glossary';
    case Bibliography = 'bibliography';
    case Contributors = 'contributors';
    case Index = 'index';
    case AboutAuthor = 'about_author';
    case AboutSeries = 'about_series';
    case AboutPublisher = 'about_publisher';
    case Colophon = 'colophon';

    // COVER (metadata, never a section)
    case BackCoverCopy = 'back_cover_copy';

    public const LANGUAGES = ['en', 'fr', 'es', 'ht', 'de', 'pt'];

    /**
     * Title words, by language. The first entry is the heading the app uses
     * when it creates the part; inference also accepts the aliases.
     *
     * @var array<string, array<string, string>>
     */
    private const TITLES = [
        'half_title' => ['en' => 'Half Title', 'fr' => 'Faux-titre', 'es' => 'Anteportada', 'ht' => 'Fo tit', 'de' => 'Schmutztitel', 'pt' => 'Anterrosto'],
        'series_page' => ['en' => 'In the Same Series', 'fr' => 'Dans la même collection', 'es' => 'En la misma colección', 'ht' => 'Nan menm koleksyon an', 'de' => 'In derselben Reihe', 'pt' => 'Na mesma coleção'],
        'frontispiece' => ['en' => 'Frontispiece', 'fr' => 'Frontispice', 'es' => 'Frontispicio', 'ht' => 'Frontispis', 'de' => 'Frontispiz', 'pt' => 'Frontispício'],
        'title_page' => ['en' => 'Title Page', 'fr' => 'Page de titre', 'es' => 'Portada', 'ht' => 'Paj tit', 'de' => 'Titelseite', 'pt' => 'Folha de rosto'],
        'copyright_page' => ['en' => 'Copyright', 'fr' => 'Mentions légales', 'es' => 'Página legal', 'ht' => 'Dwa otè', 'de' => 'Impressum', 'pt' => 'Página de créditos'],
        'dedication' => ['en' => 'Dedication', 'fr' => 'Dédicace', 'es' => 'Dedicatoria', 'ht' => 'Dedikas', 'de' => 'Widmung', 'pt' => 'Dedicatória'],
        'epigraph' => ['en' => 'Epigraph', 'fr' => 'Épigraphe', 'es' => 'Epígrafe', 'ht' => 'Epigraf', 'de' => 'Motto', 'pt' => 'Epígrafe'],
        'table_of_contents' => ['en' => 'Contents', 'fr' => 'Table des matières', 'es' => 'Índice', 'ht' => 'Tab matyè', 'de' => 'Inhalt', 'pt' => 'Sumário'],
        'list_of_illustrations' => ['en' => 'List of Illustrations', 'fr' => 'Liste des illustrations', 'es' => 'Lista de ilustraciones', 'ht' => 'Lis ilistrasyon yo', 'de' => 'Abbildungsverzeichnis', 'pt' => 'Lista de ilustrações'],
        'list_of_tables' => ['en' => 'List of Tables', 'fr' => 'Liste des tableaux', 'es' => 'Lista de tablas', 'ht' => 'Lis tablo yo', 'de' => 'Tabellenverzeichnis', 'pt' => 'Lista de tabelas'],
        'foreword' => ['en' => 'Foreword', 'fr' => 'Préface', 'es' => 'Prólogo', 'ht' => 'Prefas', 'de' => 'Geleitwort', 'pt' => 'Prefácio'],
        'preface' => ['en' => 'Preface', 'fr' => 'Avant-propos', 'es' => 'Prefacio', 'ht' => 'Avanpwopo', 'de' => 'Vorwort', 'pt' => 'Apresentação'],
        'acknowledgments' => ['en' => 'Acknowledgments', 'fr' => 'Remerciements', 'es' => 'Agradecimientos', 'ht' => 'Remèsiman', 'de' => 'Danksagung', 'pt' => 'Agradecimentos'],
        'introduction' => ['en' => 'Introduction', 'fr' => 'Introduction', 'es' => 'Introducción', 'ht' => 'Entwodiksyon', 'de' => 'Einleitung', 'pt' => 'Introdução'],
        'abbreviations' => ['en' => 'Abbreviations', 'fr' => 'Abréviations', 'es' => 'Abreviaturas', 'ht' => 'Abreviyasyon', 'de' => 'Abkürzungen', 'pt' => 'Abreviaturas'],
        'chronology' => ['en' => 'Chronology', 'fr' => 'Chronologie', 'es' => 'Cronología', 'ht' => 'Kwonoloji', 'de' => 'Zeittafel', 'pt' => 'Cronologia'],
        'prologue' => ['en' => 'Prologue', 'fr' => 'Prologue', 'es' => 'Prólogo', 'ht' => 'Pwològ', 'de' => 'Prolog', 'pt' => 'Prólogo'],
        'part' => ['en' => 'Part', 'fr' => 'Partie', 'es' => 'Parte', 'ht' => 'Pati', 'de' => 'Teil', 'pt' => 'Parte'],
        'chapter' => ['en' => 'Chapter', 'fr' => 'Chapitre', 'es' => 'Capítulo', 'ht' => 'Chapit', 'de' => 'Kapitel', 'pt' => 'Capítulo'],
        'conclusion' => ['en' => 'Conclusion', 'fr' => 'Conclusion', 'es' => 'Conclusión', 'ht' => 'Konklizyon', 'de' => 'Schluss', 'pt' => 'Conclusão'],
        'epilogue' => ['en' => 'Epilogue', 'fr' => 'Épilogue', 'es' => 'Epílogo', 'ht' => 'Epilòg', 'de' => 'Epilog', 'pt' => 'Epílogo'],
        'afterword' => ['en' => 'Afterword', 'fr' => 'Postface', 'es' => 'Posfacio', 'ht' => 'Postfas', 'de' => 'Nachwort', 'pt' => 'Posfácio'],
        'appendix' => ['en' => 'Appendix', 'fr' => 'Annexe', 'es' => 'Apéndice', 'ht' => 'Anèks', 'de' => 'Anhang', 'pt' => 'Apêndice'],
        'notes' => ['en' => 'Notes', 'fr' => 'Notes', 'es' => 'Notas', 'ht' => 'Nòt', 'de' => 'Anmerkungen', 'pt' => 'Notas'],
        'glossary' => ['en' => 'Glossary', 'fr' => 'Glossaire', 'es' => 'Glosario', 'ht' => 'Glosè', 'de' => 'Glossar', 'pt' => 'Glossário'],
        'bibliography' => ['en' => 'Bibliography', 'fr' => 'Bibliographie', 'es' => 'Bibliografía', 'ht' => 'Bibliyografi', 'de' => 'Literaturverzeichnis', 'pt' => 'Bibliografia'],
        'contributors' => ['en' => 'Contributors', 'fr' => 'Liste des contributeurs', 'es' => 'Colaboradores', 'ht' => 'Kontribitè yo', 'de' => 'Beitragende', 'pt' => 'Colaboradores'],
        'index' => ['en' => 'Index', 'fr' => 'Index', 'es' => 'Índice analítico', 'ht' => 'Endèks', 'de' => 'Register', 'pt' => 'Índice remissivo'],
        'about_author' => ['en' => 'About the Author', 'fr' => 'À propos de l’auteur', 'es' => 'Sobre el autor', 'ht' => 'Konsènan otè a', 'de' => 'Über den Autor', 'pt' => 'Sobre o autor'],
        'about_series' => ['en' => 'About the Series', 'fr' => 'À propos de la collection', 'es' => 'Sobre la colección', 'ht' => 'Konsènan koleksyon an', 'de' => 'Über die Reihe', 'pt' => 'Sobre a coleção'],
        'about_publisher' => ['en' => 'About the Publisher', 'fr' => 'À propos de l’éditeur', 'es' => 'Sobre la editorial', 'ht' => 'Konsènan editè a', 'de' => 'Über den Verlag', 'pt' => 'Sobre a editora'],
        'colophon' => ['en' => 'Colophon', 'fr' => 'Achevé d’imprimer', 'es' => 'Colofón', 'ht' => 'Kolofon', 'de' => 'Kolophon', 'pt' => 'Colofão'],
        'back_cover_copy' => ['en' => 'Back Cover Copy', 'fr' => 'Quatrième de couverture', 'es' => 'Contracubierta', 'ht' => 'Tèks katriyèm kouvèti', 'de' => 'Klappentext', 'pt' => 'Texto de contracapa'],
    ];

    /**
     * Title patterns that identify a part, matched against a section title
     * that has been lower-cased, stripped of accents and of leading
     * numbering. Ordered: the first match wins, so the specific comes
     * before the general ("indice analitico" before "indice").
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        'list_of_illustrations' => '/^(list of (figures|illustrations)|figures|illustrations|liste des (figures|illustrations)|table des (figures|illustrations)|lista de (figuras|ilustraciones|ilustracoes)|lis (figi|ilistrasyon)( yo)?|abbildungsverzeichnis)$/',
        'list_of_tables' => '/^(list of tables|tables|liste des tableaux|lista de (tablas|tabelas)|lis tablo( yo)?|tabellenverzeichnis)$/',
        'index' => '/^(index( of .*)?|indexes|indices|indice (analitico|alfabetico|onomastico|tematico|remissivo)|index (des|general|thematique|des noms).*|endeks|register|sachregister|personenregister|stichwortverzeichnis)$/',
        'table_of_contents' => '/^(contents|table of contents|table des matieres|sommaire|indice|indice general|tab (de )?matye|inhalt|inhaltsverzeichnis|sumario)$/',
        'half_title' => '/^(half[- ]title|faux[- ]titre|anteportada|fo tit|schmutztitel|anterrosto|falsa folha de rosto)$/',
        'series_page' => '/^(also by .*|other books (by|in) .*|in the same series|books in (this|the) series|du meme auteur.*|dans la meme collection|chez le meme editeur|del mismo autor.*|en la misma coleccion|nan menm koleksyon an|lot liv .*|in derselben reihe|vom selben autor.*|na mesma colecao|do mesmo autor.*)$/',
        'frontispiece' => '/^(frontispiece|frontispice|frontispicio|frontispis|frontispiz)$/',
        'title_page' => '/^(title page|page de titre|portada|paj tit|titelseite|titelblatt|folha de rosto)$/',
        'copyright_page' => '/^(copyright( page)?|copyright notice|page de copyright|mentions legales|droits d.auteur|pagina (legal|de creditos)|derechos de autor|dwa ote|paj dwa ote|impressum|ficha tecnica)$/',
        'dedication' => '/^(dedication|dedicace|dedicatoria|dedikas|widmung)$/',
        'epigraph' => '/^(epigraph|epigraphe|epigrafe|epigraf|motto)$/',
        'foreword' => '/^(foreword|prefas|geleitwort|presentacion)$/',
        'preface' => '/^(author.?s note|note (de|from) l.?auteur|note to the reader|avant[- ]propos|avan ?pwopo|not ote a|nota del autor|vorwort|nota do autor|apresentacao)$/',
        'acknowledgments' => '/^(acknowledge?ments?|remerciements|agradecimientos|agradecimentos|remesiman|danksagung|dank)$/',
        'introduction' => '/^(introduction|introducao|introduccion|entwodiksyon|einleitung|einfuhrung|how to use this (book|guide|workbook)|before you (start|begin)|comment utiliser (ce|cet) (livre|ouvrage|guide|manuel)|mode d.emploi|kijan pou (w )?itilize liv sa a|como usar este (libro|livro)|so benutzen sie dieses buch)$/',
        'abbreviations' => '/^((list of )?abbreviations|(liste des |sigles et )?abreviations|abreviaturas|lista de abreviaturas|abreviyasyon|abkurzungen|abkurzungsverzeichnis)$/',
        'chronology' => '/^(chronology|timeline|chronologie|cronologia|kwonoloji|zeittafel|zeitleiste)$/',
        'prologue' => '/^(prologue|prolog|prologo|pwolog)$/',
        'conclusion' => '/^(conclusion|conclusions|conclusion generale|conclusao|konklizyon|schluss|schlussbetrachtung|fazit)$/',
        'epilogue' => '/^(epilogue|epilog|epilogo|epilog)$/',
        'afterword' => '/^(afterword|postface|posfacio|postfas|nachwort)$/',
        'appendix' => '/^(appendix|appendices|appendixes|annexe|annexes|apendice|apendices|anexo|anexos|aneks|anhang|answer key|corriges?)( .*)?$/',
        'notes' => '/^(notes|endnotes|notas|not|anmerkungen|endnoten)$/',
        'glossary' => '/^(glossary|glossaire|lexique|glosario|glossario|glose|leksik|glossar)$/',
        'bibliography' => '/^(bibliography|references|works cited|further reading|resources|selected bibliography|bibliographie|references bibliographiques|bibliografia|referencias|bibliyografi|referans|literaturverzeichnis|literatur|quellen)$/',
        'contributors' => '/^((list of )?contributors|liste des contributeurs|les auteurs|colaboradores|kontribite yo|beitragende|autorinnen und autoren)$/',
        'about_series' => '/^(about (the|this) series|a propos de (la|cette) collection|sobre (la|a) colecao|sobre la coleccion|konsenan koleksyon an|uber die reihe)$/',
        'about_publisher' => '/^(about the publisher|a propos de l.?editeur|a propos des editions .*|sobre la editorial|sobre a editora|konsenan edite a|uber den verlag)$/',
        'about_author' => '/^(about the authors?|a propos de l.?(auteur|autrice|auteure)|a propos des auteurs|l.?auteur|sobre (el|la|o|a) (autor|autora)|konsenan ote a|sou ote a|uber (den autor|die autorin)|uber die autoren)$/',
        'colophon' => '/^(colophon|acheve d.imprimer|colofon|kolofon|kolophon|colofao)$/',
    ];

    /** Parts the app makes from the book's data rather than from a section. */
    private const GENERATED = [
        'half_title', 'series_page', 'title_page', 'copyright_page',
        'table_of_contents', 'list_of_illustrations', 'list_of_tables',
    ];

    /** Display pages: no running head, no printed folio (Chicago 1.6). */
    private const DISPLAY = [
        'half_title', 'series_page', 'frontispiece', 'title_page', 'copyright_page',
        'dedication', 'epigraph', 'part',
    ];

    /** Parts Chicago allows in the back matter as well as the front. */
    private const FLEXIBLE = ['acknowledgments', 'abbreviations', 'chronology'];

    public function chicagoName(): string
    {
        return match ($this) {
            self::HalfTitle => 'Half title',
            self::SeriesPage => 'Series title, also-by, or blank',
            self::Frontispiece => 'Frontispiece',
            self::TitlePage => 'Title page',
            self::CopyrightPage => 'Copyright page',
            self::Dedication => 'Dedication',
            self::Epigraph => 'Epigraph',
            self::TableOfContents => 'Table of contents',
            self::ListOfIllustrations => 'List of illustrations',
            self::ListOfTables => 'List of tables',
            self::Foreword => 'Foreword',
            self::Preface => 'Preface',
            self::Acknowledgments => 'Acknowledgments',
            self::Introduction => 'Introduction',
            self::Abbreviations => 'Abbreviations',
            self::Chronology => 'Chronology',
            self::Prologue => 'Prologue',
            self::Part => 'Part title',
            self::Chapter => 'Chapter',
            self::Conclusion => 'Conclusion',
            self::Epilogue => 'Epilogue',
            self::Afterword => 'Afterword',
            self::Appendix => 'Appendix',
            self::Notes => 'Notes',
            self::Glossary => 'Glossary',
            self::Bibliography => 'Bibliography or reference list',
            self::Contributors => 'List of contributors',
            self::Index => 'Index',
            self::AboutAuthor => 'About the author',
            self::AboutSeries => 'About the series',
            self::AboutPublisher => 'About the publisher',
            self::Colophon => 'Colophon',
            self::BackCoverCopy => 'Back-cover copy',
        };
    }

    /** front | body | back | cover */
    public function group(): string
    {
        return match (true) {
            $this->order() < 200 => 'front',
            $this->order() < 300 => 'body',
            $this === self::BackCoverCopy => 'cover',
            default => 'back',
        };
    }

    /**
     * Position in the conventional sequence (Chicago 1.4). Parts and
     * chapters share one value: the text keeps the author's order.
     */
    public function order(): int
    {
        return match ($this) {
            self::HalfTitle => 10,
            self::SeriesPage => 20,
            self::Frontispiece => 30,
            self::TitlePage => 40,
            self::CopyrightPage => 50,
            self::Dedication => 60,
            self::Epigraph => 70,
            self::TableOfContents => 80,
            self::ListOfIllustrations => 90,
            self::ListOfTables => 100,
            self::Foreword => 110,
            self::Preface => 120,
            self::Acknowledgments => 130,
            self::Introduction => 140,
            self::Abbreviations => 150,
            self::Chronology => 160,
            self::Prologue => 170,
            self::Part, self::Chapter => 200,
            self::Conclusion => 280,
            self::Epilogue => 290,
            self::Afterword => 295,
            self::Appendix => 310,
            self::Notes => 340,
            self::Glossary => 350,
            self::Bibliography => 360,
            self::Contributors => 370,
            self::Index => 380,
            self::AboutAuthor => 390,
            self::AboutSeries => 395,
            self::AboutPublisher => 400,
            self::Colophon => 410,
            self::BackCoverCopy => 900,
        };
    }

    /**
     * Order when a flexible part sits in the back matter. Chicago puts
     * acknowledgments first there, chronology and abbreviations after the
     * appendixes and before the notes.
     */
    public function backOrder(): ?int
    {
        return match ($this) {
            self::Acknowledgments => 300,
            self::Chronology => 320,
            self::Abbreviations => 330,
            default => null,
        };
    }

    /** May this part sit in the back matter instead of the front? */
    public function isFlexible(): bool
    {
        return in_array($this->value, self::FLEXIBLE, true);
    }

    /** generated | author. The app synthesizes a generated part from data. */
    public function source(): string
    {
        return in_array($this->value, self::GENERATED, true) ? 'generated' : 'author';
    }

    public function isGenerated(): bool
    {
        return $this->source() === 'generated';
    }

    /**
     * May the author write their own version of a generated part? Only the
     * copyright page and the lists: the lists are positioned by a section
     * (Numbering::listKind), and a hand-written copyright page replaces the
     * generated one.
     */
    public function isAuthorable(): bool
    {
        return ! $this->isGenerated() || in_array($this, [
            self::CopyrightPage, self::ListOfIllustrations, self::ListOfTables,
        ], true);
    }

    /** Can a manuscript section carry this part? Everything but cover copy. */
    public function isSectionKind(): bool
    {
        return $this !== self::BackCoverCopy;
    }

    /** recto | verso | any: which side of the spread the part opens on. */
    public function start(): string
    {
        return match ($this) {
            self::SeriesPage, self::Frontispiece, self::CopyrightPage => 'verso',
            self::HalfTitle, self::TitlePage, self::Dedication, self::TableOfContents,
            self::Foreword, self::Preface, self::Acknowledgments, self::Introduction,
            self::Chronology, self::Prologue, self::Part,
            self::Appendix, self::Notes, self::Glossary, self::Bibliography,
            self::Contributors, self::Index => 'recto',
            default => 'any',
        };
    }

    /**
     * roman | arabic. Front matter is numbered in lowercase roman, the text
     * and back matter in arabic from 1 (Chicago 1.5). A prologue opens the
     * text, so it begins the arabic sequence.
     */
    public function folio(): string
    {
        return $this->group() === 'front' && $this !== self::Prologue ? 'roman' : 'arabic';
    }

    /** Display pages carry a blind folio: counted, not printed. */
    public function showsFolio(): bool
    {
        return ! in_array($this->value, self::DISPLAY, true);
    }

    public function hasRunningHead(): bool
    {
        return $this->showsFolio();
    }

    /** Does the part take a chapter number? Only chapters do. */
    public function isNumbered(): bool
    {
        return $this === self::Chapter;
    }

    /**
     * The ManuscriptSection::TYPES value a new section of this part gets.
     * Unnumbered text parts (conclusion, epilogue, afterword) are stored as
     * back_matter so the existing renderers never print "Chapter 13" over
     * an epilogue.
     */
    public function sectionType(): string
    {
        return match (true) {
            $this === self::Part => 'part',
            $this === self::Chapter => 'chapter',
            $this->group() === 'front' => 'front_matter',
            default => 'back_matter',
        };
    }

    /**
     * The section types a section of this part may already have. Wider
     * than sectionType(): authors commonly type an introduction or an
     * epilogue as a chapter, and inference must still recognise it.
     *
     * @return list<string>
     */
    public function compatibleTypes(): array
    {
        return match ($this) {
            self::Part => ['part'],
            self::Chapter => ['chapter'],
            self::Introduction, self::Prologue => ['front_matter', 'chapter'],
            self::Conclusion, self::Epilogue, self::Afterword => ['back_matter', 'chapter'],
            self::Acknowledgments, self::Abbreviations, self::Chronology => ['front_matter', 'back_matter'],
            default => [$this->sectionType()],
        };
    }

    /** The heading in a language, falling back to English. */
    public function title(string $language = 'en'): string
    {
        $language = self::normaliseLanguage($language);

        return self::TITLES[$this->value][$language] ?? self::TITLES[$this->value]['en'];
    }

    /** @return array<string, string> */
    public function titles(): array
    {
        return self::TITLES[$this->value];
    }

    /** The group a given section of this part belongs to, given its type. */
    public function groupFor(?string $sectionType): string
    {
        if ($this->isFlexible() && $sectionType === 'back_matter') {
            return 'back';
        }

        return $this->group();
    }

    /** Conventional order for a section of this part, given its type. */
    public function orderFor(?string $sectionType): int
    {
        return $this->groupFor($sectionType) === 'back' && $this->backOrder() !== null
            ? $this->backOrder()
            : $this->order();
    }

    /** @return list<self> parts in conventional order */
    public static function ordered(): array
    {
        $cases = self::cases();
        usort($cases, fn (self $a, self $b) => $a->order() <=> $b->order());

        return $cases;
    }

    /** @return list<self> */
    public static function sectionKinds(): array
    {
        return array_values(array_filter(self::ordered(), fn (self $p) => $p->isSectionKind()));
    }

    /**
     * Which part a section is, from its title and its type.
     *
     * Conservative on purpose: a title must BE the part's name ("Préface",
     * "Appendix B: Data"), not merely contain it, and the part must be
     * compatible with the section's type. "Chapter 1: Introduction" in a
     * thesis is a chapter, not front matter.
     */
    public static function infer(?string $title, ?string $type, ?string $language = null): ?self
    {
        if ($type === 'section') {
            return null;
        }

        if ($type === 'part') {
            return self::Part;
        }

        $normal = self::normaliseTitle((string) $title);

        if ($normal !== '' && ! self::isNumberedChapterTitle((string) $title)) {
            $part = self::ambiguous($normal, (string) $title, $language);

            foreach ($part ? [] : self::PATTERNS as $key => $pattern) {
                if (preg_match($pattern, $normal)) {
                    $part = self::from($key);

                    break;
                }
            }

            if ($part && ($type === null || in_array($type, $part->compatibleTypes(), true))) {
                return $part;
            }
        }

        return $type === 'chapter' ? self::Chapter : null;
    }

    /**
     * Titles whose meaning depends on the language. "Préface" is Chicago's
     * foreword in French (written by someone else) but "Preface" is the
     * author's own in English; Portuguese "Prefácio" is a foreword, Spanish
     * "Prefacio" the author's preface. With no language given, the accent
     * decides, which is how the two are written in practice.
     */
    private static function ambiguous(string $normal, string $title, ?string $language): ?self
    {
        $language = $language ? self::normaliseLanguage($language) : null;

        return match ($normal) {
            'preface' => ($language === 'fr' || ($language === null && preg_match('/é/iu', $title)))
                ? self::Foreword : self::Preface,
            'prefacio' => ($language === 'pt' || ($language === null && preg_match('/á/iu', $title)))
                ? self::Foreword : self::Preface,
            default => null,
        };
    }

    /** "Chapter 3: Introduction", "Chapitre 2 — Conclusion". */
    private static function isNumberedChapterTitle(string $title): bool
    {
        return (bool) preg_match('/^\s*(chapter|chapitre|chapit|cap[ií]tulo|kapitel)\s+(\d+|[ivxlcdm]+)\b/iu', $title);
    }

    /** Lower case, no accents or curly quotes, no leading numbering. */
    public static function normaliseTitle(string $title): string
    {
        $t = mb_strtolower(trim($title));
        $t = strtr($t, ['’' => "'", '‘' => "'", '`' => "'", '«' => '', '»' => '']);
        $t = \Normalizer::normalize($t, \Normalizer::FORM_D) ?: $t;
        $t = (string) preg_replace('/\p{Mn}+/u', '', $t);
        // Leading "1.", "IV –", "A)": numbering, not the name.
        $t = (string) preg_replace('/^(\d+|[ivxlc]+)\s*[.):\-–—]\s+/u', '', $t);
        $t = (string) preg_replace('/[\s ]+/u', ' ', $t);

        return trim($t, " \t.:;!…");
    }

    /** A display language ("French", "fr-FR", "Kreyòl") to a short code. */
    public static function normaliseLanguage(?string $language): string
    {
        $l = mb_strtolower(trim((string) $language));

        return match (true) {
            $l === '' => 'en',
            str_starts_with($l, 'haitian'), str_starts_with($l, 'kreyòl'), str_starts_with($l, 'kreyol'),
            str_starts_with($l, 'creole'), $l === 'ht' || str_starts_with($l, 'ht-') => 'ht',
            str_starts_with($l, 'fr') => 'fr',
            str_starts_with($l, 'span'), str_starts_with($l, 'espa'), $l === 'es' || str_starts_with($l, 'es-') => 'es',
            str_starts_with($l, 'germ'), str_starts_with($l, 'deutsch'), $l === 'de' || str_starts_with($l, 'de-') => 'de',
            str_starts_with($l, 'portu'), $l === 'pt' || str_starts_with($l, 'pt-') => 'pt',
            default => 'en',
        };
    }
}
