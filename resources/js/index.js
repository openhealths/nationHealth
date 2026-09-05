import focus from '@alpinejs/focus';
import mask from '@alpinejs/mask';
import ui from '@alpinejs/ui';

document.addEventListener('alpine:init', () => {
    window.Alpine.plugin(mask);
    window.Alpine.plugin(focus);
    window.Alpine.plugin(ui);
});
