import conversionList from './tools/conversions';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('conversionList', conversionList);
});

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}
