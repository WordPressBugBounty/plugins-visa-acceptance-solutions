=== Visa Acceptance Solutions ===

Author: Visa Acceptance Solutions
Contributors: visaacceptancesolutions
Tags: woocommerce, payments, visa
Requires at least: 6.1
Tested up to: 6.8
Requires PHP: 8.0.0
Stable tag: 2.0.0
WC tested up to: 10.0.4
WC requires at least: 7.6.0
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Accept payments securely with Visa Acceptance Solutions.

== Description == 

This plugin integrates **Visa Acceptance Solutions** into your **WooCommerce** store, offering multiple payment methods such as Card Payments, Apple Pay, Google Pay, and Click to Pay. 
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

//filename (screenshot-1, screenshot-2 etcof screenshot must correspond to the line.  1200x630 

== Installation ==
1. Upload the entire “visa-acceptance-solutions” folder to the “/wp-content/plugins/” directory in your WordPress installation.
2. Activate the plugin through the “Plugins” menu in WordPress.
3. Configure the plugin settings in WooCommerce → Settings → Payments → Visa Acceptance Solutions.

For full documentation, please visit our [documentation center](https://developer.visaacceptance.com/docs/vas/en-us/isv-plugins/admin/all/na/isv-plugin-o/built-by-us/vas-woocommerce-intro.html)

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

= How can I get a sandbox account? =
Sign up [here](https://developer.visaacceptance.com/hello-world/sandbox.html).  Note sandbox accounts are configured for USD currency

== Change Log ==
= 1.0.0 =
**Initial release** supporting Card Payments, Tokenisation, Payer Authentication (3D Secure), and Fraud Screening tools.

= 2.0.0 =
** Enhancements **


== Upgrade Notice ==
Version 2.0.0 is now available.  Please upgrade for Apple Pay and WooCommerce Subscriptions.

== Admin Notice ==

Version 2.0.0 is now available.  Please upgrade for Apple Pay and WooCommerce Subscriptions.

== External Domains Used ==

Below are the external domains contacted by the plugin, along with an explanation of their usage. 
Production endpoints are used in live mode; test endpoints are used in sandbox or staging environments.

1. https://flex.cybersource.com & https://testflex.cybersource.com

• Purpose: Tokenizing payment card data securely via Flex API operations.
• How it works: The plugin sends card details (like PAN, expiry date) to generate a transient token.
• When: During checkout or payment method setup in production/test mode.

2. https://api.cybersource.com & https://apitest.cybersource.com

• Purpose: Base API domain for Cybersource requests (payments, tokenization, reporting, etc.).
• How it works: The plugin sends various requests (payment processing, token management, etc.).
• When: Executed whenever the plugin operates in production/test mode.

3. https://centinelapi.cardinalcommerce.com & https://centinelapistag.cardinalcommerce.com

• Purpose: 3D Secure authentication via the Cardinal Cruise Library.
• How it works: The plugin interacts with these domains for 3D Secure flows (production/test).
• When: Used when 3D Secure authentication is required.

4. https://h.online-metrix.net/fp/tags

• Purpose: Device fingerprinting for Online Metrix fraud prevention.
• How it works: The plugin loads scripts to gather browser/device data for fraud analysis.
• When: During checkout or payment attempts to assess risk.
