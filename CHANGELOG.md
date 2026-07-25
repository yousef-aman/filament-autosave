# Changelog

## 1.0.0 - 2026-07-26

- Initial release
- Autosave for Edit pages: persists the form's dehydrated state after a debounce
  (no validation blocking), with one-step Undo of the last write
- Draft autosave for Create and custom pages: unsubmitted changes are cached and
  can be restored or discarded; the draft is cleared on successful create,
  including "Create & create another"
- Visual status indicator (unsaved, saving, saved, error, draft available,
  restored, undone)
- Filament v4 and v5, Livewire v3 and v4, Laravel 11 to 13
- Options at config, plugin and page level (debounce, excluded fields, cache TTL,
  indicator position, timestamp, enable/disable, excluded pages). Page-level
  options are methods — `shouldAutosave()`, `autosaveDebounce()`,
  `autosaveExcept()` — so no page ever redeclares a trait property
- Every public Livewire property the traits add is `#[Locked]`: a page that
  disabled autosave cannot be re-enabled from the browser
- `->password()` inputs are never autosaved or cached in a draft, and the shipped
  config excludes the common credential field names
- Lifecycle hooks (beforeAutosave, afterAutosave) and per-field autosave
  validation rules
- Option fields carrying multiple values (CheckboxList, multiple Select /
  ToggleButtons) are validated per element, so a valid multi-selection is
  autosaved rather than dropped
- Record writes (autosave and undo) run inside a database transaction, matching
  Filament's own save lifecycle, and re-baseline Filament's unsaved-changes
  tracking so `->unsavedChangesAlerts()` does not warn about saved changes
- Client-injected, non-field keys are discarded before persisting
- Dark mode support
- Translation support
