# RealtyLink PH — Complete System & Process Flow

**Purpose of this document:** a complete, screen-by-screen reference for designing and enhancing the RealtyLink PH frontend. Every screen, state, flow, and design token below is taken from the actual working system.

---

## 1. WHAT THE SYSTEM IS

RealtyLink PH is a real estate marketplace for the Philippines.

**The core rule:** anyone can browse, but only licensed agents verified by a human administrator can post listings.

**Three jobs the system does:**
1. Verifies that agents are real, PRC-licensed professionals
2. Displays their properties via search, map, and an AI assistant
3. Manages buyer–agent relationships: enquiries, chat, viewings, reviews

**Deliberate exclusions:** no payments, no paid promotion, no subscription tiers. Homepage placement is earned by an automatic merit score, never bought.

---

## 2. USER ROLES

| Role | How they get it | What they can do |
|---|---|---|
| **Visitor / Ghost Buyer** | No account | Browse everything, send one-way enquiries |
| **Buyer** | Self-registration (the only sign-up option) | Save favourites, chat, book viewings, leave reviews |
| **Agent** | Buyer → submits licence → admin approves | Everything a buyer can do, plus create listings, manage calendar, receive enquiries |
| **Admin** | Created by another admin | Approve agents, moderate listings and reviews, manage users |
| **Super Admin** | Exists in the system | Currently identical to Admin |

**The role ladder is one-directional.** Buyer → Agent only. No path back, no dual roles.

---

## 3. COMPLETE SCREEN INVENTORY

### 3.1 Public screens (no login required)

| Route | Screen | Contents |
|---|---|---|
| `/` | **Homepage** | Hero with search, Why Choose section, Featured Properties (4), Browse by Property Type, Top Verified Agents (4), How It Works, Stats bar, Testimonials, CTA banner |
| `/properties` | **Browse / Search** | Filter bar, property grid, map toggle, pagination |
| `/properties/[id]` | **Property Detail (public)** | Dark navy treatment. Gallery, 360° tour, specs, description, map, agent card, enquiry + booking actions |
| `/agents` | **Agent Directory** | Grid of verified agents ranked by listing count |
| `/agents/[id]` | **Agent Profile (public)** | Avatar, verified badge, rating, review list, their published listings |
| `/login` `/register` | **Redirect stubs** | Redirect to `/?auth=login` — auth is a modal, not a page |
| `/auth/google/callback` | **Google sign-in landing** | Full-screen spinner, "Signing you in with Google…" |
| `/google/callback` | **Google Calendar landing** | Full-screen spinner, "Connecting your Google Calendar…" |

### 3.2 Buyer screens (dashboard layout)

| Route | Screen | Contents |
|---|---|---|
| `/dashboard` | **Buyer Overview** | Welcome banner, 3 stat tiles, Recommended Properties grid |
| `/dashboard/browse` | **Browse (embedded)** | Same browse experience inside dashboard chrome |
| `/dashboard/saved` | **Saved Properties** | Favourited listings grid; un-hearting removes the card instantly |
| `/dashboard/messages` | **Messages** | Two-pane chat: conversation list + active thread |
| `/dashboard/appointments` | **Appointments** | Upcoming viewings with status badges and cancel action |
| `/dashboard/history` | **History** | Completed, cancelled, and past viewings + "Rate the agent" |
| `/dashboard/profile` | **Profile & Settings** | Avatar, details, password, theme, alerts, email verification, Google Calendar |
| `/dashboard/properties/[id]` | **Property Detail (in-dashboard)** | Same component as public, light treatment |
| `/dashboard/agents/[id]` | **Agent Profile (in-dashboard)** | Same component as public |
| `/dashboard/verify` | **Become an Agent wizard** | Multi-step verification form |
| `/dashboard/ai` | **RealtyLink AI (full page)** | Full-height chat interface |

### 3.3 Agent screens (dashboard layout)

| Route | Screen | Contents |
|---|---|---|
| `/dashboard` | **Agent Overview** | Welcome banner with rating, 4 stat tiles, recent listings, recent enquiries, upcoming viewings |
| `/dashboard/listings` | **My Listings** | Active listings with publish/unpublish/sold/delete actions |
| `/dashboard/listings/new` | **New Listing** | 3-step wizard with left rail stepper |
| `/dashboard/listings/[id]/edit` | **Edit Listing** | Same fields plus existing photo management |
| `/dashboard/inquiries` | **Inquiries** | Lead list, unread highlighted, mark-as-read |
| `/dashboard/appointments` | **Appointments** | Confirm / mark done / cancel actions |
| `/dashboard/history` | **History** | Two tabs: **Viewings** and **Inventory** (sold listings) |
| `/dashboard/messages` | **Messages** | Same chat interface |
| `/dashboard/calendar` | **Calendar** | Month grid, block days and time ranges, see scheduled viewings |
| `/dashboard/reviews` | **Reviews** | Reviews received, average rating |
| `/dashboard/profile` | **Profile & Settings** | Same as buyer |

### 3.4 Admin screens

| Route | Screen | Contents |
|---|---|---|
| `/admin` | Redirects to `/admin/agents` | — |
| `/admin/users` | **Users** | Search, role filter, create admin, delete user |
| `/admin/agents` | **Pending Agent Verifications** | Application cards with documents + AI assessment, approve/reject |
| `/admin/listings` | **All Listings** | Every listing, unpublish, delete, "Why featured?" explainer |
| `/admin/reviews` | **Reviews** | Show/hide reviews |

---

## 4. NAVIGATION STRUCTURE

**Buyer sidebar (7 items):**
Dashboard · Browse · Saved · Messages · Appointments · History · My Profile

**Agent sidebar (9 items):**
Dashboard · My Listings · Inquiries · Appointments · History · Messages · Calendar · Reviews · My Profile

**Admin sidebar (4 items):**
Users · Agents · Listings · Reviews

**Contextual sidebar items:**
- Buyer with no application → **"Become an Agent"** (gold outlined button)
- Buyer with pending application → **"Agent Application Pending"** (greyed, non-clickable)
- Agent not yet approved → **"Get Verified"** (gold text link)

**Dashboard topbar (all roles):**
Mobile menu toggle · Role label + "Hello, {FirstName} 👋" · Notification bell with unread count · Profile dropdown (avatar, name, role, links, sign out)

**Public navbar:**
Logo · Buy · Sell · Rent · About · Testimonials · Sign In / Sign Up (or avatar dropdown when logged in). Transparent over hero, solid navy after scrolling 20px.

---

## 5. PROCESS FLOWS

### FLOW 1 — Arrival and browsing (no account)

1. Visitor lands on homepage
2. Sees featured properties, top agents, search bar
3. Searches or filters, or opens map view
4. Opens a property detail page
5. Can view gallery, 360° tour, specs, map, agent info
6. **Can:** send one enquiry
7. **Cannot:** save favourites, chat, book viewing → these prompt the login modal

**End state:** visitor either leaves, sends an enquiry, or registers.

---

### FLOW 2 — Registration

1. Click Sign Up anywhere → modal opens over a blurred backdrop
2. Choose one:
   - **Email:** name, email, password, confirm password
   - **Google:** redirect to Google → return signed in
3. Account is created as **Buyer** — no role choice exists
4. Verification email queued in background
5. Redirected to `/dashboard/appointments`

**Validation rules:** password minimum 8 characters, must include a letter and a number; passwords must match.

**Session behaviour:** the token is stored in `sessionStorage`. **Closing the browser ends the session.** This is deliberate.

**End state:** logged in as Buyer.

---

### FLOW 3 — Password reset

1. Click "Forgot password?" in the login modal
2. Enter email → system sends a reset link
3. Click the link in the email → reset form
4. Enter new password + confirm
5. Password updated and **all existing sessions are invalidated**
6. Return to login

---

### FLOW 4 — Email verification (two separate paths)

**Path A — automatic, at registration**
1. Registration triggers a queued verification email
2. Email contains a signed link
3. Clicking it marks the email verified and redirects to `/?verified=1`

**Path B — manual, from Profile page**
1. Profile page shows unverified status
2. Click "Send code" → 6-digit code emailed, valid **15 minutes**
3. Enter the code into 6 input boxes
4. Verified on match; invalid or expired codes show an inline error
5. Both endpoints throttled to 6 attempts per minute

**Note:** no feature currently requires a verified email. It is a trust signal, not a gate. Google sign-in marks the email verified automatically.

---

### FLOW 5 — Become an Agent (verification wizard)

**Entry:** Buyer clicks "Become an Agent" in sidebar → `/dashboard/verify`

**Step 0 — Choose path**
Two large selectable cards:
- **Broker** — transacts independently → 4 steps
- **Salesperson** — works under a broker → 5 steps

**Broker steps:** Information → License Card → Live Scan → Review
**Salesperson steps:** Information → Accreditation → Valid ID → Live Scan → Review

**Step 1 — Information**
Full name (required) · Mobile number (optional) · PRC / Accreditation number (required) · Supervising broker (salesperson only, optional)

**Step 2 — Documents**
- Broker: PRC broker licence card
- Salesperson: accreditation document (front) + valid government ID
- Accepted: PDF, JPG, JPEG, PNG · max 5MB
- Images show a live preview; PDFs show a file icon

**Step 3 — Live Scan**
- Opens the device camera
- Live video preview with a capture button
- Captures a frame → produces a real image file
- **File upload is not permitted here** — must be a live capture
- Retake option available
- Max 4MB, JPG/PNG only

**Step 4 — Review**
Summary of all entered data and uploaded files, then Submit.

**During submission — full-screen overlay**
Body scroll locks. A rotating message cycles every 1.8 seconds:
1. "Reading your documents…"
2. "Checking details & name consistency…"
3. "Comparing your live face scan…"
4. "Finalizing the AI pre-check…"

**Behind the scenes:**
- Cooldown checked first (see below)
- Profile saved, both document paths reset then the relevant one filled
- AI reads documents + selfie and writes an advisory note
- Applicant notified: "decision within 24 hours"
- All admins notified in-app and by email

**Result states:**

| State | Screen shown |
|---|---|
| **Pending** | Status card: "Under review, decision within 24 hours" + the AI assessment note |
| **Approved** | Redirected to `/dashboard/listings`, role is now Agent |
| **Rejected** | Rejection card with the admin's reason + **live countdown timer** |

**Re-apply cooldown:** a rejected applicant must wait **12 hours**. The countdown displays as `8h 12m`, then `12m 30s`, then `30s`. The re-apply button is disabled until it expires.

---

### FLOW 6 — Create a listing

**Entry:** Agent clicks "New Listing" → `/dashboard/listings/new`

Left rail stepper shows 3 steps with completion checkmarks.

**Step 1 — Property Photos**
- Multi-file upload with thumbnail previews
- Remove individual photos
- Held in memory — nothing uploads yet

**Step 2 — Virtual Tour (optional)**
- Panorama photos, tracked separately
- These are flagged as 360° on upload

**Step 3 — Listing Details**

| Field | Type | Required |
|---|---|---|
| Title | text, max 255 | Yes |
| Offer type | For Sale / For Rent | Defaults to Sale |
| Property type | House / Condo / Lot / Commercial / Apartment | Yes |
| Price | number | Yes |
| Bedrooms | number 0–50 | Yes |
| Bathrooms | number 0–50 | Yes |
| Floor area (sqm) | number | No |
| Lot area (sqm) | number | No |
| Address | text, max 500 | Yes |
| Description | textarea | No |

**AI description button**
- Sends the entered details + uploaded photos to the AI
- AI examines the photos and writes 2–3 concise sentences
- Agent can edit the result
- Requires either photos or an address, otherwise shows an inline error

**Price sanity check on submit**
A confirm dialog appears if:
- Rent ≥ ₱1,000,000/month → "did you mean For Sale?"
- Sale < ₱100,000 → "is that correct?"

Agent can override; the listing saves but becomes ineligible for featuring.

**On submit:**
1. Property row created as **Draft**
2. Gallery photos upload sequentially with sort order
3. Panorama photos upload after, flagged 360°
4. Address geocoded to map coordinates in the background
5. Redirect to `/dashboard/listings`

---

### FLOW 7 — Listing lifecycle

```
DRAFT ──publish──▶ PUBLISHED ──mark sold──▶ SOLD ──relist──▶ DRAFT
  ▲                    │
  └────unpublish───────┘
```

| Action | Who | Effect |
|---|---|---|
| **Publish** | Agent | Visible to all. **First publish only:** emails every buyer with Property Alerts on |
| **Unpublish** | Agent or Admin | Returns to Draft, hidden from buyers |
| **Mark Sold** | Agent | Hidden from search, moves to Inventory, records sold date |
| **Re-list** | Agent | Returns to **Draft**, not Published — forces a review before going live |
| **Delete** | Agent or Admin | Permanent, behind a confirm. Deletes all photos too |

**Draft privacy:** an unpublished listing returns 404 for everyone except its owning agent and admins.

---

### FLOW 8 — Photo management (on edit)

1. Open `/dashboard/listings/[id]/edit`
2. Existing photos display in a grid in sort order
3. Available actions: upload more, delete individual photos, reorder by drag
4. Reordering rewrites sort order for all photos at once
5. Deleting removes both the database row and the stored file

---

### FLOW 9 — Buyer discovery

**Three entry points:**

**9.1 Search and filter**
Filters: location keyword · property type · offer type · min price · max price · bedrooms · bathrooms
- Keyword matches **title and address only**, not description
- Only published listings ever appear
- Filters persist in the URL query so results are shareable
- Paginated

**9.2 Map view**
- Toggle from the browse screen
- Leaflet map, defaults to a Philippines-wide view
- Markers show first photo + abbreviated price (`₱3.5M`, `₱25K`)
- Click a pin → popup with title, price, address, "View details →"
- Map auto-fits to visible results
- **Only listings with coordinates appear** — a listing that failed geocoding will not show

**9.3 AI assistant**
Covered in Flow 16.

**Property detail page contents:**
Photo gallery with lightbox (arrow navigation) · 360° tour viewer with auto-rotate · Offer type badge + property type badge · Title, address, price (with "per month" if rental) · Spec tiles: bedrooms, bathrooms, floor area, lot area · Description · Location map · Agent card with avatar, verified badge, rating · Actions: Save (heart), Send Enquiry, Message Agent, Schedule Viewing

**View counting:** increments on every page open, except when the owning agent views their own listing.

---

### FLOW 10 — Save to favourites

1. Buyer taps the heart on any property card or detail page
2. Heart fills immediately (optimistic)
3. Property appears in `/dashboard/saved`
4. Un-hearting from the Saved page removes the card instantly without a refresh

**Empty state:** heart icon, "No saved properties yet", "Tap the heart on any listing to save it here", plus a "Browse Listings" button.

---

### FLOW 11 — Contact channel A: Enquiry

**Available to:** anyone, including visitors with no account

1. Click "Send Enquiry" on a property
2. Modal opens with three quick-message chips:
   - "Is it available?"
   - "Financing"
   - "Schedule a viewing"
3. Clicking a chip pre-fills the message
4. Fields: name, email, phone (optional), message
5. Submit
6. Agent receives it in Inquiries + a bell notification

**This is one-way.** There is no reply button. It exists to capture leads from people who will not register.

**Agent's Inquiries screen:**
- Unread items highlighted with a gold border and tint
- Shows sender name, property title, message, timestamp
- Ghost-buyer enquiries are visually marked as coming from a non-registered user
- Mark as read action

---

### FLOW 12 — Contact channel B: Live chat

**Requires:** both parties logged in

1. Buyer clicks "Message Agent" on a property
2. System finds or creates the conversation thread for that buyer + property
3. Redirects to `/dashboard/messages` with the thread open

**Chat interface — two panes:**

**Left pane — conversation list**
Search by name · Avatar with online dot · Other person's name · Last message preview · Timestamp · Unread count badge

**Right pane — active thread**
- Header: avatar, name, presence text, link to agent profile
- Messages grouped by sender, with date separators ("Today", "Yesterday", "March 14")
- Own messages right-aligned, other person's left-aligned
- Typing indicator when the other person is typing
- "Seen 3 mins ago" under your last message once read
- Emoji picker
- Hover reactions on messages (🔥 ❤️ 👍 😂 😮 🙏) — **display only, not saved**
- Message input with send button

**Real-time behaviour:**
- New messages appear instantly without refresh
- Typing indicators expire after 2.5 seconds of inactivity
- Presence refreshes every 30 seconds
- Read receipts survive a page refresh

**Presence labels:**
`Active now` · `Active just now` · `Active 20 mins ago` · `Active 3 hours ago` · `Active 2 days ago` · `Active 3 weeks ago` · `Offline`

**One thread per buyer per property.** It can never be duplicated.

---

### FLOW 13 — Contact channel C: Book a viewing

**Requires:** buyer account

1. Click "Schedule a Viewing"
2. Modal opens and loads the agent's availability

**Date picker — next 14 days**

| State | Appearance | Selectable |
|---|---|---|
| Available | Normal | Yes |
| Limited (partly blocked) | Visual hint / muted | Yes |
| Fully blocked | Disabled | No |

3. Buyer picks a date → time slots load

**Time slots**
- Working day is **8:00 AM – 6:00 PM in 30-minute increments**
- Removed automatically: blocked time ranges, slots already booked
- Displayed in 12-hour format (`9:30 AM`)
- If no slots remain, show an empty state

4. Buyer selects a slot
5. Optional notes field
6. Submit

**Server validation:**
- Slot must still be free
- **One active viewing per buyer per property** (a cancelled one can be re-booked)
- Date must be in the future

**On success:**
- Status is **Pending**
- Agent receives a bell notification
- **Both** buyer and agent receive an email

**Error cases to design for:**
- "The selected time slot is not available."
- "You already have a scheduled viewing for this property."
- "Please pick a date and an available time."

---

### FLOW 14 — Viewing lifecycle

```
PENDING ──agent confirms──▶ CONFIRMED ──agent marks done──▶ COMPLETED
   │                            │
   └────────cancel──────────────┴──────▶ CANCELLED
```

| Action | Who can do it | What happens |
|---|---|---|
| **Confirm** | Listing agent only | Buyer notified. Event added to **both** Google Calendars (if connected) |
| **Mark as Done** | Listing agent only | Moves to History for both parties. Buyer notified |
| **Cancel** | Buyer, agent, or admin | See below |

**When the buyer cancels:**
- Both parties get a notification and an email
- Both calendar events deleted
- **A message is automatically posted into the buyer–agent chat thread:** "❌ I've cancelled my viewing for "{property}" scheduled on {date}."

**When the agent cancels:**
- Buyer notified
- Calendar events deleted

**Status badge colours:**

| Status | Colour |
|---|---|
| Pending | Amber |
| Confirmed | Emerald |
| Completed | Emerald / neutral |
| Cancelled | Red |

---

### FLOW 15 — Agent calendar management

**Screen:** `/dashboard/calendar`

**Month grid view**
- 6 rows × 7 days
- Navigate previous / next month
- Today highlighted
- Each cell shows: blocked indicator, scheduled viewing count

**Selecting a day** opens a detail panel showing:
- Any whole-day block on that date
- Any time-range blocks, sorted by start time
- Scheduled viewings for that date with buyer name and time

**Blocking options:**

| Type | Configuration |
|---|---|
| **Whole day, one-off** | Pick a date, optional reason |
| **Time range, one-off** | Pick a date + start time + end time |
| **Whole day, recurring** | Pick a weekday (0 = Sunday … 6 = Saturday) — repeats every week |
| **Time range, recurring** | Pick a weekday + start and end time |

**Rules:**
- Blocked dates cannot be in the past
- End time must be after start time
- Both times required together, or neither

**Unblock:** remove any block from the detail panel.

**Important limitation:** working hours are fixed at 8:00 AM – 6:00 PM. Agents can only **subtract** availability, never extend it.

---

### FLOW 16 — RealtyLink AI (buyer assistant)

**Two entry points:** floating bubble on any page, or `/dashboard/ai` full page.

**Empty state suggestions:**
- "Find me a condo under ₱3M"
- "2-bedroom house in Cebu"
- "What can I afford on ₱25k/month?"
- "Best areas for first-time buyers?"

**How it works — three stages:**
1. **Understand** — the AI reads the conversation and extracts location, property type, offer type, price range, bedrooms, bathrooms. It understands Filipino price shorthand: "5M" → 5,000,000 · "500k" → 500,000 · "25 thousand" → 25,000. A stated budget is treated as a **maximum**.
2. **Search** — those criteria query the real published listings database
3. **Reply** — the AI writes a friendly response **recommending only listings that actually exist**

**Response contains:** a conversational reply plus up to 4 real property cards the buyer can tap through to.

**Guardrails:**
- Never invents listings, prices, or details
- If nothing matches, says so honestly and suggests broadening the search
- Asks a clarifying question if budget or location is missing
- Uses Philippine pesos
- Replies under 180 words
- Rate limited to 20 messages per minute

**If the AI service is down:** the assistant still returns real matched listings in a plain formatted list — it degrades but never breaks.

---

### FLOW 17 — RealtyLink AI (agent companion)

**Available to:** verified agents only. Floating bubble.

**What it knows about (real data, injected before every reply):**
- The agent's last 20 listings with status, views, price, specs
- A performance snapshot: total views, best-performing listing, and specifically which published listings have **zero views**
- Market comparables: average and price range for every property type and offer type across the whole platform

**What it can do:**
1. **Write descriptions** — attach a photo and it describes what it sees and weaves it into the copy
2. **Propose a complete listing** — when given enough detail (type, price, location), it generates a full draft listing the agent can review and create with one click
3. **Pricing advice** — compares the agent's listing against real platform averages and says whether it's above or below market
4. **Marketing insights** — identifies underperforming listings and suggests concrete fixes (price, title, photos, 360° tour)

**Listing proposal UI:** the AI's reply appears as normal chat text, and the proposed listing renders as a separate editable card with a "Create this listing" action.

**Supports:** image attachment up to 5MB · rate limited to 20 messages per minute.

---

### FLOW 18 — Review the agent

**Requires:** the buyer had a viewing with that agent, and has not already reviewed it.

1. Buyer opens `/dashboard/history`
2. Eligible viewings show a "Rate the agent" action
3. Rating modal opens:
   - **Star rating 1–5** (required)
   - **Standout tags** (optional, multi-select): On time · Knowledgeable · Honest · Responsive · Friendly
   - **Written feedback** (optional, max 2000 characters)
4. Submit
5. Success toast: "Thanks for rating!"

**Behind the scenes:** selected tags are prepended to the review text as `👍 On time · Knowledgeable`.

**Rules:**
- **One review per viewing, permanently enforced**
- Agent receives a bell notification and an email
- Review appears on the agent's public profile and changes their average rating

**Error state:** "Please tap a star rating."

---

### FLOW 19 — Profile & settings

**Screen:** `/dashboard/profile` — same for buyers and agents.

**Section 1 — Profile information**
- Avatar upload with live preview (max 2MB)
- Name (required)
- Phone
- Save button with success confirmation

**Section 2 — Appearance**
Three theme options as selectable cards: **Light** · **Dark** · **System**
- Applies instantly
- Saved to the user account so it follows them across devices
- System mode follows the OS preference live

**Section 3 — Property alerts**
Toggle switch. When on, the buyer receives an email each time a new listing is published. Optimistic toggle that reverts on failure.

**Section 4 — Email verification**
Shown only if unverified.
1. "Send code" button
2. Six-digit code input
3. Confirm
4. Error states: "Invalid verification code." · "Your code has expired. Please request a new one."

**Section 5 — Google Calendar**

| State | UI |
|---|---|
| Not connected | "Connect Google Calendar" button |
| Connected | Connected badge + "Disconnect" button |

Connecting redirects to Google's consent screen → returns to `/google/callback` → back to profile with `?gcal=connected` or `?gcal=error`.

**Once connected:** every confirmed viewing is automatically added to that person's calendar, and removed if cancelled. **Buyers and agents both** can connect — sync is not agent-only.

**Section 6 — Change password**
Current password · New password · Confirm new password. Same strength rules as registration.

**Section 7 — Agent application status** (buyers with an application)
Shows current status, the admin's note if rejected, the AI assessment, and the re-apply countdown.

---

### FLOW 20 — Notifications

**Bell icon in the dashboard topbar.**

- Unread count badge (shows `9+` above nine)
- Dropdown lists the 15 most recent
- Unread items have a gold dot and tinted background
- "Mark all read" action
- Clicking a notification marks it read and navigates to the relevant page

**Routing map:**

| Notification type | Destination |
|---|---|
| Appointment events | `/dashboard/appointments` |
| New agent application | `/admin/agents` |
| Agent application decision | `/dashboard/profile` |
| New inquiry | `/dashboard/inquiries` |
| New message | `/dashboard/messages` |
| New review | `/dashboard/reviews` |
| Property / listing events | `/dashboard/listings` |

**Empty state:** "No notifications yet"

**Reliability:** notifications are saved to the database before being pushed live. If the real-time connection is down, they still appear on the next page load.

---

### FLOW 21 — Admin: approve agents

**Screen:** `/admin/agents`

Each application card shows:
- Applicant avatar, name, email
- **Type badge:** Broker (navy) or Salesperson (gold)
- PRC / Accreditation number
- Supervising broker, if provided
- Document links: License card · Accreditation · Valid ID · Live selfie
- **RealtyLink AI assessment panel** — gold-bordered, labelled "advisory only — you decide"

**Actions:**
- **Approve** → role becomes Agent, approval email sent
- **Reject** → modal requires a reason → rejection email sent with the reason and the re-apply time

**Empty state:** "No pending applications."

---

### FLOW 22 — Admin: moderate listings

**Screen:** `/admin/listings`

Table of every listing with status filter and pagination.

**Actions per listing:**
- **Unpublish** — returns it to draft
- **Delete** — permanent, behind a confirm
- **Why featured?** — opens a modal showing:
  - Eligibility: passed, or the specific reasons it failed
  - Total score out of 100
  - Each of the four factors with its score, maximum, and human-readable notes
  - An AI-written plain-language summary based only on those numbers

---

### FLOW 23 — Admin: manage users

**Screen:** `/admin/users`

- Search by name or email
- Filter by role: all · buyers · agents · admins
- Stat tiles: total, buyers, agents, admins
- **Create Admin** — name, email, password. Created pre-verified.
- **Delete user**

**Two protection rules:**
1. An admin cannot delete their own account → "You cannot delete your own account."
2. The last remaining admin cannot be deleted → "You cannot delete the last admin."

---

### FLOW 24 — Admin: moderate reviews

**Screen:** `/admin/reviews`

Each row shows buyer name → agent name, star rating, visibility badge, review text.

**Action:** Show / Hide toggle. Hiding removes the review from the agent's public average immediately but does not delete it.

---

## 6. BACKGROUND PROCESSES

### 6.1 Featured Properties ranking (runs hourly)

**Five requirements — fail any one and the score is zero:**
1. Listing is published
2. Agent is PRC-verified
3. Has at least one photo
4. Has a description
5. Price is sensible for its offer type

**Four scored factors, totalling 100:**

| Factor | Points | Measures |
|---|---|---|
| Listing completeness | 30 | Photo count (3 is enough), 360° tour present, specs filled |
| Agent trust | 25 | Average rating + number of reviews (saturates around 20) |
| Engagement | 25 | Views per day, relative to the best performer on the platform |
| Freshness | 20 | Decays exponentially — nothing stays featured forever |

**Fairness rule:** maximum **one featured listing per agent**.

**Fallback:** if nothing is scored yet, the homepage shows the most recent published listings instead.

### 6.2 Emails (all queued in the background)

Email verification · Password reset · New viewing request (to both parties) · Viewing cancelled · New review received · Agent application approved / rejected · New agent application (to admins) · New property alert (to opted-in buyers)

### 6.3 Maintenance commands

| Command | Purpose |
|---|---|
| Recompute featured scores | Refreshes homepage ranking (scheduled hourly) |
| Geocode properties | Fills in missing map coordinates |
| Audit prices | Lists listings whose price looks wrong for their offer type |

---

## 7. DESIGN SYSTEM REFERENCE

### 7.1 Colours

| Token | Hex | Usage |
|---|---|---|
| `brand-navy` | `#08152F` | Primary brand, headings, dark surfaces |
| `brand-navy-mid` | `#10264D` | Dark-mode cards, gradient midpoint |
| `brand-navy-light` | `#2E6DA4` | Accent blue |
| `brand-navy-deep` | `#060E1F` | Darkest background |
| `brand-gold` | `#D4AF37` | Primary accent, CTAs, active nav, badges |
| `brand-gold-light` | `#E8C547` | Gold hover |
| `brand-silver` | `#D4D4D4` | Muted |
| `brand-silver-light` | `#F0F0F0` | Light muted |
| `brand-cream` | `#F7F5F0` | Warm background |
| `brand-border` | `#E5E7EB` | Default borders |
| `brand-text-primary` | `#08152F` | Body text |
| `brand-text-secondary` | `#6B7280` | Secondary text |
| `brand-text-light` | `#9CA3AF` | Tertiary text |

**Status colours:**
Pending → amber · Confirmed / Success → emerald · Cancelled / Error → red · Info → blue

### 7.2 Typography

| Role | Font | Notes |
|---|---|---|
| Headings / display | **Sora** | Also aliased as `font-playfair` in the codebase |
| Body | **Inter** | Default sans |

**Common patterns:**
- Section eyebrow: 11px, bold, uppercase, letter-spacing `0.25em`, gold, preceded by a short gold rule
- Page heading: Sora, bold, 2xl–4xl
- Card heading: bold, base size
- Metadata: 10–11px, grey

### 7.3 Shapes and elevation

| Token | Value |
|---|---|
| Card radius | `12px` |
| Button radius | `8px` |
| Larger surfaces | `rounded-2xl` / `rounded-3xl` |
| Card shadow | `0 2px 12px rgba(0,0,0,0.08)` |
| Card hover shadow | `0 8px 32px rgba(0,0,0,0.16)` |
| Gold glow (CTA) | `0 4px 20px rgba(212,175,55,0.4)` |
| Max content width | `1280px` |

### 7.4 Recurring UI patterns

**Welcome banner** — navy gradient, gold dot-grid overlay at 5% opacity, decorative outlined circles top-right, gold eyebrow, Sora heading, gold CTA button.

**Stat tile** — white card, gold-tinted icon square, large bold number, small grey label, lifts on hover.

**Property card** — image with offer-type badge and heart, title, address, price in gold, spec row.

**Sidebar active state** — gold background, navy text, gold glow shadow. Inactive: 60% white text, subtle white hover.

**Modal** — blurred dark backdrop (`rgba(6,14,31,0.65)` with 10px blur), navy gradient card, gold accents, Escape closes, body scroll locks.

**Empty state** — grey circle with an outline icon, bold navy heading, grey helper line, gold CTA button.

**Loading** — skeleton placeholders matching the final layout, never blank space.

### 7.5 Dark mode

Class-based (`dark` on the root). Both themes are fully designed.

| Surface | Light | Dark |
|---|---|---|
| Page background | `#F9FAFB` | `#060E1F` |
| Card | `#FFFFFF` | `#10264D` |
| Topbar / sidebar | White / navy gradient | `#0d1f3c` / same gradient |
| Border | `#E5E7EB` | `rgba(255,255,255,0.1)` |
| Primary text | `#08152F` | `#FFFFFF` |
| Secondary text | `#6B7280` | `rgba(255,255,255,0.5)` |

The public property detail page always uses the dark navy treatment regardless of the user's theme.

---

## 8. STATES TO DESIGN FOR EVERY SCREEN

1. **Loading** — skeleton matching the final layout
2. **Empty** — icon, heading, helper text, primary action
3. **Error** — clear message plus a retry path
4. **Populated** — the normal case
5. **Partial** — some data missing (no photos, no coordinates, no reviews yet)

**Specific empty states needed:**
No saved properties · No conversations yet · No appointments · No history · No listings yet · No inquiries · No reviews yet · No pending applications · No search results · No available time slots · No notifications

---

## 9. THE COMPLETE JOURNEY IN ONE PARAGRAPH

A visitor browses freely and registers as a Buyer. To sell, they apply to become an Agent by submitting licence documents and a live face scan; AI pre-screens the documents and an administrator makes the final decision. Once approved, the Agent creates a listing with photos, a 360° tour, and an AI-assisted description, then publishes it. Buyers discover it through search, the map, or the AI assistant. A buyer makes contact — by enquiry, by live chat, or by booking a viewing in a real available time slot. The Agent confirms and the viewing appears on both people's Google Calendars. They meet at the property. The Agent marks the viewing done. The Buyer rates the Agent, raising their public reputation, which in turn improves the ranking of their future listings. When the deal closes, the Agent marks the property Sold and it moves to their private Inventory. The cycle repeats.

---
---

# SECTION 10 — DETAILED SCREEN SPECIFICATIONS

Element-by-element breakdown of every major screen. Use this when designing or redesigning a specific page.

---

## 10.1 HOMEPAGE — `/`

**Layout:** default (public navbar + footer). Full SSR.

**Nine sections, top to bottom:**

### Section 1 — Hero with search
- **Height:** `min-h-screen`, content vertically centred
- **Background:** full-bleed photo (`/background.png`), `object-cover`, with two stacked overlays: a vertical navy gradient (80% → 55% → 85% opacity) plus a flat 35% navy wash
- **Content column:** max-width 900px, centred, `pt-32` to clear the fixed navbar

**Contents in order:**
1. **Eyebrow pill** — glass effect (6% white, 8px blur, 20% white border), rounded-full, containing a 6px gold dot + text "TRUSTED AGENTS · VERIFIED PROPERTIES" (10px, bold, uppercase, `0.25em` tracking, gold)
2. **Animated heading** — the main headline with an animated focus-reticle effect
3. **Subtext** — 55% white, max-width `xl`, centred
4. **Search bar** — see below
5. **Stats row** — 4 items separated by vertical hairlines (hidden on mobile)

**Search bar spec:**
- Container: max-width 820px, rounded-2xl, padding 2.5, glass (6% white bg, 20px blur, 13% white border, heavy drop shadow)
- Stacks vertically below `lg`, horizontal row above
- **Field 1 — Location:** flex-1, gold pin icon, transparent text input, placeholder "Location", Enter key submits
- **Field 2 — Property type:** fixed `w-44`, gold house icon, native select. Options: Property type (placeholder) / House / Condo / Lot / Commercial / Apartment. Placeholder shows at 40% white, selected value at full white
- **Field 3 — Budget:** fixed `w-44`, gold card icon, native select. Options: Budget · Under ₱1M · ₱1M–₱5M · ₱5M–₱10M · ₱10M–₱20M · ₱20M+
- **Button:** gold background, navy text, `font-black`, magnifier icon + "Search", gold glow shadow, lifts on hover
- **Submits to:** `/properties` with query params `search`, `type`, `min_price`, `max_price`

**Stats row (4 items):** each is a gold outline icon + gold value (xl, bold) + 45% white label (11px)
- `500+` Active Listings · `100+` Verified Agents · `50+` Properties Listed · `17` Regions Covered

> **Note:** these four figures are currently hard-coded, not live data.

### Section 2 — Why Choose
White background. Value-proposition blocks.

### Section 3 — Featured Properties
- White background, `py-20`, max-width 1280px
- **Header row** (stacks on mobile, spread on `sm`+):
  - Left: gold rule (`w-8`, 1px) + eyebrow "HANDPICKED FOR YOU" → h2 "Featured Properties" (Sora, 3xl–4xl, navy) → subtext "Verified listings from licensed agents across the Philippines."
  - Right: pill link "View all properties" with a chevron that slides right on hover. Border navy at 15%, turns gold on hover
- **Body:** `PropertyGrid` with 4 cards, 4 skeletons while loading

### Section 4 — Browse by Property Type
Grid of the five property types as tappable cards.

### Section 5 — Top Verified Agents
- **Hidden entirely if there are no agents**
- White background, top border
- Same header pattern: eyebrow "TRUSTED PROFESSIONALS" → "Top Verified Agents" → "PRC-licensed agents ready to help you find your next home." → "View all agents" pill
- Grid: 1 col → 2 cols (`sm`) → 4 cols (`lg`), gap 6, using `AgentCard`

### Section 6 — How It Works
Navy background. Step-by-step explainer.

### Section 7 — Stats Bar
Darker navy. Platform numbers.

### Section 8 — Testimonials
Carousel.

### Section 9 — CTA Banner
Closing call to action.

---

## 10.2 PROPERTY CARD (shared component)

Used on the homepage, browse grid, saved page, and dashboard. The single most-repeated component in the app.

**Container:** `NuxtLink` wrapping the whole card. White (`#10264D` in dark), rounded-xl, 1px border, hover raises shadow and lifts `-translate-y-0.5`.

**Photo area — `aspect-[4/3]`:**
- Image fills, scales to `1.05` on card hover
- **Carousel** (only when >1 photo, all controls appear on hover only):
  - Left/right chevron buttons: white at 80%, rounded-full, vertically centred
  - Dot indicators bottom-centre: active dot is `w-4` white, inactive `w-1.5` at 60%. Max 5 dots
- **Empty state:** grey image icon centred on `bg-gray-100`

**Top-left badge stack** (vertical, gap 1.5, only renders what applies):

| Badge | Style | Condition |
|---|---|---|
| `★ FEATURED` | Gold bg, navy text | `is_featured` |
| `✓ VERIFIED` | Navy 80% + blur bg, gold text | Agent profile approved |
| `360°` | Gold bg, navy text | Any photo flagged 360° |

All are 10px, bold, `px-2.5 py-1`, rounded-full.

**Top-right — heart button:**
- 32×32, white 90%, rounded-full
- **Hidden until card hover unless already favourited**
- Filled red when saved, grey outline when not
- Click behaviour: if not logged in → opens the login modal. If logged in → optimistic toggle, toast "Saved" or "Removed from saved", reverts on error

**Info area — `p-4`:**
1. **Title** — bold, navy, `text-sm`, `line-clamp-1`
2. **Address** — 12px grey, pin icon, `line-clamp-1`
3. **Specs row** — hidden if no specs. Beds / Baths / m² each with a small grey icon, 12px, gap 4
4. **Price** — `text-lg`, bold, **gold**. Abbreviated: `₱3.5M` · `₱25K` · `₱850,000`

---

## 10.3 BROWSE / SEARCH — `/properties` and `/dashboard/browse`

Same component, `embedded` prop removes the page background and max-width wrapper for the dashboard version.

### Filter bar
White card (`#10264D` dark), rounded-2xl, border, `p-3` → `p-4`, margin-bottom 5.
Stacks vertically below `lg`, horizontal row above, aligned to bottom.

**Fields** — every one uses the same style: `px-3.5 py-2.5`, 12px text, rounded-xl, `bg-gray-50`, turns white on focus with a gold ring.
Every label: 10px, bold, uppercase, `tracking-wide`, navy at 50%.

| Field | Type | Notes |
|---|---|---|
| Location | text | Magnifier icon inset left, `!pl-9`. Enter submits |
| Property type | select | Blank option = all |
| Offer type | select | Blank = both |
| Min price | number | — |
| Max price | number | — |
| Beds | select | `""` maps to undefined via a computed proxy |

**Actions:** Apply Filters (gold) · Clear (ghost) · Map toggle

### Results area
- **Grid view:** `PropertyGrid`, responsive columns, 8 skeletons while loading
- **Map view:** toggled by `showMap`. Leaflet, OSM tiles, default centre `[12.8797, 121.7740]` at zoom 5

**Map markers** — custom `divIcon`, two variants:
- With photo: `map-photo-pin` — thumbnail + price label beneath
- Without photo: `map-price-pin` — price label only

**Marker popup** (min-width 170px): navy bold title → gold bold price → grey 11px address → "View details →" link.
Map auto-fits bounds to all visible markers.

### URL sync
Applied filters are pushed into the query string, so results are shareable and survive a refresh. Empty values are stripped.

### Pagination
Page buttons; changing page scrolls smoothly to top.

---

## 10.4 PROPERTY DETAIL — `/properties/[id]` and `/dashboard/properties/[id]`

**One shared component, two wrappers:**
- **Public:** wrapped in a scoped `dark` class over `#08152F`, `pt-24` to clear the navbar — a deliberately premium dark treatment
- **In-dashboard:** normal light dashboard chrome

**Container:** max-width 1280px, centred.

### States
- **Loading:** skeleton 100%×460px → 60%×32px → 4 text lines
- **Error:** centred red text, `py-16`

### Header row
Stacks on mobile, spread with bottom alignment on `sm`+.

**Left column:**
1. **Badge row:** offer-type pill (rent = blue-100/blue-700, sale = emerald-100/emerald-700, 10px bold uppercase) + property-type badge (navy variant)
2. **Title:** Sora, bold, `2xl` → `4xl`, navy (white in dark)
3. **Address:** anchor to `#location`, pin icon, turns gold on hover

**Right column:** price `text-3xl` bold navy (gold in dark), plus "per month" beneath when rental.

### Gallery
- Grid: 2 cols mobile → 3 cols `sm`+, gap 2 → 3
- Each tile `aspect-[4/3]`, rounded-2xl, image scales `1.04` on hover
- **Maximum 6 tiles.** The 6th shows a black 55% overlay reading `+N more` when there are extras
- **360° badge** floats top-left of the gallery: navy 90% + blur, gold globe icon, "360° Virtual Tour", opens the tour modal
- **No photos:** `aspect-[16/7]` placeholder reading "No photos available"

### Body — 3-column grid on `lg`, gap 8

**Left (spans 2 columns), stacked with gap 8:**

1. **Spec tiles** — 2 cols mobile → 4 cols `sm`. Each: rounded-2xl bordered card, centred, gold-tinted 36px icon square, value at `text-xl` bold, 11px label. Only renders specs that exist: Bedrooms · Bathrooms · Floor (sqm) · Lot (sqm)
2. **About this property** — Sora `text-xl` heading, body text with `whitespace-pre-line`. Hidden if no description
3. **Location** — `id="location"`, `scroll-mt-24`. Heading, gold pin + address, then the embedded location map

**Right (1 column) — sticky action card** (`lg:sticky lg:top-24`, gap 4):

1. **Price block** — `text-3xl` bold, then offer label + "· per month" for rentals
2. **Agent block** — rounded-2xl on a light grey (or 5% white) fill:
   - Avatar (md) + name + "Verified Agent" success badge
   - **If logged in:** email and phone rows, each with a grey icon, turning gold on hover. Falls back to "No contact details provided."
   - **If NOT logged in:** a single gold link — `🔒 Sign in to view contact details →` which opens the login modal
   - "View full profile →" link
3. **Action buttons** (conditional):

| Button | Shown to | Style |
|---|---|---|
| 360° Virtual Tour | Anyone, if panoramas exist | Navy fill, white text, gold icon |
| Schedule Viewing | Buyers only | Gold primary, full width |
| Send Inquiry | Guests only (not logged in) | Secondary, full width |
| Message Agent | Logged-in buyers | Outline, links to `/dashboard/messages?agent=X&property=Y` |

### Inquiry modal
Title "Send Inquiry". Intro line "Leave your contact details and we'll connect you with the agent."
- Name / Email / Phone fields — **only shown to guests**; logged-in users skip them
- **Message field** with a required asterisk, preceded by three quick-fill chips: `Is it available?` · `Financing` · `Schedule a viewing`. Chips are rounded-full, grey outline, gold border on hover. Clicking one replaces the message text
- Textarea, 4 rows, no resize
- Footer: Cancel (ghost) + Send (primary, with loading state)

### Booking modal — size `lg`

Error banner at top when present (red text on red-50, rounded).

**Two-column grid:** `125px | 1fr` on mobile, `185px | 1fr` on `sm`+, gap 4–5.

**Left — date list:**
- Label "SELECT A DATE" (11px bold uppercase grey)
- Scrollable, `max-h-[300px]`
- 14 buttons, each showing weekday (11px uppercase, 70% opacity) on the left and month+day (bold) on the right

| Date state | Style | Behaviour |
|---|---|---|
| Available | Grey border, gold border on hover | Selectable |
| Selected | Navy fill, white text | — |
| Limited | Adds an amber `LIMITED` pill before the date | Selectable, tooltip "Limited availability" |
| Blocked | Grey-50 fill, grey-300 text, **strikethrough**, `cursor-not-allowed` | Disabled, tooltip "Agent is unavailable this day" |

**Right — time slots:**
- Label "AVAILABLE TIMES"
- Scrollable, `max-h-[300px]`
- **Four states:**
  - No date picked → "Pick a date to see times." (grey, `py-12`)
  - Loading → "Loading…"
  - Slots exist → grid, 2 cols mobile / 3 cols `sm`. Each button 12px bold, grey border → gold border on hover; **selected = gold fill + navy text**. Times shown 12-hour: `9:30 AM`
  - None available → amber text "No available times — pick another date."

**Below the grid:** Notes textarea (2 rows, optional).
**Footer:** Cancel (ghost) + Confirm Booking (primary, loading state).

**Auto-behaviour on open:** loads the agent's unavailable dates, then pre-selects the first non-blocked day automatically.

### Lightbox — teleported, `z-[100]`
Black 95% backdrop, click outside closes.
- **Top bar:** `3 / 12` counter (left) + circular close button (right)
- **Centre:** image `object-contain`, with circular prev/next buttons overlaid left and right (only when >1 photo)
- **Bottom:** horizontal thumbnail strip, active thumb has a gold 2px border

### 360° tour modal — teleported, `z-[100]`
Black 90% backdrop.
- **Header:** gold globe icon + "360° Virtual Tour" + "· drag to look around" (hidden on mobile) + close button
- **Body:** Photo Sphere Viewer, auto-rotates at 0.6rpm, starts after 2s, resumes when idle
- **Footer:** panorama thumbnail strip when there are multiple (gold border on active)

Both overlays use a 0.25s fade transition.

---

## 10.5 AGENT CARD & AGENT PROFILE

### Agent card (used in grids)
White, rounded-2xl, border, `p-5`, lifts on hover.

- **Avatar (lg)** with a **gold verification tick** overlapping bottom-right — 20px gold circle, navy checkmark, 2px white ring. Only when approved
- **Name** — semibold, `text-sm`, truncated
- **Subtitle** — "Licensed Agent" (12px grey)
- **Stats row** — spread: listing count on the left, gold star + rating (1 decimal) on the right. Each hides if absent
- **Footer button** — "View Profile", full width, navy outline, inverts to navy fill on hover

### Agent profile page — `/agents/[id]` and `/dashboard/agents/[id]`
- Header: large avatar, name, verified badge, average rating with star, review count, listing count
- Their published listings in a property grid
- Review list: reviewer name, star rating, text, date
- Contact actions
- **If the buyer has an unreviewed viewing with this agent**, a rating prompt appears

---

## 10.6 MESSAGES / CHAT — `/dashboard/messages`

The most interaction-dense screen in the app. Two panes.

### Entry behaviour
Arriving with `?agent=X&property=Y` automatically finds or creates that conversation, opens it, then clears the query string.

### Left pane — conversation list

1. **Search box** — filters by the other person's name, case-insensitive
2. **Conversation rows**, each showing:
   - Avatar with an **online dot** overlay
   - Other person's name (never your own — resolved by comparing your ID against `buyer_id`)
   - Last message preview, truncated
   - Timestamp
   - **Unread count badge** (gold) when > 0
   - Active row is visually highlighted

### Right pane — active thread

**Header:**
- Avatar + name
- **Presence line** beneath: `Active now` / `Active just now` / `Active 20 mins ago` / `Active 3 hours ago` / `Active 2 days ago` / `Active 3 weeks ago` / `Offline`
- Name links to the agent's profile **only when the other party is an agent** (buyers have no public profile)

**Message feed** — built from a computed list that interleaves two item types:

1. **Date separators** — inserted whenever the day changes. Labels: `Today` · `Yesterday` · `March 14`
2. **Messages** — carrying a `showMeta` flag that is true only when the sender changed from the previous message. This drives **grouping**: consecutive messages from one person show the avatar and timestamp once, not on every bubble

- Own messages right-aligned; other person's left-aligned
- **Hover reactions:** hovering a bubble reveals a reaction picker — 🔥 ❤️ 👍 😂 😮 🙏. Selected reactions render on the bubble. **These are local-only and are not saved** — they vanish on refresh
- **"Seen" indicator** appears under your most recent message only when the other person has read it, reading `Seen 3 mins ago`, `Seen 2 hours ago`, etc.
- **Typing indicator** appears when the other person is typing, and clears after 2.5s of silence

**Composer:**
- Text input
- Emoji picker toggle
- Send button
- Typing whispers are throttled to one per 1.2 seconds

### Real-time details
| Behaviour | Timing |
|---|---|
| Inbound messages | Instant |
| Typing indicator timeout | 2.5s |
| Presence refresh | Every 30s |
| Heartbeat (marks you online) | Every 45s + on tab focus |
| "Online" threshold | Active within last 2 minutes |

**On opening a thread:** loads messages, seeds the seen-state from stored timestamps, marks messages read, tells the other person you've seen them, and subscribes to the channel. Leaving unsubscribes from the previous thread.

### Mobile
Below `lg` the two panes collapse — the list shows first, selecting a conversation swaps to the thread with a back control.

---

## 10.7 MY LISTINGS — `/dashboard/listings`

**Header:** Sora `2xl` "My Listings" + subline `{N} total · {N} published` (published count in emerald). Right side: gold "New Listing" button.

**Each listing row/card shows:** thumbnail, title, status, view count, price (abbreviated), and an action set.

**Actions by status:**

| Status | Available actions |
|---|---|
| Draft | Edit · **Publish** · Delete |
| Published | Edit · **Unpublish** · **Mark Sold** · Delete |

Sold listings are **not** on this page — they live under History → Inventory.

**Confirm dialogs:**
- Delete → "Delete this listing? This cannot be undone."
- Mark Sold → "Mark this listing as Sold? It moves to your Inventory (under History) and is hidden from buyers. You can re-list it anytime."

**Busy state:** the acting row disables while its request is in flight.

**Empty state:** "No listings yet." + "Create your first listing" gold link.

---

## 10.8 NEW LISTING WIZARD — `/dashboard/listings/new`

**Container:** max-width `5xl`. Back link "← Back" + Sora `2xl` heading "New Listing".
**Grid:** 3 columns on `lg`, gap 6, top-aligned.

### Left rail (1 col, sticky at `top-6`)
Navy gradient card (`#10264D` → `#08152F` → `#060E1F`), rounded-2xl, `p-5`, with a decorative gold outlined circle bleeding off the top-right corner.

- Eyebrow: "CREATE A LISTING" (10px gold, `0.2em` tracking)
- Three step buttons, each clickable to jump directly:
  - **Number circle** — 28px: gold fill with a navy checkmark once passed · white fill with navy number when current · 10% white with 50% white number when upcoming
  - **Label** (semibold) + **description** (11px, 40% white)

| # | Label | Description |
|---|---|---|
| 1 | Property Photos | The real photos buyers will see |
| 2 | Virtual Tour | 360° / panorama shots · optional |
| 3 | Listing Details | Title, price & description |

### Right panel (2 cols)

**Step 1 — Photos**
Multi-file input, thumbnail grid with previews, remove button per thumbnail. Files stay in memory.

**Step 2 — Virtual Tour**
Identical UI, separate array. These upload flagged as 360°.

**Step 3 — Details**
Full field set (see Flow 6 table), plus the **AI description block**:
- "Generate with AI" button showing a loading state while working
- Inline error: "Add a few photos (step 1) or fill in the address first." or "Could not generate a description. The AI may be busy — try again."
- Result populates the description textarea and stays editable

**Submit button** — enabled only when title, address, and a price above zero are all present.

**On submit:** price sanity confirm (if triggered) → create property → upload gallery photos in order → upload panoramas → redirect to `/dashboard/listings`.

---

## 10.9 EDIT LISTING — `/dashboard/listings/[id]/edit`

Same field set as step 3 of the wizard, plus:
- **Existing photo grid** in sort order, with delete per photo and drag-to-reorder
- Upload additional photos and panoramas
- Same AI description button and price sanity check
- Save returns to My Listings

---

## 10.10 INQUIRIES — `/dashboard/inquiries`

**Container:** max-width `4xl`.

**Header row:** Sora `2xl` "Inquiries" + subline "Messages from buyers interested in your listings."
**Right:** segmented filter in a grey-100 rounded-xl track with 4px padding — **All** / **Unread**. Active segment is white with a small shadow. The Unread tab carries a gold count badge.

**Inquiry card** — rounded-2xl, `p-5`:
- Border is grey-200 when read, **gold at 40% with a 3% gold tint when unread**
- Top row: bold navy **name** + gold dot (unread only) + grey **"GUEST"** chip for ghost buyers · timestamp on the right (11px grey)
- Beneath the name: property title as a gold link, or `Property #12` in grey if the property is gone
- **Message body** — 14px, `whitespace-pre-wrap`, relaxed line height, `mt-3`
- **Footer** (separated by a top border, `pt-3`): `mailto:` email link and `tel:` phone link, each with an icon and gold hover · **"Mark as read"** pushed right, shown only while unread

**Loading:** 3 skeletons at 110px.
**Empty:** envelope icon in a grey circle + "No inquiries yet." or "No unread inquiries." depending on the active filter.

---

## 10.11 APPOINTMENTS — `/dashboard/appointments`

**Container:** max-width `4xl`.
**Header:** Sora `2xl` "Appointments" + a role-aware subline — agents see "Property viewings requested on your listings.", buyers see "Property viewings you've scheduled."

### Grouping
Three sections, and **empty groups are hidden entirely**:

| Group | Label | Contents | Dot |
|---|---|---|---|
| pending | Agent: "Needs your response" · Buyer: "Pending" | Pending and not past, soonest first | Amber |
| upcoming | "Upcoming" | Confirmed and not past, soonest first | Emerald |
| past | "Past" | Not cancelled, not completed, date has passed, newest first | Grey |

Cancelled and completed viewings are **not here** — they move to History.

**Group header:** coloured dot + uppercase bold navy label + grey count in parentheses.

### Appointment card
White, rounded-2xl, border, `p-4`, horizontal flex with gap 4. Cancelled cards render at 70% opacity.

1. **Thumbnail** — 64×96, rounded-lg, links to the property. Falls back to a grey image icon
2. **Details column:**
   - Property title (links, gold on hover) + **status pill** (10px bold uppercase)
   - Calendar icon + formatted datetime: `Mon, Mar 14, 02:30 PM`
   - Person icon + `Buyer: Name` or `Agent: Name` depending on your role
   - Notes in italic quotes, `line-clamp-2`, when present
3. **Action column** — vertical, hidden entirely for cancelled appointments:

| Button | Condition | Style |
|---|---|---|
| **Confirm** | Agent + pending + not past | Gold fill, navy text |
| **Mark as Done** | Agent + confirmed | Emerald gradient, white text, checkmark icon |
| **Decline** | Agent + pending | Red outline |
| **Cancel** | Everyone else, any non-cancelled | Red outline |

**Confirms:** Cancel → "Cancel this viewing?" · Mark as Done → "Mark this viewing as done? It will move to your History."

**Status pill colours:** pending = amber-100/700 · confirmed = emerald-100/700 · completed = emerald-100/700 · cancelled = red-100/600.

**Loading:** 4 skeletons at 96px.
**Empty:** gold-tinted rounded square with a calendar icon, "No appointments yet", plus "Browse listings →" for buyers only.

---

## 10.12 AGENT CALENDAR — `/dashboard/calendar`

### Month grid
- Header: `‹` · **"March 2026"** · `›`
- Weekday row: Sun Mon Tue Wed Thu Fri Sat
- **42 cells** (6 rows × 7), including leading and trailing days from adjacent months rendered muted
- Today is highlighted
- Each cell shows the day number plus indicators for: a whole-day block, time-range blocks, and a count of scheduled viewings

### Day detail panel
Opens on selecting a date. Shows:
- **Whole-day block** for that date, if one exists, with its reason and a remove action
- **Time-range blocks**, sorted by start time, each with its range, reason, and remove action
- **Scheduled viewings** for that date, sorted by time, showing buyer name and time, linking to the property

Cancelled viewings never appear here.

### Blocking controls
Four combinations:

| Configuration | Inputs |
|---|---|
| Whole day, one-off | Date + optional reason |
| Time range, one-off | Date + start + end + optional reason |
| Whole day, recurring | Weekday (Sun–Sat) + optional reason |
| Time range, recurring | Weekday + start + end + optional reason |

**Validation:** date cannot be in the past · end must be after start · start and end are required together or not at all.

**Date handling note:** all dates are formatted locally (`YYYY-MM-DD` built from local year/month/day) specifically to avoid UTC drift shifting a date by one day.

---

## 10.13 AUTH MODAL (login + register)

Teleported to body, `z-[100]`, centred, `p-4` → `p-6`.

**Backdrop:** `rgba(6,14,31,0.65)` with a **10px blur**. Clicking it closes.

**Card:**
- Navy gradient at high opacity: `rgba(16,38,77,.96)` → `rgba(8,21,47,.97)` → `rgba(6,14,31,.98)`
- rounded-3xl, `px-7 py-6` → `px-9`
- 1px white border at 8%
- Layered shadow: heavy drop + inset white highlight + a faint gold ring
- `max-h-[94vh]`, scrolls internally
- **Width animates between modes:** `420px` for login, `620px` for register, over 300ms

**Transitions:** backdrop fades, card pops.

### Login state
- Email field
- Password field with a show/hide toggle
- "Remember me" checkbox
- "Forgot password?" link
- Primary submit
- Divider
- **"Continue with Google"** button
- Footer: "Don't have an account? Sign up" — switches mode and resets the form

### Register state
- Name
- Email
- Password with show/hide
- Confirm password with its own show/hide
- Primary submit
- **"Continue with Google"**
- Footer: "Already have an account? Sign in"

**No role selector exists.** Every registration is hard-coded to `buyer`.

### Validation (client-side, inline per field)
| Field | Rule | Message |
|---|---|---|
| Name | Required | "Name is required" |
| Email | Required | "Email is required" |
| Password (login) | Required | "Password is required" |
| Password (register) | ≥8 chars, ≥1 letter, ≥1 digit | "Password must be at least 8 characters and include a letter and a number" |
| Confirm | Must match | "Passwords do not match" |

**Server errors** display above the form. Google failure shows: "Could not start Google sign-in. Make sure the server is running, then try again."

### Behaviour
- Escape closes
- Body scroll locks while open
- Form fully resets each time it opens
- Auto-opens from `?auth=login` or `?auth=register`, then removes that query param
- On success the modal closes and routing is role-aware: admin → `/admin/users` · agent → `/dashboard/listings` · buyer → `/dashboard/appointments`

---

## 10.14 BECOME-AN-AGENT WIZARD — `/dashboard/verify`

**On mount:** refreshes the user, and redirects verified agents straight to `/dashboard/listings`.

### Screen states (mutually exclusive)

| State | When | Shows |
|---|---|---|
| **Wizard** | No application, or re-applying | The step flow below |
| **Status** | Application pending, or just submitted | Pending card + AI assessment |
| **Rejected** | Last application rejected | Reason + countdown + re-apply button |

### Step 0 — Choose applicant type
Two large selectable cards: **Broker** and **Salesperson**, each with a short description of who it's for. Selecting one sets the step list and advances to step 1.

### Step layout
Two columns: the form on the left, a persistent side panel on the right.

**Side panel contains:**

1. **Live requirements checklist** — one row per step (excluding Review), each with its label, description, and a **tick that turns green as that step is completed**:

| Step label | Description |
|---|---|
| Information | Your details + license / accreditation no. |
| License Card | PRC broker license card |
| Accreditation | Accreditation document (front) |
| Valid ID | A valid government-issued ID |
| Live Scan | A quick live face scan |

2. **Benefits list:**
   - Post your property listings
   - Receive buyer inquiries & messages
   - Appointment booking with Google Calendar sync
   - A verified agent badge on your profile

### Step completion rules
The Next button is disabled until the current step passes:

| Step | Passes when |
|---|---|
| Information | Full name **and** PRC number are both non-empty |
| Accreditation | File chosen |
| Valid ID | File chosen |
| License Card | File chosen |
| Live Scan | Face image captured |
| Review | Always |

**Back** from step 1 returns to the type chooser and clears the selection.

### Live Scan component
- Idle: "Start camera" button
- Active: live video feed with a capture button
- Camera denied: "Could not access the camera. Please allow camera permission in your browser, then try again."
- Captured: still preview + **Retake**, which clears the captured file and restarts the camera
- The camera stream stops on capture and on unmount

### Submitting overlay
Full-screen, body scroll locked. Rotating text every **1.8 seconds**:
1. "Reading your documents…"
2. "Checking details & name consistency…"
3. "Comparing your live face scan…"
4. "Finalizing the AI pre-check…"

### Result cards

**Pending:** status message that review takes up to 24 hours, plus the **RealtyLink AI assessment** panel.

**Rejected:**
- The admin's reason
- The AI assessment
- **Live countdown**, ticking every second: `8h 12m` → `12m 30s` → `30s`
- Re-apply button, disabled while the countdown runs. Pressing it resets the wizard to step 0

---

## 10.15 PROFILE & SETTINGS — `/dashboard/profile`

Seven stacked sections, same for buyers and agents.

### 1. Profile information
- Avatar with live preview on selection (max 2MB), circular, with an upload control
- Name (required)
- Phone
- Save button → success confirmation
- Error: "Could not update profile."

> Submitted as multipart with a `_method: PUT` override so the file upload works.

### 2. Appearance
Three selectable cards, each with an icon and label: **Light** (sun) · **Dark** (moon) · **System** (monitor).
Applies instantly, saves locally first, then persists to the account.

### 3. Property alerts
Toggle switch with an explanatory line. Optimistic — flips immediately, reverts if the request fails.

### 4. Email verification
Only rendered when the email is unverified.
- Stage `idle`: "Send code" button
- Stage `sent`: six-digit code input + Confirm
- Errors: "Invalid verification code." · "Your code has expired. Please request a new one."

### 5. Google Calendar
| State | UI |
|---|---|
| Not connected | Explanatory line + "Connect Google Calendar" |
| Connected | Connected badge + "Disconnect" |

Returning from OAuth sets `?gcal=connected` or `?gcal=error` — show a toast for each.

### 6. Change password
Current password · New password · Confirm. Show/hide toggle. Same strength rules as registration. Fields clear on success. Field-level server errors surface inline.

### 7. Agent application status
Only for users with an application. Shows status, the admin's note if rejected, the AI assessment, and the re-apply countdown.

---

## 10.16 SHARED COMPONENT LIBRARY

Existing base components to reuse rather than redesign:

| Component | Purpose |
|---|---|
| `AppButton` | Variants: primary (gold) · secondary · ghost · danger. Sizes sm/md/lg. `full-width` and `loading` props |
| `AppInput` | Labelled input with error slot |
| `AppModal` | Titled modal shell, sizes incl. `lg`, emits `close` |
| `AppBadge` | Variants: navy · success · error |
| `AppAvatar` | Sizes sm/md/lg, falls back to initials when there's no image |
| `AppRating` | Star display, `readonly` mode, sizes |
| `AppSkeleton` | Configurable width/height/rounded, or `lines` for text blocks |
| `AppSpinner` | Sizes |
| `AppToast` | Global toasts: `success` · `info` · `error` |
| `AppLogo` | Has an `on-dark` variant |
| `PropertyGrid` | Wraps PropertyCard, takes `loading` + `skeleton-count`, re-emits `toggle` |
| `PropertySkeleton` | Card-shaped placeholder |

**Utility classes already defined:** `.card` · `.btn-primary` · `.btn-outline` · `.input-field`

---

## 10.17 RESPONSIVE BREAKPOINTS IN USE

| Breakpoint | Width | Main effects |
|---|---|---|
| base | — | Single column, sidebar hidden behind a drawer, chat panes stacked |
| `sm` | 640px | 2–3 column grids, header rows spread horizontally, secondary labels appear |
| `md` | 768px | Public navbar links appear |
| `lg` | 1024px | Sidebar becomes permanent, property detail goes 3-column with a sticky rail, chat becomes two panes, filter bar goes horizontal |

**Mobile-specific behaviours:**
- Dashboard sidebar is a slide-in drawer with a 40% black backdrop; any navigation closes it
- Public navbar collapses to a hamburger
- Chat collapses to one pane at a time
- Hero search stacks its four controls vertically

---
---

# SECTION 11 — SCREEN FLOW MAP & BUILD ORDER

**Read this section first.** It shows how screens connect, and the order to design them in.

---

## 11.1 MASTER MAP — ENTRY AND ROUTING

```
                          ┌─────────────────────┐
                          │   LANDING PAGE  /   │  ◀── everyone starts here
                          └──────────┬──────────┘
                                     │
         ┌───────────────────────────┼───────────────────────────┐
         │                           │                           │
         ▼                           ▼                           ▼
  ┌─────────────┐          ┌──────────────────┐        ┌──────────────┐
  │ Hero Search │          │ Featured / Types │        │ Sign In / Up │
  └──────┬──────┘          └────────┬─────────┘        └──────┬───────┘
         │                          │                         │
         └────────────┬─────────────┘                         ▼
                      ▼                               ┌───────────────┐
            ┌───────────────────┐                     │  AUTH MODAL   │
            │  PROPERTIES LIST  │                     └───────┬───────┘
            │    /properties    │                             │
            └─────────┬─────────┘                  ┌──────────┴──────────┐
                      │                            ▼                     ▼
                      ▼                      ┌──────────┐         ┌───────────┐
            ┌───────────────────┐            │ REGISTER │         │   LOGIN   │
            │  PROPERTY DETAIL  │            └────┬─────┘         └─────┬─────┘
            │  /properties/:id  │                 │                     │
            └─────────┬─────────┘                 └──────────┬──────────┘
                      │                                      ▼
        ┌─────────────┴─────────────┐            ┌────────────────────┐
        ▼                           ▼            │    ROLE  ROUTER    │
 ┌──────────────┐        ┌────────────────────┐  └─────────┬──────────┘
 │ SEND INQUIRY │        │  Save / Chat /     │            │
 │  (guest OK)  │        │  Book → needs auth │  ┌─────────┼─────────┐
 └──────┬───────┘        └─────────┬──────────┘  ▼         ▼         ▼
        ▼                          │        ┌────────┐┌────────┐┌────────┐
   ✔ GUEST END                     └───────▶│ BUYER  ││ AGENT  ││ ADMIN  │
                                            │  DASH  ││  DASH  ││ PANEL  │
                                            └────────┘└────────┘└────────┘
```

**Landing rules after auth:** Buyer → `/dashboard/appointments` · Agent → `/dashboard/listings` · Admin → `/admin/users`

---

## 11.2 BUYER JOURNEY

```
┌──────────────────┐
│  BUYER DASHBOARD │
│    /dashboard    │
└────────┬─────────┘
         │
   ┌─────┴──────┬──────────┬───────────┬───────────┬──────────┐
   ▼            ▼          ▼           ▼           ▼          ▼
┌───────┐  ┌────────┐ ┌─────────┐ ┌──────────┐ ┌───────┐ ┌─────────┐
│BROWSE │  │ SAVED  │ │MESSAGES │ │APPOINTM. │ │HISTORY│ │ PROFILE │
└───┬───┘  └───┬────┘ └────┬────┘ └────┬─────┘ └───┬───┘ └────┬────┘
    │          │           │           │           │          │
    ▼          │           │           │           │          ▼
┌────────────┐ │           │           │           │   ┌─────────────┐
│  PROPERTY  │◀┘           │           │           │   │ BECOME AGENT│
│   DETAIL   │             │           │           │   │  /verify    │
└─────┬──────┘             │           │           │   └─────────────┘
      │                    │           │           │
      ├── ♥ Save ──────────┤           │           │
      │                    │           │           │
      ├── 💬 Message ──────┘           │           │
      │                                │           │
      └── 📅 Book Viewing ─────────────┘           │
                    │                              │
                    ▼                              │
          ┌───────────────────┐                    │
          │ Agent confirms    │                    │
          │ Viewing happens   │                    │
          │ Agent marks done  │                    │
          └─────────┬─────────┘                    │
                    └──────────────────────────────┤
                                                   ▼
                                          ┌─────────────────┐
                                          │ ★ RATE THE AGENT│
                                          └─────────────────┘
```

---

## 11.3 BUYER → AGENT CONVERSION

```
┌──────────────┐
│    BUYER     │
└──────┬───────┘
       │ clicks "Become an Agent"
       ▼
┌──────────────────────┐
│  VERIFY WIZARD  /    │
│  dashboard/verify    │
└──────────┬───────────┘
           │
           ▼
    ┌─────────────┐
    │ CHOOSE PATH │
    └──────┬──────┘
           │
   ┌───────┴────────┐
   ▼                ▼
┌────────┐    ┌──────────────┐
│ BROKER │    │ SALESPERSON  │
│4 steps │    │   5 steps    │
└───┬────┘    └──────┬───────┘
    │                │
    ▼                ▼
 Info             Info
 License Card     Accreditation
 Live Scan        Valid ID
 Review           Live Scan
    │             Review
    └───────┬────────┘
            ▼
   ┌─────────────────┐
   │  AI PRE-SCREEN  │  ← rotating overlay, ~5–25s
   │   (advisory)    │
   └────────┬────────┘
            ▼
   ┌─────────────────┐
   │  PENDING CARD   │  ← "decision within 24 hours"
   └────────┬────────┘
            │
      ┌─────┴──────┐
      ▼            ▼
┌───────────┐ ┌──────────────┐
│ APPROVED  │ │   REJECTED   │
│ → AGENT   │ │ + reason     │
│   DASH    │ │ + 12h timer  │
└───────────┘ └──────┬───────┘
                     │ after countdown
                     ▼
                 Re-apply
```

---

## 11.4 AGENT JOURNEY

```
┌──────────────────┐
│  AGENT DASHBOARD │
└────────┬─────────┘
         │
  ┌──────┼──────┬──────────┬──────────┬─────────┬─────────┐
  ▼      ▼      ▼          ▼          ▼         ▼         ▼
┌──────┐┌─────┐┌────────┐┌────────┐┌───────┐┌────────┐┌───────┐
│LIST- ││INQU-││APPOINT-││MESSAGES││CALEN- ││REVIEWS ││HISTORY│
│INGS  ││IRIES││MENTS   ││        ││DAR    ││        ││       │
└──┬───┘└─────┘└───┬────┘└────────┘└───┬───┘└────────┘└───┬───┘
   │               │                   │                  │
   ▼               │                   ▼                  ▼
┌──────────┐       │            ┌────────────┐     ┌───────────┐
│NEW       │       │            │Block days /│     │ INVENTORY │
│LISTING   │       │            │time ranges │     │  (sold)   │
│(3 steps) │       │            └─────┬──────┘     └─────┬─────┘
└────┬─────┘       │                  │                  │
     ▼             │                  ▼                  │ relist
┌──────────┐       │        affects buyer booking        │
│  DRAFT   │       │                                     │
└────┬─────┘       ▼                                     │
     │ publish  Confirm ──▶ Mark Done ──▶ buyer can rate  │
     ▼                                                   │
┌──────────┐                                             │
│PUBLISHED │◀────────────────────────────────────────────┘
└────┬─────┘
     │ mark sold
     ▼
┌──────────┐
│   SOLD   │──▶ Inventory
└──────────┘
```

---

## 11.5 ADMIN JOURNEY

```
┌──────────────┐
│ ADMIN LOGIN  │
└──────┬───────┘
       ▼
┌──────────────┐
│ /admin/users │  ← default landing
└──────┬───────┘
       │
  ┌────┼─────────┬───────────┬──────────┐
  ▼    ▼         ▼           ▼          ▼
┌──────┐  ┌──────────┐ ┌──────────┐ ┌─────────┐
│USERS │  │  AGENTS  │ │ LISTINGS │ │ REVIEWS │
└──────┘  └────┬─────┘ └────┬─────┘ └────┬────┘
               │            │            │
               ▼            ▼            ▼
        ┌─────────────┐ ┌─────────┐ ┌─────────┐
        │ Review app  │ │Unpublish│ │Show/Hide│
        │ + AI note   │ │ Delete  │ └─────────┘
        │             │ │  Why    │
        │ Approve ────┼─│featured?│
        │ Reject      │ └─────────┘
        └─────────────┘
```

---

## 11.6 THE THREE CONTACT CHANNELS

```
                    ┌───────────────────┐
                    │  PROPERTY DETAIL  │
                    └─────────┬─────────┘
                              │
        ┌─────────────────────┼─────────────────────┐
        ▼                     ▼                     ▼
┌───────────────┐    ┌────────────────┐    ┌────────────────┐
│  A. INQUIRY   │    │  B. CHAT       │    │  C. VIEWING    │
│  no account   │    │  both logged   │    │  buyer only    │
│  needed       │    │  in            │    │                │
└───────┬───────┘    └───────┬────────┘    └───────┬────────┘
        ▼                    ▼                     ▼
┌───────────────┐    ┌────────────────┐    ┌────────────────┐
│Agent Inquiries│    │ Both: Messages │    │Both:Appointment│
│  ONE-WAY      │    │   TWO-WAY      │    │  4-state flow  │
│  no reply     │    │   realtime     │    │  + calendar    │
└───────────────┘    └────────────────┘    └───────┬────────┘
                                                   ▼
                                           ┌────────────────┐
                                           │ UNLOCKS REVIEW │
                                           └────────────────┘
```

Only channel C leads to a review. That is the whole trust loop.

---

## 11.7 BUILD ORDER — WHAT TO DESIGN, IN SEQUENCE

Ordered by dependency. Each phase unblocks the next.

### ▶ PHASE 1 — Foundation *(build before any screen)*

Nothing renders correctly until these exist.

| # | Item | Why first |
|---|---|---|
| 1 | **Design tokens** — navy/gold palette, Sora + Inter scale, radii, shadows, spacing | Every screen consumes these |
| 2 | **Buttons** — primary (gold), secondary, ghost, danger, outline · sizes · loading state | On every screen |
| 3 | **Form fields** — text input, select, textarea, label, error, focus ring | Auth, listings, filters, profile |
| 4 | **Badge / pill** — navy, success, error, plus status colours | Cards, tables, appointments |
| 5 | **Avatar** — sm/md/lg + initials fallback + online dot | Cards, chat, navbar |
| 6 | **Modal shell** — blurred backdrop, sizes, close behaviour | 6+ screens use it |
| 7 | **Skeleton + spinner + toast** | Every loading and feedback state |

### ▶ PHASE 2 — Public entry *(first impression)*

| # | Screen | Depends on | Note |
|---|---|---|---|
| 8 | **Property Card** | Phase 1 | Design this before any grid — it appears on 6+ screens |
| 9 | **Landing page** | 8 | Hero + search is the single most-seen surface |
| 10 | **Auth modal** | 1, 3 | Both login and register states |
| 11 | **Browse / Search** | 8 | Filter bar + grid + map toggle |
| 12 | **Property Detail** | 6, 8 | The conversion screen — gallery, sticky action card |
| 13 | **Inquiry modal** | 6 | The only guest conversion path |

> After Phase 2 a guest can complete a full journey: land → search → view → enquire.

### ▶ PHASE 3 — Dashboard shell *(unblocks 20 screens)*

| # | Item | Note |
|---|---|---|
| 14 | **Sidebar** | Three link sets (buyer 7 / agent 9 / admin 4) + contextual CTAs + mobile drawer |
| 15 | **Topbar** | Greeting, notification bell + dropdown, profile dropdown |
| 16 | **Page header pattern** | Title + subline + right-side action — repeats on every dashboard page |
| 17 | **Empty state pattern** | Icon circle + heading + helper + CTA — needed 11+ times |

> Do not design individual dashboard pages before this. Every one of them sits inside this frame.

### ▶ PHASE 4 — Buyer core

| # | Screen | Depends on |
|---|---|---|
| 18 | **Buyer overview** | 14–17 |
| 19 | **Saved** | 8, 17 |
| 20 | **Booking modal** | 6 — date list + slot grid, four slot states |
| 21 | **Appointments** | 17 — three groups, conditional action buttons |
| 22 | **History** | 21 — plus the rating modal |

> After Phase 4 the buyer side is functionally complete end to end.

### ▶ PHASE 5 — Communication

| # | Screen | Note |
|---|---|---|
| 23 | **Messages** | The densest screen. Two panes, grouping, seen/typing, mobile collapse |
| 24 | **Notification dropdown** | Already framed in 15; design the item rows and empty state |

### ▶ PHASE 6 — Agent onboarding

| # | Screen | Note |
|---|---|---|
| 25 | **Verify wizard** | Type chooser, both step paths, side checklist, camera states, AI overlay |
| 26 | **Result cards** | Pending · Approved · Rejected with live countdown |
| 27 | **Profile & Settings** | All 7 sections — serves buyers and agents both |

### ▶ PHASE 7 — Agent workspace

| # | Screen | Depends on |
|---|---|---|
| 28 | **Agent overview** | 14–17 — 4 stat tiles + 3 panels |
| 29 | **My Listings** | 8 — status-conditional actions |
| 30 | **New Listing wizard** | 3 — sticky rail stepper + 3 steps + AI button |
| 31 | **Edit Listing** | 30 — adds photo grid management |
| 32 | **Inquiries** | 17 — segmented filter, read/unread card states |
| 33 | **Calendar** | Month grid + day panel + 4 blocking modes |
| 34 | **Reviews** | Received reviews + average |

### ▶ PHASE 8 — Admin

| # | Screen |
|---|---|
| 35 | **Users** — search, filter, stat tiles, create-admin modal |
| 36 | **Agents** — application card with documents + AI panel, reject modal |
| 37 | **Listings** — table + "Why featured?" breakdown modal |
| 38 | **Reviews** — show/hide rows |

### ▶ PHASE 9 — AI surfaces *(last — they overlay everything else)*

| # | Screen |
|---|---|
| 39 | **Floating AI bubble** — buyer and agent variants |
| 40 | **Buyer AI chat** — suggestions, message thread, embedded property cards |
| 41 | **Agent AI chat** — plus image attach and the listing-proposal card |
| 42 | **AI full page** — `/dashboard/ai` |

---

## 11.8 IF YOU ONLY HAVE TIME FOR SIX SCREENS

The minimum set that demonstrates the whole product:

```
1. LANDING PAGE      →  the pitch and the search
2. PROPERTY DETAIL   →  the conversion moment
3. AUTH MODAL        →  the gate
4. BUYER DASHBOARD   →  proves the account layer
5. AGENT LISTINGS    →  proves the supply side
6. VERIFY WIZARD     →  the differentiator nobody else has
```

---

## 11.9 SHARED PIECES — DESIGN ONCE, REUSE

Avoid redesigning these per screen:

| Component | Appears on |
|---|---|
| **Property Card** | Landing · Browse · Saved · Buyer overview · Agent overview · AI chat results |
| **Dashboard shell** | All 20 dashboard + admin screens |
| **Property Detail view** | Public route **and** in-dashboard route — one component, two wrappers |
| **Agent Profile view** | Public route **and** in-dashboard route |
| **Messages** | Identical for buyers and agents |
| **Profile & Settings** | Identical for buyers and agents |
| **Appointments** | Same screen, role-conditional buttons |
| **Empty state** | 11+ screens |
| **Status pill** | Appointments · Listings · Admin tables |

**Rule of thumb:** if a screen exists at both `/x` and `/dashboard/x`, it is one design with two wrappers — not two designs.
