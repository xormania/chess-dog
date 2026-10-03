import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['trigger', 'modal'];

    static values = {
        open: Boolean,
    };

    connect() {
        this.element.dataset.modalConnected = 'true';
        if (this.openValue) {
            this.open();
        } else {
            this.syncState();
        }
    }

    disconnect() {
        delete this.element.dataset.modalConnected;
    }

    beforeCache() {
        // Turbo snapshots clone markup, so preserve the closed, unconnected state.
        delete this.element.dataset.modalConnected;
        this.close();
    }

    open() {
        this.modalTarget.showModal();
        this.syncState();
    }

    closeOnClickOutside({ target, clientX, clientY }) {
        if (target !== this.modalTarget) {
            return;
        }

        // Native dialog backdrop clicks target the dialog, as do its padding clicks.
        const bounds = this.modalTarget.getBoundingClientRect();
        if (clientX < bounds.left || clientX > bounds.right || clientY < bounds.top || clientY > bounds.bottom) {
            this.close();
        }
    }

    close() {
        this.modalTarget.close();
        this.syncState();
    }

    syncState() {
        // A queued native close event may arrive after the dialog has reopened.
        const isOpen = this.modalTarget.open;
        this.modalTarget.setAttribute('aria-hidden', String(!isOpen));
        if (this.hasTriggerTarget) {
            this.triggerTarget.setAttribute('aria-expanded', String(isOpen));
        }
    }
}
