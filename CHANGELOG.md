# Changelog

All notable changes to this project will be documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

⚠️ **Note:** This project is in early development (`0.x.x`). **Breaking changes can occur at any time.**

---

## [0.2.0] - Unreleased

### Changed
- **Plurals are resolved by Laravel's own `MessageSelector`** for each CLDR plural category i18next uses in the locale, instead of a hand-written parser. The output now matches `trans_choice` for any syntax Laravel accepts.
- **`_zero` is only emitted when Laravel prints distinct text for 0.** Strings with no `{0}` branch used to get `_zero: ""`, which i18next rendered as an empty string for a count of 0.
- **Every plural category the locale needs is emitted**, e.g. `_many` for French and Spanish. Before, counts of 1,000,000+ rendered the raw key there.
- **camelCase placeholders keep their name:** `:dueDate` → `{{dueDate}}` (was `{{duedate}}`, which never matched the value passed in). `:AppName` → `{{appName, capitalize}}`.
- Default output path uses `public_path('locales')` and can be configured.
- `versions.json` hashes file names as well as contents, so renaming a namespace bumps the version.
- Requires `ext-intl`. Supports Laravel 12 and 13.

### Fixed
- `10:30`, `3:1` and other colons followed by a digit are no longer turned into placeholders.
- Plural shapes other than `{0}|{1}|[2,*]` (three plain segments, ranges, `||`) are no longer glued into one garbled `_other` string.
- Self-closing tags with attributes keep their slash (`<br class="x" />` → `<br/>`).
- `lang/vendor` is no longer exported as a locale.
- JSON files end with a newline; an empty file is written as `{}`, not `[]`.

### Added
- Warnings for plurals i18next cannot express exactly and for generated keys that collide, plus a `--strict` flag that fails on them (and on files that fail to load).
- `--prune` removes JSON files whose PHP source was deleted or renamed.
- `--types=<path>` / `types.path` writes a TypeScript declaration of every namespace with literal values, so i18next type-checks keys and interpolation options.
- Publishable config: `output_path`, `strip_html_attributes`, `types`.
- Test suite with 100% line and type coverage, including a check against `trans_choice` across several locales.

---

## [0.1.0] - 2025-08-03

### Added
- Initial release of **Laravel to i18next Lang Parser**.
- Command `lang:to-i18next` to convert Laravel `lang` files to i18next-compatible JSON.
- Automatic handling of pluralization rules:
    - Converts Laravel's `{0}`, `{1}`, `[2,*]` syntax into `_zero`, `_one`, `_other` keys.
- Support for placeholder replacement:
    - `:name` → `{{name}}`
    - `:Name` → `{{name, capitalize}}`
    - `:NAME` → `{{name, uppercase}}`
- Strips attributes from HTML tags in translations so that they can be used freely with i18Next Trans components.
- Support for nested arrays in Laravel translation files.
- Writes converted files into `public/locales/{locale}/{namespace}.json`.
- Version tracking with `versions.json` for cache-busting.

---
