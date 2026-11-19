=== ShipConnect for WooCommerce ===
Contributors: acode
Tags: shipping, woocommerce, tracking, cash on delivery, shipping label
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect carriers to WooCommerce: live checkout rates, one-click or automatic shipments, automatic tracking, dashboards and alerts.

== Description ==

ShipConnect connects WooCommerce to your delivery companies from one screen (WooCommerce > ShipConnect Shipping).

* **Live rates at checkout** from your connected carriers, with markup, handling fee, free-shipping threshold and a fallback rate.
* **Confirm, then ship**: a "Confirmed" order status, cash-on-delivery orders held until you confirm them, a "Send to carrier" button in the orders list, a bulk action, or fully automatic sending.
* **Price check before buying**: for API carriers, pick the service from the order's rates before the shipment is created.
* **Correct or cancel** a shipment (also with the carrier when it supports it), then create a new one.
* **Automatic tracking** every 30 minutes and through webhooks: order notes, order status, a "Track my parcel" box for the customer and tracking in emails.
* **Dashboard, analytics and alerts**: delivery rate, average delivery time, returns, cash on delivery in transit, charts, alerts for failed sends and blocked parcels.
* **Carriers without an API**: manual tracking with an automatic tracking link and an optional flat rate.
* **Worldwide carrier catalogue** with country, region and API filters.
* **English and French** interface.

API connections included: Sendit, Quick Livraison, Mylerz, Colivry and Atlas Livraison (Morocco, cash on delivery); Shippo, EasyPost, DHL Express (MyDHL API) and Aramex (shipments and labels) for international shipping.

Test each connection with the carrier's test or sandbox keys before using it in production.

ShipConnect is an independent plugin by Acode. It is not affiliated with or endorsed by WooCommerce, Automattic or the carriers it connects to; their names are trademarks of their owners.

== External services ==

ShipConnect sends nothing to Acode and contacts no carrier until you connect it with your own account credentials.

**What is sent, and when** (only to the carriers you connect):

* Connection test: your API credentials, when you click "Connect and test" or "Test".
* Checkout rates (carriers set to show at checkout): destination address without name, email or phone, store address, parcel weight and size and cart subtotal, when the cart or checkout calculates shipping.
* Shipment creation: recipient name, company, address, phone and email, store address, parcel weight and size, order number and total, items (name, quantity, price, weight) when the carrier needs them for customs, and the cash-on-delivery amount. Sent when you click "Send to carrier", use the bulk action, or when automatic sending is on and the order reaches the chosen status.
* Tracking and cancellation: the tracking number or shipment reference, every 30 minutes for open parcels (up to 45 days), when a carrier webhook arrives, when you refresh a shipment or when you cancel it.

**Services:**

* Sendit (https://app.sendit.ma/api/v1, labels from app.sendit.ma): terms https://app.sendit.ma/cgu (no separate privacy policy published).
* Quick Livraison (https://clients.quicklivraison.ma/api): privacy https://quicklivraison.ma/privacy-policy (no terms page published).
* Mylerz Morocco (https://integration.morocco.mylerz.net): privacy https://www.mylerz.com/privacy-policy (no terms page published).
* Colivry (https://www.app.colivry.co/api/v8): no terms or privacy page published on https://www.colivry.co/ at the time of writing.
* Atlas Livraison (https://api.atlaslivraison.com): terms https://atlaslivraison.com/conditions, privacy https://atlaslivraison.com/confidentialite.
* Shippo (https://api.goshippo.com): terms https://privacy.goshippo.com/policies?name=terms-of-use, privacy https://privacy.goshippo.com/policies?name=privacy-notice.
* EasyPost (https://api.easypost.com): terms https://www.easypost.com/legal/terms, privacy https://www.easypost.com/privacy.
* DHL Express, MyDHL API (https://express.api.dhl.com): terms https://developer.dhl.com/terms-use, privacy https://developer.dhl.com/privacy-notice.
* Aramex (https://ws.aramex.net, test: ws.dev.aramex.net): terms https://www.aramex.com/ae/en/legal-details/terms-and-conditions, privacy https://www.aramex.com/ae/en/legal-details/privacy-policy.

**Carrier logos (optional):** nothing is downloaded until you click "Load logos" in the Carriers tab. Your server then requests the home page and icon files of each catalogue company from that company's own website and keeps valid images in `wp-content/uploads/shipconnect-logos/`. Only a plain HTTP request is made (your server's IP address and a "ShipConnect" user agent); no store or customer data is sent. Each website's own terms apply; logos remain the property of their owners.

**Webhooks (incoming):** carriers you configure can call `/wp-json/shipconnect/v1/webhook/{connection}` with a secret key. A webhook only triggers a tracking check with the carrier.

**Tracking links:** for carriers without an API, the customer's tracking link opens the address you enter; your server makes no request.

Mention in your privacy policy that delivery data is shared with the carriers you use.

== Installation ==

1. Install from Plugins > Add New, or upload the `shipconnect-for-woocommerce` folder to `/wp-content/plugins/`.
2. Activate it (WooCommerce must be active).
3. Open WooCommerce > ShipConnect Shipping and follow the "Get started" checklist: connect a carrier, add the ShipConnect method to a shipping zone, complete the sender address.

Recommended: define a dedicated encryption key for saved API credentials in `wp-config.php`:

`define( 'SHIPC_ENCRYPTION_KEY', 'a-long-random-string' );`

On Nginx, deny direct access to `wp-content/uploads/shipconnect-private/` (shipping labels). The System status tab checks it.

== Frequently Asked Questions ==

= Is it free? =

Yes. ShipConnect is free and licensed under the GPL. Carrier accounts and shipping fees are billed by each carrier.

= My carrier has no API. Can I still use it? =

Yes: add it as a carrier without API, enter the tracking number given by the carrier, and the customer gets a tracking link.

= Does it work with cash on delivery? =

Yes. Cash-on-delivery orders can be held until you confirm them. The amount to collect is shown on the order and sent to carriers that support it (Sendit, Quick Livraison, Mylerz, Colivry, Atlas Livraison).

= Is my carrier API key safe? =

Keys are encrypted (libsodium) before being saved and are never shown again in the interface.

= Where are shipping labels stored? =

Label files returned by a carrier are stored in a protected folder (`wp-content/uploads/shipconnect-private/`) and only shown to shop managers. Uninstalling the plugin deletes them with all plugin data.

== Screenshots ==

1. Dashboard: alerts, key figures, orders to ship and latest shipments.
2. Analytics: delivery rate, delivery time, return rate, shipments per day, by status and by carrier.
3. Carriers: your carriers and the built-in API connections.
4. Worldwide carrier catalogue with country, region and API filters.
5. Connecting a carrier with your own API credentials.
6. Orders list with the "Confirmed" status: "Send to carrier" buttons and delivery column.
7. Order screen: the ShipConnect box with tracking page, status correction and cancellation.
8. Settings: sender address, order confirmation and automatic sending.
9. System status: versions, encryption, labels folder, scheduled tracking and webhook security.

== Changelog ==

= 0.2.0 =
* "Confirmed" order status, "Send to carrier" button, bulk action and automatic sending after confirmation.
* Rate check before buying, shipment correction and cancellation.
* Dashboard, analytics with charts, alerts and announcements.
* Moroccan connections: Sendit, Quick Livraison, Mylerz, Colivry, Atlas Livraison.
* English and French interface; text domain is now `shipconnect-for-woocommerce`.
* Worldwide catalogue (211 companies) with country filter and optional logos.
* Security hardening.

= 0.1.0 =
* First version: Shippo, EasyPost, DHL Express, Aramex, manual carriers, checkout rates, automatic tracking.

== Upgrade Notice ==

= 0.2.0 =
Confirm-then-ship workflow, analytics and alerts, and five Moroccan cash-on-delivery carriers. Test your carrier connections after updating.
