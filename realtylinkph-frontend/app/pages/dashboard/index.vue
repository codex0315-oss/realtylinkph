<script setup lang="ts">
import type { Property } from '~/types'

definePageMeta({ layout: 'dashboard' })

const authStore = useAuthStore()

// Admins have their own panel; agents get the agent overview (below).
if (authStore.isAdmin) {
  await navigateTo('/admin/agents')
}

const { getFeatured }                       = useProperty()
const { appointments, fetchMyAppointments } = useAppointment()
const { favorites, fetchFavorites }         = useFavorite()
const { fetchConversations }                = useConversation()
const convStore                             = useConversationStore()

const recommended = ref<Property[]>([])
const loadingRec  = ref(true)

if (authStore.isBuyer) {
  const results = await Promise.allSettled([
    getFeatured(),
    fetchMyAppointments(),
    fetchFavorites(),
    fetchConversations(),
  ])
  if (results[0].status === 'fulfilled') recommended.value = results[0].value as Property[]
  loadingRec.value = false
}

const upcomingAppointments = computed(() =>
  appointments.value
    .filter(a => a.status !== 'cancelled')
    .sort((a, b) => +new Date(a.preferred_datetime) - +new Date(b.preferred_datetime)),
)

const unreadMessages = computed(() =>
  convStore.conversations.reduce((sum, c) => sum + (c.unread_count ?? 0), 0),
)

/*
 * `accent` marks the counters that represent something waiting on the user, so
 * the eye lands on those rather than on whichever happens to be first.
 */
const stats = computed(() => [
  {
    label: 'Upcoming viewings',
    value: upcomingAppointments.value.length,
    href:  '/dashboard/appointments',
    accent: upcomingAppointments.value.length > 0,
  },
  {
    label: 'Saved properties',
    value: favorites.value.length,
    href:  '/dashboard/saved',
  },
  {
    label: 'Unread messages',
    value: unreadMessages.value,
    href:  '/dashboard/messages',
    accent: unreadMessages.value > 0,
  },
])
</script>

<template>
  <!-- Agents get the agent overview; buyers get the buyer overview below. -->
  <AgentDashboard v-if="authStore.isAgent" />

  <!--
    No welcome banner. The topbar already greets the user by name, so a second
    greeting directly beneath it said the same thing twice and spent most of the
    fold on a sentence nobody reads more than once.
  -->
  <div v-else class="space-y-8 max-w-6xl">

    <!-- ── Page header ── -->
    <header class="flex flex-wrap items-end justify-between gap-4 pb-5 border-b border-gray-200 dark:border-white/[0.08]">
      <div class="min-w-0">
        <h1 class="font-display text-2xl sm:text-[28px] font-bold text-brand-navy dark:text-white leading-tight">
          Dashboard
        </h1>
        <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/50">
          Your saved homes, viewings and messages in one place.
        </p>
      </div>

      <DashCta to="/dashboard/browse">
        <svg class="h-4 w-4 transition-transform duration-300 group-hover:scale-110" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        Browse listings
      </DashCta>
    </header>

    <!-- ── Counters ── -->
    <DashStatStrip :stats="stats" />

    <!-- ── Recommended ── -->
    <DashSection title="Recommended for you" action-href="/dashboard/browse" :framed="false">
      <PropertyGrid v-if="loadingRec || recommended.length" :properties="recommended" :loading="loadingRec" :skeleton-count="4" />

      <div
        v-else
        class="rounded-2xl border border-gray-200 dark:border-white/[0.08] bg-white dark:bg-[#0B1A35] px-5 py-12 text-center"
      >
        <p class="text-sm text-brand-navy/60 dark:text-white/50">No listings available yet.</p>
        <p class="mt-1 text-xs text-brand-navy/40 dark:text-white/35">
          New properties from verified agents will appear here.
        </p>
      </div>
    </DashSection>

  </div>
</template>
