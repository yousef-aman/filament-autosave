export default function autosave({ debounce = 1500, mode = 'edit' }) {
    return {
        status: 'idle',
        timestamp: null,
        timer: null,
        fadeTimer: null,
        previousData: null,
        mode: mode,

        init() {
            if (this.$wire.autosaveEnabled === false) {
                return
            }

            this.previousData = JSON.stringify(this.$wire.data)

            if (mode === 'create' && this.$wire.autosaveHasDraft) {
                this.status = 'draft_available'
            }

            this.$watch(
                () => JSON.stringify(this.$wire.data),
                (newVal) => {
                    if (newVal === this.previousData) {
                        return
                    }

                    this.onDataChanged()
                },
            )

            this.$wire.$on('autosave-status', (params) => {
                const data = Array.isArray(params) ? params[0] : params
                this.setStatus(data.status, data.timestamp || null)
            })

            this._submitHandler = (e) => {
                const mine = this.$el?.closest?.('[wire\\:id]')
                const submitted = e.target?.closest?.('[wire\\:id]')

                if (!mine || !submitted || mine === submitted) {
                    this.cancelPending()
                }
            }
            document.addEventListener('submit', this._submitHandler)
        },

        cancelPending() {
            clearTimeout(this.timer)
            clearTimeout(this.fadeTimer)
            this.status = 'idle'
        },

        onDataChanged() {
            clearTimeout(this.timer)
            clearTimeout(this.fadeTimer)

            this.status = 'unsaved'

            this.timer = setTimeout(() => {
                this.save()
            }, debounce)
        },

        async save() {
            if (this.status === 'saving') {
                return
            }

            this.status = 'saving'

            try {
                await this.$wire.autosave()
            } catch (e) {
                this.setStatus('error')
            }
        },

        async undo() {
            clearTimeout(this.timer)
            clearTimeout(this.fadeTimer)
            this.status = 'saving'

            try {
                await this.$wire.undoAutosave()
            } catch (e) {
                this.setStatus('error')
            }
        },

        async restore() {
            clearTimeout(this.timer)
            clearTimeout(this.fadeTimer)
            this.status = 'saving'

            try {
                await this.$wire.restoreDraft()
            } catch (e) {
                this.setStatus('error')
            }
        },

        async discard() {
            clearTimeout(this.timer)
            clearTimeout(this.fadeTimer)

            try {
                await this.$wire.discardDraft()
            } catch (e) {
                this.setStatus('error')
            }
        },

        setStatus(newStatus, newTimestamp = null) {
            clearTimeout(this.fadeTimer)

            this.status = newStatus
            this.timestamp = newTimestamp

            const fadeDelays = { saved: 5000, restored: 3000, undone: 3000 }

            if (fadeDelays[newStatus]) {
                this.fadeTimer = setTimeout(() => {
                    this.status = 'idle'
                }, fadeDelays[newStatus])
            }

            if (newStatus === 'saved' || newStatus === 'idle' || newStatus === 'restored' || newStatus === 'undone') {
                this.previousData = JSON.stringify(this.$wire.data)
            }
        },

        destroy() {
            clearTimeout(this.timer)
            clearTimeout(this.fadeTimer)
            document.removeEventListener('submit', this._submitHandler)
        },
    }
}
