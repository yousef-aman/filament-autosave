export default function autosave({ debounce = 1500, enabled = true }) {
    return {
        status: 'idle',
        timestamp: null,
        timer: null,
        fadeTimer: null,
        previousData: null,

        init() {
            if (! enabled) {
                return
            }

            this.previousData = JSON.stringify(this.$wire.data)

            this.$watch(
                () => JSON.stringify(this.$wire.data),
                (newVal) => {
                    if (newVal === this.previousData) {
                        return
                    }

                    this.onDataChanged()
                }
            )

            this.$wire.$on('autosave-status', (params) => {
                const data = Array.isArray(params) ? params[0] : params
                this.setStatus(data.status, data.timestamp || null)
            })

            this._submitHandler = () => this.cancelPending()
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
            this.status = 'saving'

            try {
                await this.$wire.undoAutosave()
            } catch (e) {
                this.setStatus('error')
            }
        },

        setStatus(newStatus, newTimestamp = null) {
            clearTimeout(this.fadeTimer)

            this.status = newStatus
            this.timestamp = newTimestamp

            const fadeDelays = { saved: 5000, undone: 3000 }

            if (fadeDelays[newStatus]) {
                this.fadeTimer = setTimeout(() => {
                    this.status = 'idle'
                }, fadeDelays[newStatus])
            }

            if (newStatus === 'saved' || newStatus === 'undone' || newStatus === 'idle') {
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
