# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-09-14

### Changed
- All translatable strings in PHP and JS rewritten to English so the plugin is fully translatable from `translate.wordpress.org`.

### Added
- Regenerated `languages/clevers-shipping-for-multicouriers.pot` (153 strings, now in English as the base locale).
- Initial Spanish (neutral) translation: `languages/clevers-shipping-for-multicouriers-es.po` + `.mo`. WordPress fallback ensures es_CL, es_ES, es_MX, es_AR and any other Spanish locale get this translation automatically.

## [1.0.9] - 2026-09-14

### Added
- `languages/clevers-shipping-for-multicouriers.pot` translation template generated via `wp i18n make-pot`, enabling community translations through translate.wordpress.org.
- Translation contributions entry point documented in `readme.txt`.

## [Unreleased]

### Added
- CHANGELOG.md to track changes following Keep a Changelog format
- PHPUnit test suite with basic coverage for address resolution, utils, and uninstall
- CI workflow with PHP 7.4/8.0/8.1/8.2/8.3 matrix and i18n lint
- Version validation script to ensure header, readme.txt, and constant stay in sync
- CONTRIBUTING.md with development setup and commit conventions

### Changed
- Centralized version constant documentation (MCWS_VERSION is the single source of truth)

## [1.0.5] - 2026-06-01

### Fixed
- Handle API empty response gracefully
- City dropdown format compatibility with local fallback data

### Changed
- Extract correlation_id logic into shared method
- Create MCWS_Utils helper class
- Create abstract base class for WooCommerce Blocks support

### Removed
- Dead code from unused method

## [1.0.4] - 2026-05-15

### Changed
- Maintenance and stability improvements

## [1.0.3] - 2026-05-01

### Fixed
- Domain path and improve uninstall cleanup

### Changed
- Version bump for stability

## [1.0.2] - 2026-04-15

### Changed
- Stability improvements

## [1.0.1] - 2026-04-01

### Fixed
- Bug fixes and improvements

## [1.0.0] - 2026-03-15

### Added
- Initial public release
- Fixed shipping rates by region or commune
- Dynamic shipping quotes using the Multicouriers API (premium)
- WooCommerce Cart/Checkout Blocks support
- Chile region/commune helpers for checkout and postcode resolution
- Diagnostics and operational support tools

[Unreleased]: https://github.com/agenciaingenium/Multicouriers-Shipping-For-Woocommerce/compare/v1.0.5...HEAD
[1.0.5]: https://github.com/agenciaingenium/Multicouriers-Shipping-For-Woocommerce/compare/v1.0.4...v1.0.5
[1.0.4]: https://github.com/agenciaingenium/Multicouriers-Shipping-For-Woocommerce/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/agenciaingenium/Multicouriers-Shipping-For-Woocommerce/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/agenciaingenium/Multicouriers-Shipping-For-Woocommerce/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/agenciaingenium/Multicouriers-Shipping-For-Woocommerce/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/agenciaingenium/Multicouriers-Shipping-For-Woocommerce/releases/tag/v1.0.0
