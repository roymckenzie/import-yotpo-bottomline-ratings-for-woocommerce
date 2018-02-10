=== Import Yotpo Bottomline Ratings for WooCommerce ===
Contributors: roypmckenzie, mintunmedia
Tags: woocommerce, yotpo, ratings, api
Donate link: https://paypal.me/roypmckenzie/3
Requires at least: 4.9.4
Tested up to: 4.9.4
Requires PHP: 5.5.38
Stable tag: trunk
License: MIT
License URI: https://opensource.org/licenses/MIT

Import Yotpo Bottomline Ratings into your WooCommerce products meta.

== Description ==
Import Yotpo Bottomline Ratings for WooCommerce connected to the Yotpo in the average rating and the number of reviews as meta fields on your products so you can query them to determine most popular products, most reviewed products, etc.

Connects to the Yotpo API at regular intervals of your choosing or on-demand to update ratings.

== Installation ==
1. Upload "import-yotpo-bottomline-ratings-for-woocommerce.php" to the "/wp-content/plugins/" directory.
2. Activate the plugin through the "Plugins" menu in WordPress.
4. Add your Yotpo API Key and API Secret pair on the settings panel *WooCommerce->Import Yotpo Ratings*.

== Frequently Asked Questions ==
= What are the meta keys for the Yotpo product score and the Yotpo total reviews that are added to my products? =
The meta keys are `yotpo_product_score` and `yotpo_total_reviews`, respectively.

== Screenshots ==
1. Settings Page.

== Changelog ==
= 0.1 =
* Initial release.