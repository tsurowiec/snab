import cardImportPaste from './tools/card-import';
import conversionList from './tools/conversions';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('conversionList', conversionList);
    window.Alpine.data('cardImportPaste', cardImportPaste);
});

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}
