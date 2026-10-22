"""python sheet.py front|back [cols] [width] — contact sheet of all covers on one side."""
import glob, os, sys
from PIL import Image
side = sys.argv[1] if len(sys.argv) > 1 else 'front'
cols = int(sys.argv[2]) if len(sys.argv) > 2 else 5
w = int(sys.argv[3]) if len(sys.argv) > 3 else 320
h, gap = w * 8 // 5, 24
fs = sorted(glob.glob(os.path.join(side, '*.png')))
rows = (len(fs) + cols - 1) // cols
s = Image.new('RGB', (cols * w + (cols + 1) * gap, rows * h + (rows + 1) * gap), '#E9E6E1')
for i, f in enumerate(fs):
    s.paste(Image.open(f).convert('RGB').resize((w, h), Image.LANCZOS), (gap + (i % cols) * (w + gap), gap + (i // cols) * (h + gap)))
s.save(f'sheet-{side}.png')
print(len(fs), s.size)
