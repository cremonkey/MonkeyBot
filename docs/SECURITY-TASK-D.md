# SPEC-00 Task D — CSRF protection (DEFERRED, not blocking)

## Decision: CI global `csrf_protection` left FALSE for now.

### Why deferred
1. The app already has its OWN CSRF mechanism — `Home::csrf_token_check()` validates a
   per-session `csrf_token` on sensitive POST forms (login, signup, admin actions, AI
   settings save, etc.). So the highest-value forms are already protected.
2. Enabling CI's global CSRF would require EVERY state-changing request across the whole
   admin to carry CI's token. This app has hundreds of jQuery AJAX endpoints AND a
   **compiled/minified Vue SPA** (the Visual Flow Builder, `plugins/flow_builder/js/app.*.js`)
   whose XHR calls we cannot easily modify. Those would start returning 403 → broken builder.

### Groundwork shipped (inert)
- `assets/js/csrf-ajax.js` — a jQuery `ajaxPrefilter` that auto-attaches the CI token to all
  non-GET AJAX. Safe to include; does nothing until CSRF is enabled.

### To enable later (maintenance window)
1. Include `assets/js/csrf-ajax.js` after jQuery in the admin + member footer views.
2. Set in BOTH config copies: `csrf_protection = TRUE`, `csrf_regenerate = FALSE`.
3. Populate `csrf_exclude_uris` with all public/webhook/payment endpoints, e.g.:
   `messenger_bot/<webhook>`, `instagram_reply/<webhook>`, `cron_job/.+`, `paypal_ipn/.+`,
   `stripe_action/.+`, plus the new-channel webhooks `whatsapp_bot/webhook/.+`,
   `telegram_bot/webhook/.+`, `webchat/.+`.
4. Test the Visual Flow Builder end-to-end (it's the highest-risk consumer). If it breaks,
   add its save/postback endpoints to `csrf_exclude_uris` (they're still covered by
   session-auth) or wire the token into its build — then re-test.
