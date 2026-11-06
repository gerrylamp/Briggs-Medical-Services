Briggs Stripe Checkout — Sandbox prototype

This prototype creates fixed-price Stripe Checkout sessions for $200 deposit or $775 full payment, verifies signed Stripe webhooks, and sends a confirmed payment result back to the original Elementor form tab. It is not approved for live payments until end-to-end testing and security review are complete.

INSTALL
1. In WordPress staging, go to Plugins > Add New Plugin > Upload Plugin.
2. Upload briggs-stripe-checkout.zip, install, and activate.
3. Open Settings > Briggs Stripe Checkout.
4. Enter the Stripe restricted Sandbox secret key (starts rk_test_) in the key field. Never use a live key on staging.
5. Copy the webhook URL shown on that page. In Stripe Sandbox, create an endpoint for that URL and subscribe to checkout.session.completed and checkout.session.async_payment_succeeded.
6. Copy that endpoint's signing secret (whsec_...) into the plugin settings and save.

ELEMENTOR SETUP (do not change until the plugin is configured)
- Add an Elementor Shortcode widget on the Payment step with [briggs_stripe_checkout]. Do not use an HTML widget for the shortcode.
- Add Elementor Hidden fields with these IDs: payment_status, amount_paid, payment_option_paid, stripe_session_id.
- Existing custom JavaScript may conflict with the new widget. Review and remove conflicting code only after the new flow has been tested safely on staging.
- Keep the current Easy Stripe widget in place until the replacement has passed tests.

TESTING
Use Stripe Sandbox only. Test both amounts with test card 4242 4242 4242 4242, any future expiry, and any CVC. Confirm the webhook endpoint receives events, the original form tab receives the verified payment, hidden fields are populated, and Elementor saves the submission correctly.

SECURITY / LIMITATIONS
- Never put API keys in HTML, JavaScript, shortcode attributes, or public repositories; never paste them into chat.
- The plugin relies on a signed Stripe webhook and stores a short-lived payment status in WordPress transients.
- This prototype does not create a separate registration database record. Elementor must submit the registration after payment and include the hidden fields in its saved submission.
- Do not use with live payments without security review, client approval, and full testing.
