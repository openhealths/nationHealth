window.printSandboxedHtml = function (html) {
    const printWindow = window.open('', '_blank', 'noopener,noreferrer');
    if (!printWindow) {
        return;
    }

    printWindow.opener = null;
    printWindow.document.open();
    printWindow.document.write(
        '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Пам\'ятка</title>'
        + '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data: https:; style-src \'unsafe-inline\'; script-src \'none\'; object-src \'none\'; base-uri \'none\'">'
        + '</head><body>'
        + String(html ?? '')
        + '</body></html>'
    );
    printWindow.document.close();
    printWindow.focus();
    window.setTimeout(function () {
        printWindow.print();
    }, 250);
};
