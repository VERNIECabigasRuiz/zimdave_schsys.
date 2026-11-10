"""Shared HTML shell and PDF printing for the documentation set."""
import html
import subprocess
import tempfile
from pathlib import Path

CHROME = r"C:\Program Files\Google\Chrome\Application\chrome.exe"
# build files (HTML, browser profile, entities.json) go to a temp folder, never next to the docs
SCRATCH = Path(tempfile.gettempdir()) / "zim-docs-build"
SCRATCH.mkdir(exist_ok=True)

CSS = """
@page { size: A4; margin: 16mm 14mm 16mm 14mm; }
@page wide { size: A4 landscape; margin: 12mm; }
* { box-sizing: border-box; }
body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 10pt; color: #1d2733; line-height: 1.45; margin: 0; }
h1 { font-size: 22pt; color: #12355b; margin: 0 0 6pt; }
h2 { font-size: 15pt; color: #12355b; border-bottom: 2px solid #12355b; padding-bottom: 3pt; margin: 18pt 0 8pt; page-break-after: avoid; }
h3 { font-size: 12pt; color: #1f5a8a; margin: 14pt 0 6pt; page-break-after: avoid; }
h4 { font-size: 10.5pt; margin: 10pt 0 4pt; color: #24364a; page-break-after: avoid; }
p { margin: 4pt 0 6pt; }
code { font-family: Consolas, monospace; font-size: 8.8pt; background: #eef2f6; padding: 0 2pt; border-radius: 2pt; }
table { border-collapse: collapse; width: 100%; margin: 4pt 0 10pt; font-size: 8.8pt; }
th, td { border: 1px solid #c7d0da; padding: 3pt 5pt; vertical-align: top; text-align: left; }
th { background: #e3ebf3; color: #12355b; }
tr { page-break-inside: avoid; }
.cover { height: 250mm; display: flex; flex-direction: column; justify-content: center; page-break-after: always; }
.cover .sub { font-size: 13pt; color: #44576b; }
.cover .meta { margin-top: 30pt; font-size: 10pt; color: #5a6b7c; }
.page { page-break-before: always; }
.wide { page: wide; page-break-before: always; }
.diagram { text-align: center; margin: 6pt 0 10pt; page-break-inside: avoid; }
.diagram svg { width: auto; height: auto; max-width: 100%; max-height: 225mm; }
.wide .diagram svg { max-height: 140mm; }
.wide .diagram.overview svg { max-height: 122mm; }
.keep { page-break-inside: avoid; }
.diagram { margin-bottom: 2pt; }
.key { text-align: center; margin: 0 0 10pt; page-break-before: avoid; line-height: 2; }
.chip { display: inline-block; border: 1.5px solid; border-radius: 9pt; padding: 0 7pt; margin: 0 3pt; font-size: 8.5pt; font-weight: 600; line-height: 1.6; }
.chip .dot { display: inline-block; width: 7pt; height: 7pt; border-radius: 50%; margin-right: 4pt; vertical-align: -0.5pt; }
.diagram.short svg { max-height: 70mm; }
.note { background: #f3f7fb; border-left: 4px solid #1f5a8a; padding: 5pt 8pt; margin: 6pt 0 10pt; }
.warn { background: #fff7e8; border-left: 4px solid #c98a12; padding: 5pt 8pt; margin: 6pt 0 10pt; }
.uc { border: 1px solid #c7d0da; border-radius: 4pt; margin: 8pt 0 10pt; page-break-inside: avoid; }
.uc .head { background: #12355b; color: #fff; padding: 4pt 8pt; font-weight: 600; font-size: 10pt; }
.uc .head .id { color: #ffd479; margin-right: 6pt; }
.uc .body { padding: 4pt 8pt 6pt; }
.uc .body table { margin: 2pt 0 4pt; }
.uc .body th { width: 23%; background: #f3f6f9; }
.uc ol, .uc ul { margin: 2pt 0 2pt 16pt; padding: 0; }
.pill { display: inline-block; font-size: 8pt; padding: 0 5pt; border-radius: 8pt; background: #e3ebf3; color: #12355b; margin-right: 3pt; }
.toc li { margin: 2pt 0; }
.muted { color: #5a6b7c; }
.small { font-size: 8.5pt; }
"""

MERMAID = """
<script src="https://cdn.jsdelivr.net/npm/@viz-js/viz@3.29.0/dist/viz-global.js"></script>
<script src="https://cdn.jsdelivr.net/npm/mermaid@10.9.1/dist/mermaid.min.js"></script>
<script>
mermaid.initialize({ startOnLoad: false, theme: 'neutral', securityLevel: 'loose',
  flowchart: { useMaxWidth: true, htmlLabels: true, curve: 'basis' },
  sequence: { useMaxWidth: true } });
async function renderAll() {
  const graphs = document.querySelectorAll('pre.graphviz');
  if (graphs.length) {
    const viz = await Viz.instance();
    graphs.forEach(pre => pre.replaceWith(viz.renderSVGElement(pre.textContent)));
  }
  await mermaid.run({ querySelector: '.mermaid' });
  // give each diagram its natural size so CSS can scale it down to fit the page both ways
  document.querySelectorAll('.diagram svg').forEach(svg => {
    const vb = svg.viewBox.baseVal;
    svg.removeAttribute('style');
    svg.setAttribute('width', vb.width);
    svg.setAttribute('height', vb.height);
  });
  // a wide flow prints larger on a landscape page
  document.querySelectorAll('section.page .diagram.flow svg').forEach(svg => {
    const vb = svg.viewBox.baseVal;
    if (vb.width > vb.height * 1.6) svg.closest('section').className = 'wide';
  });
  // a tall diagram prints larger on a portrait page than on a landscape one
  document.querySelectorAll('section.wide').forEach(sec => {
    const svg = sec.querySelector('.diagram:not(.overview) svg');
    if (svg && svg.viewBox.baseVal.height > svg.viewBox.baseVal.width * 0.75) sec.className = 'page';
  });
  document.title = 'RENDERED';
}
renderAll().catch(e => { document.title = 'DIAGRAM-ERROR'; document.body.insertAdjacentHTML('afterbegin', '<pre>' + e + '</pre>'); });
</script>
"""


def esc(s):
    return html.escape(str(s), quote=False)


def diagram(src, extra=""):
    return f'<div class="diagram {extra}"><pre class="mermaid">{esc(src)}</pre></div>'


def graph(dot, extra=""):
    return f'<div class="diagram {extra}"><pre class="graphviz">{esc(dot)}</pre></div>'


def page(title, body):
    return (f"<!doctype html><html><head><meta charset='utf-8'><title>{esc(title)}</title>"
            f"<style>{CSS}</style></head><body>{body}{MERMAID}</body></html>")


def to_pdf(html_text, out_pdf: Path):
    # Chrome resolves a relative path against its own folder, so always hand it an absolute one
    out_pdf = Path(out_pdf).resolve()
    out_pdf.parent.mkdir(parents=True, exist_ok=True)
    before = out_pdf.stat().st_mtime if out_pdf.exists() else 0
    src = SCRATCH / "build" / (out_pdf.stem + ".html")
    src.parent.mkdir(exist_ok=True)
    src.write_bytes(html_text.encode("utf-8"))
    cmd = [CHROME, "--headless=new", "--disable-gpu", "--no-pdf-header-footer",
           "--run-all-compositor-stages-before-draw", "--virtual-time-budget=60000",
           f"--user-data-dir={SCRATCH / 'chrome-profile'}",
           f"--print-to-pdf={out_pdf}", src.as_uri()]
    dom = subprocess.run([CHROME, "--headless=new", "--disable-gpu", "--virtual-time-budget=60000",
                          f"--user-data-dir={SCRATCH / 'chrome-profile'}", "--dump-dom", src.as_uri()],
                         capture_output=True, text=True, encoding="utf-8", timeout=300).stdout
    wanted = html_text.count('class="mermaid"') + html_text.count('class="graphviz"')
    if wanted and ("<title>RENDERED</title>" not in dom or dom.count("<svg") < wanted):
        err = dom[dom.find("<pre>"):dom.find("<pre>") + 300] if "DIAGRAM-ERROR" in dom else ""
        raise SystemExit(f"Diagrams did not render in {src.name}: {dom.count('<svg')} of {wanted} {err}")
    print(f"{src.name}: {wanted} diagrams rendered")
    r = subprocess.run(cmd, capture_output=True, text=True, timeout=300)
    if r.returncode != 0 or not out_pdf.exists() or out_pdf.stat().st_mtime <= before:
        raise SystemExit(f"Chrome did not write a new {out_pdf}: {r.stderr[-2000:]}")
    return src
