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
        }
    }

    disconnect() {
        delete this.element.dataset.modalConnected;
    }

    beforeCache() {
        // Turbo snapshots clone markup, so preserve the closed, unconnected state.
        delete this.element.dataset.modalConnected;
        if (this.modalTarget.open) {
            this.close();
        }
    }

    open() {
        this.modalTarget.showModal();
        this.modalTarget.setAttribute('aria-hidden', 'false');
        if (this.hasTriggerTarget) {
            this.triggerTarget.setAttribute('aria-expanded', 'true');
        }
    }

    closeOnClickOutside({ target }) {
        if (target === this.modalTarget) {
            this.close();
        }
    }

    close() {
        this.modalTarget.close();
        this.modalTarget.setAttribute('aria-hidden', 'true');
        if (this.hasTriggerTarget) {
            this.triggerTarget.setAttribute('aria-expanded', 'false');
        }
    }
}
