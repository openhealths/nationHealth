# Spec Kit (project overlay)

This repository follows [GitHub Spec Kit](https://github.com/github/spec-kit) structure.

CLI bootstrap was unavailable in the agent environment (`uv`/`specify` install blocked). Artifacts were authored to match Spec Kit templates:

- `.specify/memory/constitution.md` — project principles for ESОЗ compliance work
- `specs/001-esoz-compliance-employee-party-contracts/` — feature spec, plan, tasks, checklist, research, quickstart

When CLI is available locally:

```bash
uv tool install specify-cli --from git+https://github.com/github/spec-kit.git
specify init . --here   # if not already initialized
# then use /speckit.* skills against existing specs/
```
