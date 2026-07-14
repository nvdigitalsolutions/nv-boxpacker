# FK USPS Optimizer BMAD Teams

> These team definitions map BMAD roles to the FK USPS Optimizer development workflow.

This directory contains team composition definitions for the GSD × BMAD multi-agent workflow.

## Available Teams

| File | Description | Use When |
|------|-------------|----------|
| `feature-development.yaml` | Full GSD × BMAD team (6 agents, Phases 0–9) | Medium-to-major features and integrations |

## How Teams Work

A **team** definition maps BMAD agent roles to their responsibilities across phases. The Orchestrator (Scrum Master) delegates to specialists at each phase gate, enforcing quality before advancing.

## Scale-Adaptive Usage

Not every feature requires the full team. Choose based on complexity:

| Feature Size | Agents | Phases |
|-------------|--------|--------|
| **Patch / Bug Fix** | Developer + QA | 5, 6, 7 |
| **Small Feature** | Orchestrator, Developer, QA | 0, 4, 5, 6, 7, 9 |
| **Medium Feature** | Orchestrator, Researcher, Developer, QA | 0, 1, 2, 3, 4, 5, 6, 7, 9 |
| **Major Feature** | Full 6-agent team | 0–9 |

## Related Files

- `.bmad/agents/` — Individual agent role definitions
- `.context/` — GSD context files loaded by agents
- `CLAUDE.md` — Claude Code per-turn context
- `AGENTS.md` — Agent inventory and coordination
