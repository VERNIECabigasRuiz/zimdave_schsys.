"""Extracts Doctrine entity and enum metadata from src/*/Domain/Model into JSON."""
import json
import re
import sys
from pathlib import Path

root = Path(sys.argv[1])
out = Path(sys.argv[2])
result = {"entities": [], "enums": []}

for f in sorted(root.glob("src/*/Domain/Model/*.php")):
    text = f.read_text(encoding="utf-8")
    module = f.parts[-4]
    doc = re.search(r"/\*\*(.*?)\*/\s*(?:#\[[^\n]*\]\s*)*(?:final\s+|abstract\s+|readonly\s+)*(?:class|enum)\s", text, re.S)
    desc = " ".join(l.strip(" *") for l in doc.group(1).splitlines()).strip() if doc else ""
    em = re.search(r"\benum\s+(\w+)(?:\s*:\s*(\w+))?", text)
    if em and "#[ORM\\Entity" not in text:
        cases = re.findall(r"case\s+(\w+)(?:\s*=\s*'([^']*)')?;", text)
        result["enums"].append({"module": module, "name": em.group(1), "desc": desc,
                                "cases": [{"name": c, "value": v} for c, v in cases]})
        continue
    if "#[ORM\\Entity" not in text:
        result.setdefault("other", []).append(f"{module}/{f.stem}")
        continue
    cls = re.search(r"^(?:final\s+|abstract\s+)*class\s+(\w+)", text, re.M).group(1)
    table = re.search(r"#\[ORM\\Table\(name:\s*'([^']+)'", text)
    uniques = re.findall(r"#\[ORM\\UniqueConstraint\(([^\]]*)\)\]", text)
    indexes = re.findall(r"#\[ORM\\Index\(([^\]]*)\)\]", text)
    props = []
    # attribute block(s) followed by a property declaration
    for m in re.finditer(r"((?:\s*#\[[^\n]*\]\n)+)\s*(?:private|protected|public)\s+(?:readonly\s+)?(\??[\\\w|]+)\s+\$(\w+)", text):
        attrs, ptype, pname = m.group(1), m.group(2), m.group(3)
        if "ORM\\" not in attrs:
            continue
        p = {"name": pname, "type": ptype.lstrip("?\\"), "nullable_php": ptype.startswith("?"), "attrs": [a.strip() for a in attrs.strip().splitlines()]}
        rel = re.search(r"ORM\\(ManyToOne|OneToMany|OneToOne|ManyToMany)\b(\(([^\n]*)\))?", attrs)
        if rel:
            p["relation"] = rel.group(1)
            tgt = re.search(r"targetEntity:\s*([\\\w]+)::class", rel.group(3) or "")
            p["target"] = tgt.group(1).split("\\")[-1] if tgt else p["type"].split("\\")[-1]
            mb = re.search(r"mappedBy:\s*'(\w+)'", rel.group(3) or "")
            if mb:
                p["mappedBy"] = mb.group(1)
            jc = re.search(r"JoinColumn\(([^\n]*)\)", attrs)
            p["join"] = jc.group(1) if jc else ""
        col = re.search(r"ORM\\Column(\(([^\n]*)\))?", attrs)
        if col:
            p["column"] = col.group(2) or ""
        if "ORM\\Id" in attrs:
            p["id"] = True
        props.append(p)
    result["entities"].append({"module": module, "name": cls, "table": table.group(1) if table else None,
                               "desc": desc, "uniques": uniques, "indexes": indexes, "props": props})

out.parent.mkdir(parents=True, exist_ok=True)
out.write_bytes(json.dumps(result, indent=1).encode("utf-8"))
print(len(result["entities"]), "entities;", len(result["enums"]), "enums; other:", result.get("other"))
