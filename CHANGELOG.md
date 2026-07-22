# Changelog

## 1.0.0 - 2026-07-23

- Initial release
- Autosave for Edit pages: persists the form's dehydrated state after a debounce
  (no validation blocking), with one-step Undo of the last write
- Draft autosave for Create and custom pages: unsubmitted changes are cached and
  can be restored or discarded; the draft is cleared on successful create,
  including "Create & create another"
- Visual status indicator (unsaved, saving, saved, error, draft available,
  restored, undone)
- Filament v4 and v5, Livewire v3 and v4
- Per-page, plugin, and config-level options (debounce, excluded fields,
  cache TTL, indicator position, timestamp, enable/disable, excluded pages)
- Lifecycle hooks (beforeAutosave, afterAutosave) and per-field autosave
  validation rules
- Option fields carrying multiple values (CheckboxList, multiple Select /
  ToggleButtons) are validated per element, so a valid multi-selection is
  autosaved rather than dropped
- Record writes (autosave and undo) run inside a database transaction, matching
  Filament's own save lifecycle
- Client-injected, non-field keys are discarded before persisting
- Dark mode support
- Translation support
