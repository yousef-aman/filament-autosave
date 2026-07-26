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
- Secret inputs (`->password()` or `->type('password')`) are never autosaved or
  cached in a draft — at any nesting depth — and the shipped config excludes the
  common credential field names
- Fields nested under a `statePath()`ed group or inside a `Repeater` are resolved
  by their full state path. Because such a container is written as one whole column
  value, it is autosaved only when every field beneath it survived the safeguards;
  otherwise it is left untouched rather than written back without the skipped field
- Autosave enforces each option field's own allowed values (`Select`,
  `CheckboxList`, `ToggleButtons`), including tenant- and team-scoped relationship
  options, so a crafted payload cannot persist a value a normal save would reject.
  Multi-value fields are validated per element, so a valid multi-selection is
  autosaved rather than dropped
- Lifecycle hooks (beforeAutosave, afterAutosave) and autosave validation rules,
  including rules on nested paths (`items.*.qty`)
- Record writes (autosave and undo) run inside a database transaction, matching
  Filament's own save lifecycle. Filament's unsaved-changes tracking is
  re-baselined only when the write covered every filled field, so
  `->unsavedChangesAlerts()` still warns about a field autosave had to skip
- Client-injected, non-field keys are discarded before persisting, at every depth
- Draft and undo cache keys are scoped by tenant, auth guard and user, so two
  panels authenticating different people under the same id never collide; guests
  are keyed by a hash of the session id rather than the id itself
- Reordering `Repeater` rows is detected as a change and autosaved
- Undo replays only a snapshot created by the same live page instance
- The indicator falls back to the end of the page when a page renders no header,
  instead of silently disabling autosave
- Integration tests run the traits on real `EditRecord`/`CreateRecord` pages inside
  a booted panel, writing to a real database
- Dark mode support
- Translation support
