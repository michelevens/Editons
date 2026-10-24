"""Build covers/specs.json: one front and back cover spec per book, stats counted from the book itself."""
import json, os, re, sys

here = os.path.dirname(os.path.abspath(__file__))
books = os.environ.get('BOOKS_DIR', os.path.join(here, '..', 'books'))

M = {  # motif SVGs in a 1000 × 1000 box, white strokes
 'math': '<path d="M80 900 L80 100 M80 900 L920 900"/><path d="M120 860 Q 420 -100 900 700"/><text x="560" y="420" font-size="260" fill="#fff" stroke="none" font-family="serif">π</text><text x="200" y="560" font-size="230" fill="#fff" stroke="none" font-family="serif">∑</text><text x="640" y="820" font-size="170" fill="#fff" stroke="none" font-family="serif">√x</text>',
 'wave': '<path d="M40 500 C 140 250, 240 250, 340 500 S 540 750, 640 500 S 840 250, 960 500"/><path d="M40 760 h200 v-90 h120 v180 h120 v-90 h200 M780 760 h180" /><circle cx="250" cy="220" r="90"/><path d="M250 130 v-80 M250 310 v80"/>',
 'hex': '<polygon points="300,180 430,255 430,405 300,480 170,405 170,255"/><polygon points="560,330 690,405 690,555 560,630 430,555 430,405"/><polygon points="560,630 690,705 690,855 560,930 430,855 430,705"/><circle cx="300" cy="330" r="70"/><path d="M690 405 L820 330 M690 705 L850 790"/>',
 'helix': '<path d="M300 60 C 700 220, 300 380, 700 540 S 300 860, 700 980"/><path d="M700 60 C 300 220, 700 380, 300 540 S 700 860, 300 980"/><path d="M400 140 h200 M380 300 h240 M400 460 h200 M380 620 h240 M400 780 h200 M380 920 h240"/>',
 'column': '<path d="M300 140 h400 M330 200 h340 M360 200 v600 M440 200 v600 M560 200 v600 M640 200 v600 M330 800 h340 M290 870 h420"/><text x="430" y="130" font-size="160" fill="#fff" stroke="none" font-family="serif">?</text>',
 'chart': '<path d="M120 880 h780 M120 880 V120"/><rect x="200" y="620" width="110" height="260"/><rect x="380" y="480" width="110" height="400"/><rect x="560" y="340" width="110" height="540"/><rect x="740" y="200" width="110" height="680"/><path d="M180 560 L420 380 L560 450 L860 140 M760 140 h100 v100"/>',
 'globe': '<circle cx="500" cy="500" r="380"/><ellipse cx="500" cy="500" rx="170" ry="380"/><path d="M120 500 h760 M175 300 h650 M175 700 h650 M500 120 v760"/>',
 'quote': '<text x="80" y="640" font-size="720" fill="#fff" stroke="none" font-family="serif">«</text><text x="520" y="640" font-size="720" fill="#fff" stroke="none" font-family="serif">»</text><path d="M200 820 h600 M260 900 h480"/>',
 'bubble': '<path d="M160 180 h680 a80 80 0 0 1 80 80 v360 a80 80 0 0 1 -80 80 h-420 l-170 150 v-150 h-90 a80 80 0 0 1 -80 -80 v-360 a80 80 0 0 1 80 -80z"/><text x="250" y="560" font-size="260" fill="#fff" stroke="none" font-family="Anton, sans-serif">__TXT__</text>',
 'code': '<text x="70" y="620" font-size="420" fill="#fff" stroke="none" font-family="monospace">&lt;/&gt;</text><text x="120" y="860" font-size="110" fill="#fff" stroke="none" font-family="monospace">01101 1001</text><text x="120" y="230" font-size="110" fill="#fff" stroke="none" font-family="monospace">si … alors</text>',
 'notes': '<path d="M300 760 V220 L780 140 V680"/><ellipse cx="230" cy="770" rx="90" ry="65"/><ellipse cx="710" cy="690" rx="90" ry="65"/><path d="M300 330 L780 250"/><circle cx="520" cy="880" r="60"/><path d="M120 120 l60 60 M880 860 l60 60"/>',
 'atom': '<ellipse cx="500" cy="500" rx="420" ry="150"/><ellipse cx="500" cy="500" rx="420" ry="150" transform="rotate(60 500 500)"/><ellipse cx="500" cy="500" rx="420" ry="150" transform="rotate(-60 500 500)"/><circle cx="500" cy="500" r="55" fill="#fff"/>',
}

S = {  # book: subject title (<br> = deliberate line break), gradient c1 → c2, accent, motif
 '9e-math': ('Mathématiques', '#F26B1D', '#A8370A', '#FFD23F', 'math'),
 '9e-francais': ('Français', '#1F4FA3', '#0E2A63', '#FFC93C', 'quote'),
 '9e-creole': ('Kreyòl', '#00209F', '#0A1450', '#D21034', 'bubble:Kreyòl'),
 '9e-anglais': ('Anglais', '#C8102E', '#6E0717', '#FFD100', 'bubble:ABC'),
 '9e-espagnol': ('Espagnol', '#E39B00', '#B5281C', '#FFFFFF', 'bubble:¡Sí!'),
 '9e-sciences-exp': ('Sciences<br>expérimentales', '#12A37A', '#0B5040', '#C6FF3D', 'atom'),
 '9e-sciences-sociales': ('Sciences<br>sociales', '#B5432D', '#5E1C12', '#FFC845', 'globe'),
 'ns4-math': ('Mathématiques', '#2E3192', '#11124A', '#FF7A1A', 'math'),
 'ns4-physique': ('Physique', '#0072CE', '#002350', '#FFD400', 'wave'),
 'ns4-chimie': ('Chimie', '#00A3A1', '#004E4D', '#FF5A5F', 'hex'),
 'ns4-svt': ('SVT<span class="sub">Biologie · Géologie</span>', '#3BA935', '#174F1B', '#FFE14D', 'helix'),
 'ns4-philosophie': ('Philosophie', '#5B2C83', '#25103A', '#F4C542', 'column'),
 'ns4-economie': ('Économie', '#C9940E', '#6B4600', '#1B998B', 'chart'),
 'ns4-sciences-sociales': ('Histoire<br>et géographie', '#A23B2A', '#46160F', '#F2C14E', 'globe'),
 'ns4-anglais': ('Anglais', '#B3122E', '#520613', '#FFD100', 'bubble:ABC'),
 'ns4-espagnol': ('Espagnol', '#EF9A06', '#A8321A', '#FFFFFF', 'bubble:¡Sí!'),
 'ns4-creole': ('Kreyòl', '#00209F', '#0A1450', '#D21034', 'bubble:Kreyòl'),
 'ns4-informatique': ('Informatique', '#0BB5C9', '#073B47', '#FF3EA5', 'code'),
 'ns4-art-musique': ('Arts et<br>musique', '#D6246E', '#510C34', '#FFD23F', 'notes'),
}

# House rules shared by every cover.
MAX_TITLE = 380   # px: the subject is fitted to its zone below this, never above
GOLD = '#F5C518'  # the Bac level colour
LIGHT = ('#FFFFFF', '#FFD23F', '#FFD100', '#FFC93C', '#FFD400', '#FFE14D', '#FFC845', '#F2C14E', '#F4C542', '#C6FF3D')


def stats(book):
    meta = json.load(open(os.path.join(books, book, 'meta.json'), encoding='utf-8'))
    text, titles, part = '', [], ''
    for s in meta['sections']:
        if s['type'] == 'part':
            part = s['title']
            continue
        if s['type'] == 'back_matter':
            part = ''
        titles.append((part, s['title']))
        if s.get('file') and os.path.exists(p := os.path.join(books, book, 'sections', s['file'])):
            text += open(p, encoding='utf-8').read()
    annales = [t for p, t in titles if re.search(r'Pati IV|Partie IV', p)]
    years = [int(y) for t in annales for y in re.findall(r'\b(20\d\d)\b', t)]
    mocks = [t for p, t in titles if re.search(r'Pati V\b|Partie V\b', p) and not re.search(r'[Cc]orrig|[Kk]orije', t)]
    exercises = len(re.findall(r'^\*\*(?:Exercice|Egzèsis)\s', text, re.M))
    chapters = sum(1 for p, t in titles if re.match(r'(Chapitre|Chapit)\s+\d', t))
    return meta, len(annales), (min(years), max(years)) if years else None, len(mocks), exercises, chapters


specs = {}
for book in sys.argv[1:] or sorted(S):
    if not os.path.exists(os.path.join(books, book, 'meta.json')):
        continue
    subject, c1, c2, accent, motif = S[book]
    meta = json.load(open(os.path.join(books, book, 'meta.json'), encoding='utf-8'))
    kreyol = meta['language'] == 'Haitian Creole'
    bac = book.startswith('ns4')
    # The front makes no numerical claim and names no author: the series is
    # anonymous and ÉCRIVAIN PRÉPA is the only name on it.
    if kreyol:
        kicker = 'Reyisi Bak la' if bac else 'Reyisi egzamen an'
        tag = 'Kou · Metòd · Egzamen korije'
        promises = ['Konprann nosyon esansyèl yo', 'Pratike ak egzèsis varye',
                    'Reyisi Bak la avèk konfyans' if bac else 'Reyisi egzamen an avèk konfyans']
    else:
        kicker = 'Réussir le Bac' if bac else 'Réussir l’examen'
        tag = 'Cours · Méthodes · Annales corrigées'
        promises = ['Comprendre les notions essentielles', 'S’entraîner avec des exercices variés',
                    'Réussir le Bac en toute confiance' if bac else 'Réussir l’examen en toute confiance']
    # The level is named as the manifest names it, in French on every book.
    level = 'Baccalauréat<i>·</i>NS4' if bac else '9<sup>e</sup>&nbsp;année fondamentale'
    # The illustration panel: art/<book>.jpg or .png, 1600 px wide.
    art = next((f for f in (os.path.join(here, 'art', f'{book}.{x}') for x in ('jpg', 'png')) if os.path.exists(f)), '')
    # The back (print): the book's own description and what it holds, every
    # figure counted from the book's files by stats(), never estimated.
    _, annales, years, mocks, exercises, chapters = stats(book)
    plain = re.sub(r'<span class="sub">(.*?)</span>', r' (\1)', subject).replace('<br>', ' ')
    span = f' ({years[0]}–{years[1]})' if years and years[0] != years[1] else (f' ({years[0]})' if years else '')
    if kreyol:
        inside = [f'<b>{chapters}</b> chapit kou, metòd ak egzèsis',
                  f'<b>{annales}</b> egzamen ofisyèl korije{span}' if annales else 'Yon tès dyagnostik pou kòmanse',
                  f'<b>{mocks}</b> egzamen blan ak korije' if mocks else 'Pyèj pou evite ak konsèy pou egzamen an',
                  f'<b>{exercises}</b> egzèsis ak korije detaye' if exercises else 'Egzèsis ak korije detaye']
        inside_title, footnote = 'Nan liv sa a', 'Liv endepandan: li pa gen okenn lyen ofisyèl ak MENFP. Egzamen ofisyèl yo repwodui ak non sous la.'
    else:
        inside = [f'<b>{chapters}</b> chapitres de cours, de méthodes et d’exercices',
                  f'<b>{annales}</b> sujets officiels corrigés{span}' if annales else 'Un test diagnostique pour commencer',
                  f'<b>{mocks}</b> examens blancs avec corrigés' if mocks else 'Les pièges à éviter et les conseils pour l’examen',
                  f'<b>{exercises}</b> exercices avec corrigés détaillés' if exercises else 'Des exercices avec corrigés détaillés']
        inside_title, footnote = 'Dans ce livre', 'Ouvrage indépendant, sans lien officiel avec le MENFP. Les épreuves officielles sont reproduites avec mention de la source.'
    specs[book] = dict(
        bac=bac, edition='Édition<b>2026</b>', level=level, kicker=kicker, subject=subject,
        maxSize=MAX_TITLE, tagline=tag, promises=promises,
        c1=c1, c2=c2, c1Ink=f'color-mix(in srgb, {c1} 80%, #000)',
        art=('file:///' + art.replace(os.sep, '/')) if art else None,
        backLevel=f'<span>{level}</span><i>·</i><span>{plain}</span>',
        description=meta.get('description', ''), insideTitle=inside_title, inside=inside, footnote=footnote,
    )
json.dump(specs, open(os.path.join(here, 'specs.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
for b, s in specs.items():
    print(b, '|', 'art' if s['art'] else 'NO ART', '|', s['kicker'], '|', s['promises'][2])
