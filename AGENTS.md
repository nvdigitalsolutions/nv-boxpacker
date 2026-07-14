# AI Agents — FK USPS Optimizer

> This document is the single source of truth for every AI coding agent that operates in this repository. It describes who they are, what they can do, which context files they load, and how they coordinate.
>
> Last reviewed: **July 2026** · Version: **1.0**

### Related Files

| File | Purpose |
|------|---------|
| [`CLAUDE.md`](CLAUDE.md) | Claude Code per-turn context (naming, security, architecture) |
| [`.context/conventions.md`](.context/conventions.md) | Naming conventions, PHP compat, code style |
| [`.context/security-checklist.md`](.context/security-checklist.md) | Security requirements for all code changes |
| [`.context/testing.md`](.context/testing.md) | PHPUnit test conventions |
| [`.github/copilot-instructions.md`](.github/copilot-instructions.md) | GitHub Copilot repo-level instructions |

---

## 1. Agent Inventory

### External AI Coding Agents

| Agent | Provider | Context File | Trigger | Scope |
|-------|----------|-------------|---------|-------|
| **Claude Code** | Anthropic | [`CLAUDE.md`](CLAUDE.md) | Manual invocation | Full codebase — code generation, review, refactoring, docs |
| **GitHub Copilot** | GitHub / OpenAI | [`.github/copilot-instructions.md`](.github/copilot-instructions.md) | IDE completions, Copilot Chat, PR reviews | Inline suggestions, chat Q&A, PR summaries |
| **GitHub Custom Agents** | GitHub | [`.github/agents/*.agent.md`](.github/agents/) | Auto-discovered by Copilot Coding Agent | Role-specific agents — see each `*.agent.md` for scope |

### Internal BMAD Agents (GSD × BMAD Workflow)

The plugin includes BMAD agent definitions for structured feature development following the 10-phase GSD × BMAD methodology. These agents are defined as YAML configurations that establish consistent role behavior across AI development sessions.

| Agent ID | BMAD Role | Phases | YAML Definition |
|----------|-----------|--------|-----------------|
| `fk-usps-analyst` | Analyst (Mary) | 1 | [`.bmad/agents/fk-usps-analyst.yaml`](.bmad/agents/fk-usps-analyst.yaml) |
| `fk-usps-product-manager` | Product Manager (John) | 2 | [`.bmad/agents/fk-usps-product-manager.yaml`](.bmad/agents/fk-usps-product-manager.yaml) |
| `fk-usps-architect` | Architect (Winston) | 3 | [`.bmad/agents/fk-usps-architect.yaml`](.bmad/agents/fk-usps-architect.yaml) |
| `fk-usps-scrum-master` | Scrum Master (Bob) | 0, 4, 7, 9 | [`.bmad/agents/fk-usps-scrum-master.yaml`](.bmad/agents/fk-usps-scrum-master.yaml) |
| `fk-usps-developer` | Developer (Amelia) | 5 | [`.bmad/agents/fk-usps-developer.yaml`](.bmad/agents/fk-usps-developer.yaml) |
| `fk-usps-qa-engineer` | QA Engineer (Quinn) | 6, 8 | [`.bmad/agents/fk-usps-qa-engineer.yaml`](.bmad/agents/fk-usps-qa-engineer.yaml) |

Team composition and scale-adaptive usage are defined in [`.bmad/teams/feature-development.yaml`](.bmad/teams/feature-development.yaml).

### Agent Skills (Zed Editor)

The `.agents/skills/` directory contains WordPress-focused agent skills — portable instruction packages that the Zed coding agent can load on demand. These are not AI agents themselves; they are reusable instruction sets triggered by the context of a coding task.

| Category | Skills |
|----------|--------|
| WordPress APIs | wp-abilities-api, wp-rest-api, wp-html-api |
| WordPress Plugins | wp-plugin-architecture, wp-plugin-bootstrap, wp-plugin-lifecycle, wp-plugin-options-storage, wp-plugin-dto, wp-plugin-presenter, wp-plugin-hooks, wp-plugin-rewrite-rules, wp-plugin-cron, wp-plugin-assets-loading |
| WordPress Background | wp-action-scheduler |
| WordPress Security | wp-security-audit, wp-security-deep, wp-security-secrets |
| WordPress Text | wp-i18n-audit, wp-utf8-text |
| WordPress Performance | wp-query-cache |

---

## 2. Context-Loading Strategy

All agents follow the **GSD 30% Rule**: context files should consume no more than 30% of the agent's context window.

### Base context (always loaded)

| File | Content |
|------|---------|
| [`.context/conventions.md`](.context/conventions.md) | Naming conventions, PHP compatibility, code style, build commands |
| [`.context/security-checklist.md`](.context/security-checklist.md) | Security requirements for all code changes |

### Subsystem context (loaded per task)

| File | Load When |
|------|-----------|
| [`.context/testing.md`](.context/testing.md) | Writing or reviewing PHPUnit tests |

### Feature context (loaded per active feature)

Active features get a context file in `.context/active/[feature].md`. These are created at Phase 0, updated during development, and archived to `.context/archive/` at Phase 9.

Template: [`.context/templates/active-feature-template.md`](.context/templates/active-feature-template.md)

---

## 3. Agent Capabilities and Limitations

### Claude Code

| Aspect | Details |
|--------|---------|
| **Strengths** | Full codebase reasoning, multi-file refactoring, test generation, architecture analysis |
| **Context file** | `CLAUDE.md` — loaded automatically every turn |
| **Limitations** | Cannot push to git directly |
| **PHP compat** | Must target PHP 8.0+ |
| **Security** | Must follow all rules in `.context/security-checklist.md` |

### GitHub Copilot

| Aspect | Details |
|--------|---------|
| **Strengths** | Fast inline completions, Copilot Chat for Q&A, PR review summaries |
| **Context file** | `.github/copilot-instructions.md` — loaded per Copilot session |
| **Limitations** | Shorter context window; best for single-file or small-scope changes |
| **Configuration** | Follows WPCS for PHP |

### BMAD Agents

| Aspect | Details |
|--------|---------|
| **Strengths** | Structured 10-phase workflow with phase gates; specialized per role |
| **Context files** | Each agent loads role-specific context from `.bmad/agents/*.yaml` |
| **Limitations** | Best for medium-to-major features; overhead for patches/bug fixes |
| **Coordination** | Scrum Master delegates to specialists at each phase gate |

---

## 4. Inter-Agent Coordination

### Avoiding duplication

When multiple agents work on the same repository:

1. **Check recent commits** before starting work — another agent may have already addressed part of the task.
2. **Use conventional commits** (`feat(scope):`, `fix(scope):`, `docs(scope):`) so other agents can parse intent from `git log`.
3. **Do not modify files another agent is actively editing** in the same PR.

### Handoff protocol

The BMAD workflow defines explicit phase gates (documented in [`.bmad/teams/feature-development.yaml`](.bmad/teams/feature-development.yaml)):

| Transition | Gate Criteria |
|------------|---------------|
| Phase 0 → 1 | Base context loaded, feature context initialized |
| Phase 1 → 2 | Project Brief complete and approved |
| Phase 2 → 3 | PRD approved with all acceptance criteria |
| Phase 3 → 4 | Architecture Specification reviewed |
| Phase 4 → 5 | All stories broken down and sequenced |
| Phase 5 → 6 | All story code committed with atomic commits |
| Phase 6 → 7 | All acceptance criteria verified; lint + tests pass |
| Phase 7 → 8 | Version bumped, CHANGELOG updated, Git tag + Release published |
| Phase 8 → 9 | No new errors in first 48 hours |

### Scale-adaptive usage

Not every change requires the full 10-phase workflow:

| Change Size | Agents Involved | Phases |
|------------|----------------|--------|
| **Patch / Bug Fix** | Developer + QA | 5, 6, 7 |
| **Small Feature** | Scrum Master, Developer, QA | 0, 4, 5, 6, 7, 9 |
| **Major Feature** | Full 6-agent team | 0–9 |

---

## 5. Security and Privacy

### Data handling

- **No secrets in prompts.** API keys, tokens, and credentials must never appear in agent context files or commit messages.
- **No PII in agent output.** Sanitize any customer data before including it in agent-visible context.
- **Credential storage.** ShipEngine and ShipStation API keys are stored in a single WordPress option (`fk_usps_optimizer_settings`) — never in source code.

### Agent permissions

| Agent | Can Write Code | Can Push to Git | Can Access Secrets |
|-------|---------------|----------------|-------------------|
| Claude Code | ✅ | Via human commit | ❌ |
| GitHub Copilot | ✅ (suggestions) | Via human commit | ❌ |
| BMAD Agents | ✅ (via developer agent) | Via human commit | ❌ |

---

## 6. Updating Agent Configuration

### When to update which file

| Change | Files to Update |
|--------|----------------|
| New naming convention or security rule | `CLAUDE.md`, `.github/copilot-instructions.md`, `.context/conventions.md` |
| New filter/action hook | `CLAUDE.md` (hooks table), `.context/conventions.md` |
| New BMAD agent or workflow change | `.bmad/agents/*.yaml`, `AGENTS.md`, `.bmad/teams/feature-development.yaml` |
| New subsystem context | `.context/`, `AGENTS.md` (context-loading table) |
| New external AI agent | `AGENTS.md` (agent inventory) |
| New or changed GitHub Custom Agent | `.github/agents/*.agent.md`, `AGENTS.md` (agent inventory) |

### Review cadence

These files should be reviewed whenever:
- A new AI coding agent is adopted
- The project's coding standards change materially
- A major version is released
- Quarterly, at minimum

---

## 7. References

- [GSD × BMAD Methodology](.bmad/README.md)
- [Context Engineering Files](.context/README.md)
- [BMAD Agent Definitions](.bmad/README.md)
- [FK USPS Optimizer README](README.md)
