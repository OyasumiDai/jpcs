# JPCS glass presentation layer

- `assets/css/glass.css` provides shared dark/light tokens, glass surfaces, input and button utilities, floating navigation, modal styling, focus states, reduced-motion rules and solid-color fallbacks.
- `js/glass-ui.js` adds route-backed native authentication dialogs, floating labels, real server-feedback toasts, separate course/year presentation, chapter badges, desktop cursor glow and gentle hero tilt. Numeric counters enhance existing statistic elements only.
- All 19 existing public, authentication, student and management page entry points load these files after the existing UI styles. Authentication, database queries and route guards are unchanged.

## Reuse

Use `.glass-panel`, `.glass-card`, `.glass-input`, `.glass-button` / `.btn-glow`, and `.glass-secondary` on new components. Existing cards and controls are mapped automatically. Use `[data-counter]` on a plain integer statistic for an entrance count-up. The existing ThemeToggle continues to control `data-theme` and persisted preferences.

## Authentication behavior

Login/Register links retain their original URLs; those pages open their existing forms inside a native dialog. Navigation, refresh, server validation and POST redirects continue normally. Closing with Escape, the backdrop or the close button returns to the landing page. The navbar remains usable inside the dialog. Without JavaScript, the normal forms remain available.

Course/year controls serialize into the existing validated `course_year` field. Confirm-password validation is retained. Remember-me and password-recovery behavior has not been expanded: the existing checkbox and contact link remain, with no new persistent-login or reset backend. No unsupported social sign-in or invented statistics were added.

## Verification

All 19 PHP entry points passed syntax checks. JavaScript passed `node --check`. Browser checks covered guest landing, registration dialog, theme switching, mobile overflow, mobile menu, close/backdrop actions, and guest redirects for protected student routes. Authenticated management workflows were not exercised.

The existing `http://localhost/jpcs/` served an older project copy during verification. The working directory was previewed separately at `http://127.0.0.1:8088/`; point the intended web server at this workspace to see these changes.
