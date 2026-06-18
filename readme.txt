=== Multicouriers Shipping for WooCommerce ===
Contributors: multicouriers
Tags: shipping, chile, woocommerce, checkout blocks
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce shipping plugin for Chile with fixed rates by region/commune and optional premium API quotes from Multicouriers.

== Description ==

Multicouriers Shipping for WooCommerce adds shipping tools focused on Chile:

* Fixed shipping rates by region or commune (including exclude rules by commune).
* Dynamic shipping quotes using the Multicouriers API (premium).
* WooCommerce Cart/Checkout Blocks support.
* Chile region/commune helpers for checkout and postcode resolution.
* Diagnostics and operational support tools for troubleshooting.

== Installation ==

1. Upload the `multicouriers-shipping-for-woocommerce` folder to `/wp-content/plugins/`.
2. Activate the plugin from `Plugins` in WordPress admin.
3. Make sure WooCommerce is active.
4. Go to `WooCommerce > Settings > Shipping` and add the Multicouriers shipping method(s) to the desired zones.
5. Configure fixed-rate rules in `WooCommerce > Multicouriers Tarifas`.
6. (Optional) Configure premium API access in `WooCommerce > Multicouriers Premium`.

== Frequently Asked Questions ==

= Do I need to add a shipping method in WooCommerce zones? =

Yes. WooCommerce requires at least one shipping method assigned to the zone. The `Multicouriers Tarifas` page stores the rules, but the zone still needs the `Multicouriers Tarifa Fija` method enabled.

= Where do I configure fixed-rate rules? =

Use `WooCommerce > Multicouriers Tarifas`. The zone modal for `Multicouriers Tarifa Fija` is intentionally minimal (enabled/title/default fallback cost).

= Is the plugin compatible with Checkout Blocks? =

Yes. The plugin supports WooCommerce Cart/Checkout Blocks and includes commune/region handling for the blocks checkout.

== External Services ==

This plugin connects to external services operated by Multicouriers to provide premium shipping quotes and diagnostics, and may fetch the Chile cities dataset for admin-side updates.

= Service provider =

Multicouriers (`https://multicouriers.cl/`)

= What endpoints are used =

* `https://app.multicouriers.cl/api/v1/quotes` (premium shipping quotes)
* `https://app.multicouriers.cl/api/v1/project/status` (premium diagnostics/status)
* `https://app.multicouriers.cl/api/v1/token/rotate` (premium token rotation from admin)
* `https://app.multicouriers.cl/api/v1/token/rotations` (premium token rotation history from admin)
* `https://app.multicouriers.cl/api/chile/cities` (Chile cities/postal codes dataset; admin/maintenance contexts)

= When data is sent =

* During shipping quote calculation when the premium dynamic shipping method is enabled and configured.
* When the store administrator runs diagnostics, project status refresh, or token actions from `WooCommerce > Multicouriers Premium`.
* When the plugin refreshes the Chile cities dataset (admin/maintenance contexts).

= What data is sent =

For premium quotes and diagnostics, the plugin may send:

* Store domain
* Authentication token (API key) in the Authorization header
* Origin and destination shipping data (country, region/state, commune/city, postcode)
* Package information (weight and dimensions)
* Store currency
* Selected couriers
* Correlation and request metadata headers used for diagnostics

For the Chile cities dataset endpoint, the plugin performs a read-only request and sends no customer personal data.

= Service terms and privacy =

* Provider website: `https://multicouriers.cl/`
* Privacy/terms information is provided by Multicouriers on their service channels and website.

== Screenshots ==

1. Fixed-rate rules table (WooCommerce > Multicouriers Tarifas)
2. Premium configuration and diagnostics (WooCommerce > Multicouriers Premium)
3. Shipping method configuration inside WooCommerce shipping zones

== Changelog ==

= 1.0.5 =
* Fix: Handle API empty response gracefully
* Fix: City dropdown format compatibility with local fallback data
* Refactor: Extract correlation_id logic into shared method
* Refactor: Create MCWS_Utils helper class
* Refactor: Create abstract base class for WooCommerce Blocks support
* Remove: Dead code from unused method

= 1.0.4 =
* Maintenance and stability improvements

= 1.0.3 =
* Version bump
* Fix: Domain path and improve uninstall cleanup

= 1.0.2 =
* Stability improvements

= 1.0.1 =
* Bug fixes and improvements

= 1.0.0 =
* Initial public release
