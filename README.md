# Apostolic Spotlight Outreach

A WordPress plugin that adds pastor outreach, Spotlight interview scheduling, and opportunity tracking to the existing **Apostolic Spotlight** church directory. It sends through your Google Workspace Gmail account, and it books interviews on your Google Calendar with Google Meet.

The plugin lives in [`apostolic-spotlight-outreach/`](apostolic-spotlight-outreach/). Zip that folder and upload it under **Plugins → Add New → Upload**, or copy it to `wp-content/plugins/`.

---

## 1. Install & first-run checklist

1. Activate **Apostolic Spotlight Outreach**. A top-level admin menu appears with these pages: Dashboard, Churches, Campaigns, Email Templates, Queue, Bookings, Opportunities and Settings.
2. **Settings → Church Directory**: confirm the post type that holds your churches and check the field mapping (see §2). Save.
3. **Settings → Google Workspace**: create the OAuth client (steps are shown on the page) and click **Connect Google Workspace**.
4. **Settings → Sending**: set the daily limit, days, hours and timezone.
5. **Settings → Booking**: set your weekly interview hours. Then visit `/spotlight/schedule/`.
6. Recommended: add the following to `wp-config.php`. Then add a real cron job that runs every 5 minutes: `*/5 * * * * curl -s https://YOURSITE/wp-cron.php?doing_wp_cron > /dev/null`.

```php
define( 'DISABLE_WP_CRON', true );          // use a real cron (step 6)
define( 'ASO_GOOGLE_CLIENT_ID', '...' );     // optional: keeps OAuth creds out of the DB
define( 'ASO_GOOGLE_CLIENT_SECRET', '...' );
define( 'ASO_ENCRYPTION_KEY', 'long-random-string' ); // optional: token encryption key
```

---

## 2. Existing church data — what is reused

> **Important:** this repository did not contain your site's code or database. I couldn't inspect the exact storage, so the plugin uses an **adapter** that works with any directory stored as a custom post type. That covers CPT + ACF, CPT + plain post meta, and taxonomy-based directories such as Directorist or Business Directory. GeoDirectory-style custom tables can be supported through filters (§9).

- **Source of truth = the existing church post.** The plugin never copies churches into its own table. Everything is keyed by the directory post ID.
- **Auto-detection.** The plugin finds the post type whose name or label contains "church". It then matches meta keys, ACF field names and taxonomies against common names: `pastor_name`, `pastor`, `pastor_email`, `email`, `phone`, `website`, `city`, `state`, `denomination`, `organization`, `affiliation`, and so on. The detected mapping is used immediately, until you confirm or adjust it on **Settings → Church Directory**. That screen shows a sample value for each field.
- **Each outreach field maps to one source:**
  - post title
  - permalink (used for the directory listing URL)
  - an ACF field or meta key
  - a taxonomy (shown read-only in outreach screens)
  - "outreach field", only when the directory doesn't have that field. The value is stored as `_aso_{field}` meta on the *same* church post.
- **Writing back.** Edits made on the outreach screens are written to the mapped directory field. ACF fields are written with `update_field()`, so ACF's reference meta stays intact. This can be switched off.
- **Booking forms never overwrite directory data.** Blank fields are filled in. Any differences are logged on the timeline for you to review.
- **The directory's own screens are untouched.** The plugin adds only a read-only "Spotlight Outreach" metabox and an "Outreach" row action on the church edit/list screens.

Fields: church name, pastor name, pastor email, church email, phone, website, denomination/organization, city, state, listing URL, contact preference (pastor email / church email / phone / do not email), notes.

---

## 3. File structure

```
apostolic-spotlight-outreach/
├── apostolic-spotlight-outreach.php   bootstrap, constants, activation hooks
├── uninstall.php                      keeps data unless ASO_DELETE_DATA_ON_UNINSTALL
├── includes/
│   ├── class-autoloader.php  class-plugin.php  class-installer.php (dbDelta schema)
│   ├── class-capabilities.php  class-settings.php  class-util.php  class-crypto.php
│   ├── class-statuses.php          Spotlight / Website / Grow / review vocabularies
│   ├── class-activity.php          per-church timeline
│   ├── class-templates.php  class-template-renderer.php
│   ├── class-campaigns.php  class-enrollments.php (safety checks, automation stops)
│   ├── class-queue.php             materialize → plan slots → send
│   ├── class-mailer.php  class-email-log.php  class-reply-detector.php
│   ├── class-cron.php  class-metrics.php
│   ├── church/    class-field-map.php  class-church.php  class-church-repository.php  class-outreach-state.php
│   ├── google/    class-google-oauth.php  class-google-client.php  class-gmail.php  class-calendar.php
│   ├── booking/   class-availability.php  class-bookings.php  class-booking-frontend.php
│   └── admin/     class-admin.php  class-admin-actions.php  class-churches-table.php  class-metabox.php
│                  views/*.php
├── templates/   booking-page.php  booking-confirmation.php  booking-block-theme.php
└── assets/      css/admin.css  css/booking.css  js/admin.js  js/booking.js
```

The plugin has no Composer or vendor dependencies. Google is called through the WordPress HTTP API.

---

## 4. Database schema (all datetimes are UTC; created with `dbDelta`, versioned by `aso_db_version`)

| Table | Purpose |
|---|---|
| `wp_aso_church_outreach` | One row per directory church (`church_id` = post ID). Holds spotlight_status, website_status, grow_status, website_review, grow_fit, paused, needs_review + reason, booking_token (unique, random), first/last contacted, replied_at. Indexed for filtering thousands of churches. |
| `wp_aso_campaigns` | name, description, status (draft/active/paused/archived), optional start date |
| `wp_aso_campaign_steps` | sequence: step_number, template_id, delay_days (from previous send), reply_in_thread |
| `wp_aso_enrollments` | church ↔ campaign (unique pair), status (active/finished/completed/stopped/paused), current_step, next_step_due_at, Gmail thread id + first Message-ID/subject (for threading), stop_reason, override note |
| `wp_aso_queue` | one row per email to send: pending → scheduled (slot) → sending → sent / failed / held / canceled |
| `wp_aso_email_log` | every outgoing email (subject, rendered body, template, campaign, step, Gmail message/thread IDs, error) and detected inbound reply/bounce headers |
| `wp_aso_activity` | the timeline: emails, replies, status changes, opportunity changes, bookings, notes, automation stops |
| `wp_aso_bookings` | public_id (random), church_id (nullable until matched), contact details, start/end, Google event ID, calendar, Meet URL, status |
| `wp_aso_templates` | editable templates by category (invitation, follow_up, website, grow, general) |

Statuses stay separate. The Spotlight pipeline uses the 12 statuses you listed. Website (Unknown → Won/Lost) and Apostolic Grow (Unknown → Customer/Lost) are independent fields. The post-Spotlight reviews are also separate fields: Website = Looks Good / Needs Improvement / Strong Opportunity, and Grow = Unknown / Possible Fit / Strong Fit.

---

## 5. Google OAuth flow & scopes

**Flow (web server OAuth 2.0):**
1. You click **Settings → Connect**.
2. `admin-post.php?action=aso_google_connect` checks the nonce and the `aso_manage_settings` capability.
3. You're redirected to Google consent with `access_type=offline`, `prompt=consent`, `include_granted_scopes=true` and a random `state` (stored per user for 15 minutes).
4. Google redirects to `admin-post.php?action=aso_google_callback`. The plugin verifies `state` and the capability, then exchanges the code for tokens.
5. The connected email is read from the `id_token`.

**Token storage:** tokens are encrypted with libsodium secretbox. The key comes from `ASO_ENCRYPTION_KEY`, or is derived from your AUTH salts. They're kept in non-autoloaded options. Access tokens refresh automatically when they're within 60 seconds of expiry, and once more on any 401. An `invalid_grant` response marks the account disconnected and shows an admin notice. **Disconnect** revokes the token at Google.

**Scopes (least-privilege):**

| Scope | Why |
|---|---|
| `openid`, `email` | show which account is connected |
| `gmail.send` | send only; **cannot read** the mailbox. Sent mail appears in your Gmail Sent folder |
| `calendar.events` | create, move and cancel interview events, and request Google Meet (`conferenceData.createRequest`, `conferenceDataVersion=1`, `sendUpdates=all`) |
| `calendar.freebusy` | read busy blocks only; no event titles or details |
| `gmail.metadata` *(optional)* | reply detection only: headers (From, Subject, auto-reply headers) and thread IDs, **never bodies**. Requested separately, with an explanation, only when you turn on Reply Detection |

Use an **Internal** OAuth consent screen in your Workspace Google Cloud project. Google's app verification process doesn't apply to Internal apps, so you can use these scopes without it.

Campaign email **never** falls back to `mail()`/`wp_mail()`. If Google is disconnected, emails stay in the queue.

---

## 6. Scheduled email processing

WP-Cron runs `aso_cron_queue` every 5 minutes (use a real cron job for reliable timing). Each run takes a database lock, then:

1. **Reply detection** runs first, if it's enabled. A reply that just arrived therefore cancels the next follow-up before it can send.
2. **No-response sweep.** When a sequence's last email is sent, it waits *N* days (setting). If there's still no reply, the church moves to *No Response*.
3. **Materialize.** Each *active* enrollment in an *active* campaign whose next step is due gets one `pending` queue row.
4. **Plan.** Pending rows get send times inside the window (days, hours, timezone). Follow-ups are planned before new invitations. The planner fills today up to the daily limit (counting sent + scheduled), then rolls the rest to the next allowed days. Times are spread across the window, either exactly evenly or evenly with random jitter, and are kept at least *min spacing* minutes apart.
5. **Send.** Each due row is claimed atomically (`scheduled → sending`), so overlapping runs never double-send. Before sending, the plugin re-checks the enrollment, campaign, pause flag and Spotlight status, then renders the template and sends through Gmail.
   - Follow-ups go as `Re:` in the same Gmail thread (`threadId` + `In-Reply-To`/`References`).
   - Missing data (no email, or a variable such as `{{pastor_name}}` that's blank with no fallback) puts the row on **held for review** instead of sending "Pastor ,". Fallbacks use `{{pastor_first_name|friend}}` syntax.
   - Transient errors retry up to 3 times, then the row is marked failed and flagged for review.
   - A row stuck in `sending` is held for review instead of being re-sent, to avoid a duplicate email.
   - Rows scheduled for an earlier day (because cron was down) are re-planned rather than sent at night.

Hard cap: 100 emails per day (default 25). There's no open or click tracking. The emails are plain and personal.

**Automation stops immediately** when a church:
- replies (detected or "Mark Replied")
- books
- is marked Interested, Completed, Published or Not Interested
- is paused
- bounces
- is removed from the campaign or its campaign is archived

Pending queue rows for that church are canceled, and the stop is logged on the timeline.

**Duplicate protection** runs before enrollment (review screen):
- **Blocked:** no email, "do not email", already in this campaign.
- **Warnings, skipped unless you tick "Override":** active in another campaign, contacted within *N* days, replied before, Not Interested, Spotlight already scheduled/completed, outreach paused.

---

## 7. Reply detection

- **Always available:** **Mark Replied** (single or bulk). It cancels pending emails immediately.
- **Optional automatic detection:** this needs the extra `gmail.metadata` permission. The settings screen explains it before requesting it.
  - It uses incremental `users.history.list` (new INBOX messages since the last `historyId`) and fetches only headers.
  - **What counts as a reply:**
    - a message in a thread the plugin started (this catches a secretary replying), or
    - a message from an address the plugin emailed.
  - If the history cursor has expired (~1 week), the plugin falls back to checking each open enrollment's thread.
  - **How each match is handled:**
    - **Real reply:** the church is marked Replied, automation stops, and it's flagged "respond personally".
    - **Auto-reply / out-of-office** (`Auto-Submitted`, `X-Autoreply`, `Precedence`, subject): logged and flagged, but the sequence continues.
    - **Bounce** (`mailer-daemon`/`postmaster`): the sequence stops and the address is flagged.
  - Inbound message IDs are logged, so nothing is processed twice.

---

## 8. Booking availability

- **Public page:** `/spotlight/schedule/` (the path is configurable), or the `[apostolic_spotlight_booking]` shortcode on any page. Personal links look like `/spotlight/schedule/?church=<random 24-char token>`. They pre-fill the church, pastor and email, and the pastor can edit everything. Pages are sent with no-cache headers and `noindex`.
- **How times are calculated:**
  - **Candidates:** your weekly hours (up to 2 ranges per day, in the booking timezone), stepped every *slot interval* and *duration* long, between **now + minimum notice** and **now + booking window**. DST is handled by PHP timezones.
  - **Removed:** any slot where `[start − buffer before, end + buffer after]` overlaps:
    - a **Google free/busy** block from any configured calendar (cached for 3 minutes; checked fresh at booking time), or
    - an existing confirmed booking.
  - **Also removed:** days that have hit the optional *max interviews/day*.
  - **Fails closed:** if Google isn't connected or a calendar can't be read, no times are shown.
- **Booking** (REST `POST /wp-json/aso/v1/booking`; honeypot field; 8 attempts per hour per IP):
  - takes a MySQL lock and re-validates the slot against fresh free/busy, which prevents double booking
  - creates the Calendar event ("Apostolic Spotlight – {{church_name}}", the pastor as attendee, you as organizer, church details in the description, a Meet conference) and Google emails the invitation
  - saves the event ID, Meet URL, start/end and attendee
  - sets the church to *Spotlight Scheduled* and stops automation
  - shows a confirmation page with the date, time, timezone, Meet link, an "Add to Google Calendar" link and a `.ics` download
  - matches a booking that came through the generic link (no token) to a church by email. If there's no unique match, the booking appears under **Bookings → Unlinked** for one-click linking.
- **Admin:**
  - tabs for upcoming, past (mark outcome), completed, canceled and unlinked
  - actions: Open Church, Join Meet, **Reschedule** (moves the event; Google emails the update), **Cancel** (deletes the event and notifies the pastor), **Mark Spotlight Completed**, No-show

---

## 9. After the Spotlight & opportunities

Marking a Spotlight Completed or Published **never sends anything**. It adds the church to **Opportunities → Needs review**. There you record the Website review and the Grow fit, plus each pipeline status, independently.

**Website** and **Apostolic Grow** follow-ups are separate templates, and they can't be added to automated sequences. To send one, use **Send Email → template → edit → Send**. A sent website or Grow email moves that opportunity from Unknown to Potential.

**Extension points for later versions:**
- `aso_loaded`
- `aso_church_post_type`, `aso_church_read_field`, `aso_church_write_field` (for custom-table directories)
- `aso_template_context` (extra variables)
- `aso_spotlight_status_changed`, `aso_activity_logged`, `aso_booking_created`, `aso_booking_unlinked`

---

## 10. Permissions & security

- **Capabilities:**
  - `aso_manage_outreach`: daily use
  - `aso_manage_templates`: edit templates
  - `aso_manage_settings`: Google, OAuth, limits, mapping
- **Roles:** Administrators get all three. Editors can optionally be given outreach and templates under **Settings → Permissions**. They never get Settings or tokens.
- **Request handling:** every admin action is an `admin-post` handler with a nonce and capability check. Input is sanitized, output is escaped, and all SQL uses `$wpdb->prepare` or integer-only lists.
- **Public endpoints** expose only open start times and the booking form result. Booking tokens and confirmation IDs are random, never sequential IDs.

---

## 11. What was tested

Testing used WordPress 7.1 + MariaDB, a sample directory (a `church` CPT with meta and an `organization` taxonomy, 60 churches) and **mocked** Google endpoints:

- Field auto-detection and mapping.
- Filtering by organization + state.
- Bulk select → Add to Campaign → safety review → enroll.
- Queue planning within limits.
- Gmail send with held-for-review on a missing pastor name.
- Threaded follow-ups.
- Stopping on Mark Replied, a detected reply, a bounce, and a booking. An auto-reply does not stop the sequence.
- Free/busy conflicts removing slots.
- Booking through a personal link with pre-fill, the Meet link, and a double-booking rejection.
- Admin reschedule/cancel, completion → opportunity review, and manual website follow-up.
- Every admin screen renders with no PHP notices.

**Not yet exercised: live Google APIs.** Before your first real campaign:
- connect your account
- send one manual test email to yourself
- book a test slot

Out of scope for v1, as requested: SMS, newsletters, inbox replacement, AI generation, forecasting, payments, Grow provisioning, site building, social scheduling.
