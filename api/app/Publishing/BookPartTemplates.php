<?php

namespace App\Publishing;

use App\Models\Book;

/**
 * Starting content for a part the author adds from the Book parts checklist.
 *
 * Guidance only, never facts: every paragraph is an instruction in square
 * brackets, in the book's language, telling the author what conventionally
 * goes here. Nothing pretends to be the author's prose, and no name, date or
 * number is made up. The one exception is the copyright page, which is built
 * from the book's own publishing metadata (Anatomy::copyrightLines) and so
 * contains only what the author entered.
 *
 * A guidance paragraph left untouched is detectable (isGuidance), so the
 * checklist can say "still a placeholder" and the renderers can refuse to
 * print instructions as if they were text.
 */
class BookPartTemplates
{
    /** @var array<string, array<string, list<string>>> */
    private const GUIDANCE = [
        'frontispiece' => [
            'en' => ['[Insert the frontispiece illustration here, with its caption and credit. It faces the title page.]'],
            'fr' => ['[Insérez ici l’illustration du frontispice, avec sa légende et son crédit. Elle fait face à la page de titre.]'],
            'ht' => ['[Mete imaj frontispis la isit la, ak lejann li ak non moun ki fè l la. Li fè fas ak paj tit la.]'],
            'es' => ['[Inserte aquí la ilustración del frontispicio, con su pie y su crédito. Va frente a la portada.]'],
            'de' => ['[Fügen Sie hier das Frontispiz mit Bildunterschrift und Bildnachweis ein. Es steht der Titelseite gegenüber.]'],
            'pt' => ['[Insira aqui a ilustração do frontispício, com legenda e crédito. Ela fica de frente para a folha de rosto.]'],
        ],
        'dedication' => [
            'en' => ['[Write your dedication: one or two short lines, for example “For …”.]'],
            'fr' => ['[Écrivez votre dédicace : une ou deux lignes brèves, par exemple « À … ».]'],
            'ht' => ['[Ekri dedikas ou : youn oswa de ti liy, pa egzanp « Pou … ».]'],
            'es' => ['[Escriba su dedicatoria: una o dos líneas breves, por ejemplo «A …».]'],
            'de' => ['[Schreiben Sie Ihre Widmung: eine oder zwei kurze Zeilen, zum Beispiel „Für …“.]'],
            'pt' => ['[Escreva sua dedicatória: uma ou duas linhas curtas, por exemplo “Para …”.]'],
        ],
        'epigraph' => [
            'en' => ['[Quote the epigraph exactly, then its source on a separate line. Check the wording against the original and clear permission if it is still in copyright.]'],
            'fr' => ['[Citez l’épigraphe exactement, puis sa source sur une ligne séparée. Vérifiez le texte sur l’original et obtenez l’autorisation s’il est encore protégé.]'],
            'ht' => ['[Site epigraf la egzakteman, epi mete sous li sou yon lòt liy. Verifye tèks la sou orijinal la, epi mande otorizasyon si li toujou pwoteje.]'],
            'es' => ['[Cite el epígrafe con exactitud y su fuente en una línea aparte. Compruebe el texto con el original y pida permiso si aún tiene derechos.]'],
            'de' => ['[Zitieren Sie das Motto wörtlich und die Quelle in einer eigenen Zeile. Prüfen Sie den Wortlaut am Original und holen Sie die Genehmigung ein, falls es noch geschützt ist.]'],
            'pt' => ['[Cite a epígrafe exatamente e a fonte numa linha à parte. Confira o texto no original e obtenha permissão se ainda estiver protegido.]'],
        ],
        'foreword' => [
            'en' => ['[A foreword is written by someone other than the author. Paste the text you received here, followed by the writer’s name and, if they wish, their title and the place and date.]'],
            'fr' => ['[La préface est écrite par une autre personne que l’auteur. Collez ici le texte reçu, suivi du nom du préfacier et, s’il le souhaite, de sa qualité, du lieu et de la date.]'],
            'ht' => ['[Se yon lòt moun, pa otè a, ki ekri prefas la. Kole tèks ou resevwa a isit la, epi mete non moun ki ekri l la, ak tit li, kote ak dat si li vle.]'],
            'es' => ['[El prólogo lo escribe otra persona, no el autor. Pegue aquí el texto recibido, seguido del nombre de quien lo firma y, si lo desea, su cargo, el lugar y la fecha.]'],
            'de' => ['[Ein Geleitwort stammt nicht vom Autor selbst. Fügen Sie hier den erhaltenen Text ein, gefolgt vom Namen der Verfasserin oder des Verfassers und gegebenenfalls Funktion, Ort und Datum.]'],
            'pt' => ['[O prefácio é escrito por outra pessoa que não o autor. Cole aqui o texto recebido, seguido do nome de quem o assina e, se desejar, cargo, local e data.]'],
        ],
        'preface' => [
            'en' => ['[In your own voice: why you wrote this book, who it is for, and how it came about. Thanks may go here or in a separate Acknowledgments.]'],
            'fr' => ['[À la première personne : pourquoi vous avez écrit ce livre, à qui il s’adresse et comment il est né. Les remerciements peuvent figurer ici ou dans une section à part.]'],
            'ht' => ['[Ak pwòp vwa ou : poukisa ou ekri liv sa a, pou ki moun li ye, ak kijan li fèt. Ou ka remèsye moun isit la oswa nan yon seksyon Remèsiman apa.]'],
            'es' => ['[Con su propia voz: por qué escribió este libro, a quién va dirigido y cómo nació. Los agradecimientos pueden ir aquí o en una sección aparte.]'],
            'de' => ['[In Ihrer eigenen Stimme: warum Sie dieses Buch geschrieben haben, für wen es ist und wie es entstanden ist. Dank kann hier oder in einer eigenen Danksagung stehen.]'],
            'pt' => ['[Com a sua própria voz: por que escreveu este livro, a quem se destina e como surgiu. Os agradecimentos podem vir aqui ou numa seção à parte.]'],
        ],
        'acknowledgments' => [
            'en' => ['[Thank the people and institutions who helped with this book. Check every name’s spelling with the person.]'],
            'fr' => ['[Remerciez les personnes et les institutions qui ont aidé à faire ce livre. Vérifiez l’orthographe de chaque nom auprès de la personne.]'],
            'ht' => ['[Remèsye moun ak enstitisyon ki te ede w fè liv sa a. Verifye kijan chak non ekri avèk moun nan.]'],
            'es' => ['[Agradezca a las personas e instituciones que ayudaron con este libro. Confirme la grafía de cada nombre con la persona.]'],
            'de' => ['[Danken Sie den Menschen und Institutionen, die an diesem Buch mitgewirkt haben. Prüfen Sie die Schreibweise jedes Namens mit der Person.]'],
            'pt' => ['[Agradeça às pessoas e instituições que ajudaram neste livro. Confirme a grafia de cada nome com a pessoa.]'],
        ],
        'introduction' => [
            'en' => ['[Introduce the subject: what the book covers, what the reader needs to know first, and how the chapters are organized.]'],
            'fr' => ['[Présentez le sujet : ce que couvre le livre, ce que le lecteur doit savoir d’abord, et comment les chapitres sont organisés.]'],
            'ht' => ['[Prezante sijè a : sa liv la kouvri, sa lektè a dwe konnen anvan, ak kijan chapit yo òganize.]'],
            'es' => ['[Presente el tema: qué abarca el libro, qué debe saber primero el lector y cómo se organizan los capítulos.]'],
            'de' => ['[Führen Sie in das Thema ein: was das Buch behandelt, was Leser vorab wissen sollten und wie die Kapitel aufgebaut sind.]'],
            'pt' => ['[Apresente o tema: o que o livro abrange, o que o leitor precisa saber primeiro e como os capítulos estão organizados.]'],
        ],
        'how_to_use' => [
            'en' => ['[Explain how to use this book: who it is for, how each chapter is built (lesson, worked examples, exercises, answers), and how to plan revision with it.]', '[Say where the answer key, glossary and index are, and what the symbols or boxes in the pages mean.]'],
            'fr' => ['[Expliquez comment utiliser ce livre : à qui il s’adresse, comment chaque chapitre est construit (cours, exemples corrigés, exercices, corrigés) et comment s’en servir pour réviser.]', '[Indiquez où se trouvent les corrigés, le glossaire et l’index, et ce que signifient les pictogrammes ou encadrés.]'],
            'ht' => ['[Eksplike kijan pou itilize liv sa a : pou ki moun li ye, kijan chak chapit fèt (leson, egzanp ki korije, egzèsis, repons), ak kijan pou sèvi avè l pou revize.]', '[Di ki kote repons yo, glosè a ak endèks la ye, ak sa siy oswa kad ki nan paj yo vle di.]'],
            'es' => ['[Explique cómo usar este libro: a quién va dirigido, cómo se organiza cada capítulo (lección, ejemplos resueltos, ejercicios, soluciones) y cómo planificar el repaso.]', '[Indique dónde están las soluciones, el glosario y el índice analítico, y qué significan los símbolos o recuadros.]'],
            'de' => ['[Erklären Sie, wie dieses Buch zu benutzen ist: für wen es gedacht ist, wie jedes Kapitel aufgebaut ist (Lektion, Beispiele, Übungen, Lösungen) und wie man damit wiederholt.]', '[Sagen Sie, wo Lösungen, Glossar und Register stehen und was Symbole oder Kästen bedeuten.]'],
            'pt' => ['[Explique como usar este livro: a quem se destina, como cada capítulo está organizado (lição, exemplos resolvidos, exercícios, respostas) e como planejar a revisão.]', '[Indique onde ficam as respostas, o glossário e o índice remissivo, e o que significam os símbolos ou quadros.]'],
        ],
        'abbreviations' => [
            'en' => ['[List each abbreviation used in the book, alphabetically, with what it stands for.]'],
            'fr' => ['[Listez par ordre alphabétique chaque abréviation ou sigle employé dans le livre, avec sa forme développée.]'],
            'ht' => ['[Fè lis tout abreviyasyon ki nan liv la, nan lòd alfabetik, ak sa chak vle di.]'],
            'es' => ['[Enumere alfabéticamente cada abreviatura usada en el libro con su significado.]'],
            'de' => ['[Listen Sie jede im Buch verwendete Abkürzung alphabetisch mit ihrer Auflösung auf.]'],
            'pt' => ['[Liste em ordem alfabética cada abreviatura usada no livro, com o seu significado.]'],
        ],
        'chronology' => [
            'en' => ['[List the key dates in order, one per line: the date, then the event. Check each date against a source.]'],
            'fr' => ['[Listez les dates clés dans l’ordre, une par ligne : la date, puis l’événement. Vérifiez chaque date dans une source.]'],
            'ht' => ['[Fè lis dat enpòtan yo nan lòd, youn pa liy : dat la, epi evènman an. Verifye chak dat nan yon sous.]'],
            'es' => ['[Enumere las fechas clave en orden, una por línea: la fecha y luego el hecho. Verifique cada fecha en una fuente.]'],
            'de' => ['[Listen Sie die wichtigen Daten der Reihe nach auf, eines pro Zeile: Datum, dann Ereignis. Prüfen Sie jedes Datum anhand einer Quelle.]'],
            'pt' => ['[Liste as datas principais em ordem, uma por linha: a data e depois o acontecimento. Confira cada data numa fonte.]'],
        ],
        'prologue' => [
            'en' => ['[Write the prologue: a scene or passage set apart from the main story, often earlier in time, that frames what follows.]'],
            'fr' => ['[Écrivez le prologue : une scène ou un passage détaché du récit principal, souvent antérieur, qui éclaire la suite.]'],
            'ht' => ['[Ekri pwològ la : yon sèn oswa yon pasaj apa istwa prensipal la, souvan ki pase anvan, ki prepare sa k ap vini.]'],
            'es' => ['[Escriba el prólogo: una escena o pasaje separado de la historia principal, a menudo anterior, que enmarca lo que sigue.]'],
            'de' => ['[Schreiben Sie den Prolog: eine Szene oder Passage außerhalb der Haupthandlung, oft zeitlich davor, die das Folgende rahmt.]'],
            'pt' => ['[Escreva o prólogo: uma cena ou passagem separada da história principal, muitas vezes anterior, que enquadra o que vem a seguir.]'],
        ],
        'chapter' => [
            'en' => ['[Write this chapter.]'],
            'fr' => ['[Écrivez ce chapitre.]'],
            'ht' => ['[Ekri chapit sa a.]'],
            'es' => ['[Escriba este capítulo.]'],
            'de' => ['[Schreiben Sie dieses Kapitel.]'],
            'pt' => ['[Escreva este capítulo.]'],
        ],
        'conclusion' => [
            'en' => ['[Draw the book together: what the reader should take away, and where the subject goes next.]'],
            'fr' => ['[Rassemblez le propos : ce que le lecteur doit retenir, et les prolongements possibles du sujet.]'],
            'ht' => ['[Rasanble tout sa liv la di : sa lektè a dwe sonje, ak ki kote sijè a ka ale apre.]'],
            'es' => ['[Reúna las ideas del libro: qué debe llevarse el lector y hacia dónde puede ir el tema.]'],
            'de' => ['[Führen Sie das Buch zusammen: was Leser mitnehmen sollen und wohin das Thema weiterführt.]'],
            'pt' => ['[Reúna as ideias do livro: o que o leitor deve levar consigo e para onde o tema pode seguir.]'],
        ],
        'epilogue' => [
            'en' => ['[Write the epilogue: what happens after the story ends.]'],
            'fr' => ['[Écrivez l’épilogue : ce qui se passe après la fin du récit.]'],
            'ht' => ['[Ekri epilòg la : sa k pase apre istwa a fini.]'],
            'es' => ['[Escriba el epílogo: lo que ocurre después de que termina la historia.]'],
            'de' => ['[Schreiben Sie den Epilog: was nach dem Ende der Geschichte geschieht.]'],
            'pt' => ['[Escreva o epílogo: o que acontece depois que a história termina.]'],
        ],
        'afterword' => [
            'en' => ['[An afterword looks back on the book: how it came to be, what has changed since, or a note to the reader. It may be by the author or by someone else; sign it if it is not yours.]'],
            'fr' => ['[La postface revient sur le livre : sa genèse, ce qui a changé depuis, ou un mot au lecteur. Elle peut être de l’auteur ou d’une autre personne ; signez-la si elle n’est pas de vous.]'],
            'ht' => ['[Postfas la gade liv la an aryè : kijan li fèt, sa ki chanje depi lè a, oswa yon mo pou lektè a. Otè a oswa yon lòt moun ka ekri l ; siyen l si se pa ou ki ekri l.]'],
            'es' => ['[El posfacio vuelve sobre el libro: cómo surgió, qué ha cambiado desde entonces o una nota al lector. Puede ser del autor o de otra persona; fírmelo si no es suyo.]'],
            'de' => ['[Das Nachwort blickt auf das Buch zurück: wie es entstand, was sich seither geändert hat, oder ein Wort an die Leser. Unterzeichnen Sie es, wenn es nicht von Ihnen stammt.]'],
            'pt' => ['[O posfácio olha para trás: como o livro surgiu, o que mudou desde então ou uma nota ao leitor. Pode ser do autor ou de outra pessoa; assine-o se não for seu.]'],
        ],
        'appendix' => [
            'en' => ['[Material that supports the text but would interrupt it: documents, data, worked solutions, forms. Give each appendix a letter and a title, e.g. “Appendix A: …”.]'],
            'fr' => ['[Les documents qui appuient le texte sans l’alourdir : pièces, données, corrigés, formulaires. Numérotez et titrez chaque annexe, par exemple « Annexe 1 : … ».]'],
            'ht' => ['[Dokiman ki sipòte tèks la san yo pa koupe l : done, repons, fòmilè. Bay chak anèks yon nimewo ak yon tit, pa egzanp « Anèks 1 : … ».]'],
            'es' => ['[Material que apoya el texto sin interrumpirlo: documentos, datos, soluciones, formularios. Dé a cada apéndice una letra y un título, p. ej. «Apéndice A: …».]'],
            'de' => ['[Material, das den Text stützt, ihn aber unterbrechen würde: Dokumente, Daten, Lösungen, Formulare. Geben Sie jedem Anhang einen Buchstaben und Titel, z. B. „Anhang A: …“.]'],
            'pt' => ['[Material que apoia o texto sem interrompê-lo: documentos, dados, soluções, formulários. Dê a cada apêndice uma letra e um título, p. ex. “Apêndice A: …”.]'],
        ],
        'notes' => [
            'en' => ['[Endnotes, grouped under each chapter’s number and title. If you use footnotes in the chapters, you may not need this section.]'],
            'fr' => ['[Les notes de fin, regroupées sous le numéro et le titre de chaque chapitre. Si vous utilisez des notes de bas de page, cette section est peut-être inutile.]'],
            'ht' => ['[Nòt final yo, gwoupe anba nimewo ak tit chak chapit. Si ou itilize nòt anba paj nan chapit yo, petèt ou pa bezwen seksyon sa a.]'],
            'es' => ['[Notas finales, agrupadas bajo el número y título de cada capítulo. Si usa notas al pie, quizá no necesite esta sección.]'],
            'de' => ['[Endnoten, nach Kapitelnummer und -titel gegliedert. Wenn Sie Fußnoten verwenden, brauchen Sie diesen Abschnitt vielleicht nicht.]'],
            'pt' => ['[Notas finais, agrupadas sob o número e o título de cada capítulo. Se usar notas de rodapé, talvez não precise desta seção.]'],
        ],
        'glossary' => [
            'en' => ['[Define the book’s key terms, alphabetically: the term, then a short definition in the sense the book uses it.]'],
            'fr' => ['[Définissez les termes clés du livre par ordre alphabétique : le terme, puis une brève définition au sens où le livre l’emploie.]'],
            'ht' => ['[Defini mo kle liv la nan lòd alfabetik : mo a, epi yon ti definisyon jan liv la itilize l.]'],
            'es' => ['[Defina los términos clave del libro por orden alfabético: el término y una breve definición en el sentido que le da el libro.]'],
            'de' => ['[Definieren Sie die Schlüsselbegriffe alphabetisch: den Begriff, dann eine kurze Erklärung im Sinne des Buches.]'],
            'pt' => ['[Defina os termos-chave do livro em ordem alfabética: o termo e uma breve definição no sentido usado no livro.]'],
        ],
        'bibliography' => [
            'en' => ['[The works you cite or recommend, in your book’s citation style. Sources in the research library can be inserted from the References screen; never list a work you have not checked.]'],
            'fr' => ['[Les ouvrages cités ou recommandés, dans le style de citation du livre. Les sources de la bibliothèque de recherche peuvent être insérées depuis l’écran Références ; ne listez jamais un ouvrage non vérifié.]'],
            'ht' => ['[Liv ak dokiman ou site oswa rekòmande, nan estil sitasyon liv la. Ou ka mete sous ki nan bibliyotèk rechèch la depi ekran Referans lan ; pa janm mete yon zèv ou pa verifye.]'],
            'es' => ['[Las obras citadas o recomendadas, en el estilo de citación del libro. Las fuentes de la biblioteca de investigación pueden insertarse desde Referencias; no incluya nunca una obra que no haya comprobado.]'],
            'de' => ['[Die zitierten oder empfohlenen Werke im Zitierstil des Buches. Quellen aus der Recherche-Bibliothek lassen sich über „Referenzen“ einfügen; nehmen Sie nie ein ungeprüftes Werk auf.]'],
            'pt' => ['[As obras citadas ou recomendadas, no estilo de citação do livro. As fontes da biblioteca de pesquisa podem ser inseridas a partir de Referências; nunca liste uma obra que não tenha verificado.]'],
        ],
        'contributors' => [
            'en' => ['[One short paragraph per contributor, alphabetically by surname: name, affiliation, and what they contributed. Use the wording each contributor approved.]'],
            'fr' => ['[Un court paragraphe par contributeur, par ordre alphabétique des noms : nom, rattachement, contribution. Reprenez la formulation validée par chacun.]'],
            'ht' => ['[Yon ti paragraf pou chak kontribitè, nan lòd alfabetik siyati : non, enstitisyon, ak sa yo te pote. Sèvi ak tèks chak moun te apwouve.]'],
            'es' => ['[Un párrafo breve por colaborador, por orden alfabético de apellido: nombre, afiliación y contribución. Use la redacción que cada uno aprobó.]'],
            'de' => ['[Ein kurzer Absatz je Beitragender, alphabetisch nach Nachnamen: Name, Institution, Beitrag. Verwenden Sie den von allen freigegebenen Wortlaut.]'],
            'pt' => ['[Um parágrafo curto por colaborador, em ordem alfabética de sobrenome: nome, afiliação e contribuição. Use o texto aprovado por cada um.]'],
        ],
        'index' => [
            'en' => ['[The index is compiled from final page proofs. List headings alphabetically with their page numbers once pagination is fixed; for an ebook, link entries instead.]'],
            'fr' => ['[L’index se compose sur les épreuves définitives. Listez les entrées par ordre alphabétique avec leurs pages une fois la pagination arrêtée ; en numérique, utilisez des liens.]'],
            'ht' => ['[Endèks la fèt sou dènye vèsyon paj yo. Mete antre yo nan lòd alfabetik ak nimewo paj yo lè paj yo fin fikse ; pou liv nimerik, mete lyen pito.]'],
            'es' => ['[El índice analítico se elabora sobre las pruebas finales. Ordene las entradas alfabéticamente con sus páginas cuando la paginación esté cerrada; en el libro electrónico, use enlaces.]'],
            'de' => ['[Das Register entsteht auf Basis der finalen Seiten. Ordnen Sie die Einträge alphabetisch mit Seitenzahlen, sobald der Umbruch steht; im E-Book verlinken Sie stattdessen.]'],
            'pt' => ['[O índice remissivo é feito sobre as provas finais. Ordene as entradas alfabeticamente com as páginas quando a paginação estiver fechada; no e-book, use links.]'],
        ],
        'about_author' => [
            'en' => ['[A short biography in the third person: who you are, what you do, and what qualifies you to write this book. Only facts you are happy to print.]'],
            'fr' => ['[Une courte biographie à la troisième personne : qui vous êtes, ce que vous faites, et ce qui vous a mené à écrire ce livre. Uniquement des faits que vous acceptez de publier.]'],
            'ht' => ['[Yon ti biyografi nan twazyèm moun : kiyès ou ye, sa ou fè, ak sa ki fè ou kalifye pou ekri liv sa a. Sèlman bagay ou dakò pou yo pibliye.]'],
            'es' => ['[Una breve biografía en tercera persona: quién es, a qué se dedica y por qué escribió este libro. Solo datos que acepte publicar.]'],
            'de' => ['[Eine kurze Biografie in der dritten Person: wer Sie sind, was Sie tun und was Sie für dieses Buch qualifiziert. Nur Angaben, die gedruckt werden dürfen.]'],
            'pt' => ['[Uma breve biografia na terceira pessoa: quem você é, o que faz e o que o qualifica para escrever este livro. Apenas fatos que aceite publicar.]'],
        ],
        'about_series' => [
            'en' => ['[Describe the series: its purpose, who it is for, and how the volumes fit together. The list of titles is generated on the series page.]'],
            'fr' => ['[Présentez la collection : son objectif, son public, et la manière dont les volumes s’articulent. La liste des titres est générée sur la page « Dans la même collection ».]'],
            'ht' => ['[Prezante koleksyon an : objektif li, pou ki moun li ye, ak kijan liv yo mache ansanm. Lis tit yo parèt otomatikman sou paj koleksyon an.]'],
            'es' => ['[Describa la colección: su propósito, a quién se dirige y cómo encajan los volúmenes. La lista de títulos se genera en la página de la colección.]'],
            'de' => ['[Beschreiben Sie die Reihe: Zweck, Zielgruppe und wie die Bände zusammenhängen. Die Titelliste wird auf der Reihenseite erzeugt.]'],
            'pt' => ['[Descreva a coleção: o seu propósito, a quem se destina e como os volumes se articulam. A lista de títulos é gerada na página da coleção.]'],
        ],
        'about_publisher' => [
            'en' => ['[A few lines about the publisher: what it publishes and how readers can find its other books.]'],
            'fr' => ['[Quelques lignes sur l’éditeur : ce qu’il publie et où trouver ses autres ouvrages.]'],
            'ht' => ['[Kèk liy sou editè a : sa li pibliye ak kote lektè yo ka jwenn lòt liv li yo.]'],
            'es' => ['[Unas líneas sobre la editorial: qué publica y dónde encontrar sus otros libros.]'],
            'de' => ['[Einige Zeilen über den Verlag: was er veröffentlicht und wo Leser seine anderen Bücher finden.]'],
            'pt' => ['[Algumas linhas sobre a editora: o que publica e onde encontrar os seus outros livros.]'],
        ],
        'colophon' => [
            'en' => ['[A note on the making of the book: the typefaces, and the printer and date of printing once known.]'],
            'fr' => ['[L’achevé d’imprimer : imprimeur, lieu et date d’impression, une fois connus ; éventuellement les polices employées.]'],
            'ht' => ['[Yon nòt sou kijan liv la fèt : karaktè yo itilize, ak non enprimè a ak dat enpresyon an lè w konnen yo.]'],
            'es' => ['[El colofón: tipografías empleadas y, cuando se conozcan, la imprenta y la fecha de impresión.]'],
            'de' => ['[Das Kolophon: verwendete Schriften und, sobald bekannt, Druckerei und Druckdatum.]'],
            'pt' => ['[O colofão: tipos de letra usados e, quando souber, a gráfica e a data de impressão.]'],
        ],
    ];

    /** "How to Use This Book", the educational introduction's title. */
    private const HOW_TO_USE_TITLE = [
        'en' => 'How to Use This Book',
        'fr' => 'Comment utiliser ce livre',
        'ht' => 'Kijan pou itilize liv sa a',
        'es' => 'Cómo usar este libro',
        'de' => 'So benutzen Sie dieses Buch',
        'pt' => 'Como usar este livro',
    ];

    /** Does the app have a starting template for this part? */
    public static function has(BookPart $part): bool
    {
        return isset(self::GUIDANCE[$part->value])
            || in_array($part, [BookPart::CopyrightPage, BookPart::ListOfIllustrations, BookPart::ListOfTables], true);
    }

    /**
     * The new section for a part: its type, title and ProseMirror content.
     *
     * @return array{type: string, part: string, title: string, content: array<string, mixed>}
     */
    public static function for(Book $book, BookPart $part): array
    {
        $language = Anatomy::language($book);
        $howTo = $part === BookPart::Introduction && Anatomy::profile($book) === 'educational';

        $paragraphs = match (true) {
            $part === BookPart::CopyrightPage => array_column(Anatomy::copyrightLines($book, $language), 'text'),
            // The lists are filled by the renderer from the captions.
            $part === BookPart::ListOfIllustrations, $part === BookPart::ListOfTables => [],
            default => self::guidance($howTo ? 'how_to_use' : $part->value, $language),
        };

        return [
            'type' => $part->sectionType(),
            'part' => $part->value,
            'title' => $howTo ? self::HOW_TO_USE_TITLE[$language] : $part->title($language),
            'content' => [
                'type' => 'doc',
                'content' => array_map(fn (string $text) => [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => $text]],
                ], $paragraphs),
            ],
        ];
    }

    /** @return list<string> */
    public static function guidance(string $key, string $language): array
    {
        $byLanguage = self::GUIDANCE[$key] ?? [];

        return $byLanguage[$language] ?? $byLanguage['en'] ?? [];
    }

    /** Is this paragraph one of the template instructions, untouched? */
    public static function isGuidance(string $paragraph): bool
    {
        static $all = null;

        $all ??= array_flip(array_merge(...array_map(
            fn (array $byLanguage) => array_merge(...array_values($byLanguage)),
            array_values(self::GUIDANCE),
        )));

        return isset($all[trim($paragraph)]);
    }

    /** Does a section still contain template instructions? */
    public static function hasGuidance(?array $content): bool
    {
        foreach ($content['content'] ?? [] as $block) {
            $text = '';
            $walk = function (array $node) use (&$walk, &$text) {
                $text .= $node['text'] ?? '';
                foreach ($node['content'] ?? [] as $child) {
                    is_array($child) && $walk($child);
                }
            };
            is_array($block) && $walk($block);

            if ($text !== '' && self::isGuidance($text)) {
                return true;
            }
        }

        return false;
    }
}
