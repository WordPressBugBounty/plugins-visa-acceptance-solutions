=== Visa Acceptance Solutions ===

Author: Visa Acceptance Solutions
Contributors: visaacceptancesolutions
Tags: woocommerce, payments, visa
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.2.0
Stable tag: 2.1.0
WC tested up to: 10.4.3
WC requires at least: 10.3.7
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Accept payments securely with Visa Acceptance Solutions.

== Description == 

This plugin integrates **Visa Acceptance Solutions** into your **WooCommerce** store, offering multiple payment methods such as Card Payments, Apple Pay, Google Pay, Click to Pay, and Paze.
Securely store customer payment details with our Token Management Services.
Utilize Cybersource’s fraud prevention services to process transactions safely.
Compatible with [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions)

== Screenshots ==
1. Configuration Screen 1
2. Configuration Screen 2
3. Configuration Screen 3
4. Checkout 1
5. Checkout 2
6. Checkout 3
7. Express Pay 1
8. Express Pay 2

== Installation ==
1. Upload the entire “visa-acceptance-solutions” folder to the “/wp-content/plugins/” directory in your WordPress installation.
2. Activate the plugin through the “Plugins” menu in WordPress.
3. Configure the plugin settings in WooCommerce → Settings → Payments → Visa Acceptance Solutions.

For full documentation, please visit our [documentation center](https://developer.visaacceptance.com/docs/vas/en-us/isv-plugins/admin/all/na/isv-plugin-o/built-by-us/wc-introduction.html)

== Privacy Policy and Terms of Service ==

Refer to [Terms of Service](https://www.visaacceptance.com/en-gb/become-a-partner/merchant-agreement.html)
Refer to [Privacy Policy](https://www.visa.co.uk/legal/global-privacy-notice.html)

== Frequently Asked Questions ==

= How can I test credit card transactions? =
Configure Plugin in "Test" Environment. Then submit an order with valid billing address and payment information according to our [test documentation](https://developer.visaacceptance.com/hello-world/testing-guide.html)

= How can I test 3D Secure authentication? =
Configure Plugin in "Test" Environment. Then submit an order with valid billing address, additional min required fields and payment information according to our [3D Secure Test documentation](https://developer.visaacceptance.com/docs/vas/en-us/payer-authentication/developer/all/rest/payer-auth/pa-testing-intro/pa-testing-3ds-2x-intro.html).

= What are the required credentials to set up the plugin? =
You'll need your Visa Acceptance Solutions Merchant ID, API Key ID, and Shared Secret Key. For production, you'll need production credentials, and for testing, you'll need test credentials from your Visa Acceptance Solutions account.  Please visit [Support](https://support.visaacceptance.com) or contact your reseller.

= How do I get support with this plugin? =
In most cases we can provide support through the WordPress or WooCommerce forums.  In some cases we may need you to contact our [Support Team](https://support.visaacceptance.com) or your reseller, if some information is required that should not be in the public domain.  

= How can I get a sandbox account? =
Sign up [here](https://developer.visaacceptance.com/hello-world/sandbox.html).  Note sandbox accounts are configured for USD currency

== Changelog ==

= 2.1.0 =
**Enhancements**
* Updated Unified Checkout to v0.33
* Added support for China Union Pay, Maestro, Jaywan, & Paze
* Payer Authentication/3D-Secure for Google Pay
* Express Pay for Product & Checkout pages
* Replaced Cybersource endpoints with Visa Acceptance Solutions endpoints
* Wordpress v6.9 compatibility

** Bug Fixes **
* IP address collected for all requests
* Updated CVV input field text
* Saved tokens are now only accessible when tokenization is enabled
* Administrative state field not passed for non-US addresses in 3DS transactions
* Resolved Woo Block Warning in Checkout page editor

= 2.0.1 =
**Bug Fix**
* remove mapping for Customer ID due to exceeding limits within our platform for some processors
* Removed Commerce Indicator from the Payment Acceptance Request

= 2.0.0 =
**Enhancements**
* Unified Checkout v0.23
* Apple Pay
* Adopt Visa Acceptance REST Client SDK
* Message-Level Encryption
* WooCommerce Subscriptions & HPOS Compatibility

= 1.0.0 =
**Initial release** supporting Card Payments, Tokenisation, Payer Authentication (3D Secure), and Fraud Screening tools.

== Upgrade Notice ==
Version 2.1.0 is now available.  Please refer to change log for details.

== Admin Notice ==
Version 2.1.0 is now available.  Please refer to change log for details.
