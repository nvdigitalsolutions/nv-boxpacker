# Context Engineering — FK USPS Optimizer

> These files provide AI coding agents with persistent knowledge about the codebase.
> Load only the files relevant to your current task to stay under the GSD 30% rule.

---

## Always Load

| File | Content |
|------|---------|
| [`conventions.md`](conventions.md) | Naming conventions, PHP compatibility, code style, build commands |
| [`security-checklist.md`](security-checklist.md) | Security requirements for all code changes |

---

## Load Per Task

| File | Load When |
|------|-----------|
| [`testing.md`](testing.md) | Writing or reviewing PHPUnit tests |

---

## Feature Context

Active features get a context file in [`active/`](active/) (created at Phase 0, archived to [`archive/`](archive/) at Phase 9).

Template: [`templates/active-feature-template.md`](templates/active-feature-template.md)

---

## Folder READMEs

PHP-bearing subdirectories under `includes/` should have a `README.md` declaring the folder's purpose, public surface, and neighbors. Template: [`templates/folder-readme-template.md`](templates/folder-readme-template.md).

---

## Templates

| Template | Use |
|----------|-----|
| [`templates/active-feature-template.md`](templates/active-feature-template.md) | New feature context files |
| [`templates/folder-readme-template.md`](templates/folder-readme-template.md) | New `includes/*/README.md` files |

---

## GSD 30% Rule

Context files should consume no more than 30% of the agent's context window. Load only what's needed for the current task. If you're writing a simple test, you don't need `security-checklist.md` in full — just follow the conventions you already know.
