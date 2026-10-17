# Modern light theme

`assets/css/light-clean.css` contains the light-only palette and component overrides. It is loaded after the shared glass stylesheet by all page templates; the student login compatibility route inherits the shared login template.

- White page background, gray secondary surfaces, slate text, orange primary actions, crisp borders, 12px card corners and restrained shadows.
- White-on-orange primary buttons use 19px bold text to meet the large-text contrast threshold. Small links use charcoal with orange accents instead.
- Light hero photo overlay, clearer table/form boundaries, explicit validation colors and mobile styles. Existing routes, account handling and theme persistence are unchanged.

Validation: PHP syntax checks passed for the 18 direct templates (19 routes including the student login alias). Browser checks covered the landing page, Login/Register, theme switching and mobile layouts. Computed styles on the checked dark-mode components match with this stylesheet enabled or disabled after transitions settle. Authenticated student/officer/admin screens still require a signed-in visual review; no full-system accessibility certification is claimed.
