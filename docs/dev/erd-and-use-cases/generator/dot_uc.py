"""Colour-coded diagrams for the use case documents: UML-style use case maps (Graphviz) and themed flows (Mermaid)."""
import json
import re

from dot_erd import COLORS, GRAPH, TINTS, e

# line colour of each kind of actor
ACTOR_COLORS = {
    "Administrator": "#111827", "Principal": "#c2410c", "Subject teacher": "#0284c7", "Teacher": "#0284c7",
    "Adviser": "#059669", "Individual-plan teacher": "#c026d3", "Student": "#ca8a04",
    "Any signed-in user": "#64748b", "Principal / Teacher": "#0d9488", "Principal / Teacher (own records)": "#0d9488",
    "Principal / Administrator": "#c2410c", "Teacher as adviser": "#059669", "Teacher on Individual plan": "#c026d3",
}


def actor_color(label):
    return ACTOR_COLORS.get(label, "#334155")


def parse_map(src):
    """Reads the small flowchart language the maps are written in: actors ID(["x"]), use cases ID["x"], A & B --> C & D."""
    actors, cases, edges = {}, {}, []
    for raw in src.splitlines()[1:]:
        line = raw.strip()
        if not line:
            continue
        for nid, label in re.findall(r'(\w+)\(\["([^"]+)"\]\)', line):
            actors[nid] = label
        for nid, label in re.findall(r'(\w+)\["([^"]+)"\]', line):
            cases.setdefault(nid, label)
        dotted = re.search(r'-\.\s*"([^"]*)"\s*\.->', line)
        parts = re.split(r'-\.\s*"[^"]*"\s*\.->|-->', line)
        if len(parts) < 2:
            continue
        # drop the labels, leaving "A & B" on each side of the arrow
        bare = [re.sub(r'\(\["[^"]*"\]\)|\["[^"]*"\]', "", p) for p in parts]
        left, right = ([i.strip() for i in side.split("&") if i.strip()] for side in bare[:2])
        for a in left:
            for b in right:
                edges.append((a, b, dotted.group(1) if dotted else None))
    return actors, cases, edges


def light(hex_color, amount=0.16):
    """An opaque light tint of a colour (mixed with white)."""
    r, g, b = (int(hex_color[i:i + 2], 16) for i in (1, 3, 5))
    return "#" + "".join(f"{round(255 - (255 - v) * amount):02x}" for v in (r, g, b))


def map_dot(src, title, module_of_case):
    actors, cases, edges = parse_map(src)
    out = ["digraph G {", "  " + GRAPH.replace("nodesep=0.8, ranksep=1.1", "nodesep=0.22, ranksep=2.2"),
           "  rankdir=LR;", '  edge [arrowhead=none, penwidth=2];']
    for aid, label in actors.items():
        c = actor_color(label)
        out.append(f'  "{aid}" [shape=box, style="rounded,filled", fillcolor="{c}", color="{c}", fontcolor="white", '
                   f'fontsize=14, margin="0.25,0.15", label=<&#9679;<br/><b>{e(label)}</b>>];')
    out.append(f'  subgraph cluster_system {{ label=<<b>{e(title)}</b>>; fontsize=14; fontcolor="#12355b"; '
               'color="#94a3b8"; style="rounded"; penwidth=1.5; margin=16;')
    for cid, label in cases.items():
        m = module_of_case(cid)
        out.append(f'    "{cid}" [shape=ellipse, style=filled, fillcolor="{TINTS[m]}", color="{COLORS[m]}", '
                   f'penwidth=2, fontsize=12, margin="0.12,0.06", label="{e(label)}"];')
    out.append("  }")
    for a, b, label in edges:
        c = actor_color(actors.get(a, ""))
        extra = f', style=dashed, label=< <i>{e(label)}</i> >, fontcolor="{c}", fontsize=11' if label else ""
        out.append(f'  "{a}" -> "{b}" [color="{c}"{extra}];')
    out.append("}")
    used_actors = list(dict.fromkeys(actors.values()))
    used_mods = list(dict.fromkeys(module_of_case(c) for c in cases))
    return "\n".join(out), used_actors, used_mods


def actor_key(actors):
    chips = "".join(f"<span class='chip' style='border-color:{actor_color(a)};color:{actor_color(a)}'>"
                    f"<span class='dot' style='background:{actor_color(a)}'></span>{e(a)}</span>" for a in actors)
    return f"<div class='key'>{chips}<span class='muted small'>Line colour = the actor who starts it.</span></div>"


def state_dot(src, module):
    """Draws a Mermaid stateDiagram (transitions, [*] start/end, notes) with Graphviz in the module's colours."""
    c = COLORS[module]
    out = ["digraph G {", "  " + GRAPH.replace("nodesep=0.8, ranksep=1.1", "nodesep=0.9, ranksep=0.9"),
           f'  node [shape=box, style="rounded,filled", fontsize=15, margin="0.3,0.14", penwidth=2, color="{c}", fillcolor="{light(c)}"];',
           f'  edge [color="{c}", fontcolor="#111827", fontsize=12, arrowhead=vee, penwidth=2];']
    states, notes = [], []
    for raw in src.splitlines()[1:]:
        line = raw.strip()
        note = re.match(r"note \w+ of (\w+)\s*:\s*(.+)", line)
        if note:
            notes.append(note.groups())
            continue
        t = re.match(r"(\[\*\]|\w+)\s*-->\s*(\[\*\]|\w+)\s*(?::\s*(.+))?$", line)
        if not t:
            continue
        a, b, label = t.groups()
        a = "start" if a == "[*]" else a
        b = "finish" if b == "[*]" else b
        for s in (a, b):
            if s not in states:
                states.append(s)
        lab = f', label=< {e(label)} >' if label else ""
        out.append(f'  "{a}" -> "{b}" [{lab.lstrip(", ")}];')
    for s in states:
        if s == "start":
            out.append('  start [shape=circle, width=0.22, label="", style=filled, fillcolor="#111827", color="#111827"];')
        elif s == "finish":
            out.append('  finish [shape=doublecircle, width=0.18, label="", style=filled, fillcolor="#111827", color="#111827"];')
        else:
            out.append(f'  "{s}" [label=<<b>{e(s)}</b>>];')
    for i, (s, text) in enumerate(notes):
        out.append(f'  note{i} [shape=note, style=filled, fillcolor="#fff7e8", color="#c98a12", fontsize=12, label="{e(text)}"];')
        out.append(f'  "{s}" -> note{i} [style=dashed, color="#c98a12", arrowhead=none];')
        out.append(f'  {{ rank=same; "{s}"; note{i}; }}')
    out.append("}")
    return "\n".join(out)


def themed(src, module):
    """Prefixes a Mermaid flow with a theme in the module's colours and larger text."""
    c, tint = COLORS[module], light(COLORS[module])
    theme = {
        "theme": "base",
        "themeVariables": {
            "fontSize": "16px", "fontFamily": "Arial", "primaryColor": "#ffffff", "primaryBorderColor": c,
            "primaryTextColor": "#111827", "lineColor": c, "secondaryColor": "#f8fafc", "tertiaryColor": "#f8fafc",
            "clusterBkg": "#f8fafc", "edgeLabelBackground": "#ffffff",
            "actorBkg": tint, "actorBorder": c, "actorTextColor": "#111827", "actorLineColor": c,
            "signalColor": c, "signalTextColor": "#111827", "labelBoxBkgColor": tint, "labelBoxBorderColor": c,
            "labelTextColor": "#111827", "loopTextColor": "#111827", "noteBkgColor": "#fff7e8",
            "noteBorderColor": "#c98a12", "activationBkgColor": tint, "sequenceNumberColor": "#ffffff",
            "stateBkg": tint, "stateBorder": c, "transitionColor": c, "transitionLabelColor": "#111827",
            "labelColor": "#111827", "compositeBackground": "#f8fafc",
        },
        "flowchart": {"nodeSpacing": 45, "rankSpacing": 55, "curve": "basis", "htmlLabels": True},
        "sequence": {"actorFontSize": 15, "messageFontSize": 15, "noteFontSize": 14, "boxMargin": 12},
    }
    body = src
    if src.lstrip().startswith("flowchart"):
        body += f"\n    classDef default fill:{tint},stroke:{c},stroke-width:2px,color:#111827"
        body += f"\n    linkStyle default stroke:{c},stroke-width:2px"
    return f"%%{{init: {json.dumps(theme)}}}%%\n{body}"
