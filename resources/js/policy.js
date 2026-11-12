/**
 * Beleidspagina (issue #42): tabs en "terug naar cao". Elke instelling heeft data-default met de cao-waarde.
 */
export default function policyForm() {
    return {
        tab: 'contract',
        dirty: false,

        init() {
            window.addEventListener('beforeunload', (e) => {
                if (this.dirty) e.preventDefault();
            });
        },

        set(input) {
            if (input.dataset.type === 'bool') input.checked = input.dataset.default === '1';
            else input.value = input.dataset.default;
            this.dirty = true;
        },

        reset(id) {
            const input = document.getElementById(id);
            if (input) this.set(input);
        },

        resetGroup(section) {
            section.querySelectorAll('[data-default]').forEach((input) => this.set(input));
        },

        resetAll() {
            this.$root.querySelectorAll('[data-default]').forEach((input) => this.set(input));
        },
    };
}
