# SPEC-05: Product catalog inside the chat (Phase 2.1)

> READ `.specs/MASTER-PLAN.md` header first. Do not run concurrently with any spec touching Messenger_bot.php or Ecommerce.php.

## Constraint (verified)
The Visual Flow Builder canvas is a compiled/minified Vue SPA (`plugins/flow_builder/js/app.*.js`, no source) — we CANNOT add a new node type. Catalog is delivered backend-side: (a) a carousel generator, (b) postback handlers for Add-to-Cart, (c) an AI tool that sends the carousel.

## Task 1 — Study send + postback paths (READ ONLY, report findings first in your log)
- How Messenger_bot.php sends a generic/carousel template (grep `generic` / `elements` / `attachment` in Messenger_bot.php and Home.php; find the reusable send-API function and its exact payload format).
- How incoming postbacks are routed in the webhook (grep `postback` in Messenger_bot.php) and where a custom payload prefix can be intercepted safely.

## Task 2 — Carousel helper
Create `application/helpers/ecommerce_catalog_helper.php`:
- `ecom_catalog_elements($user_id, $store_id=null, $category_id=null, $limit=10, $search=null)` → array of FB generic-template elements: title (name + price), image_url (product thumbnail — inspect how product images are stored/served), subtitle, buttons: [postback `ECOM_ADDCART_<product_id>` "🛒 Add to Cart", web_url to the product page "View"]. Uses live `ecommerce_product`/`ecommerce_store` columns (verify with SHOW CREATE TABLE).
- `ecom_send_catalog($page_access_context, $recipient_id, $elements)` — wraps the existing send function found in Task 1 (reuse, don't duplicate the Graph API call).

## Task 3 — Postback handler
In the Messenger webhook postback path (location from Task 1): intercept payloads starting `ECOM_ADDCART_`:
- Resolve product + store; find-or-create an open cart row for this subscriber (study how Ecommerce.php's store front creates `ecommerce_cart`/`ecommerce_cart_item` rows — reuse its column conventions exactly; set a source marker if a column allows).
- Reply with a text confirmation + checkout link (reuse the store's checkout URL builder found in Ecommerce.php ~3057 area / cart page URL) — and if lead_scoring helper exists (SPEC-07), call `lead_add_score($subscriber_id,'add_to_cart')`.
- MUST be defensive: wrong/foreign product id → silent log + generic reply. Never fatal in the webhook path.

## Task 4 — AI tool integration (if SPEC-03 done)
Add tool `send_product_catalog` {search_query?, limit?} to `application/libraries/Ai_tools.php`: builds elements via helper, sends carousel via helper using the context's page/subscriber, returns "catalog sent" so the model can follow up. Skip gracefully if Ai_tools.php absent.

## Task 5 — Verify
Lint; smoke; SQL-verify all column names used; simulate postback handling logic via code-trace (document the exact webhook JSON shape you handle). Commit: `feat: in-chat product catalog with add-to-cart postbacks`.
