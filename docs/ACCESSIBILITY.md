# Accessibility audit and contributor checklist

This is a practical audit of the public tour, itinerary, gallery, review and booking interfaces. It documents the checks implemented in the plugin and gives contributors reproducible manual test steps. It is not a claim of full WCAG 2.2 AA conformance.

## Implemented checks

- Booking and review forms have explicit label/ID associations and unique IDs, including when a shortcode is rendered more than once on a page.
- Traveller controls are grouped in a fieldset with a legend. Child and infant age inputs reference a shared instruction with aria-describedby.
- Booking and review forms have accessible headings referenced by aria-labelledby.
- Submission errors render an assertive role=alert region; success and moderation notices use role=status.
- Review stars expose a text equivalent through role=img and an accessible name.
- Tour thumbnails use attachment alt text, falling back to the tour title. Gallery images use attachment alt text, falling back to a contextual Tour photo label.
- Itinerary, inclusions/exclusions and review content use semantic sections, articles, lists and heading levels.
- Interactive links, buttons and form controls have visible :focus-visible indicators with a high-contrast outline.
- Gallery hover animation is disabled when prefers-reduced-motion: reduce is active.
- Forced-colors mode receives explicit borders and button outlines.
- User-facing gallery dialog strings are localized with the plugin text domain.

## Reproducible manual audit

Run these checks on a staging site with a published tour that has a featured image, gallery, itinerary, inclusions, reviews and at least one available departure.

1. **Keyboard only:** reload the tour page, press Tab repeatedly, and confirm focus is visible on every link, field, select and button. Use Enter to open links and Space/Enter to activate buttons. Confirm no keyboard trap occurs.
2. **Forms:** use only the keyboard to complete the booking and review forms. Confirm each control is announced with its label, the traveller group announces Travellers, and age instructions are announced when focusing the age fields.
3. **Validation:** submit invalid traveller ages and invalid review fields. Confirm the error page exposes an assertive alert and the error text is visible. Submit valid data and confirm the success status is announced.
4. **Screen reader:** test with NVDA + Firefox on Windows or VoiceOver + Safari on macOS/iOS. Confirm heading navigation, image alternatives, star-rating names, fieldset legends and status/error announcements.
5. **Zoom and reflow:** zoom the page to 200% and 400% and check that forms, cards, gallery and itinerary remain usable without horizontal scrolling at a 320 CSS-pixel viewport.
6. **Contrast:** check text and controls against their actual backgrounds. Current theme-independent plugin colors include primary blue #1d4ed8 (white contrast 6.70:1), muted text #64748b (white 4.76:1), included heading #166534 (white 7.13:1), excluded heading #991b1b (white 8.31:1), and review stars #8a4b08 (white 6.79:1). A site theme can override these styles; re-check the rendered page.
7. **Motion and contrast preferences:** enable the operating system's reduced-motion preference and confirm gallery hover zoom is disabled. Test Windows High Contrast/forced-colors mode and confirm focus remains visible.

## Known limitations

- Themes and page builders can override plugin CSS, heading hierarchy, focus styles or color choices. Repeat the checks with the site's active theme.
- Tour editors remain responsible for writing meaningful attachment alt text. Fallback labels are useful but may not describe the subject as accurately as a human-written alternative.
- CI checks accessible markup and CSS hooks; it does not run a real screen reader, browser keyboard session, axe-core scan or complete WCAG conformance audit.
- The plugin does not add client-side JavaScript validation. Server-side validation remains authoritative, and errors are presented on an accessible WordPress error page.
