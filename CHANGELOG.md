# Changelog

## 1.0.0 - 2026-04-15

- Initial release
- Autosave for Edit pages: persists the form's dehydrated state after a debounce
  (no validation blocking), with one-step Undo of the last write
- Draft autosave for Create and custom pages: unsubmitted changes are cached and
  can be restored or discarded; the draft is cleared on successful create,
  including "Create & create another"
- Visual status indicator (unsaved, saving, saved, error, draft available,
  restored, reverted)
- Filament v4 and v5, Livewire v3 and v4
- Per-page, plugin, and config-level options (debounce, excluded fields,
  cache TTL, indicator position, timestamp, enable/disable, excluded pages)
- Lifecycle hooks (beforeAutosave, afterAutosave) and per-field autosave
  validation rules
- Client-injected, non-field keys are discarded before persisting
- Dark mode support
- Translation support
