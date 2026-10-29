"""Geometry of a PDF's pages, as JSON, for tests that check where things print.

Usage: python -I pdfprobe.py file.pdf
       python -I pdfprobe.py file.pdf --meta

For each page: its text spans (text, font, font size, baseline y, bbox),
the bounding boxes of its vector drawings (formulas are drawn as paths),
its filled shapes with their colour, its raster pictures' boxes and its
size in points. With --meta, the document instead: its outline
(bookmarks), metadata, page count and every font with whether its program
is embedded. Needs PyMuPDF; tests that use it are skipped where it is not
installed.
"""
import json
import sys

import fitz

doc = fitz.open(sys.argv[1])

if '--meta' in sys.argv[2:]:
    fonts = {}

    for page in doc:
        for font in page.get_fonts(full=True):
            # The extension of the embedded font program; "n/a" when the
            # file carries none and a reader must substitute one.
            fonts[font[3]] = {'ext': font[1], 'type': font[2], 'embedded': font[1] != 'n/a'}

    json.dump({'toc': doc.get_toc(), 'metadata': doc.metadata, 'fonts': fonts, 'pages': doc.page_count}, sys.stdout)
    sys.exit(0)

out = []

for page in doc:
    spans = []

    for block in page.get_text('dict')['blocks']:
        for line in block.get('lines', []):
            for span in line['spans']:
                if span['text'].strip():
                    spans.append({'text': span['text'], 'size': round(span['size'], 2), 'font': span['font'],
                                  'baseline': round(span['origin'][1], 2), 'bbox': [round(v, 2) for v in span['bbox']]})

    drawings = [[round(v, 2) for v in d['rect']] for d in page.get_drawings()]
    # Filled shapes with their grey (a tinted cell, a zebra row), and where
    # each raster picture is drawn.
    fills = [{'rect': [round(v, 2) for v in d['rect']], 'fill': [round(c, 3) for c in d['fill']]}
             for d in page.get_drawings() if d.get('fill')]
    images = [[round(v, 2) for v in i['bbox']] for i in page.get_image_info()]
    out.append({'text': page.get_text(), 'spans': spans, 'drawings': drawings, 'fills': fills, 'images': images,
                'width': round(page.rect.width, 2), 'height': round(page.rect.height, 2)})

json.dump(out, sys.stdout)
