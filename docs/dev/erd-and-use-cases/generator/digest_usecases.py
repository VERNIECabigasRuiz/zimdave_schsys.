"""Builds a per-module digest of use cases, queries, routes and rule messages."""
import re
import sys
from pathlib import Path

root = Path(sys.argv[1])
outdir = Path(sys.argv[2])
outdir.mkdir(exist_ok=True)


def docblock(text, kw):
    m = re.search(r"/\*\*(.*?)\*/\s*(?:#\[[^\n]*\]\s*)*(?:final\s+|abstract\s+|readonly\s+)*" + kw + r"\s", text, re.S)
    return " ".join(l.strip(" *") for l in m.group(1).splitlines()).strip() if m else ""


ctrl_text = {p: p.read_text(encoding="utf-8") for p in root.glob("src/*/Presentation/Controller/**/*.php")}


def routes_using(name):
    found = []
    for p, t in ctrl_text.items():
        if name not in t:
            continue
        prefix = re.search(r"^#\[Route\('([^']*)'", t, re.M)
        prefix = prefix.group(1) if prefix else ""
        cls_grant = re.findall(r"^#\[IsGranted\(([^\n]*)\)\]", t, re.M)
        # split into methods
        for m in re.finditer(r"((?:    #\[[^\n]*\]\n|    /\*\*.*?\*/\n)*)    public function (\w+)\((.*?)\n    \}", t, re.S):
            if name in m.group(0):
                attrs = m.group(1)
                r = re.search(r"#\[Route\('([^']*)', name: '(\w+)'(?:.*?methods: \[([^\]]*)\])?", attrs)
                g = re.findall(r"#\[IsGranted\(([^\n]*)\)\]", attrs)
                found.append(f"{p.stem}::{m.group(2)} " + (f"{r.group(2)} {r.group(3) or ''} {prefix}{r.group(1)}" if r else "") + f" grants={cls_grant + g}")
    return found


for mod in sorted(p.name for p in (root / "src").iterdir() if p.is_dir()):
    base = root / "src" / mod
    lines = [f"# MODULE {mod}"]
    for kind in ("UseCase", "Query"):
        for c in sorted((base / "Application" / kind / "Command").glob("*.php")) if (base / "Application" / kind / "Command").exists() else []:
            t = c.read_text(encoding="utf-8")
            if f"implements {kind}" not in t and not (base / "Application" / kind / "Handler" / (c.stem.replace("Command", "Handler") + ".php")).exists():
                continue
            lines.append(f"\n## {kind} {c.stem}: {docblock(t, 'class')}")
            props = re.findall(r"public (?:readonly )?\??[\w\\|]+ \$(\w+)", t)
            lines.append("  fields: " + ", ".join(props))
            asserts = re.findall(r"#\[Assert\\(\w+)\(([^\n]*)\)\]", t)
            msgs = [a[1] for a in asserts if "message" in a[1]]
            if msgs:
                lines.append("  validation: " + " | ".join(re.findall(r"message: '([^']*)'", " ".join(msgs))))
            h = base / "Application" / kind / "Handler" / (c.stem.replace("Command", "Handler") + ".php")
            if h.exists():
                ht = h.read_text(encoding="utf-8")
                body = re.search(r"public function __invoke\(.*?\n    \}", ht, re.S)
                if body:
                    calls = re.findall(r"\$this->(\w+)->(\w+)\(([^;\n]{0,80})", body.group(0))
                    lines.append("  handler calls: " + "; ".join(f"{a}.{b}({c[:50]})" for a, b, c in calls))
                    thrown = re.findall(r"throw new (\w+)\(([^;]{0,120})", body.group(0))
                    if thrown:
                        lines.append("  throws: " + "; ".join(f"{a}({b})" for a, b in thrown))
            for r in routes_using(c.stem):
                lines.append("  route: " + r)
    # policies, exceptions, service messages
    for p in sorted(base.glob("**/*.php")):
        t = p.read_text(encoding="utf-8")
        if p.parent.name == "Exception" or "Policy" in p.stem or p.parent.name == "Service":
            consts = re.findall(r"const (\w+) = '([^']{8,})'", t)
            news = re.findall(r"new (\w*Exception)\(\s*'([^']+)'", t)
            parent_msgs = re.findall(r"parent::__construct\(\s*'([^']+)'", t)
            if consts or news or parent_msgs:
                lines.append(f"\n### {p.relative_to(base)}: {docblock(t, 'class')}")
                for a, b in consts:
                    lines.append(f"  const {a} = {b}")
                for a, b in news:
                    lines.append(f"  {a}: {b}")
                for b in parent_msgs:
                    lines.append(f"  msg: {b}")
    (outdir / f"{mod}.txt").write_bytes("\n".join(lines).encode("utf-8"))
    print(mod, len(lines))
