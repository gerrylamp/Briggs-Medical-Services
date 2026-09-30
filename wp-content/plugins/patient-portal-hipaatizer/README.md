# Patient Portal for HIPAAtizer

Version 1.1.2

A lightweight WordPress + Elementor patient portal that uses normal WordPress accounts for authentication and HIPAAtizer for patient forms.

## What this plugin stores

Only minimal portal metadata:

- WordPress user ID
- HIPAAtizer form ID
- HIPAAtizer submission ID
- Portal status
- Event name
- Submission/update timestamps

It deliberately does **not** save:

- form answers
- medical history
- diagnoses
- signatures
- PDFs
- attachments
- raw webhook payloads

This design reduces the amount of HIPAAtizer data copied into WordPress. It does not, by itself, make the surrounding WordPress environment HIPAA compliant. Have the Covered Entity's compliance/security team review hosting, backups, administrators, logs, CDNs, email systems, vendors and BAAs before using identifiable health-related status data in production.

## Install

1. Upload `patient-portal-hipaatizer.zip` in **WordPress → Plugins → Add New → Upload Plugin**.
2. Activate **Patient Portal for HIPAAtizer**.
3. Activation creates:
   - a **Patient** role
   - a **Patient Login** page
   - a **Patient Portal** page
   - the minimal submission-status database table
4. Open **Patient Portal → Settings**.
5. Edit the generated pages with Elementor if desired. Keep the portal shortcodes in an Elementor Shortcode widget.

## Elementor shortcodes

- `[patient_login]`
- `[patient_portal_dashboard]`
- `[patient_submission_statuses]`
- `[patient_form key="intake"]`
- `[patient_account]`
- `[patient_logout]`

## Add HIPAAtizer forms

Open **Patient Portal → Forms → Add Form**.

For each form enter:

- Form title
- Form key
- HIPAAtizer Form ID
- Official HIPAAtizer WordPress shortcode OR the published HTTPS form URL
- Optional patient instructions

The HIPAAtizer Form ID must match `form_id` in the webhook payload.

## Patient accounts

Create patient users in **WordPress → Users → Add New** and assign the **Patient** role.

For the default webhook matching workflow, the email address submitted in the HIPAAtizer form must be the same email address used on the patient's WordPress account.

The webhook can also match a patient by `wp_user_id` or `patient_username` if your workflow can safely provide one of those values.

## HIPAAtizer webhook endpoint

The endpoint is shown in **Patient Portal → Settings** and normally looks like:

`https://example.com/wp-json/patient-portal/v1/hipaatizer`

### Minimal JSON payload

Use HIPAAtizer's webhook payload builder to map only the values needed for the portal:

```json
{
  "event": "submitted",
  "form_id": "FORM-ID-FROM-HIPAATIZER",
  "submission_id": "SUBMISSION-ID-FROM-HIPAATIZER",
  "patient_email": "patient@example.com",
  "status": "submitted",
  "submitted_at": "2026-08-08T12:00:00Z"
}
```

Do **not** add clinical form answers to the payload unless you have independently designed and approved the WordPress environment to receive them.

### Supported patient matching keys

Preferred order:

1. `wp_user_id`
2. `patient_email`
3. `patient_username`

### Supported statuses

- `incomplete`
- `submitted`
- `under_review`
- `action_needed`
- `completed`
- `voided`

Aliases such as `partial`, `saved`, `returned`, `complete`, and `deleted` are normalized automatically.

## Webhook authentication

HIPAAtizer documents a signed `HIPAA-Signature` HMAC header. The plugin supports hex/base64 signatures and common prefixes, with SHA-256 as the default digest. HIPAAtizer's public webhook page does not currently specify the digest/encoding details, so verify the actual webhook behavior in the HIPAAtizer sandbox before production. The digest is selectable in plugin settings.

HIPAAtizer also supports custom Authorization headers. The plugin can use a Bearer token instead of HMAC, or require both.

For better secret handling you can put secrets in `wp-config.php`:

```php
define( 'PPH_WEBHOOK_SECRET', 'your-generated-hipaatizer-secret' );
define( 'PPH_WEBHOOK_BEARER_TOKEN', 'a-long-random-token' );
```

When constants are present they override values saved in WordPress options.

## Staff workflow

Open **Patient Portal → Submissions**.

Authorized WordPress administrators can change a patient's portal-facing status to:

- Submitted
- Processing
- Action Needed
- Completed
- Incomplete
- Voided

A manual staff status is preserved when webhook updates arrive. A webhook event recognized as deleted/voided can still mark the record Voided. If you intentionally need a trusted webhook to replace a manual status, include `"reset_manual_override": true` in that webhook payload.

## Save & Continue Later

HIPAAtizer can trigger webhooks when a patient saves a partial form. Configure a partial-save webhook using the same endpoint, but send:

```json
"status": "incomplete"
```

or

```json
"event": "partial"
```

The portal will display **Incomplete**. The actual resume flow remains inside HIPAAtizer.

## Production checklist

- Activate/publish forms from the appropriate HIPAAtizer Covered Entity account and complete the required HIPAAtizer BAA/setup.
- Use HTTPS everywhere.
- Use HMAC and/or a strong Authorization bearer token on the webhook.
- Test the webhook in a HIPAAtizer sandbox before production.
- Do not send unnecessary PHI in webhook JSON.
- Use strong WordPress administrator accounts and MFA where appropriate.
- Review WordPress hosting, backups, database access, logging, security services, CDN, transactional email and all vendors with the Covered Entity's HIPAA/security team.
- Keep WordPress, Elementor, HIPAAtizer and this plugin updated.

## First test

1. Create a WordPress user with the Patient role.
2. Make sure its email matches the email you will submit in your HIPAAtizer sandbox form.
3. Configure the form under **Patient Portal → Forms**.
4. Configure the HIPAAtizer webhook and secret.
5. Submit the sandbox form.
6. Open **Patient Portal → Overview** and confirm the webhook health shows success.
7. Log in as the Patient user and confirm the status appears in the dashboard.


## Version 1.0.2 compatibility fix

Version 1.0.2 removes PHP 8.0/8.1-only syntax from the initial build, including union return types, `mixed`, `str_contains()`, typed properties, and the `never` return type. The plugin now declares PHP 7.4+ compatibility. This fixes activation-time fatal/parse errors on hosts using PHP 7.4 or PHP 8.0.


## v1.0.5

- Fixes valid portal-created patients being redirected back to login when another plugin (such as WooCommerce) changes or retains the account's primary role.
- Adds a durable `_pph_patient_account` marker for portal-created accounts.
- Automatically repairs v1.0.2 patient accounts and re-attaches the Patient role without removing other roles.

## v1.0.2 registration update

- Adds a **Create New Account** button to the Patient Login screen.
- Automatically creates a **Create Patient Account** page using `[patient_register]`.
- Self-registered users are assigned only the **Patient** role.
- Requires email verification before a newly registered patient can sign in.
- Includes verification-link resend support.
- Restricts patient portal pages and webhook account matching to Patient-role users.
- Replaces the remaining PHP 8-only `str_starts_with()` call for PHP 7.4 compatibility.


## v1.0.5
- Fixes Patient Account Required loop for existing WooCommerce Customer/WordPress Subscriber accounts.
- Successful authentication through Patient Login now safely provisions Patient Portal access for non-privileged customer/subscriber users.
- Staff/admin accounts are never automatically converted to patients.
- Adds no-cache headers to Patient Login, Registration, and Portal pages to reduce stale authentication output from page caches/CDNs.


## Automatic patient email prefilling (v1.0.5)

Each configured portal form now has an **HIPAAtizer email field Unique Name** setting and an **Automatically prefill the logged-in patient's email** checkbox.

1. In HIPAAtizer, edit the patient email component and copy its exact **Unique Name** (for example `email` or `patient_email`).
2. In WordPress, go to **Patient Portal → Forms**, edit the matching form, and paste that value into **HIPAAtizer email field Unique Name**.
3. Keep **Automatically prefill the logged-in patient's email** enabled.
4. In the HIPAAtizer webhook body, map that HIPAAtizer email field to the portal key `patient_email`. For example, if the HIPAAtizer Unique Name is `patient_email`, use a webhook value sourced from that field for the JSON property `patient_email`.

The portal uses HIPAAtizer's documented URL-parameter capture behavior for embedded forms. For a direct published form URL, the email parameter is appended to the iframe URL. For the official HIPAAtizer WordPress shortcode, the portal also passes the parameter through the parent page during embed initialization and rewrites direct iframe output when available.

## v1.1.0 — Modern staff dashboard

This release is based on the working v1.0.5 webhook/patient-linking branch and adds a redesigned WordPress staff experience without changing the HIPAAtizer webhook endpoint behavior.

New features:

- Modern **Patient Portal Overview** with summary cards, status donut chart, submission activity graph, form activity bars, quick actions and recent submissions.
- Select one or more **Client dashboard users** under **Patient Portal → Settings**.
- Selected staff users can be redirected directly to Patient Portal Overview after WordPress login.
- The standard WordPress Dashboard menu can be hidden for selected staff users.
- Patient Portal is moved near the top of the admin menu for selected staff users.
- Adds a `manage_patient_portal` capability and a **Patient Portal Manager** role with Media Library + Patient Portal access.
- Settings remain administrator-only; Patient Portal Managers can access Overview, Forms and Submissions.
- Job List quick action is detected automatically from an existing WordPress admin menu whose title/slug contains “job”.

### Recommended setup for Briggs Admin

After updating, sign in with your main Administrator account and open **Patient Portal → Settings → Client Admin Dashboard**. Select **Briggs Admin**, keep **Send selected users directly to Patient Portal Overview after login** checked, and keep **Hide the standard WordPress Dashboard menu** checked.

The optional **Patient Portal Manager** role is safer than Administrator for long-term client access, but Job List plugins use different capabilities. Confirm the Jobs menu works with that role before removing Administrator privileges from the client account.


## Version 1.1.1

- Removed **Incomplete** from the staff-editable Submission History status dropdown.
- Renamed the patient-facing/admin display label **Under Review** to **Processing** while preserving the internal `under_review` key for compatibility with existing records and webhooks.
- HIPAAtizer partial/save events may still use internal `incomplete` status; staff can no longer manually assign it.


## Version 1.1.2

- Requires patients to have a submitted/completed Patient Intake Form before service forms can be opened.
- Enforces the intake requirement inside form rendering so direct `pph_form` URL access cannot bypass it.
- Providers bypass the intake requirement and can access service forms directly.
