# ERD and use case PDFs

Builds `../ZIM-School-ERD.pdf` and `../Use-Cases-*.pdf`. Needs `python3` and Google Chrome (headless, prints the PDFs; diagrams load Graphviz and Mermaid from jsdelivr). Build files go to `%TEMP%/zim-docs-build`.

Run from the repo root:

```
python3 docs/dev/erd-and-use-cases/generator/parse_entities.py . "$TEMP/zim-docs-build/entities.json"
python3 docs/dev/erd-and-use-cases/generator/build_erd.py docs/dev/erd-and-use-cases/ZIM-School-ERD.pdf .
python3 docs/dev/erd-and-use-cases/generator/build_uc.py docs/dev/erd-and-use-cases
```

- The ERD's tables, columns, keys and relationships come from the entities in `src/*/Domain/Model`, so rerunning picks up schema changes. The chapter texts (`NOTES`, `GROUPS`) and the overview links (`dot_erd.py`) are written by hand.
- The use cases are written by hand in `uc_*.py` (one file per area). Update the cards and views there when commands, routes, rules or messages change. `digest_usecases.py` and `rules.py` list the commands, routes and messages from `src` to compare against.
- `build_uc.py <out dir> uc_grading` rebuilds one area; add `overview` to rebuild the index.
- `render_pages.py <pdf> <out dir> <first> <last>` saves pages as images for checking (needs `pip install --target "$TEMP/zim-docs-build/pylib" pypdfium2 pillow`).
