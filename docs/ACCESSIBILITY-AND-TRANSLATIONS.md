# Accessibility and translations

## Accessibility audit

The public shortcodes are intended to work with keyboard and screen-reader users. The current audit was performed against generated markup and the plugin stylesheet; it is a source-level audit, not a claim of WCAG certification.

| Area | Finding | Resolution / verification |
| --- | --- | --- |
| Booking and review forms | Labels were visually adjacent to controls but did not consistently have explicit \`for\`/\`id\` associations. Repeated shortcodes could also duplicate IDs. | Every public control now has a matching label and a per-form unique ID. Form headings are connected with \`aria-labelledby\`. |
| Traveller inputs | The age fields needed a programmatic description explaining the comma-separated format. | Both age fields reference a visible help paragraph with \`aria-describedby\`; numeric input mode is enabled. |
| Keyboard focus | The component stylesheet did not provide a consistent visible focus indicator. | Links, buttons and form controls receive a high-contrast focus outline with offset. |
| Motion | Gallery hover animation had no reduced-motion override. | The gallery transition and hover transform are disabled under \`prefers-reduced-motion: reduce\`. |
| Gallery images | Images without Media Library alt text could leave linked images without an accessible name. | The gallery supplies a contextual fallback alt value; tour-card thumbnails fall back to the tour title. |
| Review ratings | A generic paragraph carried an \`aria-label\`, which is not a reliable image announcement. | Star graphics now use \`role="img"\` with a translated rating label; the visible star glyphs remain available. |
| Heading hierarchy | Booking and review forms lacked a clear heading associated with the form. | Each form now has a heading referenced by \`aria-labelledby\`. |
| Contrast | Gold review stars were too light for meaningful foreground contrast. | The star color is darkened; the primary button colors remain unchanged. |
| Translation strings | Media modal labels were embedded as English JavaScript literals. | The strings are localized through WordPress and all new public UI text uses the \`opentrip-engine\` text domain. |

### Manual verification checklist

Before release, test the shortcodes in a real WordPress theme with:

1. Keyboard only: Tab, Shift+Tab, Enter and Space through tour cards, gallery links, booking controls and review controls. Confirm focus is always visible and follows visual order.
2. Screen reader: NVDA + Firefox or VoiceOver + Safari. Confirm each form control announces its label, required state and age-field help text; confirm star ratings announce “N out of 5 stars”.
3. Zoom/reflow: 200% and 400% browser zoom. Confirm forms and tour cards remain usable without horizontal page scrolling at a 320 CSS-pixel viewport.
4. Reduced motion: enable the operating-system reduced-motion preference and confirm gallery hover motion is removed.
5. Images: provide meaningful Media Library alt text where the photo conveys information. The plugin fallback is a safety net, not a replacement for editorial alt text.

Automated tests verify generated labels, unique IDs, accessible star labels, fallback gallery alt text and focus/reduced-motion CSS. Automated tests do not replace the manual assistive-technology checks above.

## Translation workflow

All plugin-facing PHP strings use the \`opentrip-engine\` text domain. The checked-in template is \`languages/opentrip-engine.pot\`.

### Generate or refresh the template

Install WP-CLI and its i18n command package:

\`\`\`sh
wp package install wp-cli/i18n-command
composer i18n:pot
\`\`\`

The command runs \`wp i18n make-pot\` and updates \`languages/opentrip-engine.pot\`. Run it after adding or changing translatable strings, then commit the updated template with the code change.

### Create and compile a translation

1. Copy the template to a locale-specific file, for example \`languages/opentrip-engine-fr_FR.po\`.
2. Translate each \`msgid\` in Poedit or another gettext editor; preserve placeholders such as \`%1$s\`, \`%d\` and HTML-free text.
3. Compile the catalog to \`languages/opentrip-engine-fr_FR.mo\` using Poedit or \`wp i18n make-mo languages\`.
4. Commit both the translated \`.po\` and compiled \`.mo\` files.

WordPress loads translations for the \`opentrip-engine\` text domain from the plugin's \`languages\` directory. Do not add telemetry, remote translation services or runtime network requests.

### Contributor checklist

- Keep every new user-facing string in the \`opentrip-engine\` text domain.
- Use \`_n()\` for plural forms and \`_x()\` when a short string needs translator context.
- Escape at output time with the appropriate \`esc_html__()\`, \`esc_attr__()\` or \`esc_html_e()\` function.
- Regenerate the POT file whenever source strings change.
- Never translate placeholders, shortcode names, metadata keys or API field names.
