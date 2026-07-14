# `includes/{folder}/README.md` — Folder Context Template

> Copy this template into `includes/{your-folder}/README.md` and fill in the sections.
> Keep it under 100 lines. Last reviewed: July 2026.

---

## `{FolderName}`

<!-- One sentence: what does this folder contain? -->

### Purpose

<!-- 2-3 sentences: why does this folder exist? What problem does it solve? -->

### Public Surface

| File | Role |
|------|------|
| `class-{name}.php` | <!-- One-line description --> |

### Neighbors

| Folder | Relationship |
|--------|-------------|
| `../{other-folder}/` | <!-- How these folders interact --> |

### Context Files to Load

When editing files in this folder, also load:

- `.context/conventions.md` (always)
- `.context/security-checklist.md` (always)
- `.context/{relevant-subsystem}.md` (if applicable)

### Key Hooks

| Hook | Type | Purpose |
|------|------|---------|
| `fk_usps_optimizer_{name}` | filter/action | <!-- What it does --> |

### Testing

Tests live in `tests/Unit/{ClassName}Test.php`.
