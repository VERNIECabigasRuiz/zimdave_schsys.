"""Renders PDF pages to PNG contact sheets for visual review."""
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(tempfile.gettempdir()) / "zim-docs-build" / "pylib"))
import pypdfium2 as pdfium  # noqa: E402
from PIL import Image  # noqa: E402

pdf = pdfium.PdfDocument(sys.argv[1])
outdir = Path(sys.argv[2])
outdir.mkdir(exist_ok=True)
first, last = int(sys.argv[3]), min(int(sys.argv[4]), len(pdf))
print("pages", len(pdf))
imgs = [pdf[i].render(scale=0.9).to_pil() for i in range(first - 1, last)]
# 2 per sheet side by side
for k in range(0, len(imgs), 2):
    pair = imgs[k:k + 2]
    w = sum(i.width for i in pair)
    h = max(i.height for i in pair)
    sheet = Image.new("RGB", (w, h), "white")
    x = 0
    for i in pair:
        sheet.paste(i, (x, 0))
        x += i.width
    sheet.save(outdir / f"p{first + k:03d}.png")
