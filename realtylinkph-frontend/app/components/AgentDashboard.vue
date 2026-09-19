<script setup lang="ts">
import type { Property } from '~/types'

const authStore = useAuthStore()
const { properties, fetchMyListings }                 = useProperty()
const { inquiries, fetchInquiries, unreadCount }      = useInquiry()
const { appointments, fetchMyAppointments }           = useAppointment()
const { reviews, averageRating, fetchAgentReviews }   = useReview()

await Promise.allSettled([
  fetchMyListings(),
  fetchInquiries(),
  fetchMyAppointments(),
  authStore.user ? fetchAgentReviews(authStore.user.id) : Promise.resolve(),
])

const publishedCount = computed(() => properties.value.filter(p => p.status === 'published').length)
const totalViews     = computed(() => properties.value.reduce((s, p) => s + (p.views ?? 0), 0))

const upcoming = computed(() =>
  appointments.value
    .filter(a => a.status !== 'cancelled' && new Date(a.preferred_datetime) >= new Date(Date.now() - 36e5))
    .sort((a, b) => +new Date(a.preferred_datetime) - +new Date(b.preferred_datetime)),
)

/*
 * `accent` marks the counters that represent work waiting on the agent, so the
 * eye lands on those rather than on whichever card happens to be first.
 */
const stats = computed(() => [
  { label: 'Active listings', value: publishedCount.value,  href: '/dashboard/listings' },
  { label: 'Total views',     value: totalViews.value,      href: '/dashboard/listings' },
  { label: 'New inquiries',   value: unreadCount.value,     href: '/dashboard/inquiries',    accent: unreadCount.value > 0 },
  { label: 'Upcoming viewings', value: upcoming.value.length, href: '/dashboard/appointments', accent: upcoming.value.length > 0 },
])

const recentListings  = computed(() => [...properties.value].slice(0, 5))
const recentInquiries = computed(() =>
  [...inquiries.value].sort((a, b) => +new Date(b.created_at) - +new Date(a.created_at)).slice(0, 5),
)

const roleLabel = computed(() => (authStore.isVerifiedAgent ? 'Verified agent' : 'Agent'))

// Opacities must be multiples of 5 — Tailwind silently drops anything else, so
// /12 here compiled to no background at all.
const statusBadge: Record<string, string> = {
  pending:   'bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/25',
  confirmed: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border-emerald-500/25',
  cancelled: 'bg-red-500/15 text-red-600 dark:text-red-300 border-red-500/25',
}

function priceLabel(p: Property): string {
  const n = Number(p.price)
  if (n >= 1_000_000) return `₱${(n / 1_000_000).toFixed(n % 1_000_000 === 0 ? 0 : 1)}M`
  if (n >= 1_000)     return `₱${(n / 1_000).toFixed(0)}K`
  return `₱${n.toLocaleString('en-PH')}`
}
function fmtDate(d: string) {
  return new Date(d).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
}
function timeAgo(d: string) {
  return new Date(d).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })
}

/* Shared row padding — keeps the three lists on one rhythm. */
const rowClass = 'flex items-center gap-3 px-5 py-3.5 transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.04]'
/* Fills and centres inside the panel, so a short empty state still sits in the
   middle of a box levelled against a taller sibling. */
const emptyClass = 'flex-1 flex flex-col items-center justify-center px-5 py-12 text-center'
</script>

<template>
  <!--
    No welcome banner. The topbar already greets the user by name, so a second
    "Welcome back, <name>" directly beneath it said the same thing twice and
    spent ~140px of the fold on a sentence nobody reads more than once. The page
    now opens on the numbers.
  -->
  <div class="space-y-8 max-w-6xl mx-auto">

    <!-- ── Page header ── -->
    <header class="flex flex-wrap items-end justify-between gap-4 pb-5 border-b border-gray-200 dark:border-white/[0.08]">
      <div class="min-w-0">
        <h1 class="font-display text-2xl sm:text-[1.75rem] font-bold text-brand-navy dark:text-white leading-tight">
          Dashboard
        </h1>
        <p class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-brand-text-secondary dark:text-white/50">
          <span>{{ roleLabel }}</span>
          <template v-if="reviews.length">
            <span aria-hidden="true">·</span>
            <span class="inline-flex items-center gap-1">
              <svg class="h-3.5 w-3.5 text-brand-gold" fill="currentColor" viewBox="0 0 20 20">
                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
              </svg>
              <span class="font-semibold text-brand-navy dark:text-white">{{ averageRating.toFixed(1) }}</span>
              <span>from {{ reviews.length }} review{{ reviews.length === 1 ? '' : 's' }}</span>
            </span>
          </template>
        </p>
      </div>

      <DashCta to="/dashboard/listings/new">
        <svg class="h-4 w-4 transition-transform duration-300 group-hover:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
        </svg>
        New listing
      </DashCta>
    </header>

    <!-- ── Counters ── -->
    <DashStatStrip :stats="stats" />

    <!-- ── Listings + inquiries ── -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

      <DashSection title="Your listings" action-label="Manage all" action-href="/dashboard/listings">
        <div v-if="recentListings.length" class="divide-y divide-gray-100 dark:divide-white/[0.06]">
          <NuxtLink
            v-for="prop in recentListings"
            :key="prop.id"
            :to="`/dashboard/listings/${prop.id}/edit`"
            :class="rowClass"
            class="group"
          >
            <div class="h-11 w-14 rounded-lg bg-gray-100 dark:bg-white/[0.06] overflow-hidden flex-shrink-0">
              <img v-if="prop.photos?.length" :src="prop.photos[0]?.thumb_url ?? prop.photos[0]?.url" :alt="prop.title" class="w-full h-full object-cover" />
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-semibold text-brand-navy dark:text-white truncate group-hover:text-brand-gold-deep dark:group-hover:text-brand-gold transition-colors">
                {{ prop.title }}
              </p>
              <p class="mt-0.5 text-xs text-brand-navy/45 dark:text-white/40">
                <span class="capitalize">{{ prop.status }}</span>
                <span> · {{ prop.views ?? 0 }} view{{ prop.views === 1 ? '' : 's' }}</span>
              </p>
            </div>
            <span class="text-sm font-bold text-brand-navy dark:text-white flex-shrink-0 tabular-nums">{{ priceLabel(prop) }}</span>
          </NuxtLink>
        </div>

        <div v-else :class="emptyClass">
          <p class="text-sm text-brand-navy/60 dark:text-white/50">You haven't listed a property yet.</p>
          <NuxtLink
            to="/dashboard/listings/new"
            class="mt-4 inline-flex items-center gap-2 text-sm font-semibold px-4 py-2 rounded-xl border
                   border-brand-gold/40 text-brand-gold-deep dark:text-brand-gold hover:bg-brand-gold/10 transition-colors"
          >
            Create your first listing
          </NuxtLink>
        </div>
      </DashSection>

      <DashSection title="Recent inquiries" action-href="/dashboard/inquiries">
        <div v-if="recentInquiries.length" class="divide-y divide-gray-100 dark:divide-white/[0.06]">
          <NuxtLink
            v-for="inq in recentInquiries"
            :key="inq.id"
            to="/dashboard/inquiries"
            class="block px-5 py-3.5 transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.04]"
          >
            <div class="flex items-center gap-2">
              <!-- Unread marker, rather than tinting the whole row gold -->
              <span
                class="h-1.5 w-1.5 rounded-full flex-shrink-0"
                :class="inq.is_read ? 'bg-transparent' : 'bg-brand-gold'"
                aria-hidden="true"
              />
              <p class="text-sm font-semibold text-brand-navy dark:text-white truncate">{{ inq.name }}</p>
              <span class="ml-auto text-[0.6875rem] text-brand-navy/40 dark:text-white/35 flex-shrink-0">{{ timeAgo(inq.created_at) }}</span>
            </div>
            <p class="mt-1 pl-3.5 text-xs text-brand-navy/55 dark:text-white/45 truncate">
              {{ inq.property?.title ?? `Property #${inq.property_id}` }}
            </p>
            <p class="mt-0.5 pl-3.5 text-xs text-brand-navy/40 dark:text-white/35 truncate">{{ inq.message }}</p>
          </NuxtLink>
        </div>

        <div v-else :class="emptyClass">
          <p class="text-sm text-brand-navy/60 dark:text-white/50">No inquiries yet.</p>
          <p class="mt-1 text-xs text-brand-navy/40 dark:text-white/35">
            Messages from buyers on your listings arrive here.
          </p>
        </div>
      </DashSection>
    </div>

    <!-- ── Upcoming viewings ── -->
    <DashSection title="Upcoming viewings" action-href="/dashboard/appointments">
      <div v-if="upcoming.length" class="divide-y divide-gray-100 dark:divide-white/[0.06]">
        <div v-for="appt in upcoming.slice(0, 5)" :key="appt.id" :class="rowClass">
          <div class="h-10 w-10 rounded-lg bg-brand-navy/5 dark:bg-white/[0.06] flex items-center justify-center flex-shrink-0">
            <svg class="h-5 w-5 text-brand-navy/50 dark:text-white/50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-brand-navy dark:text-white truncate">
              {{ appt.property?.title ?? `Property #${appt.property_id}` }}
            </p>
            <p class="mt-0.5 text-xs text-brand-navy/45 dark:text-white/40">
              {{ appt.buyer?.name ? appt.buyer.name + ' · ' : '' }}{{ fmtDate(appt.preferred_datetime) }}
            </p>
          </div>
          <span
            class="text-[0.625rem] font-bold px-2 py-1 rounded-full border capitalize flex-shrink-0"
            :class="statusBadge[appt.status]"
          >{{ appt.status }}</span>
        </div>
      </div>

      <div v-else :class="emptyClass">
        <p class="text-sm text-brand-navy/60 dark:text-white/50">Nothing scheduled.</p>
        <p class="mt-1 text-xs text-brand-navy/40 dark:text-white/35">
          Viewings buyers book on your listings show up here.
        </p>
      </div>
    </DashSection>

  </div>
</template>
