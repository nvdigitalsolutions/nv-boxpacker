# FK USPS Optimizer BMAD Agent Definitions

> **Last reviewed:** July 2026. The BMAD agent definitions below are the FK USPS Optimizer-specific instantiation of the GSD × BMAD methodology — mapping standard BMAD roles to the WooCommerce shipping plugin's conventions, code patterns, and toolchain.

This directory contains the **BMAD (Breakthrough Method for Agile AI-Driven Development)** agent role definitions for the FK USPS Optimizer plugin development workflow. These are the **Agent-as-Code** configurations that enable consistent, specialized AI prompting across development sessions.

## Overview

FK USPS Optimizer uses a hybrid **GSD × BMAD** methodology for AI-assisted feature development. The GSD half provides context engineering and phase-loop governance; the BMAD half provides role specialization and handoff protocols.

Each agent definition (`.yaml` file) specifies a BMAD role with:
- **Persona** — role identity, communication style, domain expertise
- **Responsibilities** — which phases this agent leads
- **Critical rules** — non-negotiable requirements for this role
- **Context files** — which `.context/` files to load
- **Handoff criteria** — what must be true before passing work to the next agent

## Agent Roster

| File | BMAD Role | Phases |
|------|-----------|--------|
| `fk-usps-analyst.yaml` | Analyst (Mary) | 1 |
| `fk-usps-product-manager.yaml` | Product Manager (John) | 2 |
| `fk-usps-architect.yaml` | Architect (Winston) | 3 |
| `fk-usps-scrum-master.yaml` | Scrum Master (Bob) | 0, 4, 7, 9 |
| `fk-usps-developer.yaml` | Developer (Amelia) | 5 |
| `fk-usps-qa-engineer.yaml` | QA Engineer (Quinn) | 6, 8 |

## Teams

Predefined multi-agent team compositions are in the [`teams/`](teams/) subdirectory.

The default team definition is `teams/feature-development.yaml`, which supports **scale-adaptive** usage:

| Feature Size | Agents | Phases |
|-------------|--------|--------|
| Patch / Bug Fix | Developer + QA | 5, 6, 7 |
| Small Feature | Orchestrator, Developer, QA | 0, 4, 5, 6, 7, 9 |
| Medium Feature | Orchestrator, Researcher, Developer, QA | 0, 1, 2, 3, 4, 5, 6, 7, 9 |
| Major Feature | Full 6-agent team | 0–9 |

## How to Use Agent Definitions

When starting an AI development session, load the appropriate agent definition to establish consistent role behavior:

```
1. Open your AI tool (Claude Code, GitHub Copilot, etc.)
2. Reference the agent YAML: .bmad/agents/fk-usps-[role].yaml
3. Load the context files listed in the agent's context_files field
4. The agent definition sets the persona, responsibilities, and rules for the session
```

## Related Files

- [`teams/`](teams/) — Multi-agent team compositions
- [`.context/`](../.context/) — GSD context engineering files
- [`CLAUDE.md`](../CLAUDE.md) — Claude Code per-turn context
- [`AGENTS.md`](../AGENTS.md) — Agent inventory and coordination
