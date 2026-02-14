---
name: planning-mode
description: Interactive planning mode: when triggered, enter a persistent "planning" state where the agent and Ahmad brainstorm, outline plans, evaluate options, and iterate until Ahmad explicitly ends planning mode. Use when the user wants collaborative ideation, design, or stepwise planning without executing system changes.
---

# Planning Mode Skill

Purpose

Enable a session-level planning mode that stays active until the user ends it. While in planning mode, the agent should treat the conversation as ideation and planning only — no execution of system changes, installs, or external actions — and should focus on exploring alternatives, evaluating tradeoffs, and producing actionable plans and next steps.

System directive (to apply when the skill is loaded or used by a session):

```
System: Enter PLANNING MODE. Remain in planning mode for the lifetime of this session until Ahmad explicitly says "End planning mode" or an equivalent clear instruction. In planning mode, the agent must:
- Treat every user message as part of collaborative ideation and planning.
- Not perform any system changes, run commands, install packages, or call external tools.
- Provide concise options, tradeoffs, risks, and stepwise plans with estimates (time, complexity, prerequisites).
- Ask clarifying questions only to refine options when necessary for producing valid plans (keep questions minimal).
- When asked, convert selected plans into concrete implementation steps, checklists, or drafts, but do not execute them.
- Confirm understanding and next steps when the user selects an option.

End planning mode only when the user explicitly says "End planning mode", "Exit planning mode", or similar clear instruction.
```

Usage

- Trigger this skill by asking the agent to "Enter planning mode" or by invoking the skill explicitly.
- Use the session for brainstorming, design, threat modeling, rollout plans, project timelines, and decision matrices.
- When you want to proceed to execution, say "End planning mode" and then spawn or instruct an execution-capable session.

Notes for operators

- This skill intentionally prevents execution during planning to keep ideation safe and separate from actions.
- If you want a planning session that can later be converted to an execution session, spawn a separate execution-agent session after ending planning mode and pass the chosen plan as input.

Examples

- "Enter planning mode: we need a migration plan for service X."
- "In planning mode, give me three rollout strategies with risk assessments."
- "End planning mode and prepare the chosen plan for execution."
