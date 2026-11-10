"""Lists user-facing rule messages (sentences in quotes) per module and file."""
import re
import sys
from pathlib import Path

root = Path(sys.argv[1])
out = []
for f in sorted(root.glob("src/*/**/*.php")):
    if "DataFixtures" in f.parts or "Controller" in f.parts or "Form" in f.parts:
        continue
    t = f.read_text(encoding="utf-8")
    msgs = [m for m in re.findall(r"'([A-Z][^'\n]{20,}[.?!])'", t) if " " in m]
    if msgs:
        out.append(f"## {f.relative_to(root / 'src')}")
        out += [f"  - {m}" for m in dict.fromkeys(msgs)]
Path(sys.argv[2]).write_bytes("\n".join(out).encode("utf-8"))
print(len(out))
