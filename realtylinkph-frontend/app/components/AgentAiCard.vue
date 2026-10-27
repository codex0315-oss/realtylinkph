<script setup lang="ts">
import type { AgentAiCard, AgentAiListing } from '~/composables/useAgentAi'

/**
 * What the agent assistant answered with, under its reply: their listings,
 * viewings, inquiries or reviews; a price check or listing check; a task to
 * approve; or a reply draft to send themselves.
 *
 * Nothing here acts without a tap. Tasks call the same endpoints as the
 * pages do; drafts only fill in a composer (chat, review reply, email).
 */
const props = defineProps<{ card: AgentAiCard }>()
const emit = defineEmits<{
  ask: [prompt: string]                     // send a follow-up to the assistant
  patch: [change: Record<string, unknown>]  // remember a card's new state
  navigate: []                              // leaving for another page
}>()

const toast = useToast()
const { openChat, openInquiries } = useDashPanel()
const { confirm: confirmAppointment, error: apptError } = useAppointment()
const { blockDate } = useAvailability()
const { publishProperty, unpublishProperty, markSold, error: propError } = useProperty()

function go(href: string) {
  emit('navigate')
  if (href === '/dashboard?panel=inquiries') { openInquiries(); return }
  navigateTo(href)
}

/* ── Formatting ── */
function peso(n: number): string {
  if (n >= 1_000_000) return `₱${+(n / 1_000_000).toFixed(2)}M`
  if (n >= 10_000)    return `₱${Math.round(n / 1_000).toLocaleString('en-PH')}K`
  return `₱${Math.round(n).toLocaleString('en-PH')}`
}
/** Agent titles are often ALL CAPS; soften them like the dashboard does. */
function calm(t: string): string {
  const letters = t.replace(/[^A-Za-z]/g, '')
  return letters.length > 3 && letters === letters.toUpperCase()
    ? t.toLowerCase().replace(/\b([a-z])/g, (_, c: string) => c.toUpperCase())
    : t
}
const STATUS: Record<AgentAiListing['status'], { label: string; cls: string }> = {
  published: { label: 'Live', cls: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' },
  draft:     { label: 'Draft', cls: 'bg-brand-navy/[0.07] text-brand-navy/70 dark:bg-white/10 dark:text-white/70' },
  sold:      { label: 'Sold', cls: 'bg-brand-gold/20 text-brand-gold-deep dark:text-brand-gold' },
}
const day   = (iso: string) => new Date(iso).toLocaleDateString('en-PH', { weekday: 'short' })
const date  = (iso: string) => new Date(iso).getDate()
const month = (iso: string) => new Date(iso).toLocaleDateString('en-PH', { month: 'short' })
const time  = (iso: string) => new Date(iso).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })
function ago(iso: string): string {
  const d = Math.floor((Date.now() - +new Date(iso)) / 86_400_000)
  return d <= 0 ? 'Today' : d === 1 ? 'Yesterday' : d < 30 ? `${d} days ago` : new Date(iso).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })
}

/* ── Viewings: confirm from the list ── */
const confirming = ref<number | null>(null)
async function confirmViewing(id: number) {
  if (props.card.type !== 'viewings' || confirming.value) return
  confirming.value = id
  const ok = await confirmAppointment(id)
  confirming.value = null
  if (!ok) { toast.error(apptError.value || 'Couldn’t confirm that viewing. Please try again.'); return }
  emit('patch', { items: props.card.items.map(v => (v.id === id ? { ...v, status: 'confirmed', confirmed: true } : v)) })
  toast.success('Viewing confirmed. The buyer has been notified.')
}

/* ── Tasks: run only on Approve ── */
async function approve() {
  const c = props.card
  if (c.type !== 'task' || c.state !== 'ready') return
  emit('patch', { state: 'working', error: undefined })
  const a = c.args
  let ok = false
  switch (c.task) {
    case 'confirm_viewing': ok = await confirmAppointment(Number(a.appointment_id)); break
    case 'block_day':       ok = await blockDate(String(a.date), 'Blocked with RealtyLink AI'); break
    case 'block_hours':     ok = await blockDate(String(a.date), 'Blocked with RealtyLink AI', String(a.start), String(a.end)); break
    case 'publish':         ok = !!(await publishProperty(Number(a.property_id))); break
    case 'unpublish':       ok = !!(await unpublishProperty(Number(a.property_id))); break
    case 'mark_sold':       ok = !!(await markSold(Number(a.property_id))); break
  }
  const why = c.task === 'confirm_viewing' ? apptError.value : propError.value
  emit('patch', ok ? { state: 'done' } : { state: 'failed', error: why || 'That didn’t go through. Please try again.' })
}
const DONE: Record<string, string> = {
  confirm_viewing: 'Viewing confirmed. The buyer has been notified.',
  block_day: 'Day blocked. Buyers can’t book it now.',
  block_hours: 'Hours blocked. Buyers can’t book them now.',
  publish: 'Published. It’s live on Browse.',
  unpublish: 'Unpublished. It’s back in your drafts.',
  mark_sold: 'Marked as sold. 🎉',
}
const AFTER: Record<string, { label: string; href: string }> = {
  confirm_viewing: { label: 'Appointments', href: '/dashboard/appointments' },
  block_day: { label: 'Calendar', href: '/dashboard/calendar' },
  block_hours: { label: 'Calendar', href: '/dashboard/calendar' },
  publish: { label: 'My Listings', href: '/dashboard/listings' },
  unpublish: { label: 'My Listings', href: '/dashboard/listings' },
  mark_sold: { label: 'History', href: '/dashboard/history' },
}

/* ── Drafts: edit, copy, or put into the right composer ── */
const draftText = ref(props.card.type === 'draft' ? props.card.text : '')
watch(() => (props.card.type === 'draft' ? props.card.text : ''), t => { draftText.value = t })
function saveDraft() {
  if (props.card.type === 'draft' && draftText.value !== props.card.text) emit('patch', { text: draftText.value })
}
async function copyDraft() {
  try { await navigator.clipboard.writeText(draftText.value); toast.success('Copied.') } catch { toast.error('Couldn’t copy. Select the text and copy it instead.') }
}
const reviewDraft = useState<{ id: number; text: string } | null>('review-reply-draft', () => null)
function useDraft() {
  const c = props.card
  if (c.type !== 'draft') return
  saveDraft()
  emit('patch', { used: true })
  if (c.kind === 'chat') { emit('navigate'); openChat(c.target_id, draftText.value); return }
  if (c.kind === 'review') { reviewDraft.value = { id: c.target_id, text: draftText.value }; go('/dashboard/reviews'); return }
  const subject = encodeURIComponent(c.subject ?? 'Your inquiry')
  window.location.href = `mailto:${c.email}?subject=${subject}&body=${encodeURIComponent(draftText.value)}`
}
const USE_LABEL = { chat: 'Use in chat', review: 'Use as my reply', inquiry: 'Open in email' } as const

/* ── Price check: where the numbers sit on the min–max line ── */
const pricePos = computed(() => {
  const c = props.card
  if (c.type !== 'price_check') return null
  const lo = Math.min(c.min, c.low, c.listing?.price ?? Infinity)
  const hi = Math.max(c.max, c.high, c.listing?.price ?? 0)
  const at = (n: number) => (hi > lo ? ((n - lo) / (hi - lo)) * 100 : 50)
  return { low: at(c.low), high: at(c.high), own: c.listing ? at(c.listing.price) : null }
})
const VERDICT = {
  above:   { label: 'Above market', cls: 'bg-amber-500/15 text-amber-700 dark:text-amber-300' },
  below:   { label: 'Below market', cls: 'bg-sky-500/15 text-sky-700 dark:text-sky-300' },
  in_line: { label: 'In line', cls: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' },
} as const

const box  = 'mt-2 rounded-2xl bg-white ring-1 ring-brand-navy/[0.07] dark:bg-white/[0.06] dark:ring-white/10'
const pill = 'rounded-full px-3 py-1.5 text-xs font-semibold transition'
const gold = `${pill} bg-brand-gold text-brand-navy hover:bg-brand-gold-light disabled:opacity-50`
const soft = `${pill} text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold hover:bg-brand-gold/10 dark:text-white dark:ring-white/20 dark:hover:ring-brand-gold`
</script>

<template>
  <!-- Listings -->
  <div v-if="card.type === 'listings'" :class="box" class="overflow-hidden">
    <ul class="divide-y divide-brand-navy/[0.06] dark:divide-white/[0.07]">
      <li v-for="l in card.items" :key="l.id" class="flex items-center gap-3 p-2.5">
        <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left group" @click="go(`/dashboard/listings/${l.id}/edit`)">
          <span class="h-12 w-12 flex-none overflow-hidden rounded-xl bg-brand-navy/[0.06] dark:bg-white/10">
            <img v-if="l.thumb" :src="l.thumb" alt="" class="h-full w-full object-cover" loading="lazy" />
          </span>
          <span class="min-w-0 flex-1">
            <span class="flex items-center gap-1.5">
              <span class="truncate text-[0.8125rem] font-semibold text-brand-navy dark:text-white group-hover:text-brand-gold-deep dark:group-hover:text-brand-gold transition">{{ calm(l.title) }}</span>
              <span class="flex-none rounded-full px-1.5 py-0.5 text-[0.5625rem] font-bold uppercase tracking-wide" :class="STATUS[l.status].cls">{{ STATUS[l.status].label }}</span>
            </span>
            <span class="mt-0.5 flex flex-wrap gap-x-2 text-[0.6875rem] text-brand-navy/55 dark:text-white/50">
              <span class="font-semibold text-brand-navy/80 dark:text-white/80">{{ peso(l.price) }}{{ l.offer_type === 'rent' ? '/mo' : '' }}</span>
              <span>{{ l.views }} {{ l.views === 1 ? 'view' : 'views' }}</span>
              <span v-if="l.inquiries !== null">{{ l.inquiries }} {{ l.inquiries === 1 ? 'inquiry' : 'inquiries' }}</span>
            </span>
          </span>
        </button>
        <button v-if="card.pick" type="button" :class="soft" class="flex-none !px-2.5 !py-1 !text-[0.6875rem]" @click="emit('ask', `${card.pick.task === 'price_check' ? 'Price check' : card.pick.task === 'mark_sold' ? 'Mark as sold' : card.pick.task === 'unpublish' ? 'Unpublish' : 'Publish'} “${l.title}”`)">
          {{ card.pick.label }}
        </button>
      </li>
    </ul>
    <button type="button" class="w-full border-t border-brand-navy/[0.06] dark:border-white/[0.07] px-3 py-2 text-xs font-semibold text-brand-navy/70 hover:text-brand-gold-deep dark:text-white/65 dark:hover:text-brand-gold transition text-left" @click="go(card.link)">
      {{ card.total > card.items.length ? `See all ${card.total} in My Listings` : 'Open My Listings' }} →
    </button>
  </div>

  <!-- Viewings -->
  <div v-else-if="card.type === 'viewings'" :class="box" class="overflow-hidden">
    <ul class="divide-y divide-brand-navy/[0.06] dark:divide-white/[0.07]">
      <li v-for="v in card.items" :key="v.id" class="flex items-center gap-3 p-2.5">
        <span class="flex h-12 w-12 flex-none flex-col items-center justify-center rounded-xl bg-brand-navy text-white dark:bg-brand-gold dark:text-[#0B2A5C]">
          <span class="text-[0.5625rem] font-bold uppercase leading-none opacity-80">{{ month(v.at) }}</span>
          <span class="text-base font-bold leading-tight">{{ date(v.at) }}</span>
        </span>
        <span class="min-w-0 flex-1">
          <span class="block truncate text-[0.8125rem] font-semibold text-brand-navy dark:text-white">{{ v.buyer }}</span>
          <span class="block truncate text-[0.6875rem] text-brand-navy/55 dark:text-white/50">{{ day(v.at) }} · {{ time(v.at) }} · {{ v.listing }}</span>
        </span>
        <button
          v-if="v.status === 'pending'"
          type="button"
          :class="gold"
          class="flex-none !px-3 !py-1.5"
          :disabled="confirming !== null"
          @click="confirmViewing(v.id)"
        >
          {{ confirming === v.id ? 'Confirming…' : 'Confirm' }}
        </button>
        <span v-else class="flex-none rounded-full bg-emerald-500/15 px-2 py-1 text-[0.625rem] font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">{{ v.confirmed ? 'Confirmed ✓' : 'Confirmed' }}</span>
      </li>
    </ul>
    <button type="button" class="w-full border-t border-brand-navy/[0.06] dark:border-white/[0.07] px-3 py-2 text-xs font-semibold text-brand-navy/70 hover:text-brand-gold-deep dark:text-white/65 dark:hover:text-brand-gold transition text-left" @click="go(card.link)">
      {{ card.total > card.items.length ? `See all ${card.total} in Appointments` : 'Open Appointments' }} →
    </button>
  </div>

  <!-- Inquiries -->
  <div v-else-if="card.type === 'inquiries'" :class="box" class="overflow-hidden">
    <ul class="divide-y divide-brand-navy/[0.06] dark:divide-white/[0.07]">
      <li v-for="q in card.items" :key="q.id" class="p-3">
        <div class="flex items-center gap-2">
          <span v-if="!q.is_read" class="h-2 w-2 flex-none rounded-full bg-brand-gold" aria-label="Unread" />
          <span class="truncate text-[0.8125rem] font-semibold text-brand-navy dark:text-white">{{ q.name }}</span>
          <span class="ml-auto flex-none text-[0.625rem] text-brand-navy/45 dark:text-white/40">{{ ago(q.created_at) }}</span>
        </div>
        <p v-if="q.listing" class="truncate text-[0.6875rem] text-brand-navy/55 dark:text-white/50">{{ calm(q.listing) }}</p>
        <p class="mt-1 line-clamp-2 text-xs leading-relaxed text-brand-navy/75 dark:text-white/70">{{ q.message }}</p>
        <button type="button" :class="soft" class="mt-2 !px-2.5 !py-1 !text-[0.6875rem]" @click="emit('ask', `Draft a reply to ${q.name}'s inquiry`)">Draft a reply</button>
      </li>
    </ul>
    <button type="button" class="w-full border-t border-brand-navy/[0.06] dark:border-white/[0.07] px-3 py-2 text-xs font-semibold text-brand-navy/70 hover:text-brand-gold-deep dark:text-white/65 dark:hover:text-brand-gold transition text-left" @click="go(card.link)">
      Open Inquiries →
    </button>
  </div>

  <!-- Reviews -->
  <div v-else-if="card.type === 'reviews'" :class="box" class="overflow-hidden">
    <ul class="divide-y divide-brand-navy/[0.06] dark:divide-white/[0.07]">
      <li v-for="r in card.items" :key="r.id" class="p-3">
        <div class="flex items-center gap-2">
          <span class="text-[0.8125rem] tracking-tight text-brand-gold" :aria-label="`${r.rating} out of 5`">{{ '★'.repeat(r.rating) }}<span class="text-brand-navy/15 dark:text-white/15">{{ '★'.repeat(5 - r.rating) }}</span></span>
          <span class="truncate text-[0.8125rem] font-semibold text-brand-navy dark:text-white">{{ r.buyer }}</span>
          <span class="ml-auto flex-none text-[0.625rem] text-brand-navy/45 dark:text-white/40">{{ ago(r.created_at) }}</span>
        </div>
        <p v-if="r.text" class="mt-1 line-clamp-2 text-xs leading-relaxed text-brand-navy/75 dark:text-white/70">“{{ r.text }}”</p>
        <div class="mt-2 flex items-center gap-2">
          <button v-if="!r.replied" type="button" :class="soft" class="!px-2.5 !py-1 !text-[0.6875rem]" @click="emit('ask', `Draft a reply to ${r.buyer}'s review`)">Draft a reply</button>
          <span v-else class="text-[0.6875rem] font-semibold text-emerald-600 dark:text-emerald-400">Replied ✓</span>
        </div>
      </li>
    </ul>
    <button type="button" class="w-full border-t border-brand-navy/[0.06] dark:border-white/[0.07] px-3 py-2 text-xs font-semibold text-brand-navy/70 hover:text-brand-gold-deep dark:text-white/65 dark:hover:text-brand-gold transition text-left" @click="go(card.link)">
      Open Reviews →
    </button>
  </div>

  <!-- Price check -->
  <div v-else-if="card.type === 'price_check'" :class="box" class="p-3.5">
    <div class="flex items-start justify-between gap-2">
      <div class="min-w-0">
        <p class="text-[0.625rem] font-bold uppercase tracking-[0.14em] text-brand-navy/45 dark:text-white/40">Price check</p>
        <p class="truncate text-[0.8125rem] font-semibold text-brand-navy dark:text-white">
          {{ card.listing ? calm(card.listing.title) : `${card.size ? `${card.size} sqm ` : ''}${card.kind}` }}
        </p>
      </div>
      <span v-if="card.verdict" class="flex-none rounded-full px-2 py-1 text-[0.625rem] font-bold uppercase tracking-wide" :class="VERDICT[card.verdict].cls">
        {{ VERDICT[card.verdict].label }}{{ card.diff_pct ? ` ${card.diff_pct > 0 ? '+' : ''}${card.diff_pct}%` : '' }}
      </span>
    </div>

    <p class="mt-3 text-[0.6875rem] text-brand-navy/55 dark:text-white/50">{{ card.listing ? 'Market range' : 'Suggested price' }}</p>
    <p class="font-heading text-xl font-semibold text-brand-navy dark:text-white">{{ peso(card.low) === peso(card.high) ? `≈ ${peso(card.low)}` : `${peso(card.low)} – ${peso(card.high)}` }}<span class="text-xs font-normal text-brand-navy/50 dark:text-white/45">{{ card.unit }}</span></p>

    <!-- min … [range] … max, with this listing's price marked -->
    <div v-if="pricePos" class="relative mt-3 mb-5 h-2 rounded-full bg-brand-navy/[0.08] dark:bg-white/10">
      <span class="absolute inset-y-0 rounded-full bg-emerald-500/60" :style="{ left: `${pricePos.low}%`, width: `${Math.max(2, pricePos.high - pricePos.low)}%` }" />
      <span v-if="pricePos.own !== null && card.listing" class="absolute -top-1 h-4 w-1 -translate-x-1/2 rounded-full bg-brand-navy dark:bg-brand-gold" :style="{ left: `${pricePos.own}%` }" />
      <span v-if="pricePos.own !== null && card.listing" class="absolute top-4 -translate-x-1/2 whitespace-nowrap text-[0.625rem] font-semibold text-brand-navy dark:text-brand-gold" :style="{ left: `${Math.min(88, Math.max(12, pricePos.own))}%` }">You: {{ peso(card.listing.price) }}</span>
    </div>

    <dl class="grid grid-cols-3 gap-2 text-center">
      <div class="rounded-xl bg-brand-cream/70 dark:bg-white/[0.05] px-2 py-2">
        <dt class="text-[0.5625rem] font-bold uppercase tracking-wide text-brand-navy/45 dark:text-white/40">Compared</dt>
        <dd class="text-[0.8125rem] font-semibold text-brand-navy dark:text-white">{{ card.count }}</dd>
      </div>
      <div class="rounded-xl bg-brand-cream/70 dark:bg-white/[0.05] px-2 py-2">
        <dt class="text-[0.5625rem] font-bold uppercase tracking-wide text-brand-navy/45 dark:text-white/40">Median</dt>
        <dd class="text-[0.8125rem] font-semibold text-brand-navy dark:text-white">{{ peso(card.median) }}</dd>
      </div>
      <div class="rounded-xl bg-brand-cream/70 dark:bg-white/[0.05] px-2 py-2">
        <dt class="text-[0.5625rem] font-bold uppercase tracking-wide text-brand-navy/45 dark:text-white/40">Per sqm</dt>
        <dd class="text-[0.8125rem] font-semibold text-brand-navy dark:text-white">{{ card.median_sqm ? peso(card.median_sqm) : '–' }}</dd>
      </div>
    </dl>
    <p class="mt-2 text-[0.625rem] text-brand-navy/45 dark:text-white/40">Published {{ card.kind }}{{ card.area_name ? ` in ${card.area_name}` : '' }} on RealtyLink PH.</p>
    <button v-if="card.listing" type="button" :class="soft" class="mt-2.5" @click="go(`/dashboard/listings/${card.listing.id}/edit`)">Edit price</button>
  </div>

  <!-- Listing check -->
  <div v-else-if="card.type === 'listing_check'" :class="box" class="overflow-hidden">
    <div v-for="row in card.items" :key="row.listing.id" class="border-b last:border-b-0 border-brand-navy/[0.06] dark:border-white/[0.07] p-3">
      <button type="button" class="flex w-full items-center gap-2.5 text-left group" @click="go(`/dashboard/listings/${row.listing.id}/edit`)">
        <span class="h-9 w-9 flex-none overflow-hidden rounded-lg bg-brand-navy/[0.06] dark:bg-white/10">
          <img v-if="row.listing.thumb" :src="row.listing.thumb" alt="" class="h-full w-full object-cover" loading="lazy" />
        </span>
        <span class="min-w-0 flex-1 truncate text-[0.8125rem] font-semibold text-brand-navy dark:text-white group-hover:text-brand-gold-deep dark:group-hover:text-brand-gold transition">{{ calm(row.listing.title) }}</span>
        <span class="flex-none rounded-full px-1.5 py-0.5 text-[0.5625rem] font-bold uppercase tracking-wide" :class="STATUS[row.listing.status].cls">{{ STATUS[row.listing.status].label }}</span>
      </button>
      <ul class="mt-2 space-y-2">
        <li v-for="issue in row.issues" :key="issue.code" class="flex items-start gap-2.5 rounded-xl bg-brand-cream/70 dark:bg-white/[0.04] p-2.5">
          <span class="mt-0.5 h-5 w-5 flex-none rounded-full flex items-center justify-center" :class="issue.code === 'ready' ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400' : 'bg-amber-500/15 text-amber-600 dark:text-amber-400'">
            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="issue.code === 'ready' ? 'M4.5 12.75l6 6 9-13.5' : 'M12 9v4m0 4h.01'" /></svg>
          </span>
          <span class="min-w-0 flex-1">
            <span class="block text-xs font-semibold text-brand-navy dark:text-white">{{ issue.label }}</span>
            <span class="block text-[0.6875rem] leading-relaxed text-brand-navy/60 dark:text-white/55">{{ issue.tip }}</span>
          </span>
          <button
            type="button"
            :class="issue.fix.prompt ? gold : soft"
            class="flex-none !px-2.5 !py-1 !text-[0.6875rem]"
            @click="issue.fix.prompt ? emit('ask', issue.fix.prompt) : go(issue.fix.href!)"
          >
            {{ issue.fix.label }}
          </button>
        </li>
      </ul>
    </div>
  </div>

  <!-- Task to approve -->
  <div v-else-if="card.type === 'task'" :class="box" class="p-3.5">
    <p class="text-[0.625rem] font-bold uppercase tracking-[0.14em] text-brand-navy/45 dark:text-white/40">{{ card.title }}</p>
    <dl class="mt-2 space-y-1.5">
      <div v-for="line in card.lines" :key="line.label" class="flex gap-3 text-xs">
        <dt class="w-16 flex-none text-brand-navy/50 dark:text-white/45">{{ line.label }}</dt>
        <dd class="min-w-0 flex-1 font-semibold text-brand-navy dark:text-white break-words">{{ line.value }}</dd>
      </div>
    </dl>
    <p v-if="card.warning && card.state === 'ready'" class="mt-3 flex gap-2 rounded-xl bg-amber-500/10 p-2.5 text-[0.6875rem] leading-relaxed text-amber-800 dark:text-amber-200">
      <svg class="h-4 w-4 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
      {{ card.warning }}
    </p>

    <div v-if="card.state === 'ready' || card.state === 'working'" class="mt-3 flex items-center justify-end gap-2">
      <button type="button" :class="soft" :disabled="card.state === 'working'" @click="emit('patch', { state: 'cancelled' })">Not now</button>
      <button
        type="button"
        class="rounded-full px-4 py-2 text-xs font-bold transition disabled:opacity-60"
        :class="card.tone === 'danger' ? 'bg-red-600 text-white hover:bg-red-700' : 'bg-brand-gold text-brand-navy shadow-[0_4px_14px_rgba(212,165,74,0.4)] hover:bg-brand-gold-light'"
        :disabled="card.state === 'working'"
        @click="approve"
      >
        {{ card.state === 'working' ? 'Working…' : card.cta }}
      </button>
    </div>
    <div v-else-if="card.state === 'done'" class="mt-3 flex items-center gap-2">
      <span class="h-6 w-6 flex-none rounded-full flex items-center justify-center bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
      </span>
      <p class="min-w-0 flex-1 text-xs font-semibold text-brand-navy dark:text-white">{{ DONE[card.task] }}</p>
      <button type="button" class="flex-none text-xs font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline" @click="go(AFTER[card.task]!.href)">{{ AFTER[card.task]!.label }} →</button>
    </div>
    <p v-else-if="card.state === 'cancelled'" class="mt-3 text-xs text-brand-navy/50 dark:text-white/45">Okay, nothing was changed.</p>
    <div v-else class="mt-3 flex items-center gap-2">
      <p class="min-w-0 flex-1 text-xs font-medium text-red-600 dark:text-red-400" role="alert">{{ card.error }}</p>
      <button type="button" :class="soft" @click="emit('patch', { state: 'ready', error: undefined })">Try again</button>
    </div>
  </div>

  <!-- Reply draft -->
  <div v-else-if="card.type === 'draft'" :class="box" class="p-3.5">
    <div class="flex items-center gap-2">
      <p class="flex-none whitespace-nowrap text-[0.625rem] font-bold uppercase tracking-[0.14em] text-brand-navy/45 dark:text-white/40">Draft reply</p>
      <span class="min-w-0 truncate text-[0.6875rem] text-brand-navy/55 dark:text-white/50">to {{ card.to }}{{ card.about ? ` · ${calm(card.about)}` : '' }}</span>
    </div>
    <p v-if="card.quote" class="mt-2 border-l-2 border-brand-gold/70 pl-2.5 text-[0.6875rem] italic leading-relaxed text-brand-navy/60 dark:text-white/55">
      <span v-if="card.rating" class="not-italic text-brand-gold">{{ '★'.repeat(card.rating) }} </span>{{ card.quote }}
    </p>
    <textarea
      v-model="draftText"
      rows="5"
      aria-label="Draft reply"
      class="mt-2.5 w-full resize-y rounded-xl border-0 bg-brand-navy/[0.04] dark:bg-white/[0.06] px-3 py-2.5 text-[0.8125rem] leading-relaxed text-brand-navy dark:text-white focus:ring-2 focus:ring-brand-gold"
      @blur="saveDraft"
    />
    <div class="mt-2 flex flex-wrap items-center justify-end gap-2">
      <span v-if="card.used" class="mr-auto text-[0.6875rem] font-semibold text-emerald-600 dark:text-emerald-400">Sent to {{ card.kind === 'inquiry' ? 'your email app' : card.kind === 'chat' ? 'the chat box' : 'the reply box' }} ✓</span>
      <button type="button" :class="soft" @click="copyDraft">Copy</button>
      <button type="button" class="rounded-full bg-brand-gold px-4 py-2 text-xs font-bold text-brand-navy shadow-[0_4px_14px_rgba(212,165,74,0.4)] hover:bg-brand-gold-light disabled:opacity-50 transition" :disabled="!draftText.trim()" @click="useDraft">
        {{ USE_LABEL[card.kind] }}
      </button>
    </div>
    <p class="mt-1.5 text-[0.625rem] text-brand-navy/40 dark:text-white/35">Nothing is sent until you send it yourself.</p>
  </div>

  <!-- A page to finish something on -->
  <button v-else-if="card.type === 'action_link'" type="button" :class="box" class="group flex w-full items-center justify-between gap-3 p-3 text-left" @click="go(card.href)">
    <span class="text-[0.8125rem] font-semibold text-brand-navy dark:text-white group-hover:text-brand-gold-deep dark:group-hover:text-brand-gold transition">{{ card.label }}</span>
    <span class="h-8 w-8 flex-none rounded-full flex items-center justify-center bg-brand-gold text-brand-navy group-hover:bg-brand-gold-light transition">
      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
    </span>
  </button>
</template>
