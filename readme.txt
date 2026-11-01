=== DEV3 AI Store Insights for WooCommerce ===
Contributors: dev3tech
Tags: woocommerce, analytics, reports, ai, dashboard
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 10.3
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ask your WooCommerce store questions in plain English and get charts, AI explanations, pinned dashboards, email digests and anomaly alerts.

== Description ==

Type "best sellers in Dubai last month" and get a clean chart, the table behind it and a short explanation.

DEV3 AI (Google Gemini, your own API key) reads the question and picks one report from a fixed library of safe, parameterised reports. It only fills in typed options such as the period, the measure or a location. The model never writes database queries, and every option is checked again before the report runs.

Everything also works without AI. Built-in rules understand the common questions, and Explore lets you build any report by hand.

= Reports =
* Store summary: net sales, orders, average order value, items sold, refunds, customers and new customers, each with a change against the previous period or the same period last year
* Sales trend by day, week, month, quarter or year
* Top products and top categories, ranked by revenue, quantity or orders
* Sales by country, region/state or city
* New vs returning customers, registered vs guest customers
* Top customers
* Refunds over time and the most refunded products
* Monthly customer cohorts, showing retention or revenue
* Stock: low stock, out of stock, days of stock left and overstock
* Top coupons
* Filters: location, product category, product, customer type and account type
* Periods: presets or a custom date range, in your site's timezone and week start

= Store owners also get =
* Saved questions and a dashboard of pinned insights, with one date range for every card
* Scheduled e-mail digests (daily, weekly or monthly), with a preview, a test send and an optional DEV3 AI summary paragraph
* Anomaly alerts: daily checks against a 28-day baseline. Each alert shows the products and countries that moved most, can be explained by DEV3 AI and can be sent by e-mail. A "best sellers about to run out" stock alert is included. DEV3 AI can suggest alert rules, and nothing is saved until you accept them.
* CSV export of any report
* A WordPress dashboard widget
* Light and dark themes. Charts are accessible: keyboard navigation, tooltips, a table view and colour-blind-safe palettes.
* HPOS and Cart/Checkout blocks compatible. Reports read the WooCommerce Analytics tables. A data health check imports any order the Analytics tables missed.

== Installation ==

1. Upload the zip in Plugins > Add New > Upload Plugin and activate it. WooCommerce 8.0+ must be active.
2. Open **DEV3 AI Insights** in the admin menu.
3. Optional: add your Google Gemini API key in **API Config** to enable DEV3 AI.

== Frequently Asked Questions ==

= Does the AI see my customers' data? =
DEV3 AI receives your question plus aggregated report rows. E-mail addresses are never sent. Customer names are replaced with "Customer 1, 2…" unless you allow names in Settings.

= Can the AI break or change my data? =
No. The AI can only choose a report and its options. Reports are read-only and run as prepared statements.

= Do I need an API key? =
No. Without a key, questions are answered by the built-in rules, and explanations are short summaries the plugin writes itself.

= My numbers look low =
WooCommerce fills its Analytics tables in the background. Settings > Data health shows any missing orders and can import them.

== Privacy ==

When DEV3 AI is enabled, questions and aggregated report data are sent to Google Gemini under your own API key. The plugin adds a suggested text to your privacy policy, plus a personal-data exporter and eraser for each user's question history.

== Screenshots ==

1. Dashboard with KPIs and pinned insights
2. Ask your store: chart, interpretation and DEV3 AI explanation
3. Dark mode
4. Anomaly alert with drill-down
5. E-mail digests
6. Mobile

== Changelog ==

= 1.0.0 =
* Initial release.
