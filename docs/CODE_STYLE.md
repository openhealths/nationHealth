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

## Repositories

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

## Livewire

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

For frontend-only changes, preserve runtime Livewire components and their existing
action arguments, authorization, validation and side effects. Keep local filters
and presentation-only modal state in Alpine; do not add server requests on input.
Status filters must use the status and processing state, independently of action
availability. Scrollable tables must contain page overflow and keep the first,
last and single-row dropdowns accessible after scrolling and Livewire updates.

Read-only address components must skip edit and suggestion-search watchers.
Keep their `entangle` and `x-model` bindings active so saved values still update.

When an Alpine.js expression spans multiple lines inside a Blade attribute, indent the expression body one level
**deeper** than the element's attributes, and align the closing `"` with the attribute block. Do not leave the body at 
the element's own indentation level.

**Correct** — the expression body is indented deeper, closing `"` aligned:

```html
<button @click.prevent="
            openModal = true;
            newAction = true;
            modalAction = new Action();
        "
        class="item-add my-5"
>
    {{ __('forms.add') }}
</button>
```

**Not this** — the body is under-indented and hard to scan:

```html
<button @click.prevent="
    openModal = true;
    newAction = true;
    modalAction = new Action();
"
class="item-add my-5"
>
    {{ __('forms.add') }}
</button>
```

### `x-data` objects

Inside a multi-line `x-data` object:

- Keep properties together as one block, without blank lines between them.
- Put a blank line before the first method and between methods (getters included).
- No blank line after the last method — close with `}"` right away.

**Correct** — properties grouped, methods separated by blank lines:

```html
<div
    x-data="{
        open: false,
        clientNotifications: [],

        addNotification(title, message) {
            this.clientNotifications.unshift({ title, message });
        },

        removeNotification(id) {
            this.clientNotifications = this.clientNotifications.filter((notification) => notification.id !== id);
        },

        get totalCount() {
            return this.clientNotifications.length;
        }
    }"
>
```

**Not this** — methods glued together and the body not indented deeper than the attribute:

```html
<div
    x-data="{
    open: false,
    clientNotifications: [],
    addNotification(title, message) {
        this.clientNotifications.unshift({ title, message });
    },
    removeNotification(id) {
        this.clientNotifications = this.clientNotifications.filter((notification) => notification.id !== id);
    }
}"
>
```

## Multi-line tag attributes

When an element's attributes are split across multiple lines:

- Put each attribute on its **own line**, starting on the line after the opening tag; indent them one level deeper than the tag.
- Put the closing `>` on its own line (aligned with the opening tag), not appended to the last attribute.

**Correct** — each attribute on its own line, closing `>` on its own line:

```html
<div
    x-show="open"
    x-cloak
    x-ref="panel"
    x-transition.origin.top.left
    @click.outside="close($refs.button)"
    :id="$id('dropdown-button')"
>
```

**Not this** — first attribute kept on the tag line:

```html
<div x-show="open"
     x-cloak
     x-ref="panel"
     x-transition.origin.top.left
     @click.outside="close($refs.button)"
     :id="$id('dropdown-button')"
>
```

**Not this** — closing `>` glued to the last attribute:

```html
<div class="record-inner-value text-[16px]"
     x-text="`${ action.code } - ${ dictionary[action.code] }`"></div>
```

The `>`-on-its-own-line rule above applies to **empty elements** (no text content) whose attributes already span multiple lines.

## Elements with text content

When an element has text content (or a Blade echo) and its attributes fit on a single line, keep the attributes and the opening `>` on the tag line, then put the content on its **own indented line** and the closing tag on its own line.

**Correct** — attributes on the tag line, content and closing tag each on their own line:

```html
<label for="providingCondition" class="label">
    {{ __('healthcare-services.providing_condition') }}
</label>
```

**Not this** — content glued to the opening `>`:

```html
<label for="providingCondition"
       class="label">{{ __('healthcare-services.providing_condition') }}</label>
```

**Not this** — pushing the `>` onto its own line just to un-glue the content (the `>`-on-its-own-line form is for empty elements only):

```html
<label for="providingCondition"
       class="label"
>{{ __('healthcare-services.providing_condition') }}</label>
```

## Repositories

Access repositories through the static `Repository::...()` entry points, rather
than `app(SomeRepository::class)`. Use `App\Repositories\Repository` for general
repositories and `App\Repositories\MedicalEvents\Repository` for medical events;
alias one import when a file needs both. Keep container resolution inside these
entry points so test bindings continue to work.

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

## Shared HTTP request quotas

Keep shared request admission and cooldown accounting in an injected limiter,
separate from HTTP transport and response parsing. Name endpoint groups for the
quota they share. Define methods, exact path patterns, request counts and window
durations together in configuration. Keep lock timings as constants. Explain
admission methods with plain request examples and state their return/waiting behavior.
Represent local capacity waits with a dedicated exception; do not fabricate an
upstream response. Count each actual HTTP attempt using the resolved URL and final
headers, including retries, rather than counting only calls to the client API.

Keep integration classes extensible: do not add `final` without a specific invariant
that requires it. Use documented `protected` hooks for behavior that an integrating
service may customize, such as endpoint grouping and quota-key construction.
