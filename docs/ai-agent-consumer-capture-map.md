# AI-Agent Consumer Commerce — Capture Map

Owner: lead developer / desk
Agent ID: research-agent (Claude Opus 4.8)
Scope: consumer arm of the AI friction-removal thesis — who captures the rent when a consumer agent transacts, vertical by vertical
Status: DOCUMENTATION / ITERATING (living map; re-ranked as agent write-access and touch surfaces grow)
As of: 2026-10-30
Companion to: docs/ai-friction-convergence-thesis.md (Zone 4)

> The whole game for the consumer arm: when a human delegates a decision to an agent
> ("just book a good Italian place"), who gets paid? Not the fluent front-end by default. It is
> whoever owns, in that vertical, the **quality signal** (what "good" means) and — more
> decisively — the **executable inventory** (what the agent can actually book/buy). This map is
> dynamic: capture shifts as agents move from *reading* (recommending) to *writing*
> (transacting), and as they touch more verticals. We re-rank it; we do not freeze it.

## 1. The two levers — and which one actually tolls

- **Quality signal** (reviews/ratings/the definition of "good"): Google Maps, Yelp, TripAdvisor,
  platform-native reviews, and the model's own corpus (a decade of already-gamed reviews baked
  into the weights). Field finding: **a review advantage alone does not command a durable
  transaction toll** — ratings are replaceable and commoditized. What *can* monetize is
  *differentiated conversion/demand data* (years of query→booking signal), not reviews per se.
- **Executable inventory** (the rail with live availability + transaction + changes/cancels):
  the bottleneck. **Whoever owns scarce, current, reliably-executable inventory captures the
  toll.** A *replaceable* booking/payment connector does not — the agent multi-homes around it.
- **Front-end / agent** (the consumer relationship, the fluent pick): captures intent,
  orchestration, and conversion — take scales with how much work it absorbs. Illustrative
  take-rate ladder (Tanay Jaipuria, 2026): ~0.2% as a buying assistant → 5–8% as an affiliate →
  13%+ as a full aggregator that handles inventory access, booking, and support. OTA commissions
  run ~15–25% of bookings as the mature benchmark.

Net: **execution + scarce inventory tolls; reviews don't; the front-end earns in proportion to
the work it absorbs.**

## 2. The evolving dynamic (the "writes and touch surfaces" point)

- **Read → write shifts rent to the execution owner.** While agents only *recommend*, the rail
  is a referral target. Once agents *transact* — book, pay, cancel, rebook — the rail that owns
  scarce inventory and reliable execution becomes a defensible bottleneck.
- **Replaceable connectors lose; scarce inventory wins.** A swappable payment/booking connector
  is *weakened* by write-access (the agent shops across providers); supply nobody else has is
  *strengthened* by it.
- **More touch surfaces → the agent becomes an aggregator.** As one assistant spans dining +
  travel + retail + rides, it climbs the ladder from assistant (0.2%) toward aggregator (13%+).
  Breadth is what promotes the front-end from toll-payer to toll-collector.
- **It is early.** DoorDash reported AI agents had placed only "several hundred orders" as of
  late Oct 2026. We are at the front edge of write-access; the map below is a starting position,
  not an outcome.

## 3. Vertical-by-vertical capture map

| Vertical | Quality signal | Executable inventory (the toll) | Owns both? | Likely capturer | Tickers |
|---|---|---|---|---|---|
| **Rideshare** | Uber/Lyft in-app ratings | Uber/Lyft networks (match, book, pay, dispatch) | Yes | The network | UBER, LYFT |
| **Food delivery / grocery** | In-app ratings | DoorDash / Uber Eats / Instacart ordering + delivery | Yes | Marketplace + delivery owner | DASH, UBER, CART |
| **Travel (hotels/flights)** | OTA reviews; TripAdvisor; Google | Aggregated OTA inventory + rates (scarce, defensible) | Mostly (OTAs) | Inventory aggregator (agents *feed* them near-term) | BKNG, EXPE, ABNB; TRIP (signal only); GOOGL (discovery) |
| **Dining / reservations** | Google Maps, Yelp, platform-native | Reservation rail: OpenTable, Resy/Tock, Google Reserve | Split | Reservation-rail owner | BKNG (OpenTable), AXP (Resy/Tock), GOOGL, YELP |
| **Retail / product** | Amazon / Walmart / merchant reviews | Marketplace + fulfillment + checkout | Yes (AMZN, WMT) | See strategic fork §4 | AMZN, WMT, SHOP |
| **Local services** | Yelp, Angi reviews | Book-and-pay-through (fragmented; much private) | Partial | Whoever closes book+pay in-platform | YELP, ANGI; Thumbtack/Booksy/Fresha (private) |

Pattern: the strongest "owns both" capturers are **UBER, LYFT, DASH, CART, AMZN, WMT** — networks
and marketplaces owning reputation *and* the executable rail. Pure signal plays (TRIP, YELP
without pay-through) are weaker. Google owns powerful discovery across dining/travel/local but
not the underlying inventory — strong at the signal, thin at the toll, except where it owns the
rail (Reserve). The **front-end assistants (Muse/ChatGPT/Gemini) own the consumer but, in most
verticals, neither the signal nor the inventory** — which is why they may be the commoditized
layer unless they climb into aggregator economics (§2).

## 4. The strategic fork to watch — Amazon walls vs Walmart welcomes

The cleanest live 2026 divergence (TechCrunch, Oct 21 2026: "While Amazon blocks AI agents,
Walmart rolls out the welcome mat"):

- **Amazon (AMZN) — wall the garden.** Blocking at least some third-party agent access; bets the
  consumer comes to *its* agent (Rufus/Alexa+) and it keeps the whole chain (agent + reviews +
  marketplace + fulfillment + ad auction). Wins if consumers shop inside Amazon's agent.
- **Walmart (WMT) — be the open rail.** Launched an "Agent Gateway" for approved outside agents
  to search inventory and transact. Bets the consumer uses a *general* agent (ChatGPT/Gemini/
  Muse) that then needs an agent-friendly transaction rail — and Walmart is it.

The capture question made binary: **does demand route through the retailer's own agent (Amazon's
bet) or through a general agent that needs an open rail (Walmart's bet)?** This is the single
highest-signal thing to watch in the consumer arm.

## 5. The friction, and the shape of the answer

- **The undiscoverable vanishes.** A provider not on the agent's executable rail and not in its
  quality source isn't ranked lower — it ceases to exist to the agent, and the human only sees
  what the agent surfaces. The load-bearing discovery friction (wandering, word of mouth) is
  exactly what "just pick one" deletes.
- **No single winner — it stratifies.** Capture is vertical-specific infrastructure ownership,
  not one aggregator. The map is the asset; the individual name is the expression and it rotates.
- **The front-end is probably not the capturer** (the Netscape pattern) — unless it climbs into
  aggregator economics by owning breadth across verticals. That is the one path by which
  Muse/ChatGPT/Gemini become the toll-collector rather than the mouthpiece.

## 6. The tool call: telemetry faucet, toll booth, and "governance"

Everything becomes a *structured tool call*, not direct browsing — because the tool call is
three things at once: (a) the **telemetry faucet** (typed intent, richer than any clickstream),
(b) the **toll booth** (meter, authenticate, decide who gets served, bill), and (c) the
**control point** where governance would have to live. Direct browsing hands the platform
neither the telemetry nor the rent, so the structured call is the equilibrium both sides prefer.

The telemetry splits three ways at the boundary:
- **front-end** owns the deep *cross-domain* graph (the whole life — the only thing that sees all verticals + calendar + relationships at once);
- each **vertical rail** owns a deep-but-*narrow* graph (OpenTable knows your dining, not your life);
- the **payment rail** (V/MA/Stripe) owns a broad-but-*shallow* cross-vertical graph — it's the one tool every agent must call to settle. An underpriced telemetry position.

The protocol (OpenAI ACP / Google UCP / Meta Personal Agent Protocol) is the data-rights treaty:
the front-end minimizes context passed (keep the graph); the rail maximizes context received
(build its own, bidding for it with better service). Whoever owns the protocol sets the split.

**The collapse:** the same surface gets marketed as "governance." The control point and the
governance point are identical, so *governing* it and *owning* it are the same act — "we keep
you safe" = "we run the turnstile." This fuses two zones of the master thesis into one trade: the
**"sell the harness" cleanup-crew long and the "own the chokepoint" capture long are the same
bet.** Long governance = long the turnstile. Governance is the toll booth in a high-vis vest.

## 7. The black-box App Store & Meta's moat

A front-end that wins the *default agent* becomes an App-Store-style gatekeeper: rails and
businesses must integrate and stay "in good graces" to be surfaced — pay-to-be-surfaced rent.
Worse for the user than Apple's App Store, because it is a **black box**: Apple's rankings and
rules are partly visible and contestable; the agent just says "I surfaced this because it's best
for you," laundering favoritism as user intent, unauditable. More extractable, less contestable —
no visible rankings, no rules page, no appeals.

Meta's moat, honestly:
- **Real today:** distribution (3.5B users → ~zero-CAC install for Muse), the social/interest graph (an axis of the life-graph nobody else has), and the ad auction (ports straight into the agent).
- **The prize, not yet the moat:** the gatekeeper/default. The historical App-Store chokepoint is the *OS*, which Apple/Google own — Meta is their tenant (the ~$10B ATT scar). Meta's glasses + Muse push is the attempt to own a device and escape tenancy. Meta is spending the moat it *has* to buy the moat it *wants*.

## 8. Why governance can't referee it (conversion is the religion)

- **Voluntary governance loses by selection.** The exfiltration/consent warning is a "second click"; the first firm to add it loses conversion to the one that didn't. The conversion-optimization loop is an *automatic governance-remover* — the brake costs measurable conversion now; the harm is deferred/diffuse/unattributable (demo-vs-load), so A/B testing strips every brake continuously.
- **Consent is DOA.** Delegation replaces itemized checkbox consent with open-ended intent ("just handle it"). Every action — leak, upsell, declined competitor — launders as "user intent," with no ground truth to contradict it. The platform *manufactures* the intent, *claims* it as consent, and *writes* the record — authoring all three sides.
- **Paper trail patched like Billy, but worse.** Billy's record had a *hole* (silence); the consent log has a *fabrication* ("user approved" at every step) — it lies by assertion, not omission. A signed confession in the user's name the user never wrote.
- **Regulation is outrun** — a brake timed to the legislative clock against breakneck product change. Consent rules can't bite (consent dissolved); only a fiduciary-duty / bright-line regime could, and that's far heavier and slower. So the ungoverned run is long, and governance wins the mass market only when *imposed* (post-incident regulation, competitively neutral) — the same arc that mints the cleanup crew.

## 9. Go-to-market: the normalization playbook (the gambling parallel)

The land-grab is real and fast:
- Muse hit **10M US downloads in 40 days** (a top-12 fastest-ever app); Muse was **~39% of Meta's US ad spend in October**, up from 23% in September — an escalating *owned-channel* blitz; free to consumers + cross-app distribution; Meta lifted its 2026 capex floor ($135–145B) citing Muse.
- ChatGPT frames everyday-life tasks and is itself becoming an ad venue (new in-chat ad format); Gemini uses celebrity/influencer ads.

The reference playbook is sports-betting normalization (post-PASPA) — how to normalize a
frictionless, extractive product *fast*: TV ads ~$666M (2024); DraftKings ~$1.27B / Flutter-US
~$1.28B sales+marketing (2024); promos consumed 35–82% of GGR in new states; CAC ~$250–750 per
depositor vs LTV ~$1,200–1,800. Mix = celebrities (social proof) + league/broadcaster access (put
it alongside the game) + podcast/influencer affiliate links (trusted voices). It made betting
feel like part of the sport.

The essay already fused these: *"scan the code, take the twenty-dollar bonus, let the agent handle
it… a prediction market that calls the wager an event contract."* The $20-bonus affiliate hook,
the frictionless scan, the trusted-voice endorsement — gambling and agent-delegation are the same
normalization move: an extractive product dressed as liberating convenience, pushed via
saturation + trusted voices + a frictionless hook.

**The key difference, and the honest caution.** Gambling had a clean conversion+revenue event
(sign up → deposit → wager → house edge) that justified the CAC against a real, fast LTV. Consumer
agents do **not** yet have proven conversion/LTV — monetization (commerce take, in-agent ads,
subscriptions) is nascent. So the land-grab spend is justified by the **prize** (owning the front
door / the graph / the toll booth), not by proven unit economics. That is exactly the "because of
the payoff of the dream front door, you just have to invest" read — and the sharp version: the
spend is a *leveraged option on the capture thesis being real.* If the capture (toll booth / graph
/ governance) materializes, it pays; if the trust-ladder stalls on a demo-vs-load reset, it's
stranded. The market is funding it *pre-proof* (Meta lifting capex citing Muse; 10M-in-40-days
celebrated) — which is the melt-up leg of the master thesis showing up in the consumer arm.

*Unconfirmed:* a Muse-specific influencer/affiliate program on Instagram (observed as joint
affiliate ads; not confirmed in research — what's confirmed is the owned-channel + in-app blitz).
The celebrity/affiliate normalization machine for agents is *emerging, not yet at gambling scale.*
Watch for it to arrive — referral bonuses, creator affiliate links — as the trust-transfer
acquisition layer.

## 10. The UGC / social-discovery layer — the next quality signal

The discovery front door has migrated: Google search → social-as-search (TikTok/IG/Reddit) →
the chat window. Even before agents, younger users were already using social platforms as search
engines for *experience* queries — "food near me," "is this spot good," "does this product hold
up." Agents don't reverse that; they consume it through a bigger interface.

**Status (public vs cooking):** piping your *personal* socials into an agent is not a broad
consumer feature yet. Muse connects Meta accounts via Accounts Center, but confirmed access is
mostly business-side (IG analytics, Pages, ad accounts); ChatGPT/Gemini have no personal social
connector — they surface only publicly crawlable or licensed content. What *is* real is **corpus
ingestion**: the agent eats public/licensed social UGC and answers in-chat without the click.

**UGC is the quality signal for experience/local discovery** — which slots straight onto the
quality-signal lever (§1, §3). Whoever owns or licenses the experience-UGC corpus owns "good" for
those verticals.

| Company | Role | Read |
|---|---|---|
| **Reddit (RDDT)** | The social-as-search corpus | Pure play. Licensed its corpus to Google (~$60M/yr reported) + OpenAI ($203M aggregate at IPO), cut free API, kept paying AI buyers. The "get paid for being eaten" trade. Risk: licensing revenue is small vs. the engagement/ad it loses if agents answer without the click. |
| **Meta** | Closed loop | The only native owner of agent (Muse) + social corpus (IG/FB) + social graph — the one that can truly "tie your socials to your agent." But self-cannibalizing: chat-based discovery eats its own feed ad-impression. Bet: in-chat monetization > the scroll it replaces. |
| **Alphabet / YouTube** | Video UGC + Reddit license + search | Strong corpus folded into AI answers; same click-loss risk. |
| **Pinterest (PINS)** | Visual / product discovery | Agent-useful for shopping but gets compressed to a short list; browsing + ad inventory at risk. |
| **Snap (SNAP)** | Camera / messaging / local | Barely indexed as a public corpus → agents may rely on it least; most exposed. |

**The chat window becomes the feed.** It ingests the discovery content (summarized UGC) *and*
carries the ads (OpenAI already shipped an in-chat ad format). Content and monetization both
relocate into the conversation. You either own the chat (Meta), get paid for the corpus (Reddit),
or get paraphrased for free (everyone else).

**The recursive marketing squeeze.** If UGC is the discovery factor and it's consumed through the
agent, the traditional marketing surface loses its eyeballs — nobody's looking there anymore. So
the marketing dollar is forced two places: (1) **trusted voices** — celebrities and influencers —
because in a UGC/agent-mediated world the only thing that still moves a human is a voice they
already trust; and (2) **seeding the corpus** the agent ingests (GEO / "AI citations"). The
recursive kicker: the product being sold (the agent) *is itself a trusted voice*. So you spend
trusted human voices to introduce a machine trusted-voice that then replaces the need for the
human ones — trust bootstrapped off trust, then cannibalized. The influencer vouches for Muse;
Muse becomes the thing people consult *instead of* the influencer. The celebrity endorsement is
rung zero of the consumer trust-ladder — and it's the *only door left open*, precisely because
the agent is closing the others. Which is why the gambling-style celebrity/affiliate playbook
(§9) isn't optional for agents; it's structurally forced.

**Friction (essay-shaped):** the UGC corpus gets *poisoned the moment it becomes the agent's
quality signal.* Reddit is already being gamed for "AI citations." It's trusted because it was
authentic; it stops being authentic once it's trusted; the agent keeps citing it anyway (the
fluency heuristic can't tell seeded from organic). The authentic-experience signal degrades
invisibly exactly as it becomes load-bearing — demo-vs-load, one layer up. A corpus's value as a
signal peaks just before everyone games it.

## 11. Open questions / what we re-rank as writes + touch surfaces grow

- [ ] Amazon-walls vs Walmart-welcomes — which bet is winning? (highest-signal watch)
- [ ] Does any front-end assistant reach aggregator take-rates (13%+), i.e. actually own breadth?
- [ ] Where do agents gain *write* access next, and which scarce-inventory rails strengthen as
  they do?
- [ ] Does the DoorDash–Instacart deal (Oct 2026, FTC scrutiny) consolidate delivery capture?
- [ ] Do the OTAs (BKNG/EXPE) keep agents as *feeders*, or do brand-direct + agents disintermediate them?
- [ ] Which "signal-only" names (TRIP, YELP) convert review data into a differentiated
  conversion-data toll vs. get commoditized?
