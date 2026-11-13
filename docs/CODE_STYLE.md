# Code Style

Conventions for contributors. Link to a specific rule from code review when needed.

## Naming

- View file names: `kebab-case` (e.g. `add-confidant-person.blade.php`).
- Language keys: `snake_case`.
- Use camelCase for PHP variables, properties, and Eloquent attribute access (e.g. `$link->linkableType`).
  Ensure the model supports camelCase access with `HasCamelCasing`; keep database column names and external payload keys
  in their required format. If an attribute name collides with a relationship or scope, use an explicit
  `getAttribute('snake_case')` read.
- Acronyms inside identifiers are treated as a single word — capitalize only the first letter:
  `Uuid`, `Id`, `Url`, `Html`. Write `legalEntityUuid` (not `legalEntityUUID`), `clientId` (not `clientID`).
- Import PHP classes with `use` instead of repeating fully qualified names at call sites.
- Use explicit parentheses for chained `??` fallbacks and nested conditional expressions.

## Class imports

Import classes with `use` and use their short names in code and type declarations,
including exceptions, static calls and `::class` references. Do not write fully
qualified class names inline. Resolve name collisions with explicit aliases.
Before finishing a change, inspect every PHP and Blade file touched by the PR,
including existing lines, for inline class references. This includes traits,
attributes, exceptions, type declarations, static calls and `::class`. Use Blade
`@use` for template references. A clean added-lines-only scan is insufficient.

## Repositories

Access repositories through the existing static `Repository::...()` entry points.
Use `App\Repositories\Repository` as `Repository` for general repositories, for
example `Repository::carePlan()`; do not alias it as `BaseRepository`. When both
entry points are needed, import the medical-event entry point as
`App\Repositories\MedicalEvents\Repository as MedicalEventsRepository`.
A file using only medical-event repositories may import their entry point as
`Repository`. Do not instantiate or resolve individual repositories at call sites
with `new`, `app()` or `resolve()`. Keep container resolution inside the entry
points so test bindings continue to work.

Keep reusable database lookup and selection logic in repositories or model scopes.
Livewire components and their traits should delegate these lookups to the repository.
Repositories should return results and reasons for refusal; the calling component or trait
forms user-facing feedback and flashes it to the session. Do not flash messages from repositories.
When a lookup must return a model, declare that model as its return type and throw a dedicated
exception for an unavailable result instead of returning an array containing a nullable model
and an error. Catch that exception in the calling component or trait to show the refusal reason.
Keep refusal message construction in the dedicated exception: define error types
as class constants, pass the type and any message context to the constructor, and select the
translated message with `match`. The constructor may delegate default message selection to
an instance method for readability. Allow an optional custom message to override the default.
Default to a general message when the error type is omitted or unrecognized, and allow
a custom message without specifying a type.
Instantiate exceptions with `new`; do not add static factory methods.
Keep additional error details limited to the types supplied by actual callers; do not add
support for underlying exceptions unless a caller passes them.
Keep database lookups and state checks in the repository.

## Shared values

Use existing enums for domain values such as authentication methods and approval statuses.
Use enums or named constants for shared filter values, resource codes and workflow steps;
reference the same values from PHP and Blade instead of duplicating string literals.

## eHealth response contracts

Validate eHealth responses against the official endpoint documentation at the API
boundary, and consume them through the configured response validator. Do not use
raw `getData()` results to map or persist records, change UI state, or decide that
an operation succeeded. Delegating client methods must preserve validation.
Test malformed responses and keep pending operations unconfirmed; do not weaken
the documented contract to make fixtures pass.
Document non-obvious integration branches with English comments explaining
why the branch is needed and when a result becomes confirmed.

## Livewire

### User feedback

For action success, error, warning, and info messages, use Laravel session flash
and the existing `x-message` component. Follow the surrounding PHP convention:

```php
Session::flash('success', __('referrals.draft_saved'));
session()->flash('error', __('care-plan.document_context_unavailable'));
```

Existing wrappers such as `flashUserError()` are appropriate when they delegate
to session flash. Keep messages in translations and do not flash from repositories.
Do not use notification dispatch/browser events, custom JavaScript toasts, or a
redirect/reload solely to display feedback.

For feedback in the same Livewire response, use the established consumer:

```blade
<livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
```

When adding this consumer to a patient page, set `:showLegacyMessages="false"`
on `x-layouts.patient` to avoid duplicate consumers. Inspect the current component
and layout first; reuse their message consumption and refresh behavior. Flash
once when an action changes state, not on every poll/render. Report asynchronous
success only once the required result is confirmed.

Keep field errors visible in the relevant form. Keep the KEP modal open when
validation fails on its signing fields. If a service raises a validation error
whose key is not displayed in the form, also show the refusal through session
flash. Verification must check user-visible feedback, not just `assertHasErrors()`.

### Authorization

Keep access rules in policies. In Livewire actions, check them with
`Auth::user()->cannot('ability', $modelOrClass)`, flash the translated denial
message, and return before side effects. Do not use `$this->authorize(...)`:
an authorization exception replaces the component's normal error feedback.
Do not remove authorization or duplicate policy rules in the component to avoid
the exception. Follow this convention when adding or changing authorization.

For approval employee selection, require an active linked employee in the current legal entity
whose own role is available in the current login and grants `approval:create`.
Combined token scopes do not grant that ability to every employee type.
Keep this selection separate from `activeDoctorEmployee()` and explain it at the approval call site.
Keep `syncPersonData` independent of approval creation: it also controls reading data with an
existing verified approval and the visibility of patient card sections.
Check approval creation permission only when a new approval is needed, before selecting its
employee or fetching authentication methods. Only an authorized creator with a missing employee
record should see a request to sync employees; a missing creation role must result in a denial.

Public properties of a Livewire component must be **primitive types only** (`string`,`int`, `bool`, `float`, `array`) 
— and enums are allowed. Do **not** type a public property as an Eloquent model.

**Correct:**

```php
public int $carePlanId;
public string $status;
public CarePlanStatus $status; // enum is fine
```

**Not this:**

```php
public CarePlan $carePlan; // Eloquent model as a public property
```

Resolve the model from its id inside the component method that needs it instead.

If a view uses a protected model, restore it on every Livewire request (for
example, in `boot()`). Initialization in `mount()` or a single action does not
cover later property updates, refreshes, or other actions.

### Synchronization

After synchronization, refresh displayed form values from the saved records.
Preserve the existing filtering and persistence rules.
For LegalEntity optional sections, do not restore data discarded by `filterUnprovidedFields()` from the raw eHealth response; update values and visibility from the saved data instead.
Keep form state updates in the form object; keep API calls, persistence, and
authorization in the component.
Before repeating asynchronous creation, check for a pending request for the same entity
and recipient, including records without a remote UUID or expiry. Show a translated waiting
message while it is pending and allow a new request after failure. Disabling a button only
during a Livewire request does not prevent duplicates while background processing continues.

## Blade + Alpine.js

Blade and Alpine.js conventions, including formatting, are in [BLADE_STYLE.md](BLADE_STYLE.md).

## Dictionary choices

Keep domain rules (such as employee role mappings) in the domain form, rather
than adding a dictionary collection over an existing data source. UI choices and
server validation must use the same form method. Keep computed option lists out
of public Livewire state.

Use `dictionary()->basics()->byName()` for active choices. Resolve historical
labels separately with `getMultipleFormatted($names, onlyActive: false)`; do not
use label dictionaries as selectable options.

For shared Blade components, prefer a single reactive options provider over
separate watcher props. Keep domain-specific reactions in the calling form.

## API error translations

Keep resource-specific error descriptions in the resource translator's `messages()` map.
Use the existing field-label translations and common error formatter rather than adding
field-specific translation hooks. Carry the originating API translator with its response
so jobs can translate business errors without selecting a translator from the model type.
