---
status: RESOLVED
opened: 2026-05-23
last_touched: 2026-05-23
resolved: 2026-05-23
owner: user
---

## Resolution

User clarified the agent rewrites were **intentional, hand-authored edits**, not silent agent drift. They were committed by the user as `0404ee4 feat(agents): rewrite project agents using 5-phase Agent Design Protocol`. The stashed copies were the same content, now obsolete and dropped. No action needed; thread closed.

---

## Original entry (kept for context)

# Concern — Project agents are self-rewriting their own files mid-task

## Signal

During sprint 01 task `01.04.00-factories-seeders` (and very likely earlier tasks too — the diff sizes are huge), the project-level agents in `.opencode/agents/` started modifying their own definition files while executing dispatched work.

Observed unsaved diffs at end of `01.04.00` QA dispatch:
- `.opencode/agents/qa.md` — `+251 / -82` lines (substantive rewrite: description, mode, tools, prompt)
- `.opencode/agents/tech-lead.md` — `+255 / -82` lines (similar rewrite)
- `.opencode/agents/coder-backend.md` — `+161 / -50` lines (similar rewrite)

All three live as stashes (not lost):
```
stash@{0}: coder-backend-self-edit-needs-review
stash@{1}: project-agent-self-edits-during-01.04-needs-review  (qa.md + tech-lead.md)
```

## Why this is a problem

1. **Silent drift** — agents rewrote their own behavioral specifications without any review gate. Future task runs will use whatever was last written, which the user never approved.
2. **Cross-task contamination** — edits made during 01.04 silently affect how the same agent behaves on 01.05 and beyond.
3. **Convention violation** — per global AGENTS.md "Domain Failure Patterns", agent file edits should go through `opencode-ecosystem-orchestrator → agent-designer`. The project has no project-level agent-designer; that's still no reason for agents to be their own editors.

## What was preserved

The stashes contain real upgrades (better descriptions, MCP tool wiring, more disciplined prompts). They are NOT junk — they look like the agent self-improving based on session experience. The question is whether to accept that mechanism or close it off.

## Decision points for user

1. **Accept-and-commit:** review each stash, cherry-pick into a `chore/agent-tuning-from-session-01` branch, commit normally. Allows the improvements through but introduces them as an explicit, reviewed change rather than silent drift.

2. **Reject-and-prevent:** drop all three stashes; tighten the agents' allowed-write permissions in `.opencode/agents/*.md` frontmatter so agents cannot edit files matching `.opencode/agents/**`. This restores the boundary.

3. **Investigate first:** read each stash diff to understand WHY each agent rewrote itself. If they're patching real bugs in their own prompts (e.g., wrong tool list, missing context), that's signal — file separate intentional improvements.

## Recommended next step

Investigate first (#3) on a quiet moment; then either #1 or #2. Do not leave the stashes dangling indefinitely.

## Stashes (do not drop without review)

```bash
git stash show -p stash@{0}  # coder-backend self-edit
git stash show -p stash@{1}  # qa + tech-lead self-edits
```

Stashes are local-only; if the working copy is moved or recloned before this is resolved, the edits are lost.
