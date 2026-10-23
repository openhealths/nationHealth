window.printSandboxedHtml = function (html) {
    document.querySelector('iframe[data-print-sandbox]')?.remove();

    const iframe = document.createElement('iframe');
    iframe.setAttribute('data-print-sandbox', '');
    iframe.setAttribute('aria-hidden', 'true');
    iframe.setAttribute('sandbox', 'allow-same-origin allow-modals');
    iframe.setAttribute('referrerpolicy', 'no-referrer');
    iframe.style.position = 'fixed';
    iframe.style.width = '1px';
    iframe.style.height = '1px';
    iframe.style.left = '-10000px';
    iframe.style.border = '0';

    iframe.addEventListener('load', function () {
        const printWindow = iframe.contentWindow;
        printWindow.addEventListener('afterprint', () => iframe.remove(), { once: true });

        try {
            printWindow.focus();
            printWindow.print();
        } catch (error) {
            iframe.remove();
            throw error;
        }
    }, { once: true });

    iframe.srcdoc = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Пам\'ятка</title>'
        + '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data: https:; style-src \'unsafe-inline\'; script-src \'none\'; frame-src \'none\'; connect-src \'none\'; object-src \'none\'; form-action \'none\'; base-uri \'none\'">'
        + '</head><body>' + String(html ?? '') + '</body></html>';

    document.body.appendChild(iframe);
};
