export function normalizeReferralStatus(status = '') {
    const normalized = String(status).replaceAll('-', '_');
    return normalized === 'recalled' ? 'revoked' : normalized;
}

export function matchesReferral(referral, patient = '', statuses = []) {
    const identity = referral.identity ?? {};
    const name = [identity.last_name, identity.first_name, identity.second_name, referral.subject?.display]
        .filter(Boolean).join(' ').toLocaleLowerCase('uk');
    if (!name.includes(patient.trim().toLocaleLowerCase('uk'))) return false;

    const status = normalizeReferralStatus(referral.status);
    const inProgress = status === 'in_progress'
        || (['active', 'new'].includes(status)
            && normalizeReferralStatus(referral.program_processing_status) === 'in_progress');
    return statuses.length === 0 || statuses.some(selected =>
        normalizeReferralStatus(selected) === 'in_progress'
            ? inProgress
            : normalizeReferralStatus(selected) === status
    );
}

export function maskRequisition(value) {
    return (value.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 16).match(/.{1,4}/g) ?? []).join('-');
}

export function referralIndex(wire) {
    return {
        requisition: wire.entangle('requisition'),
        patient: '',
        status: [],
        showRecallModal: false,
        showErrorModal: false,
        pendingStatusReferral: null,
        statusExplanatoryLetter: '',
        errorReason: '',

        matchesReferral(referral) {
            return matchesReferral(referral, this.patient, this.status);
        },

        get visibleCount() {
            return wire.get('searchResults').filter(referral => this.matchesReferral(referral)).length;
        },

        maskRequisition,

        resetFilters() {
            this.requisition = '';
            this.patient = '';
            this.status = [];
            wire.set('errorMessage', null, false);
        },

        openStatusModal(id, action) {
            this.closeStatusModal();
            this.pendingStatusReferral = id;
            this.showRecallModal = action === 'recall';
            this.showErrorModal = action === 'error';
        },

        closeStatusModal() {
            this.showRecallModal = false;
            this.showErrorModal = false;
            this.pendingStatusReferral = null;
            this.statusExplanatoryLetter = '';
            this.errorReason = '';
        }
    };
}

export function referralDropdown() {
    let reposition;
    return {
        open: false,
        panelStyle: {},

        init() {
            reposition = () => this.open && this.positionPanel();
            window.addEventListener('scroll', reposition, true);
            window.addEventListener('resize', reposition);
        },

        destroy() {
            window.removeEventListener('scroll', reposition, true);
            window.removeEventListener('resize', reposition);
        },

        toggle() {
            if (this.open) return this.close();
            this.$refs.button.focus();
            this.open = true;
            this.$nextTick(() => this.positionPanel());
        },

        positionPanel() {
            const button = this.$refs.button.getBoundingClientRect();
            const panel = this.$refs.panel;
            const gap = 8;
            const width = Math.min(256, window.innerWidth - gap * 2);
            const height = Math.min(panel.scrollHeight, window.innerHeight - gap * 2);
            const below = window.innerHeight - button.bottom - gap;
            const top = below >= height
                ? button.bottom + gap
                : Math.max(gap, button.top - height - gap);
            this.panelStyle = {
                left: Math.max(gap, Math.min(button.right - width, window.innerWidth - width - gap)) + 'px',
                top: Math.min(top, window.innerHeight - height - gap) + 'px',
                width: width + 'px',
                maxHeight: (window.innerHeight - gap * 2) + 'px'
            };
        },

        close(focusAfter) {
            if (!this.open) return;
            this.open = false;
            focusAfter?.focus();
        }
    };
}
