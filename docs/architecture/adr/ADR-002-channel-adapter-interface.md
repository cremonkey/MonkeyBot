# ADR-002: Channel Adapter Interface

- **Status:** Proposed
- **Date:** 2026-09-22
- **References:** brief §10, §11, §26, §44 and §47; `docs/channels/capability-matrix.md`; ADR-000; ADR-001

## Context

Each channel is implemented differently today.

**Inbound**

| Channel | Path |
|---|---|
| Meta (FB and IG) | Arrives at `home/central_webhook_callback` and is re-POSTed to one of three large handlers (`Messenger_bot`, `Comment_automation`, `Instagram_reply`). |
| OpenWA | `Openwa_bot` **extends `Messenger_bot`** and reuses the full engine through `media_type='wa'`. Its processing order is: log → bot enabled → subscriber → pause → opt-out → staff hours → keywords → no-match → AI. |
| WA Cloud, Telegram, web widget | Each has its own controller, calls the AI directly, handles text only, and has no flows, no subscriber and no inbox. |
| TikTok | Polled by cron. |

**Signature checks** are also per channel:

- OpenWA: `X-OpenWA-Signature` HMAC.
- WA Cloud: HMAC, fails closed.
- Telegram: secret-token header.
- Meta: added on 2026-09-22 in log mode.

**Gates are inconsistent.** Staff hours apply on FB/IG DMs, IG comments and OpenWA only. Human pause lives on the subscriber and applies only where a subscriber exists.

**Outbound.** `channel_send_helper` is already a small cross-channel sender:

- fb/ig through Graph;
- wa through OpenWA first, then Cloud;
- tg;
- web.

The cron jobs, AI tools and Re-engage use it. The bot engines themselves still send through their own code paths.

**Graph versions.** Every Graph call goes through `fb_graph_helper.php`, but about 70 call sites are still on v20.0. WA Cloud is hardcoded to v21.0.

## Decision

Define one **ChannelAdapter** contract per channel, and route new work through it. Existing handlers are wrapped first, not rewritten.

```php
interface ChannelAdapter {
    public function channel(): string;                         // fb|ig|wa_openwa|wa_cloud|tg|web|tiktok
    public function verifyInbound(array $headers, string $rawBody): bool;   // signature/secret
    public function parseInbound(string $rawBody): array;      // -> NormalizedInboundMessage[] (brief §11)
    public function send(OutboundMessage $m): SendResult;      // text, media, buttons, carousel, with graceful degradation
    public function capabilities(): array;                     // capability-matrix row (24h window, buttons, carousel, templates, ...)
    public function policy(): ChannelPolicy;                   // messaging window, rate limits, opt-out rules (brief §26)
}
```

1. **Outbound first.** `channel_send_helper` becomes the embryo of `send()`. Keep its function signature as a facade, move each channel branch into an adapter class, and add the capability-based degradation that OpenWA does today (carousel and buttons flattened to text plus an image).
2. **Inbound second.** Adapters produce normalised messages. A single pipeline then applies, in one fixed order for every channel:
   1. verify;
   2. idempotency check (external message ID);
   3. upsert customer and conversation (ADR-001);
   4. pause / automation mode / hours;
   5. opt-out;
   6. rules and flows;
   7. AI.

   The OpenWA order above is the reference order.
3. **Wrap before replacing.** The first Meta adapter wraps the existing central callback and handlers. `Openwa_bot extends Messenger_bot` stays in place until the unified pipeline covers keyword bots and flows.
4. **Every adapter gets contract tests** (brief §44), using recorded payloads for parse, verify, send-shape and degradation.
5. **Graph version pinning** stays in `fb_graph_helper`. The Meta adapter is the only caller.

## Alternatives

| Option | Why not |
|---|---|
| Keep extending `Messenger_bot` for every channel (the OpenWA approach) | Fast for WA Web, but it ties every channel to a 14,840-line FB-shaped class, its FB assumptions and its ID space. There is already an inferred OpenWA/FB ID-collision risk. |
| Third-party omnichannel gateway | Adds cost and a dependency, and loses the OpenWA self-hosting. It still needs normalisation on our side. |
| Normalise only in the inbox (read side) | Leaves gates, policies and AI inconsistent across channels, and fixes only the display. |

## Consequences

**Positive:**

- One place per channel for verification, policy and capability.
- Hours, pause and opt-out enforced the same way on every channel.
- New channels (TikTok business mode, WA Cloud revival) become an adapter plus tests.

**Negative:**

- A wrapping layer for a while.
- The legacy Messenger features (OTN/RCN, persistent menu, webviews) stay Meta-specific and are exposed as capabilities, not as generic features.
- Contract test fixtures have to be recorded.

**Dependencies:**

- Webhook signature enforcement, so adapters inherit a verified ingress.
- ADR-001 for persistence.
