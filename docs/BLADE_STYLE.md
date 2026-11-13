# Blade and Alpine.js Style

Conventions for Blade views and Alpine.js. General rules are in [CODE_STYLE.md](CODE_STYLE.md).

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
