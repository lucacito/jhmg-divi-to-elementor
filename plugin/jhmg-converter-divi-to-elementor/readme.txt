=== JHMG Converter For Divi to Elementor ===
Contributors: lucaslopvet
Tags: divi, elementor, converter, migration, page builder
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://www.paypal.com/donate/?hosted_button_id=PNMHRFF94M2Y2
Requires Plugins: elementor

Convert Divi layouts to Elementor pages with one click — upload a JSON export and get a ready-to-edit Elementor draft instantly.

== Description ==

**JHMG Converter For Divi to Elementor** lets you migrate your Divi-built pages to Elementor without rebuilding anything manually. Export your layouts from the Divi Builder Library, upload the JSON file, and the plugin creates fully converted Elementor draft pages — structure, content, and styles included.

No Divi license needed on the destination site. Works with standard page exports, full Builder Library exports, and Theme Builder templates (headers, footers, body layouts).

= Features =

* Convert single or multiple Divi layouts at once
* Upload one or more JSON files in a single import
* Full layout, content, and style preservation
* 35+ Divi modules supported
* Batch results view with per-page Edit, Preview, and Publish actions
* No Divi required on the destination site
* Supports et_builder, et_builder_layouts, and et_theme_builder export formats

= Supported Divi Modules =

**Content:** Text, Heading, Button, Image, Video, Audio, Code, Divider, Spacer
**Layout:** Section, Row, Column (full nesting support)
**Interactive:** Call to Action, Blurb, Testimonial, Accordion, Toggle, Tabs
**Media:** Gallery, Video Slider
**Navigation:** Menu, Fullwidth Menu, Sidebar
**Social & Forms:** Social Media Follow, Contact Form, Login, Search, Email Optin
**Data:** Map, Countdown Timer, Number Counter, Circle Counter, Bar Counters
**Misc:** Icon, Team Member, Pricing Tables, Blog, Portfolio, Filterable Portfolio, Post Content
**WooCommerce:** Product Title, Images, Price, Description, Add to Cart, Rating, Reviews, Breadcrumb, Additional Info, Related Products, Cart Notice

= Also by JHMG =

Need to go the other direction? **[JHMG Converter For Elementor to Divi](https://wordpress.org/plugins/jhmg-converter-for-elementor-to-divi/)** converts Elementor layouts back to Divi — the perfect companion plugin for agencies managing both builders.

= How It Works =

1. In Divi, go to **Divi Library → Portability** and export your layouts as a JSON file.
2. In WordPress, go to **Tools → Divi → Elementor** and upload the JSON.
3. The plugin converts every layout into an Elementor draft page — ready to open and edit.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin via **Plugins → Installed Plugins**.
3. Go to **Tools → Divi → Elementor** to start converting.

== Frequently Asked Questions ==

= Do I need Divi installed on the destination site? =

No. The plugin reads the Divi JSON export format and converts it to Elementor data. Divi is not required on the site where you are importing.

= Do I need Elementor installed? =

Yes. Elementor (free or Pro) must be active on the site where you are importing layouts.

= What export formats are supported? =

The plugin supports all three Divi JSON export types:

* **et_builder** — standard single-page export
* **et_builder_layouts** — Divi Builder Library export (one or many layouts)
* **et_theme_builder** — Theme Builder template export (header, footer, body)

= Can I import multiple layouts at once? =

Yes. You can upload one or more JSON files in a single import. Each layout in the file becomes its own Elementor draft page.

= Will all styles be preserved exactly? =

The converter maps Divi module settings (colors, fonts, spacing, borders, backgrounds) to their Elementor equivalents. Most visual properties transfer correctly. Some advanced Divi-specific features (custom CSS classes, Divi shortcode-based modules not in the supported list) are passed through as HTML or skipped.

= Can I convert Theme Builder headers and footers? =

Yes. Theme Builder exports (et_theme_builder) are supported. Each layout (header, footer, body) is created as a separate Elementor draft. Note that displaying them as actual theme templates requires Elementor Pro's Theme Builder.

= Where do I find the converted pages? =

After conversion, the plugin redirects you to a results page with direct links to edit, preview, or publish each converted layout. You can also find them under **Pages** (or **Elementor → My Templates** for theme templates) with a "converted from Divi" title.

== Screenshots ==

1. Export Divi layouts using the Divi Library Portability tool to get a JSON file — then upload it to the converter.
2. The converter admin page: upload one or more Divi JSON files and choose your import options.

== Changelog ==

= 1.0.0 =
* Initial release.
* Single and bulk layout conversion supported.
* 35+ Divi modules mapped to Elementor equivalents.
* Supports et_builder, et_builder_layouts, and et_theme_builder export formats.
* WooCommerce module support included.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
