# Translating OpenTrip Engine

The plugin uses the opentrip-engine text domain and loads translations from this directory.

## Generate or refresh the POT template

Requirements: WP-CLI and the WP-CLI i18n command.

Install the i18n command once:

    wp package install wp-cli/i18n-command

Generate the template from the current PHP source:

    sh scripts/make-pot.sh

This writes languages/opentrip-engine.pot. Run it after changing user-facing strings, then commit the updated template.

## Create a translation

1. Open opentrip-engine.pot in Poedit or another gettext editor.
2. Save the translation as opentrip-engine-{locale}.po, using the WordPress locale (for example opentrip-engine-fr_FR.po).
3. Compile the binary catalogue:

    wp i18n make-mo languages/opentrip-engine-fr_FR.po

4. Commit the .po source file. Include the matching .mo file when distributing a ready-to-install plugin package.

WordPress loads opentrip-engine-{locale}.mo from this directory when the site's locale matches. Keep the text domain unchanged, preserve placeholders such as %1$s and %d, and include plural forms in the translation editor.

## Translation contribution checklist

- Translate every string in context; do not translate shortcode names, metadata keys or the text domain.
- Keep HTML out of translations where possible; use placeholders for dynamic values.
- Preserve printf placeholders exactly.
- Test the translation on a staging WordPress site and check that translated text does not overflow buttons or form layouts.
- Do not add remote translation services, telemetry or runtime network requests.
