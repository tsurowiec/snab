// Client-side state for the Conversions transaction list: selection, totals
// and the left-swipe-to-toggle gesture (ported from ynab-euro's TransactionList.tsx).
export default (transactions) => ({
    transactions,
    checked: transactions.map((t) => t.id),
    touch: { x: 0, y: 0, dir: null, swiped: false },

    get allChecked() {
        return this.transactions.length > 0 && this.checked.length === this.transactions.length;
    },

    isChecked(id) {
        return this.checked.includes(id);
    },

    toggle(id) {
        this.checked = this.isChecked(id) ? this.checked.filter((c) => c !== id) : [...this.checked, id];
    },

    toggleAll() {
        this.checked = this.allChecked ? [] : this.transactions.map((t) => t.id);
    },

    get totals() {
        const selected = this.transactions.filter((t) => this.isChecked(t.id));
        return {
            convertCount: selected.length,
            ignoreCount: this.transactions.length - selected.length,
            totalOrig: selected.reduce((s, t) => s + t.amount, 0) / 1000,
            totalPln: selected.reduce((s, t) => s + t.convertedAmount, 0) / 1000,
        };
    },

    get buttonLabel() {
        const parts = [];
        if (this.totals.convertCount > 0) parts.push(`Convert ${this.totals.convertCount}`);
        if (this.totals.ignoreCount > 0) parts.push(`Ignore ${this.totals.ignoreCount}`);
        return parts.join(', ') || 'Nothing to do';
    },

    money(milliunits) {
        return (milliunits / 1000).toFixed(2);
    },

    onChange(id) {
        if (this.touch.swiped) {
            this.touch.swiped = false;
            return;
        }
        this.toggle(id);
    },

    touchStart(e) {
        this.touch = { x: e.touches[0].clientX, y: e.touches[0].clientY, dir: null, swiped: false };
    },

    touchMove(e, el) {
        const dx = e.touches[0].clientX - this.touch.x;
        const dy = e.touches[0].clientY - this.touch.y;

        if (!this.touch.dir) {
            if (Math.abs(dx) > 5 || Math.abs(dy) > 5) this.touch.dir = Math.abs(dx) > Math.abs(dy) ? 'h' : 'v';
            return;
        }
        if (this.touch.dir !== 'h') return;

        const absDx = Math.max(0, -dx); // only left swipe
        const offset = absDx / (1 + absDx / 80);
        el.style.transition = 'none';
        el.style.transform = `translateX(${-offset}px)`;
    },

    touchEnd(e, el, id) {
        el.style.transition = 'transform 0.25s ease';
        el.style.transform = 'translateX(0)';

        if (this.touch.dir !== 'h') return;

        const dx = e.changedTouches[0].clientX - this.touch.x;
        const dy = e.changedTouches[0].clientY - this.touch.y;
        if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) {
            this.touch.swiped = true;
            this.toggle(id);
        }
    },

    confirm() {
        navigator.vibrate?.(50);
        this.$flux.modal('confirm-conversion').close();
        this.$wire.convert(this.checked);
    },
});
