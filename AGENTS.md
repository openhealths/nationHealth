# Repository instructions for coding agents

Read `docs/CODE_STYLE.md` before editing code and follow its conventions.

## Review feedback that must not recur

- In Livewire actions, do not introduce `$this->authorize(...)`. Use the
  project's `Auth::user()->cannot(...)` policy check, show a translated error
  with `session()->flash('error', ...)`, and return before API, database, or
  queue side effects. Keep permission rules in policies; never remove access
  checks or copy their logic into components to satisfy this convention.
- Before finishing, inspect added/changed authorization calls in the diff and
  verify both the allowed path and denial without side effects. Existing calls
  outside the task's scope are not a reason to repeat the pattern.
- Treat reviewer feedback as a repository convention when it applies generally;
  update these instructions or the linked style guide so later tasks see it.

## Focused changes

Prefer the smallest complete fix. Reuse existing behavior and keep domain logic
at its call site. Avoid unrelated cleanup, new abstractions, and changes to shared
components unless the task requires them. Before finishing, compare the full PR
diff against its base and remove incidental refactoring.
