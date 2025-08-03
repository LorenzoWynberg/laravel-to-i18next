# Changelog

All notable changes to this project will be documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

⚠️ **Note:** This project is in early development (`0.x.x`). **Breaking changes can occur at any time.**

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
