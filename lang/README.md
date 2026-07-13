# UI translations

Flat JSON catalogs, one file per language (`de.json`, `fr.json`, …).
English is the source language and needs no file.

- Left side: the English source text as it appears in the code.
- Right side: your translation. `%s`/`%d` are value placeholders;
  positional forms like `%1$s` may be reordered in the translation.
- Missing entries (or a broken file) simply fall back to English —
  nothing breaks.

**Add a new language:** copy `de.json` to `<iso-code>.json`, set the
`"__language__"` entry to the language's own name (e.g. `"Français"` —
it becomes the label in the settings) and translate the right-hand
values. The language appears in the settings automatically once the
file exists. Check completeness with:

```bash
php bin/check_translations.php
```

A few entries (the IMDb import help) intentionally contain simple HTML
markup — keep the tags intact when translating.
