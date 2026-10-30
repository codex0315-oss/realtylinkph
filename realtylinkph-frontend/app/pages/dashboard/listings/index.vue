<script setup lang="ts">
import type { Property } from '~/types'
import { PROPERTY_TYPES } from '~/types'

definePageMeta({ layout: 'dashboard' })

/*
 * My Listings: the agent's active portfolio (sold ones live under History).
 * Header and tabs follow the other redesigned pages; filtering, search and
 * sorting happen in the browser over the one page the API returns.
 */
const { properties, loading, error, fetchMyListings, deleteProperty, publishProperty, unpublishProperty, markSold } = useProperty()
const confirm = useConfirm()
const toast   = useToast()
const propertyHref = usePropertyHref()

/* Not awaited: the page opens at once (with placeholder cards) instead of
   the previous page lingering while the list loads, e.g. right after Save. */
fetchMyListings()

/* ── Filters ── */
type Tab = 'all' | 'published' | 'draft' | 'attention'
const tab    = ref<Tab>('all')
const search = ref('')
const sort   = ref<'newest' | 'views' | 'price_desc' | 'price_asc'>('newest')

/** Waiting on the agent: taken down by an admin, or a half-finished draft. */
const needsWork = (p: Property) => p.status === 'draft' && (!!p.unpublish_reason || p.is_complete === false)
const counts = computed(() => ({
  all:       properties.value.length,
  published: properties.value.filter(p => p.status === 'published').length,
  draft:     properties.value.filter(p => p.status === 'draft').length,
  attention: properties.value.filter(needsWork).length,
}))
const TABS = computed(() => [
  { key: 'all' as const,       label: 'All' },
  { key: 'published' as const, label: 'Published' },
  { key: 'draft' as const,     label: 'Drafts' },
  ...(counts.value.attention ? [{ key: 'attention' as const, label: 'Needs attention' }] : []),
])

const shown = computed(() => {
  const q = search.value.trim().toLowerCase()
  const list = properties.value.filter(p =>
    (tab.value === 'all' || (tab.value === 'attention' ? needsWork(p) : p.status === tab.value))
    && (!q || `${p.title} ${p.address}`.toLowerCase().includes(q)),
  )
  const by: Record<typeof sort.value, (a: Property, b: Property) => number> = {
    newest:     (a, b) => +new Date(b.created_at) - +new Date(a.created_at),
    views:      (a, b) => (b.views ?? 0) - (a.views ?? 0),
    price_desc: (a, b) => Number(b.price) - Number(a.price),
    price_asc:  (a, b) => Number(a.price) - Number(b.price),
  }
  return [...list].sort(by[sort.value])
})
const totalViews = computed(() => properties.value.reduce((s, p) => s + (p.views ?? 0), 0))
const topId = computed(() => {
  const top = [...properties.value].sort((a, b) => (b.views ?? 0) - (a.views ?? 0))[0]
  return top?.views ? top.id : null
})

/* ── Per-card "more" menu ── */
const menuFor = ref<number | null>(null)
function closeMenu() { menuFor.value = null }
onMounted(() => document.addEventListener('click', closeMenu))
onUnmounted(() => document.removeEventListener('click', closeMenu))

/* ── Actions ── */
const busy = ref<number | null>(null)

/*
 * Each action asks first, then runs inside the dialog: it shows a spinner,
 * closes when done, and keeps any server error on screen for a retry.
 */
async function remove(prop: Property) {
  closeMenu()
  const ok = await confirm({
    title:   'Delete this listing?',
    message: 'The listing and all of its photos will be permanently removed. This cannot be undone.',
    preview: listingPreview(prop),
    consequences: [
      'Buyers will no longer see it anywhere on the site',
      'Its inquiries and viewing history are removed with it',
      'To hide it temporarily instead, use Unpublish',
    ],
    confirmLabel: 'Delete listing',
    busyLabel: 'Deleting…',
    tone: 'danger',
    icon: 'trash',
    typeToConfirm: 'DELETE',
    action: async () => (await deleteProperty(prop.id)) || error.value || false,
  })
  if (!ok) return
  toast.success('Listing deleted', { description: `“${prop.title}” is gone for good.` })
  await fetchMyListings()
}

async function sell(prop: Property) {
  closeMenu()
  const ok = await confirm({
    title:   'Mark this listing as sold?',
    message: 'Use this once the deal has closed.',
    preview: listingPreview(prop),
    consequences: [
      'It is hidden from buyers and removed from search',
      'It moves to your Inventory, under History',
      'You can re-list it at any time',
    ],
    confirmLabel: 'Mark as sold',
    busyLabel: 'Updating…',
    icon: 'sold',
    action: async () => !!(await markSold(prop.id)) || error.value || false,
  })
  if (!ok) return
  toast.success('Marked as sold 🎉', { description: 'It moved to History.', action: { label: 'View', run: () => navigateTo('/dashboard/history') } })
  await fetchMyListings()
}

async function publish(prop: Property) {
  const ok = await confirm({
    title:   'Publish this listing?',
    message: 'It goes live for every buyer on RealtyLink PH.',
    preview: listingPreview(prop),
    consequences: [
      'It appears on Browse right away, and on the home page within a couple of minutes',
      'Buyers can save it, message you and book viewings',
      'You can unpublish it any time',
    ],
    confirmLabel: 'Publish now',
    busyLabel: 'Publishing…',
    icon: 'publish',
    // The server refuses a listing that's missing a title, price, address or
    // photo, and says which: that message shows in the dialog.
    action: async () => !!(await publishProperty(prop.id)) || error.value || false,
  })
  if (!ok) return
  toast.success('Published', { description: 'Buyers can see it now.', action: { label: 'View live', run: () => navigateTo(propertyHref(prop.id)) } })
  await fetchMyListings()
}

async function unpublish(prop: Property) {
  closeMenu()
  const ok = await confirm({
    title:   'Unpublish this listing?',
    message: 'It’s hidden from buyers and goes back to your drafts.',
    preview: listingPreview(prop),
    consequences: [
      'It disappears from Browse, search and the home page',
      'Chats and booked viewings stay as they are',
      'Publish it again whenever you’re ready',
    ],
    confirmLabel: 'Unpublish',
    busyLabel: 'Unpublishing…',
    tone: 'warning',
    icon: 'hide',
    action: async () => !!(await unpublishProperty(prop.id)) || error.value || false,
  })
  if (!ok) return
  toast.success('Unpublished', { description: 'It’s back in your drafts.' })
  await fetchMyListings()
}

/* ── Display ── */
function priceLabel(p: Property): string {
  const n = Number(p.price)
  if (!(n > 0))       return 'Price not set'
  const s = n >= 1_000_000 ? `₱${(n / 1_000_000).toFixed(n % 1_000_000 === 0 ? 0 : 1)}M`
          : n >= 1_000     ? `₱${(n / 1_000).toFixed(0)}K`
          : `₱${n.toLocaleString('en-PH')}`
  return p.offer_type === 'rent' ? `${s}/mo` : s
}
/** Agent titles are often ALL CAPS; soften them like PropertyCard does. */
function calm(t: string): string {
  const letters = t.replace(/[^A-Za-z]/g, '')
  return letters.length > 3 && letters === letters.toUpperCase()
    ? t.toLowerCase().replace(/\b([a-z])/g, (_, c: string) => c.toUpperCase())
    : t
}
function cover(p: Property) {
  const ph = p.photos?.find(x => !x.is_360) ?? p.photos?.[0]
  return ph ? (ph.thumb_url ?? ph.url) : null
}
function updated(iso: string): string {
  const d = Math.floor((Date.now() - +new Date(iso)) / 86_400_000)
  if (d < 1) return 'Updated today'
  if (d === 1) return 'Updated yesterday'
  if (d < 30) return `Updated ${d} days ago`
  return `Updated ${new Date(iso).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })}`
}
function statusOf(p: Property) {
  if (p.status === 'published') return { label: 'Published', cls: 'bg-emerald-500 text-white' }
  if (p.unpublish_reason) return { label: 'Taken down', cls: 'bg-red-500 text-white' }
  if (p.is_complete === false) return { label: 'Unfinished', cls: 'bg-amber-400 text-[#0B2A5C]' }
  return { label: 'Draft', cls: 'bg-black/60 text-white' }
}
</script>

<template>
  <div class="page-shell">
    <!-- Header -->
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div class="min-w-0">
        <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Work</p>
        <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">My Listings</h1>
        <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/55">
          <template v-if="properties.length">
            <span class="font-semibold text-brand-navy dark:text-white">{{ counts.published }}</span> live
            <template v-if="counts.draft"> · <span class="font-semibold text-brand-navy dark:text-white">{{ counts.draft }}</span> in drafts</template>
            · <span class="font-semibold text-brand-navy dark:text-white">{{ totalViews.toLocaleString('en-PH') }}</span> views in total.
            Sold listings move to <NuxtLink to="/dashboard/history" class="font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline">History</NuxtLink>.
          </template>
          <template v-else>Manage the properties you've listed on RealtyLink PH.</template>
        </p>
      </div>
      <DashCta to="/dashboard/listings/new">
        <svg class="h-4 w-4 transition-transform duration-300 group-hover:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
        </svg>
        New listing
      </DashCta>
    </header>

    <!-- Toolbar -->
    <div v-if="properties.length" class="mb-6 flex flex-col gap-3 cw-lg:flex-row cw-lg:items-center">
      <div class="flex max-w-full overflow-x-auto rounded-full p-1 bg-white dark:bg-white/[0.05] ring-1 ring-brand-navy/10 dark:ring-white/10 self-start" role="tablist">
        <button
          v-for="t in TABS"
          :key="t.key"
          type="button"
          role="tab"
          :aria-selected="tab === t.key"
          class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-4 py-1.5 text-sm font-semibold transition"
          :class="tab === t.key ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-[#0B2A5C]' : 'text-brand-navy/65 hover:text-brand-navy dark:text-white/60 dark:hover:text-white'"
          @click="tab = t.key"
        >
          {{ t.label }}
          <span
            class="rounded-full px-1.5 text-xs tabular-nums"
            :class="tab === t.key ? 'bg-white/20 dark:bg-[#0B2A5C]/15' : t.key === 'attention' ? 'bg-amber-500/15 text-amber-700 dark:text-amber-400' : 'opacity-60'"
          >{{ counts[t.key] }}</span>
        </button>
      </div>

      <div class="flex flex-1 gap-2 cw-lg:justify-end">
        <div class="relative flex-1 cw-lg:max-w-xs">
          <svg class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-brand-navy/40 dark:text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
          <input v-model="search" type="search" placeholder="Search title or address" aria-label="Search your listings" class="w-full rounded-full border-0 bg-white dark:bg-white/[0.06] pl-10 pr-4 py-2.5 text-sm text-brand-navy dark:text-white placeholder-brand-navy/40 dark:placeholder-white/35 ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold" />
        </div>
        <select v-model="sort" aria-label="Sort listings" class="rounded-full border-0 bg-white dark:bg-white/[0.06] pl-4 pr-9 py-2.5 text-sm font-semibold text-brand-navy dark:text-white ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold">
          <option value="newest">Newest</option>
          <option value="views">Most viewed</option>
          <option value="price_desc">Price: high to low</option>
          <option value="price_asc">Price: low to high</option>
        </select>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading && !properties.length" class="grid grid-cols-[repeat(auto-fill,minmax(16.5rem,1fr))] gap-5">
      <AppSkeleton v-for="i in 6" :key="i" width="100%" height="340px" rounded="lg" />
    </div>

    <!-- Empty -->
    <div v-else-if="!properties.length" class="rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] px-6 py-16 text-center">
      <div class="h-14 w-14 rounded-2xl bg-brand-gold/15 flex items-center justify-center mx-auto mb-5">
        <svg class="h-7 w-7 text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>
      </div>
      <p class="font-display text-xl font-bold text-brand-navy dark:text-white">No listings yet</p>
      <p class="mx-auto mt-2 max-w-sm text-sm text-brand-text-secondary dark:text-white/50 leading-relaxed">
        Create your first listing to start receiving inquiries and viewing requests. RealtyLink AI can draft it for you from a few details.
      </p>
      <NuxtLink to="/dashboard/listings/new" class="mt-6 inline-flex items-center gap-2 rounded-full px-5 py-2.5 text-sm font-bold bg-brand-navy text-white hover:-translate-y-0.5 transition dark:bg-brand-gold dark:text-brand-navy">Create your first listing</NuxtLink>
    </div>

    <!-- No match -->
    <div v-else-if="!shown.length" class="rounded-3xl border border-dashed border-brand-navy/15 dark:border-white/15 px-6 py-14 text-center">
      <p class="font-semibold text-brand-navy dark:text-white">No listings match</p>
      <p class="mt-1 text-sm text-brand-navy/55 dark:text-white/50">Try another tab or search.</p>
      <button type="button" class="mt-4 text-sm font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline" @click="tab = 'all'; search = ''">Show all listings</button>
    </div>

    <!-- Cards -->
    <div v-else class="grid grid-cols-[repeat(auto-fill,minmax(16.5rem,1fr))] gap-5">
      <article
        v-for="prop in shown"
        :key="prop.id"
        class="group flex flex-col overflow-hidden rounded-3xl bg-white dark:bg-[#0B1A35] border-2 border-brand-navy dark:border-brand-gold transition-all duration-300
               shadow-[0_1px_2px_rgba(11,42,92,0.04)] hover:-translate-y-1 hover:shadow-[0_22px_44px_-22px_rgba(11,42,92,0.38)]
               dark:hover:shadow-[0_22px_44px_-22px_rgba(0,0,0,0.6),0_0_22px_-6px_rgba(212,165,74,0.5)]"
        :class="busy === prop.id ? 'opacity-60 pointer-events-none' : ''"
      >
        <!-- Cover -->
        <NuxtLink :to="`/dashboard/listings/${prop.id}/edit`" class="relative block aspect-[16/10] overflow-hidden bg-brand-navy/5 dark:bg-white/[0.06]">
          <img v-if="cover(prop)" :src="cover(prop)!" :alt="prop.title" loading="lazy" class="h-full w-full object-cover transition-transform duration-700 group-hover:scale-105" />
          <div v-else class="h-full w-full flex flex-col items-center justify-center gap-1.5 text-brand-navy/30 dark:text-white/30">
            <svg class="h-9 w-9" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.3" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M4 6h16v12H4z" /></svg>
            <span class="text-xs font-semibold">Add photos</span>
          </div>
          <div class="absolute inset-0 bg-gradient-to-t from-black/50 via-transparent to-black/10" />

          <span class="absolute top-3 left-3 flex gap-1.5">
            <span class="rounded-full px-2.5 py-1 text-[0.625rem] font-bold uppercase tracking-wide shadow-sm" :class="statusOf(prop).cls">{{ statusOf(prop).label }}</span>
          </span>
          <span class="absolute top-3 right-3 rounded-full bg-[#ffffff]/95 px-2.5 py-1 text-[0.625rem] font-bold uppercase tracking-wide text-[#0B2A5C] shadow-sm">{{ prop.offer_type === 'rent' ? 'For rent' : 'For sale' }}</span>

          <span class="absolute bottom-3 left-3 flex items-center gap-1.5">
            <span class="inline-flex items-center gap-1 rounded-full bg-black/55 px-2.5 py-1 text-[0.6875rem] font-semibold text-white backdrop-blur-sm">
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
              {{ (prop.views ?? 0).toLocaleString('en-PH') }}
            </span>
            <span v-if="prop.id === topId" class="rounded-full bg-brand-gold px-2.5 py-1 text-[0.625rem] font-bold uppercase tracking-wide text-[#0B2A5C]">Top listing</span>
            <span v-else-if="prop.is_featured" class="rounded-full bg-brand-gold px-2.5 py-1 text-[0.625rem] font-bold uppercase tracking-wide text-[#0B2A5C]">Featured</span>
          </span>
          <span class="absolute bottom-3 right-3 text-xs font-semibold text-white/90 drop-shadow">{{ prop.photos?.length ?? 0 }} photos</span>
        </NuxtLink>

        <!-- Body -->
        <div class="flex flex-1 flex-col p-5">
          <p class="font-display text-xl font-bold text-brand-gold-deep dark:text-brand-gold tabular-nums">{{ priceLabel(prop) }}</p>
          <NuxtLink
            :to="`/dashboard/listings/${prop.id}/edit`"
            class="mt-0.5 line-clamp-1 font-semibold transition-colors hover:text-brand-gold-deep dark:hover:text-brand-gold"
            :class="prop.title ? 'text-brand-navy dark:text-white' : 'italic text-brand-navy/40 dark:text-white/40'"
          >{{ prop.title ? calm(prop.title) : 'Untitled listing' }}</NuxtLink>
          <p class="mt-0.5 flex items-center gap-1 text-xs text-brand-navy/50 dark:text-white/45">
            <svg class="h-3.5 w-3.5 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
            <span class="truncate">{{ prop.address || 'Address not added yet' }}</span>
          </p>

          <div class="mt-3 flex flex-wrap gap-1.5 text-[0.6875rem] font-semibold text-brand-navy/70 dark:text-white/65">
            <span class="rounded-full bg-brand-navy/[0.05] dark:bg-white/[0.07] px-2.5 py-1">{{ PROPERTY_TYPES[prop.type] ?? prop.type }}</span>
            <span v-if="prop.bedrooms" class="rounded-full bg-brand-navy/[0.05] dark:bg-white/[0.07] px-2.5 py-1">{{ prop.bedrooms }} bed</span>
            <span v-if="prop.bathrooms" class="rounded-full bg-brand-navy/[0.05] dark:bg-white/[0.07] px-2.5 py-1">{{ prop.bathrooms }} bath</span>
            <span v-if="prop.floor_area" class="rounded-full bg-brand-navy/[0.05] dark:bg-white/[0.07] px-2.5 py-1">{{ Number(prop.floor_area) }} sqm</span>
          </div>

          <!-- Admin take-down: the reason stays here until the agent re-publishes -->
          <div v-if="prop.status === 'draft' && prop.unpublish_reason" class="mt-3 rounded-2xl bg-red-500/[0.07] ring-1 ring-red-500/20 px-3.5 py-2.5 text-xs leading-relaxed text-red-800 dark:text-red-300">
            <p class="font-bold">Taken down by an admin</p>
            <p class="mt-0.5">{{ prop.unpublish_reason }}</p>
            <p class="mt-1 text-red-700/80 dark:text-red-300/80">Fix it, then publish again.</p>
          </div>
          <p v-else-if="prop.status === 'draft' && prop.is_complete === false" class="mt-3 rounded-2xl bg-amber-500/[0.08] ring-1 ring-amber-500/20 px-3.5 py-2.5 text-xs text-amber-800 dark:text-amber-300">
            Half-finished. It needs a title, price, address and a photo before it can go live.
          </p>
          <!-- Everything else: a short look at the description, so cards in a row fill alike -->
          <p v-else-if="prop.description" class="mt-3 line-clamp-3 text-xs leading-relaxed text-brand-navy/60 dark:text-white/55">{{ prop.description }}</p>

          <!-- Actions -->
          <div class="mt-auto flex items-center gap-2 pt-4">
            <NuxtLink :to="`/dashboard/listings/${prop.id}/edit`" class="flex-1 rounded-full py-2 text-center text-xs font-bold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 transition">Edit</NuxtLink>
            <NuxtLink
              v-if="prop.status === 'draft' && prop.is_complete === false"
              :to="`/dashboard/listings/new?draft=${prop.id}`"
              class="flex-1 rounded-full bg-brand-gold py-2 text-center text-xs font-bold text-[#0B2A5C] shadow-[0_4px_14px_rgba(212,165,74,0.35)] hover:bg-brand-gold-light transition"
            >Continue</NuxtLink>
            <button
              v-else-if="prop.status === 'draft'"
              type="button"
              class="flex-1 rounded-full bg-brand-gold py-2 text-xs font-bold text-[#0B2A5C] shadow-[0_4px_14px_rgba(212,165,74,0.35)] hover:bg-brand-gold-light transition"
              @click="publish(prop)"
            >Publish</button>
            <NuxtLink
              v-else
              :to="propertyHref(prop.id)"
              class="flex-1 rounded-full bg-brand-navy py-2 text-center text-xs font-bold text-white hover:-translate-y-px dark:bg-brand-gold dark:text-[#0B2A5C] transition"
            >View live</NuxtLink>

            <!-- More -->
            <div class="relative">
              <button
                type="button"
                class="h-8 w-8 rounded-full flex items-center justify-center text-brand-navy/55 ring-1 ring-brand-navy/15 hover:text-brand-navy hover:ring-brand-gold dark:text-white/60 dark:ring-white/20 transition"
                :aria-expanded="menuFor === prop.id"
                aria-label="More actions"
                @click.stop="menuFor = menuFor === prop.id ? null : prop.id"
              >
                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zM12 10a2 2 0 11-4 0 2 2 0 014 0zM16 12a2 2 0 100-4 2 2 0 000 4z" /></svg>
              </button>
              <div
                v-if="menuFor === prop.id"
                class="absolute bottom-full right-0 z-20 mb-2 w-48 rounded-2xl bg-white dark:bg-[#0F2347] p-1.5 ring-1 ring-brand-navy/10 dark:ring-white/10 shadow-[0_18px_40px_-16px_rgba(11,42,92,0.45)]"
                @click.stop
              >
                <button v-if="prop.status === 'published'" type="button" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm text-emerald-700 hover:bg-emerald-500/10 dark:text-emerald-400" @click="sell(prop)">
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                  Mark as sold
                </button>
                <button v-if="prop.status === 'published'" type="button" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm text-brand-navy hover:bg-brand-navy/[0.05] dark:text-white dark:hover:bg-white/[0.06]" @click="unpublish(prop)">
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                  Unpublish
                </button>
                <button type="button" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm text-red-600 hover:bg-red-500/10 dark:text-red-400" @click="remove(prop)">
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                  Delete listing
                </button>
              </div>
            </div>
          </div>
          <p class="mt-3 text-[0.6875rem] text-brand-navy/40 dark:text-white/35">{{ updated(prop.updated_at) }}</p>
        </div>
      </article>
    </div>
  </div>
</template>
