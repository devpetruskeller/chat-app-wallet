# PosTooChat Chat App Wallet

## Purpose

`chat-app-wallet` is PosTooChat's payment and accounting control plane. It
collects money, records the commercial transaction, manages credit purchases
and adjustments, and gives a customer or Workspace clear wallet controls.

It does not implement provider mechanics. It authorizes billable sends locally
for chat-app plugins, while Supabase SB Coms verifies and clips the one-use
authorization before routing the logical communication. On a Wallet refusal,
Wallet may submit a non-billable factual notification through its restricted
registered SB Coms application identity; the notification is defined by
Message Center in the `postoochat_wallet` group, never as an SB Coms seed.

## Initial payment providers

The first supported provider routes are:

| Market | Provider | Role |
| --- | --- | --- |
| Global | PayPal | Default international checkout route |
| South Africa | Yoco | South African checkout route |

The plugin selects a provider from the customer's country, currency, and an
explicit customer choice where more than one provider is available. Providers
are adapters behind one wallet checkout contract, so a country-specific
provider can be added later without changing Chatti, SB Coms, or existing
wallets.

The plugin SHALL verify each payment-provider webhook before granting credit.
It SHALL use the provider's payment/order ID as an idempotency key, so retries
cannot create credit twice.

## Runtime secret names

Wallet runtime configuration uses the `PTC_WALLET_` prefix and is supplied by
the server's secret-management configuration. Its reviewed manifest is
[`config/runtime-manifest.json`](config/runtime-manifest.json); a root
administrator installs that reviewed file as
`/etc/postoochat/wp-runtime-manifests/chat-app-wallet.json` before Wallet
values are applied through `ptc-runtime-config`. The initial names are
`PTC_WALLET_AUTHORIZATION_SIGNING_SECRET`, `PTC_WALLET_PAYPAL_CLIENT_ID`,
`PTC_WALLET_PAYPAL_CLIENT_SECRET`, `PTC_WALLET_PAYPAL_WEBHOOK_ID`,
`PTC_WALLET_YOCO_SECRET_KEY`, and `PTC_WALLET_YOCO_WEBHOOK_SECRET`. Only the
signing secret is required for the current authorization service; provider
credentials are required only when their checkout adapters are enabled.

Suite lifecycle delivery additionally requires
`PTC_WALLET_IDENTITY_LIFECYCLE_SIGNING_SECRET`, shared with Supabase as
`PT_WALLET_IDENTITY_LIFECYCLE_SIGNING_SECRET`. Supabase sends signed lifecycle
events to the Wallet's `/wp-json/chat-app-wallet/v1/identity-lifecycle` route;
its `PT_WALLET_IDENTITY_LIFECYCLE_URL` must contain that deployed route.

PayPal top-up packages are disabled unless `PTC_WALLET_PAYPAL_TOPUP_PACKAGES`
contains a server-managed JSON package list. Each package must have a stable
`sku`, ISO currency, decimal amount, and credit quantity. This is deliberately
not a client or chat-app setting: no default conversion rate is inferred and
the Sandbox checkout adapter refuses an unknown or unconfigured package.

The support UI additionally needs `PTC_WALLET_OPERATIONS_URL` (the deployed
Supabase `wallet-operations` function URL) and the matching
`PTC_WALLET_OPERATIONS_TOKEN`. The token is an independent high-entropy secret:
place the same value in Supabase as `PT_WALLET_OPERATIONS_TOKEN` and in the
server's `wp-config.php` as `PTC_WALLET_OPERATIONS_TOKEN`. It is never entered
in the WordPress dashboard. The ignored `.chatappenv` file is a local reference
only; WordPress does not load it. The production constants must be defined in
`wp-config.php`.

The 2FA-protected operator controls additionally require
`PTC_WALLET_2FA_URL` (the deployed `registry-2fa` function URL) and
`PTC_WALLET_2FA_TOKEN` (the same protected management credential accepted as
`PT_REGISTRY_MANAGEMENT_TOKEN` in Supabase).

For the PayPal Sandbox adapter, the public listener URL is:

```
https://api.postoochat.com/wp-json/chat-app-wallet/v1/paypal/webhook
```

Create the webhook in the PayPal developer dashboard with that URL, then place
the returned webhook ID in `PTC_WALLET_PAYPAL_WEBHOOK_ID` through the server's
runtime secret configuration. The listener verifies PayPal's signature using
the configured client credentials before storing an idempotent receipt. It
does not grant credits until a checkout order/capture has been bound to a
Wallet purchase.

## Credit model

The initial commercial model is prepaid credit burn-down:

- a customer buys credits before use;
- a credit is a configured unit of billable outbound business messaging;
- the initial illustrative rate is USD 0.005 per billable message;
- rates SHALL be configurable by channel, country, currency, customer plan,
  and effective date; they SHALL NOT be hard-coded in an app;
- inbound messages, internal callbacks, and Suite System replies (`Start`,
  `Menu`, `Home`, `Close`, and `Exit`) are not billable by default;
- failed provider dispatches do not consume a final credit.

## Customer Wallet entry points

Wallet is not a normal Chat App menu or workflow destination. A Chat App must
not show a balance, payer choice, credit-management prompt, or Wallet menu as
part of an otherwise successful business flow. Wallet becomes visible to a
customer only when:

1. Wallet has refused a billable communication because the available credit is
   insufficient; or
2. the customer explicitly requests their balance.

The insufficient-credit path offers top-up only. A balance request may
additionally offer Wallet access sharing. This keeps commercial prompts out of
normal use while leaving a clear recovery route when funding is actually
needed.

Wallet access sharing is a Wallet feature:

- **Share wallet with a user** grants a selected person controlled access to
  an existing shared Wallet. It is available only to the current Workspace
  Owner, never to an administrator, worker, or ordinary user. Wallet enforces
  this policy; a Chat App only forwards the reply and must not decide a role,
  payer, spending limit, or Wallet scope.

An access-sharing invitation remains pending until its recipient accepts it.
The owner may grant only Wallet-defined channel eligibility and spending
limits, may revoke the access later, and cannot delegate Wallet ownership or
permission to share onward.

### Channel rate card

Telegram and WhatsApp each start with a default of `0.000000 CRD` per message.
In WordPress, an operator can approve a non-negative **Default CRD per message**
for either channel at any time. That approved channel default applies to every
billable message in the channel unless the message has its own Rate card rule.
An existing pre-change channel default is treated as `0.000000 CRD` until an
operator approves a new value, so upgrading does not unexpectedly charge
messages. A message override is channel-specific and sets a different positive
cost. Existing message-ignore records continue to resolve to zero for
compatibility, but the Rate card no longer creates them. The Rate Card UI reads
only eligible definitions from the active Message Center release in Supabase through
the protected `wallet-operations` `catalog.rate_card` action. Message Center
supplies definitions and presentation only; it does not mark a message as
billable or set its price. Internal Registry, Wallet, Suite, and Share groups
are excluded from the commercial picker. Every rate change
requires a fresh Registry-authority TOTP approval bound to the exact change.

### Message cost and WordPress operation

The effective cost for a billable catalog message is resolved in this order:

1. A **Message ignore** rule makes the selected channel/group/message free: `0.000000 CRD`.
2. Otherwise, a **Message override** supplies the selected message's positive cost.
3. Otherwise, Wallet uses that channel's approved default, initially `0.000000 CRD`.

A zero-cost message still receives
a short-lived Wallet authorization so SB Coms can verify the normal send
bindings, but it creates no debit, reservation, release, or shared-wallet-limit
usage. Every Chat App definition, including an explicit zero-cost definition,
MUST have a billing meter and receive that Wallet authorization. Only a Suite
global/context definition or a Wallet-owned recovery/context definition is
non-billable; it has no billing meter and does not enter the Wallet
authorization or settlement path. A Chat App definition cannot use that
non-billable classification to bypass Wallet policy.

In WordPress, open **Tools → Chat App Wallet**. The **Credit adjustment**
Wallet picker lists only active Wallets; held, revoked, unavailable, and
retired diagnostic profiles cannot be selected for an adjustment. Use **Credit
adjustment** to grant or remove an individual active Wallet's credits. Use **Channel defaults** to
view or change the USD-per-credit and Default CRD per message for Telegram or
WhatsApp. Use **Rate card** to set only a message-specific CRD override. Its
USD-per-credit value is taken from the selected channel's Channel defaults,
and the Scope field is not shown. Remove a listed cost before adding a new one
for the same message. Each submission creates a short-lived challenge; enter the current
authenticator code to approve that exact change. The active rate-card table
shows the current rules and their reason.

The **Wallet identities** tab is search-first and returns at most 25 matching
Supabase onboarding identities per page; it does not load every identity by
default. The Credit adjustment Wallet picker is also search-first and returns
at most 25 matching active Wallets from WordPress. Search by a Telegram ID,
WhatsApp number, channel, or onboarding status before selecting a record.

## Owned and shared Wallets

Suite onboarding never creates, selects, links or funds a Wallet. When a
channel identity completes Suite verification, Wallet provisions a distinct,
zero-balance **owned Wallet profile** for that canonical channel identity.
This profile is not a credit grant and does not merge WhatsApp and Telegram.

## Identity lifecycle, consent, and withdrawal

Suite/SB Coms is authoritative for whether a channel identity is verified and
has active consent. Wallet receives only a protected, idempotent lifecycle
event containing the canonical channel identity, lifecycle state (`verified`,
`withdrawn`, or `revoked`), event ID, and timestamp; it must never require the
detailed onboarding profile, email address, consent text, or TOTP material.
Wallet provisions or reactivates an owned Wallet only after `verified`.

On `withdrawn` or `revoked`, Wallet must immediately mark the owned Wallet
**held and unavailable**. It must refuse new billing authorizations, top-ups,
and new shared-Wallet grants; release any reservation that has not already
reached provider acceptance; and revoke the identity's shared-Wallet access.
SB Coms also rejects a still-unconsumed authorization for a no-longer-verified
identity, so the result does not depend on lifecycle-event delivery timing. A provider-
accepted send is accounted for normally because it occurred before withdrawal;
Wallet must not attempt to erase or reverse that factual usage event.

Withdrawal does not silently consume or delete credit:

- **Purchased credit** remains held in the owned Wallet. It may be refunded
  through the applicable Wallet refund policy, or reactivated only after the
  same canonical channel identity completes fresh onboarding and consent.
- **Promotional credit** remains separately identifiable in the immutable
  ledger and is subject to the published promotion terms; it is not
  automatically cash-refundable.
- **Shared-Wallet credit** belongs to the shared Wallet owner. The withdrawing
  identity loses access, while the shared balance remains unchanged.

The Wallet ledger and minimum withdrawal/revocation audit record remain for
accounting, fraud prevention, and compliance. They are not a substitute for,
or a restoration of, the deleted Suite onboarding profile.

### Lifecycle delivery contract

Suite/SB Coms sends lifecycle events to Wallet over HTTPS as at-least-once
webhooks. Each event contains `eventId`, `eventType` (`verified`, `withdrawn`,
or `revoked`), `identity`, and `occurredAt`. It includes a timestamp header and
an HMAC-SHA256 signature over the timestamp and raw request body. Wallet must
reject an invalid or stale signature and return a `2xx` response only after it
has durably recorded and applied (or deduplicated) the event.

Delivery retries use exponential backoff at approximately 1 minute, 5 minutes,
15 minutes, 1 hour, 4 hours, and 12 hours, then daily until 72 hours after the
first attempt. Repeated failures must alert operations; the final failed event
is retained in a dead-letter/recovery queue. Wallet deduplicates by `eventId`
and retains processed event IDs for at least 90 days.

Wallet may grant an identity access to a **shared Wallet** owned by an approved
customer account, household, team or other commercial entity. Wallet owns every
shared-Wallet grant, revocation, channel eligibility, sponsorship priority and
spending limit. A current Workspace Owner may request a
Wallet-managed access-share flow; Wallet validates the Owner role and creates
the pending grant only after the Owner confirms its Wallet-defined terms. An
administrator, worker, or ordinary user cannot start that flow. Chat apps
never select a payer or administer either Wallet type.

Owned and shared Wallets may have separate channel balances. Each channel
Wallet is usable by every Chat App; app IDs are retained only on individual
authorization and communication records for audit and replay protection:

```text
Owned or shared Wallet
├─ WhatsApp balance
│  └─ balance, rate card, low-balance and auto-recharge settings
├─ Telegram balance
│  └─ balance, rate card, low-balance and auto-recharge settings
└─ Wallet-owned payer policy
   └─ owned/shared eligibility, sponsorship and credit-limit decisions
```

This makes differing channel costs visible and allows a customer to fund one
channel without unintentionally funding another.

## Authority boundaries

| Owner | Responsibilities |
| --- | --- |
| Chat App Wallet (WordPress) | Customer accounts, checkout, PayPal/Yoco adapters, payment webhooks, invoices, tax/accounting records, credit purchases, grants, refunds, wallet configuration, statements and reconciliation. |
| Supabase SB Coms | Verify and consume one-use billing authorizations, prevent replay, retain a minimal authorization/communication/outcome receipt, and report delivery outcomes. It does not hold wallets, entities, prices, sponsorship rules, or accounting ledgers. |
| Chat application (for example Chatti) | Business workflow only. It supplies communication facts to Wallet and never processes card payments, selects a Wallet/payer, sets a price, reads a balance, or edits Wallet policy. |
| Message Center | Message definitions and presentation only. |

The Wallet plugin SHALL NOT edit SB Coms tables directly. Conversely, SB Coms
SHALL NOT initiate a payment, issue an invoice, or calculate a customer price.

## Ledger backend contract

Wallet is the commercial-policy and payment-administration layer; it is not
coupled to a particular ledger implementation. It calls a stable internal
**Ledger Port** for every balance-changing decision. The port exposes only the
following semantic operations:

- `credit` — post a verified purchase, grant, refund, or adjustment;
- `reserve` — hold available credit for one authorised outbound communication;
- `consume` — settle that reservation after provider acceptance;
- `release` — remove a reservation after expiry, cancellation, or failed send;
- `reverse` — create a compensating entry for a completed transaction; and
- `balance` — return available, reserved, and posted credit for a Wallet scope.

Every mutating operation includes a Wallet-generated immutable transaction ID
and idempotency key. The ledger backend must return a durable acceptance before
Wallet reports a completed credit, reservation, consumption, release, or
reversal. A `2xx` acknowledgement from a transport alone is insufficient.

The initial `InternalLedgerAdapter` persists the ledger in Wallet-owned
database tables. A future `ExternalLedgerAdapter` implements the same port and
uses TLS, scoped bearer credentials stored in protected runtime configuration,
credential rotation, idempotency headers, request/response audit IDs, and
reconciliation. Changing adapters must not change a Chat App, SB Coms, payment
provider, authorization-ticket claim, or Wallet policy.

If an external ledger cannot be durably reached, Wallet records an idempotent
outbox item locally and exposes the operation as `pending`; it must not claim a
completed debit or credit. A worker retries the item and reconciles the final
ledger result using the immutable transaction ID and idempotency key.

The ledger model is double-entry even while credits are represented in `CRD`.
Each entry records balanced postings between named accounts, including customer
available credit, customer reserved credit, platform message-usage/revenue,
promotional-credit liability, and payment-clearing accounts. A correction or
refund is a new compensating transaction, never an edit to a posted entry.

## Usage lifecycle

The Ledger Port is the canonical append-only credit ledger. Wallet may maintain
a cached balance for speed, but every balance must be explainable from the
ledger's immutable, balanced postings.

1. A verified PayPal or Yoco payment creates a `purchase` ledger entry.
2. An authorised promotion or support correction creates a `grant`, `refund`,
   or `adjustment` entry with an actor and reason.
3. Before requesting SB Coms dispatch, the chat app calls the Wallet plugin's
   registered PHP service with only its app ID, channel, subject and recipient
   channel identities, authoritative business reference, billing meter and
   idempotency key. Wallet resolves the owned/shared payer and creates a
   `reserve` entry. It returns a short-lived, one-use signed authorization
   bound to the app, channel, meter and idempotency key.
4. SB Coms verifies and clips that authorization once before provider dispatch.
5. Provider acceptance converts the reservation to `consume`; a rejection,
   cancellation, or unrecoverable failure creates `release`.

Reservations expire after five minutes. The Wallet hourly recovery task creates
exactly one `release` entry for any still-reserved expired authorization, so a
lost SB Coms outcome cannot hold credit indefinitely. All accounting timestamps
remain UTC; the Wallet timezone is only a presentation and reporting boundary.

Every entry SHALL include an immutable ID, wallet scope, channel, currency or
credit unit, amount, reason, linked payment or communication ID, actor/source,
and timestamp.

## Support and identity operations UI

**Tools → Chat App Wallet** is the operations view for channel identities. It
lists Suite identities, their selected app and activity state, their owned
Wallet profile, shared-Wallet access and available funding state. Search accepts
a mobile/address, channel, app, onboarding status, or visible activity date.

An owned Wallet profile uses the canonical identity value
`whatsapp:+27811234567` or `telegram:701258963` as its display/index key. A
shared Wallet is attached by a Wallet-owned access grant. Neither relationship
merges WhatsApp and Telegram people or alters the Suite identity model.

The **Revoke consent & access** action requires an operator reason and a
WordPress administrator session. It calls the protected Supabase Wallet
Operations boundary, which records the revocation, invalidates the identity's
Suite onboarding profile, confirmed mobile number, TOTP seed, active session
and remembered app, and requires fresh onboarding before a future entry. This
is intentionally the same personal-data reset as the Suite `WITHDRAW` keyword;
the additional retained record is the admin actor, timestamp and required
reason. It deliberately retains the wallet ledger and minimal revocation audit
record. For Telegram, the same protected operation also removes an outstanding
native number-confirmation keyboard, so a stale control cannot be mistaken for
current consent. Refunds must be separate immutable `refund` ledger entries,
never edits to an original charge.

## Chat-app communication contract

All chat apps on the same WordPress server SHALL call the registered PHP
service, not local HTTP, before a potentially billable SB Coms command:

```php
$decision = postoochat_wallet()->authorize_message( array(
    'app_id'             => 'booki',
    'channel'            => 'telegram',
    'subject_identity'   => 'telegram:701258963',
    'recipient_identity' => 'telegram:123456789',
    'business_reference' => 'booking:abc-123',
    'billing_meter'      => 'outbound_standard',
    'message_group'      => 'postoochat_booki',
    'message_name'       => 'BOOKING_REMINDER',
    'idempotency_key'    => 'booking:abc-123:reminder-01',
) );
```

The chat app MUST NOT pass or choose a wallet ID, payer, price, balance, credit
limit or shared-Wallet preference. Wallet returns either a signed one-use
authorization for the original SB Coms command or a deterministic refusal. The
authorization is passed unchanged as the command's billing authorization; SB
Coms verifies and consumes it before provider dispatch.

### Signed authorization ticket

For a billable Message Center definition, the chat app passes Wallet's response
unchanged in the SB Coms command as `billingAuthorization`. The ticket is not a
wallet-selection or pricing input; `payerReference` is an opaque reference and
`reservedAmount` is Wallet's already-resolved reservation.

```json
{
  "billingAuthorization": {
    "claims": {
      "authorizationId": "uuid",
      "appId": "booki",
      "channel": "telegram",
      "billingMeter": "outbound_standard",
      "idempotencyKey": "…",
      "businessReference": "…",
      "payerReference": "opaque-sha256-reference",
      "reservedAmount": "1.000000",
      "unit": "CRD",
      "expiresAt": "ISO-8601",
      "signature": "HMAC-SHA256"
    }
  }
}
```

SB Coms verifies the signature and every binding before atomically consuming
the authorization. An explicitly zero-cost Chat App definition still has a
`billing_meter` and includes `billingAuthorization`; its authorization carries
a zero reserved amount. Only Suite global/context and Wallet-owned
recovery/context definitions are non-billable, have no `billing_meter`, and
MUST NOT include `billingAuthorization`.

## Insufficient credit contract

When Wallet cannot fund a billable send, it returns a deterministic refusal such
as `credit_balance_exhausted`. No authorization is issued, so SB Coms rejects
the original billable command and no provider send is attempted. Wallet may
then submit the applicable non-billable Message Center definition from the
`postoochat_wallet` group—normally `WALLET_CREDIT_REQUIRED`—using Wallet's
restricted registered SB Coms application identity. That definition contains
only factual funding and approved-reply presentation; it is not an SB Coms seed
message and the requesting chat app does not choose it. The insufficient-credit
definition offers two recovery actions: `1. Add Free Credits`, which opens
`PROMO_MENU`, and `2. Add Paid Credits`, which opens `WALLET_ADD_CREDITS`.
Wallet access sharing is intentionally not available in that path. Opening
`PROMO_MENU` is customer navigation only; it does not provide Promotions with
Wallet state or create a Wallet-to-Promotions integration.

## Wallet Message Center contract

All customer-facing Wallet messages are non-billable definitions in the
`postoochat_wallet` group. Wallet chooses the definition and supplies only the
variables listed below. A Chat App must not compose a Wallet message, supply a
price/balance/payer, or choose an Owner-only definition.

| Definition | When Wallet sends it | Required variables | Initial message copy |
| --- | --- | --- | --- |
| `WALLET_CREDIT_REQUIRED` | A billable communication is refused for insufficient credit. `1. Add Free Credits` opens `PROMO_MENU`; `2. Add Paid Credits` opens `WALLET_ADD_CREDITS`. | `businessReferenceLabel`, `requiredCredit`, `availableCredit`, `shortfallCredit`, `creditUnit` | Your credit balance is insufficient to create {{businessReferenceLabel}}. It requires {{requiredCredit}} {{creditUnit}}; your balance is {{availableCredit}} {{creditUnit}}. You need {{shortfallCredit}} {{creditUnit}} more.<br><br>Reply with an option:<br>1. Add Free Credits<br>2. Add Paid Credits |
| `WALLET_OVERVIEW` | A non-Owner explicitly requests a balance. It offers `1. Add credits`. | `creditBalance`, `creditUnit` | Your wallet balance is {{creditBalance}} {{creditUnit}}.<br><br>Reply with an option:<br>1. Add credits |
| `WALLET_SHOW_CREDITS` | The verified user sends the globally reserved `CREDIT` command. It shows the currently available Wallet credit without opening the Wallet action menu. | `available_credit_display` | Available credit: {{available_credit_display}} |
| `WALLET_OVERVIEW_OWNER` | A current Workspace Owner explicitly requests a balance. It offers `1. Add credits` and `2. Share wallet with a user`. | `creditBalance`, `creditUnit` | Your wallet balance is {{creditBalance}} {{creditUnit}}.<br><br>Reply with an option:<br>1. Add credits<br>2. Share wallet with a user |
| `WALLET_ADD_CREDITS` | The customer selects the top-up route. | `checkoutUrl` | Add credits securely here:<br>{{checkoutUrl}} |
| `WALLET_ACTIVITY` | The customer explicitly requests Wallet activity. It is never sent as a normal chat-menu item. | `activitySummary`, `creditBalance`, `creditUnit` | Your wallet balance is {{creditBalance}} {{creditUnit}}.<br><br>Recent activity:<br>{{activitySummary}} |
| `WALLET_SHARE_START` | A current Workspace Owner selects Wallet access sharing. | `recipientInstruction` | Share wallet access with a user. {{recipientInstruction}} |
| `WALLET_SHARE_CONFIRM` | Wallet has resolved the proposed recipient and asks the Owner to confirm the pending grant. | `recipientDisplayName`, `eligibleChannelScope`, `spendingLimit`, `creditUnit` | You are about to share wallet access with {{recipientDisplayName}}.<br><br>Channels: {{eligibleChannelScope}}<br>Spending limit: {{spendingLimit}} {{creditUnit}}<br><br>Reply CONFIRM to send the invitation. |
| `WALLET_SHARE_PENDING` | The Owner has confirmed and the recipient must accept the grant. | `recipientDisplayName`, `eligibleChannelScope`, `spendingLimit`, `creditUnit` | A wallet-access invitation has been sent to {{recipientDisplayName}}.<br><br>Channels: {{eligibleChannelScope}}<br>Spending limit: {{spendingLimit}} {{creditUnit}}<br><br>Access remains pending until it is accepted. |
| `WALLET_SHARE_RECEIVED` | The recipient has accepted the grant; Wallet notifies the Owner and/or recipient using the appropriate approved copy. | `counterpartyDisplayName`, `eligibleChannelScope`, `spendingLimit`, `creditUnit` | Wallet access with {{counterpartyDisplayName}} is now active.<br><br>Channels: {{eligibleChannelScope}}<br>Spending limit: {{spendingLimit}} {{creditUnit}} |
| `WALLET_ACCESS_DENIED` | An administrator, worker, ordinary user, or otherwise ineligible identity requests Owner-only Wallet sharing. | `requestedActionLabel` | You cannot {{requestedActionLabel}}. Only the current Workspace Owner can share wallet access. |
The canonical insufficient-credit copy is conceptually:

```text
Your credit balance is insufficient to create {{businessReferenceLabel}}.
You need {{shortfallCredit}} {{creditUnit}} more.

Reply with an option:
1. Add Free Credits
2. Add Paid Credits
```

The Owner balance definition is conceptually:

```text
Your wallet balance is {{creditBalance}} {{creditUnit}}.

Reply with an option:
1. Add credits
2. Share wallet with a user
```

## Registered PHP authorization service

All chat apps run on the same WordPress server and SHALL request billing
authorization through the Wallet plugin's registered PHP service, not local
HTTP. The eventual interface is conceptually:

```php
$authorization = postoochat_wallet()->authorize_message( $request );
```

The request identifies the calling app, channel, channel identities,
authoritative business reference, billing meter and outbound idempotency key.
The response is either a deterministic refusal or a signed, one-use
authorization. The signature is necessary because Supabase is outside WordPress
and must verify the ticket without accessing Wallet records.

## Future capabilities

- country-specific payment-provider adapters;
- subscriptions with included monthly credits and overages;
- bundle discounts and promotional credits;
- configurable credit expiry, if legally and commercially appropriate;
- low-balance notices and capped auto-recharge;
- invoices, tax treatment, refunds, chargebacks and accounting exports;
- multi-currency display while keeping each wallet's financial ledger
  separately reconcilable.
- an external Ledger Port adapter, once the internal implementation has the
  required operating history and reconciliation coverage.

## Security requirements

- Provider credentials and webhook secrets stay in ignored deployment
  configuration, never the repository or WordPress database plaintext.
- Checkout return URLs are not proof of payment; only verified provider
  webhooks can grant credit.
- Administrative grants, refunds, rate-card changes, and provider changes
  require an auditable privileged action.
- Payment and wallet webhooks must be idempotent, signed, replay-protected,
  and recorded before side effects are applied.
