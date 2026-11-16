/**
 * Card import: paste screenshots instead of picking files.
 * - Ctrl/⌘+V anywhere on the page (desktop)
 * - the paste box (iOS/Android only offer "Paste" inside an editable field)
 * - "Paste from clipboard" button via the async Clipboard API (secure contexts only)
 * Pasted images replace the Livewire `screenshots` property (append = false), then extraction starts.
 * The window paste listener is bound with x-on:paste.window in the view so Alpine removes it with the form.
 */
export default () => ({
    busy: false,
    error: null,
    canReadClipboard: window.isSecureContext && !!navigator.clipboard?.read,

    handlePaste(event) {
        const files = [...(event.clipboardData?.files ?? [])].filter((f) => f.type.startsWith('image/'));
        if (files.length === 0) {
            return; // let normal text paste through (e.g. into other inputs)
        }
        event.preventDefault();
        this.send(files);
    },

    async readClipboard() {
        this.error = null;
        try {
            const files = [];
            for (const item of await navigator.clipboard.read()) {
                const type = item.types.find((t) => t.startsWith('image/'));
                if (type) {
                    const blob = await item.getType(type);
                    files.push(new File([blob], `pasted-${files.length + 1}.${type.split('/')[1]}`, { type }));
                }
            }
            if (files.length === 0) {
                this.error = 'There is no image on the clipboard.';
                return;
            }
            this.send(files);
        } catch {
            this.error = 'Clipboard access was blocked — paste with Ctrl/⌘+V or into the box instead.';
        }
    },

    send(files) {
        if (this.busy) {
            return;
        }
        this.error = null;
        this.busy = true;
        this.$wire.uploadMultiple(
            'screenshots',
            files,
            () => {
                this.busy = false;
                this.$wire.extract();
            },
            () => {
                this.busy = false;
                this.error = 'Upload failed — is the image larger than 5 MB?';
            },
            () => {},
            () => {
                this.busy = false;
            },
            false, // replace, don't append to previously uploaded screenshots
        );
    },
});
