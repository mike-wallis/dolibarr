# South Side Supplies — Dolibarr Outbound Email Setup

Two brands, one Dolibarr instance, one SMTP relay. BCS uses Google Workspace
(`accounts@brightcs.com.au`); SSS uses Gmail (`southsidesupplies.yes@gmail.com`).
The Workspace account acts as the SMTP relay for both.

**Status: confirmed working as of 2026-06-04.**

---

## Architecture

- **SMTP relay:** Google Workspace (`smtp.gmail.com:587`)
- **Authenticates as:** `michaelw@brightcs.com.au` (primary Workspace account)
- **Default From:** `accounts@brightcs.com.au` (Workspace alias — BCS documents)
- **SSS From:** `southsidesupplies.yes@gmail.com` (set manually per-send in Dolibarr dialog)

---

## Gotchas / Lessons Learned

### 1. Authenticate with the PRIMARY email, not the alias
`accounts@brightcs.com.au` is a Workspace alias of `michaelw@brightcs.com.au`, not a
separate user. Google SMTP rejects auth attempts using alias addresses.
**SMTP username must be `michaelw@brightcs.com.au`.**

### 2. Workspace alias must also be added as "Send as" in Gmail settings
Even though `accounts@brightcs.com.au` is an admin-assigned Workspace alias, Gmail
will send as the primary address unless the alias is explicitly added under
**Gmail Settings > Accounts > Send mail as**. Tick "Treat as alias" — Google verifies
it instantly. Without this step, outgoing mail shows `michaelw@brightcs.com.au`, not the alias.

### 3. App Password required — regular password won't work
Google disabled basic SMTP password auth in 2022. App Passwords are the replacement.
2-Step Verification must be enabled on the account first.

### 4. App Passwords are hidden from the Security page UI
Google no longer shows App Passwords in the Security & sign-in section.
Go directly to: **myaccount.google.com/apppasswords**

### 5. Workspace admin must allow per-user outbound gateways
To add `southsidesupplies.yes@gmail.com` as a "Send as" alias in the Workspace account,
the Workspace admin must enable this setting first:
**admin.google.com > Apps > Google Workspace > Gmail > End User Access >
"Allow per-user outbound gateways": ON**

### 6. "Send as" alias for SSS requires an App Password for the Gmail account
When adding `southsidesupplies.yes@gmail.com` as a Send as alias, Google asks for
SMTP credentials for that Gmail account. Needs the App Password for
`southsidesupplies.yes@gmail.com` (not the Workspace account).
Generate at myaccount.google.com/apppasswords signed in as the Gmail account.

### 7. STARTTLS on port 587, not SSL
- Port 587 + STARTTLS = correct for Gmail
- Do NOT enable both TLS (SSL) and TLS (STARTTLS) at the same time

---

## Dolibarr SMTP Configuration (Setup > Emails)

| Field | Value |
|---|---|
| Sending method | SMTP/SMTPS socket library |
| SMTP host | `smtp.gmail.com` |
| Port | `587` |
| Authentication method | Use a password (AUTH LOGIN) |
| SMTP username | `michaelw@brightcs.com.au` |
| SMTP password | App Password for `michaelw@brightcs.com.au` |
| Use TLS (SSL) | No |
| Use TLS (STARTTLS) | **Yes** |

### Other options
| Field | Value |
|---|---|
| Send all emails to (test override) | *(blank in production — set to your own email during testing)* |
| Sender email for automatic emails | `accounts@brightcs.com.au` |
| Default sender preselected on forms | Company Email (accounts@brightcs.com.au) |
| Email used for error returns | `accounts@brightcs.com.au` |

**⚠ Clear "Send all emails to" before go-live** — while set, all outgoing emails
(including to real customers) are redirected to that address.

---

## One-time Setup Steps (on a new environment)

### Step 1 — Enable per-user outbound gateways (Workspace admin)
admin.google.com > Apps > Google Workspace > Gmail > End User Access >
"Allow per-user outbound gateways": **ON**

### Step 2 — Add both Send as addresses to michaelw@ Gmail
Sign into `michaelw@brightcs.com.au` Gmail > Settings > Accounts and Import > Send mail as:

**Add `accounts@brightcs.com.au`:**
1. Add another email address
2. Enter `accounts@brightcs.com.au`, leave "Treat as alias" **ticked**
3. Next Step — Google verifies instantly (genuine alias, no code needed)

**Add `southsidesupplies.yes@gmail.com`:**
1. Add another email address
2. Enter `southsidesupplies.yes@gmail.com`, untick "Treat as alias"
3. SMTP server: `smtp.gmail.com` / Port `587` / TLS
4. Username: `southsidesupplies.yes@gmail.com`
5. Password: App Password for `southsidesupplies.yes@gmail.com`
6. Google sends a verification code to that Gmail — enter it to confirm

### Step 3 — Generate App Password for Workspace account
Sign into `michaelw@brightcs.com.au` > **myaccount.google.com/apppasswords**
Name it `Dolibarr` > Create > copy the 16-character password (shown once only).

### Step 4 — Configure Dolibarr SMTP
Setup > Emails — enter values from the table above.

---

## Sending per Brand

**BCS documents** (invoices, quotes, POs):
From is pre-filled as `accounts@brightcs.com.au` — send as-is.

**SSS documents** (invoices):
Select **South Side Supplies** from the From dropdown in the send dialog.
Configured as an Email sender profile: Setup > Emails > Emails sender profiles.
Google honours it because `southsidesupplies.yes@gmail.com` is a verified Send as
address on the Workspace account (added with its own App Password via smtp.gmail.com).
