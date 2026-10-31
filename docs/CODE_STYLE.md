# Code Style

Conventions for contributors. Link to a specific rule from code review when needed.

## Naming

- View file names: `kebab-case` (e.g. `add-confidant-person.blade.php`).
- Language keys: `snake_case`.
- Acronyms inside identifiers are treated as a single word — capitalize only the first letter:
  `Uuid`, `Id`, `Url`, `Html`. Write `legalEntityUuid` (not `legalEntityUUID`), `clientId` (not `clientID`).

## Livewire

### Authorization

Keep access rules in policies. In Livewire actions, check them with
`Auth::user()->cannot('ability', $modelOrClass)`, flash the translated denial
message, and return before side effects. Do not use `$this->authorize(...)`:
an authorization exception replaces the component's normal error feedback.
Do not remove authorization or duplicate policy rules in the component to avoid
the exception. Follow this convention when adding or changing authorization.

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

## Blade + Alpine.js

### Notifications

Flash translated messages through `session()->flash(...)`. Use `XMessage` for
both page-local session messages and layout notifications. Mount one layout
instance with `listenAsync => true` for existing `flashMessage` events; page
instances must not listen for those events. `FlashMessage` is a compatibility
name for the same implementation, not a second layout component.

Messages are consumed when rendered, including success after owner replacement
and logout. A redirect must keep its flash until the destination renders.
Do not add notification events or separate session/event toast renderers.

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
