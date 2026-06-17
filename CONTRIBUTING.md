# Contributing to Multicouriers Shipping for WooCommerce

## Versioning Policy

This project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html):

- **MAJOR** (X.0.0): Breaking changes that require user action (e.g., removing a shipping method, changing database schema)
- **MINOR** (0.X.0): New features that are backward-compatible (e.g., adding a new courier, new admin page)
- **PATCH** (0.0.X): Bug fixes and maintenance (e.g., fixing city dropdown, improving error handling)

### Version Sources

The version must be consistent across three locations:

1. `multicouriers-shipping-for-woocommerce.php` — Plugin header `Version:` and `MCWS_VERSION` constant
2. `readme.txt` — `Stable tag:` field
3. Git tag — `vX.Y.Z` format

The `bin/bump-version.php` script updates all three automatically when creating a release.

## Commit Conventions

Use [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) format:

```
<type>(<scope>): <description>

[optional body]

[optional footer]
```

### Types

- `feat`: New feature (triggers MINOR bump)
- `fix`: Bug fix (triggers PATCH bump)
- `docs`: Documentation only
- `style`: Code style (formatting, missing semicolons, etc.)
- `refactor`: Code change that neither fixes a bug nor adds a feature
- `test`: Adding or updating tests
- `chore`: Maintenance tasks (CI, dependencies, scripts)
- `perf`: Performance improvement
- `ci`: CI/CD changes
- `build`: Build system changes

### Scopes

- `admin`: Admin pages and settings
- `api`: Multicouriers API client
- `blocks`: WooCommerce Blocks support
- `checkout`: Checkout flow
- `ci`: GitHub Actions workflows
- `docs`: Documentation
- `i18n`: Internationalization
- `rates`: Shipping rate calculation
- `release`: Release process
- `tests`: Test suite
- `uninstall`: Uninstall cleanup

### Examples

```
feat(api): add support for new courier integration
fix(checkout): handle empty city dropdown in blocks checkout
docs(readme): add troubleshooting section for API errors
test(rates): add unit tests for fixed rate resolution
chore(ci): add PHP 8.3 to test matrix
```

## Development Setup

### Prerequisites

- PHP 7.4+ (8.0+ recommended for development)
- WordPress 6.0+
- WooCommerce 8.0+
- Composer (optional, for dev dependencies)

### Running Tests

```bash
# Run the basic test suite (no WordPress required)
php tests/postcode-resolution-test.php
php tests/utils-test.php
php tests/uninstall-test.php

# Run all tests
php tests/run-all.php
```

### Code Standards

- Follow WordPress Coding Standards (WPCS)
- Use `MCWS_` prefix for all classes and functions
- Use `mcws_` prefix for options, transients, and hooks
- Text domain: `multicouriers-shipping-for-woocommerce`

## Release Process

See [RELEASING.md](RELEASING.md) for the complete release workflow.

### Quick Summary

1. Create a release branch: `git checkout -b release/vX.Y.Z`
2. Update CHANGELOG.md with the new version and date
3. Run `php bin/bump-version.php X.Y.Z` to update version files
4. Commit: `git commit -am "Release vX.Y.Z"`
5. Push and create a PR to `main`
6. After merge, go to **Actions > Create Release** and enter the version
7. The workflow will tag, create the release, build the ZIP, and deploy to WordPress.org

## Pull Request Guidelines

- One feature/fix per PR
- Include tests for new functionality
- Update CHANGELOG.md for user-facing changes
- Keep PRs small and focused
- Reference related Linear issues (e.g., "Closes CLE-97")
