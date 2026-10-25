"""Geometry of a PDF's pages, as JSON, for tests that check where things print.

Usage: python -I pdfprobe.py file.pdf

For each page: its text spans (text, font size, baseline y, bbox) and the
bounding boxes of its vector drawings (formulas are drawn as paths). Needs
PyMuPDF; tests that use it are skipped where it is not installed.
"""
import json
import sys

import fitz

out = []

for page in fitz.open(sys.argv[1]):
    spans = []

    for block in page.get_text('dict')['blocks']:
        for line in block.get('lines', []):
            for span in line['spans']:
                if span['text'].strip():
                    spans.append({'text': span['text'], 'size': round(span['size'], 2),
                                  'baseline': round(span['origin'][1], 2), 'bbox': [round(v, 2) for v in span['bbox']]})

    drawings = [[round(v, 2) for v in d['rect']] for d in page.get_drawings()]
    out.append({'text': page.get_text(), 'spans': spans, 'drawings': drawings})

json.dump(out, sys.stdout)
