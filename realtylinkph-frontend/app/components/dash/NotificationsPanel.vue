<script setup lang="ts">
import type { Notification } from '~/types'

/**
 * Notifications, as a docked side panel (see layouts/dashboard).
 *
 *  - All / Unread tabs, plus category chips (Viewings, Inquiries, Listings,
 *    Reviews, Account) — only the categories you actually have are shown;
 *  - grouped by day: Today / Yesterday / This week / Earlier;
 *  - each item has a coloured icon for its kind and, where it helps, one
 *    action button that goes straight to the thing ("View viewing", "Edit
 *    listing"…);
 *  - hover (or focus, or always on touch) reveals mark read/unread and delete;
 *  - "Clear read" removes everything already read, after a confirm.
 *
 * Chat messages never appear here — they're in the Messages panel.
 */
const emit = defineEmits<{ close: [] }>()

const authStore = useAuthStore()
const notifStore = useNotificationStore()
const { markRead, markUnread, markAllRead, remove, clearRead } = useNotification()
const confirm = useConfirm()
const toast = useToast()

type Cat = 'viewings' | 'inquiries' | 'listings' | 'reviews' | 'account'
interface Meta { cat: Cat; icon: string; action?: (n: Notification) => { label: string; to: string } | null }

const ICON = {
  calendar: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',
  check:    'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
  x:        'M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
  bell:     'M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0',
  inbox:    'M2.25 13.5h3.86a2.25 2.25 0 012.012 1.244l.256.512a2.25 2.25 0 002.013 1.244h3.218a2.25 2.25 0 002.013-1.244l.256-.512a2.25 2.25 0 012.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 00-2.15-1.588H6.911a2.25 2.25 0 00-2.15 1.588L2.35 13.177a2.25 2.25 0 00-.1.661z',
  home:     'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75',
  star:     'M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z',
  shield:   'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
  photo:    'M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z',
  clock:    'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z',
}

const viewing = (label = 'View viewing') => () => ({ label, to: '/dashboard/appointments' })
const META: Record<string, Meta> = {
  new_appointment:            { cat: 'viewings',  icon: ICON.calendar, action: viewing('Review request') },
  appointment_confirmed:      { cat: 'viewings',  icon: ICON.check,    action: viewing() },
  appointment_cancelled:      { cat: 'viewings',  icon: ICON.x,        action: viewing('See details') },
  appointment_completed:      { cat: 'viewings',  icon: ICON.check,    action: () => (authStore.isBuyer ? { label: 'Rate your agent', to: '/dashboard/history' } : { label: 'View history', to: '/dashboard/history' }) },
  appointment_reminder:       { cat: 'viewings',  icon: ICON.clock,    action: viewing() },
  late_cancellation_recorded: { cat: 'viewings',  icon: ICON.clock,    action: () => ({ label: 'View history', to: '/dashboard/history' }) },
  new_inquiry:                { cat: 'inquiries', icon: ICON.inbox,    action: () => ({ label: 'Open inquiry', to: '/dashboard?panel=inquiries' }) },
  listing_unpublished:        { cat: 'listings',  icon: ICON.home,     action: n => (n.payload?.property_id ? { label: 'Fix listing', to: `/dashboard/listings/${n.payload.property_id}/edit` } : { label: 'My listings', to: '/dashboard/listings' }) },
  new_review:                 { cat: 'reviews',   icon: ICON.star,     action: () => ({ label: 'Reply to review', to: '/dashboard/reviews' }) },
  review_replied:             { cat: 'reviews',   icon: ICON.star,     action: () => ({ label: 'See reply', to: '/dashboard/reviews' }) },
  review_hidden:              { cat: 'reviews',   icon: ICON.star,     action: () => ({ label: 'See review', to: '/dashboard/reviews' }) },
  agent_application_submitted:{ cat: 'account',   icon: ICON.shield,   action: () => ({ label: 'View application', to: '/dashboard/profile' }) },
  agent_application_approved: { cat: 'account',   icon: ICON.shield,   action: () => ({ label: 'Create a listing', to: '/dashboard/listings/new' }) },
  agent_application_rejected: { cat: 'account',   icon: ICON.shield,   action: () => ({ label: 'See reason', to: '/dashboard/profile' }) },
  new_agent_application:      { cat: 'account',   icon: ICON.shield,   action: () => ({ label: 'Review', to: '/admin/agents' }) },
  cover_removed:              { cat: 'account',   icon: ICON.photo,    action: () => ({ label: 'Update cover', to: '/dashboard/profile' }) },
}
const FALLBACK: Meta = { cat: 'account', icon: ICON.bell }
const meta = (n: Notification) => META[n.type] ?? FALLBACK

const CATS: Array<{ key: Cat; label: string; tone: string }> = [
  { key: 'viewings',  label: 'Viewings',  tone: 'bg-sky-500/10 text-sky-600 dark:text-sky-300' },
  { key: 'inquiries', label: 'Inquiries', tone: 'bg-violet-500/10 text-violet-600 dark:text-violet-300' },
  { key: 'listings',  label: 'Listings',  tone: 'bg-amber-500/15 text-amber-700 dark:text-amber-300' },
  { key: 'reviews',   label: 'Reviews',   tone: 'bg-brand-gold/20 text-brand-gold-deep dark:text-brand-gold' },
  { key: 'account',   label: 'Account',   tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-300' },
]
const toneOf = (n: Notification) => {
  if (n.type.includes('cancel') || n.type.endsWith('_rejected') || n.type === 'cover_removed' || n.type === 'listing_unpublished')
    return 'bg-red-500/10 text-red-600 dark:text-red-300'
  return CATS.find(c => c.key === meta(n).cat)!.tone
}

/* ── Filters ─────────────────────────────────────────────────────────── */
const tab = ref<'all' | 'unread'>('all')
const cat = ref<Cat | ''>('')

const all = computed(() => notifStore.bellNotifications)
const presentCats = computed(() => CATS.filter(c => all.value.some(n => meta(n).cat === c.key)))
watch(presentCats, (list) => { if (cat.value && !list.some(c => c.key === cat.value)) cat.value = '' })

const shown = computed(() =>
  all.value.filter(n => (tab.value === 'all' || !n.read_at) && (!cat.value || meta(n).cat === cat.value)),
)

/* ── Day groups ──────────────────────────────────────────────────────── */
const groups = computed(() => {
  const startOfToday = new Date(); startOfToday.setHours(0, 0, 0, 0)
  const t0 = startOfToday.getTime()
  const DAY = 86_400_000
  const buckets: Record<string, Notification[]> = { Today: [], Yesterday: [], 'This week': [], Earlier: [] }
  for (const n of shown.value) {
    const t = new Date(n.created_at).getTime()
    const key = t >= t0 ? 'Today' : t >= t0 - DAY ? 'Yesterday' : t >= t0 - 6 * DAY ? 'This week' : 'Earlier'
    buckets[key]!.push(n)
  }
  return Object.entries(buckets).filter(([, list]) => list.length).map(([label, list]) => ({ label, list }))
})

function text(n: Notification): string {
  const p = n.payload ?? {}
  if (p.message) return p.message as string
  // Older inquiries were saved without a message line — build one.
  if (n.type === 'new_inquiry' && p.sender_name) {
    return `${p.sender_name} sent an inquiry${p.property_title ? ` about “${p.property_title}”` : ''}`
  }
  const words = n.type.replace(/[._]/g, ' ')
  return words.charAt(0).toUpperCase() + words.slice(1)
}
function when(iso: string): string {
  const d = new Date(iso)
  const mins = Math.floor((Date.now() - d.getTime()) / 60_000)
  if (mins < 1) return 'Just now'
  if (mins < 60) return `${mins}m ago`
  if (mins < 60 * 24) return `${Math.floor(mins / 60)}h ago`
  return d.toLocaleString('en-PH', { weekday: 'short', hour: 'numeric', minute: '2-digit' })
}

/* ── Actions ─────────────────────────────────────────────────────────── */
async function open(n: Notification, to?: string) {
  if (!n.read_at) markRead(n.id)
  const target = to ?? meta(n).action?.(n)?.to
  if (target) await navigateTo(target)
}
function toggleRead(n: Notification) {
  if (n.read_at) markUnread(n.id)
  else markRead(n.id)
}
async function del(n: Notification) {
  if (!(await remove(n.id))) toast.error('Could not delete that notification.')
}
const readCount = computed(() => all.value.filter(n => n.read_at).length)
async function clear() {
  const ok = await confirm({
    title: 'Clear read notifications?',
    message: `This removes ${readCount.value} read ${readCount.value === 1 ? 'notification' : 'notifications'}. Unread ones stay.`,
    confirmLabel: 'Clear read',
    tone: 'danger',
  })
  if (!ok) return
  const n = await clearRead()
  if (n) toast.success(`Cleared ${n} ${n === 1 ? 'notification' : 'notifications'}`)
}

const iconBtn =
  'h-7 w-7 rounded-full flex items-center justify-center text-brand-navy/50 hover:text-brand-navy hover:bg-brand-navy/[0.06] ' +
  'dark:text-white/50 dark:hover:text-white dark:hover:bg-white/10 transition'
</script>

<template>
  <div class="flex h-full flex-col">
    <!-- Header -->
    <div class="flex items-center justify-between gap-3 px-5 pt-5 pb-3">
      <h2 class="font-heading text-2xl font-semibold text-brand-navy dark:text-white">Notifications</h2>
      <button type="button" :class="iconBtn" class="!h-9 !w-9" aria-label="Close notifications" @click="emit('close')">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
      </button>
    </div>

    <!-- Tabs + mark all read -->
    <div class="flex items-center justify-between gap-2 px-5 pb-3">
      <div class="flex gap-1" role="tablist">
        <button
          v-for="t in (['all', 'unread'] as const)"
          :key="t"
          type="button"
          role="tab"
          :aria-selected="tab === t"
          class="rounded-full px-3.5 py-1.5 text-sm font-semibold transition-colors"
          :class="tab === t ? 'bg-brand-navy text-white dark:bg-white dark:text-brand-navy' : 'text-brand-navy/65 hover:bg-brand-navy/5 dark:text-white/60 dark:hover:bg-white/10'"
          @click="tab = t"
        >
          {{ t === 'all' ? 'All' : 'Unread' }}
          <span v-if="t === 'unread' && notifStore.bellUnread" class="ml-1 tabular-nums opacity-70">{{ notifStore.bellUnread }}</span>
        </button>
      </div>
      <button
        type="button"
        class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-navy/60 hover:text-brand-navy dark:text-white/55 dark:hover:text-white disabled:opacity-40 disabled:pointer-events-none transition"
        :disabled="!notifStore.bellUnread"
        @click="markAllRead()"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
        Mark all read
      </button>
    </div>

    <!-- Category chips -->
    <div v-if="presentCats.length > 1" class="flex gap-1.5 overflow-x-auto no-scrollbar px-5 pb-3 border-b border-brand-navy/[0.06] dark:border-white/10">
      <button
        type="button"
        class="flex-none rounded-full px-3 py-1 text-xs font-semibold ring-1 transition"
        :class="cat === '' ? 'bg-brand-gold text-brand-navy ring-brand-gold' : 'text-brand-navy/65 ring-brand-navy/10 hover:ring-brand-gold dark:text-white/60 dark:ring-white/15'"
        @click="cat = ''"
      >Everything</button>
      <button
        v-for="c in presentCats"
        :key="c.key"
        type="button"
        class="flex-none rounded-full px-3 py-1 text-xs font-semibold ring-1 transition"
        :class="cat === c.key ? 'bg-brand-gold text-brand-navy ring-brand-gold' : 'text-brand-navy/65 ring-brand-navy/10 hover:ring-brand-gold dark:text-white/60 dark:ring-white/15'"
        @click="cat = c.key"
      >{{ c.label }}</button>
    </div>
    <div v-else class="border-b border-brand-navy/[0.06] dark:border-white/10" />

    <!-- List -->
    <div class="flex-1 overflow-y-auto overscroll-contain">
      <div v-if="!shown.length" class="m-4 rounded-2xl bg-brand-cream dark:bg-white/[0.04] px-6 py-12 text-center">
        <span class="mx-auto mb-4 h-14 w-14 rounded-2xl flex items-center justify-center bg-white dark:bg-white/[0.06] ring-1 ring-brand-navy/[0.06] dark:ring-white/10 text-brand-gold-deep dark:text-brand-gold">
          <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="tab === 'unread' ? ICON.check : ICON.bell" /></svg>
        </span>
        <p class="font-semibold text-brand-navy dark:text-white">{{ tab === 'unread' ? 'You’re all caught up' : 'No notifications yet' }}</p>
        <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/50 leading-relaxed">
          <template v-if="tab === 'unread'">Nothing new since you last looked.</template>
          <template v-else-if="authStore.isAgent">Viewing requests, inquiries, reviews and listing updates will appear here.</template>
          <template v-else>Viewing confirmations, reminders and account updates will appear here.</template>
        </p>
      </div>

      <section v-for="g in groups" :key="g.label" class="pb-1">
        <p class="sticky top-0 z-10 px-5 pt-4 pb-2 text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40 bg-white/90 dark:bg-[#0b1f45]/90 backdrop-blur">
          {{ g.label }}
        </p>
        <TransitionGroup tag="ul" name="notif" class="relative px-2">
          <li
            v-for="n in g.list"
            :key="n.id"
            class="group relative flex gap-3 rounded-2xl px-3 py-3 transition-colors cursor-pointer
                   hover:bg-brand-navy/[0.035] dark:hover:bg-white/[0.04] focus-within:bg-brand-navy/[0.035] dark:focus-within:bg-white/[0.04]"
            :class="!n.read_at ? 'bg-brand-gold/[0.07]' : ''"
            @click="open(n)"
          >
            <span class="relative flex-none h-10 w-10 rounded-xl flex items-center justify-center" :class="toneOf(n)">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="meta(n).icon" /></svg>
              <span v-if="!n.read_at" class="absolute -top-0.5 -right-0.5 h-2.5 w-2.5 rounded-full bg-brand-gold ring-2 ring-white dark:ring-[#0b1f45]" aria-hidden="true" />
            </span>

            <div class="min-w-0 flex-1">
              <p class="text-sm leading-snug" :class="n.read_at ? 'text-brand-navy/70 dark:text-white/65' : 'font-semibold text-brand-navy dark:text-white'">
                <span v-if="!n.read_at" class="sr-only">Unread: </span>{{ text(n) }}
              </p>
              <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1.5">
                <span class="text-[0.6875rem] text-brand-navy/45 dark:text-white/40">{{ when(n.created_at) }}</span>
                <button
                  v-if="meta(n).action?.(n)"
                  type="button"
                  class="inline-flex items-center gap-1 rounded-full bg-white dark:bg-white/[0.07] px-2.5 py-1 text-[0.6875rem] font-semibold text-brand-navy dark:text-white ring-1 ring-brand-navy/10 dark:ring-white/15 hover:ring-brand-gold transition"
                  @click.stop="open(n, meta(n).action!(n)!.to)"
                >
                  {{ meta(n).action!(n)!.label }}
                  <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                </button>
              </div>
            </div>

            <!-- Hover / focus actions (always visible on touch screens) -->
            <div class="flex-none flex flex-col gap-0.5 opacity-0 group-hover:opacity-100 focus-within:opacity-100 [@media(hover:none)]:opacity-100 transition-opacity">
              <button type="button" :class="iconBtn" :aria-label="n.read_at ? 'Mark as unread' : 'Mark as read'" :title="n.read_at ? 'Mark as unread' : 'Mark as read'" @click.stop="toggleRead(n)">
                <svg v-if="n.read_at" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="4.5" /></svg>
                <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
              </button>
              <button type="button" :class="iconBtn" class="hover:!text-red-500" aria-label="Delete notification" title="Delete" @click.stop="del(n)">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
              </button>
            </div>
          </li>
        </TransitionGroup>
      </section>
    </div>

    <!-- Footer -->
    <div v-if="readCount" class="px-5 py-3 border-t border-brand-navy/[0.06] dark:border-white/10 flex justify-between items-center">
      <span class="text-xs text-brand-navy/45 dark:text-white/40">{{ all.length }} total · {{ notifStore.bellUnread }} unread</span>
      <button type="button" class="text-xs font-semibold text-red-500/80 hover:text-red-500 transition" @click="clear">
        Clear read ({{ readCount }})
      </button>
    </div>
  </div>
</template>

<style scoped>
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }

.notif-enter-active, .notif-leave-active { transition: opacity 0.22s ease, transform 0.22s ease; }
.notif-enter-from { opacity: 0; transform: translateY(-6px); }
.notif-leave-to   { opacity: 0; transform: translateX(24px); }
.notif-leave-active { position: absolute; left: 0.5rem; right: 0.5rem; }
@media (prefers-reduced-motion: reduce) {
  .notif-enter-active, .notif-leave-active { transition: none; }
}
</style>
