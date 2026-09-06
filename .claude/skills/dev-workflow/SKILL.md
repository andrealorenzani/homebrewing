---
name: dev-workflow
description: Trigger for any code change in this repo. Invoke this whenever a feature, fix, or other code change needs to happen. It hands the request to the coordinator agent, which handles analysis, planning, implementation, and documentation, and waits for it to finish.
---

Invoke the `coordinator` subagent (Agent tool, `subagent_type: "coordinator"`) with the user's request passed through verbatim, plus the current datetime (via `date -u +"%Y-%m-%dT%H:%M:%SZ"`) so it can be logged accurately later.

Do nothing else here — no analysis, no planning, no editing files yourself. This skill is intentionally a thin trigger; all real logic lives in `.claude/agents/coordinator.md` and the agents it drives.

Once the coordinator returns, relay its final summary to the user.
