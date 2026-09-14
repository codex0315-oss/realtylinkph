<script setup lang="ts">
const authStore  = useAuthStore()
const notifStore = useNotificationStore()
const route      = useRoute()
const { logout } = useAuth()
const { markRead, markAllRead } = useNotification()
const { appointments, fetchMyAppointments } = useAppointment()

// History nav badge — count of cancelled + past viewings. Shared appointment
// state keeps this in sync after a cancel elsewhere.
const historyCount = computed(() =>
  appointments.value.filter(a => a.status === 'cancelled' || new Date(a.preferred_datetime).getTime() < Date.now()).length,
)
onMounted(() => {
  // History badge is buyer/agent-only — admins have no appointments.
  if (authStore.isAuthenticated && !authStore.isAdmin && !appointments.value.length) fetchMyAppointments()
})

// Only guard in the browser, where the saved session (localStorage) actually
// lives — dashboard routes are client-rendered (see routeRules in nuxt.config),
// so the server never redirects before the session is restored.
if (import.meta.client && !authStore.isAuthenticated) {
  await navigateTo('/?auth=login')
}

// Admins live in the /admin area; bounce them out of buyer/agent /dashboard pages.
if (import.meta.client && authStore.isAdmin && route.path.startsWith('/dashboard')) {
  await navigateTo('/admin/users')
}
watch(() => route.path, (p) => {
  if (import.meta.client && authStore.isAdmin && p.startsWith('/dashboard')) navigateTo('/admin/users')
})

const sidebarOpen = ref(false)   // mobile drawer
const userOpen    = ref(false)   // profile dropdown
const notifOpen   = ref(false)   // notifications dropdown

function notifText(n: { type: string; payload: Record<string, unknown> }): string {
  return (n.payload?.message as string) ?? n.type.replace(/[._]/g, ' ')
}
function notifTime(iso: string): string {
  return new Date(iso).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

// Where each notification type takes the user when clicked.
function notifRoute(type: string): string {
  if (type.includes('appointment')) return '/dashboard/appointments'
  if (type.includes('new_agent_application')) return '/admin/agents'
  if (type.includes('agent_application')) return '/dashboard/profile'
  if (type.includes('inquiry')) return '/dashboard/inquiries'
  if (type.includes('message') || type.includes('conversation')) return '/dashboard/messages'
  if (type.includes('review')) return '/dashboard/reviews'
  if (type.includes('property') || type.includes('listing')) return '/dashboard/listings'
  return '/dashboard'
}
async function openNotification(n: { id: string; type: string; read_at: string | null }) {
  if (!n.read_at) await markRead(n.id)
  notifOpen.value = false
  await navigateTo(notifRoute(n.type))
}

const ICONS: Record<string, string> = {
  dashboard:    'M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM14 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zM14 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z',
  calendar:     'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
  chat:         'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z',
  home:         'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
  clock:        'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
  users:        'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
  star:         'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z',
  search:       'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z',
  heart:        'M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z',
  user:         'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
  sparkle:      'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.456-2.456L14.25 6l1.035-.259a3.375 3.375 0 002.456-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z',
  inbox:        'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
}

const buyerLinks = [
  { label: 'Dashboard',    href: '/dashboard',              icon: 'dashboard' },
  { label: 'Browse',       href: '/dashboard/browse',       icon: 'search' },
  { label: 'Saved',        href: '/dashboard/saved',        icon: 'heart' },
  { label: 'Messages',     href: '/dashboard/messages',     icon: 'chat' },
  { label: 'Appointments', href: '/dashboard/appointments', icon: 'calendar' },
  { label: 'History',      href: '/dashboard/history',      icon: 'clock' },
  { label: 'My Profile',   href: '/dashboard/profile',      icon: 'user' },
]

const agentLinks = [
  { label: 'Dashboard',    href: '/dashboard',              icon: 'dashboard' },
  { label: 'My Listings',  href: '/dashboard/listings',     icon: 'home' },
  { label: 'Inquiries',    href: '/dashboard/inquiries',    icon: 'inbox' },
  { label: 'Appointments', href: '/dashboard/appointments', icon: 'calendar' },
  { label: 'History',      href: '/dashboard/history',      icon: 'clock' },
  { label: 'Messages',     href: '/dashboard/messages',     icon: 'chat' },
  { label: 'Calendar',     href: '/dashboard/calendar',     icon: 'clock' },
  { label: 'Reviews',      href: '/dashboard/reviews',      icon: 'star' },
  { label: 'My Profile',   href: '/dashboard/profile',      icon: 'user' },
]

const adminLinks = [
  { label: 'Users',    href: '/admin/users',    icon: 'users' },
  { label: 'Agents',   href: '/admin/agents',   icon: 'user' },
  { label: 'Listings', href: '/admin/listings', icon: 'home' },
  { label: 'Reviews',  href: '/admin/reviews',  icon: 'star' },
]

const navLinks = computed(() => {
  if (authStore.isAdmin) return adminLinks
  if (authStore.isAgent) return agentLinks
  return buyerLinks
})

const roleLabel = computed(() => {
  if (authStore.isAdmin) return 'Administrator'
  if (authStore.isAgent) return authStore.isVerifiedAgent ? 'Verified Agent' : 'Agent'
  return 'Buyer'
})

/*
 * Agent onboarding state for buyers. A rejected applicant used to match
 * neither branch — no "Become an Agent", no "Pending" — so the sidebar gave
 * them no way back in at all. They now get a "Not approved" entry that links
 * to their profile, where the reason and the countdown live. Once the cooldown
 * expires the API stops returning the profile, so they fall back to
 * "Become an Agent" on their own.
 */
const canBecomeAgent = computed(() => authStore.isBuyer && !authStore.user?.agent_profile)
const agentPending   = computed(() => authStore.isBuyer && authStore.user?.agent_profile?.status === 'pending')
const agentRejected  = computed(() => authStore.isBuyer && authStore.user?.agent_profile?.status === 'rejected')

// Active when the path matches exactly, or is a sub-route (but '/dashboard'
// should only be active on the exact overview, not every dashboard sub-page).
function isActive(href: string): boolean {
  if (href === '/dashboard') return route.path === '/dashboard'
  return route.path === href || route.path.startsWith(href + '/')
}

function closeAll() {
  sidebarOpen.value = false
  userOpen.value    = false
  notifOpen.value   = false
}

watch(() => route.path, closeAll)
</script>

<template>
  <div class="min-h-screen flex bg-gray-50 dark:bg-[#060E1F] transition-colors">

    <!-- ═══════════ Sidebar ═══════════ -->
    <!--
      The sidebar used to be dark in both themes via an inline gradient, so light
      mode had a black slab down the side of an otherwise light page. The
      gradient is now a `dark:` variant, which means light mode gets a white
      panel separated from the gray-50 content by a hairline.
    -->
    <aside
      class="fixed lg:sticky top-0 z-40 h-screen w-64 flex-shrink-0 flex flex-col
             transition-transform duration-300 lg:translate-x-0
             bg-white border-r border-gray-200
             dark:border-white/[0.06] dark:bg-gradient-to-b dark:from-[#0d1f3c] dark:via-[#08152F] dark:to-[#060E1F]"
      :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <!-- Logo -->
      <div class="h-20 flex items-center justify-center px-6 border-b border-gray-200 dark:border-white/10">
        <NuxtLink to="/" class="flex items-center">
          <AppLogo size="md" />
        </NuxtLink>
      </div>

      <!-- Nav -->
      <nav class="flex-1 overflow-y-auto px-4 py-6 space-y-1">
        <p class="px-3 mb-2 text-[10px] font-bold uppercase tracking-[0.2em] text-brand-navy/40 dark:text-white/30">Menu</p>
        <NuxtLink
          v-for="link in navLinks"
          :key="link.href"
          :to="link.href"
          class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-150 group"
          :class="isActive(link.href)
            ? 'bg-brand-gold text-brand-navy shadow-[0_4px_16px_rgba(212,175,55,0.3)]'
            : 'text-brand-navy/65 hover:text-brand-navy hover:bg-brand-navy/5 dark:text-white/60 dark:hover:text-white dark:hover:bg-white/5'"
          @click="closeAll"
        >
          <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" :d="ICONS[link.icon]" />
          </svg>
          <span class="flex-1">{{ link.label }}</span>
          <span
            v-if="link.href === '/dashboard/history' && historyCount"
            class="flex-shrink-0 min-w-[20px] h-5 px-1.5 rounded-full text-[10px] font-bold flex items-center justify-center"
            :class="isActive(link.href)
              ? 'bg-brand-navy/15 text-brand-navy'
              : 'bg-brand-navy/10 text-brand-navy/60 dark:bg-white/10 dark:text-white/70'"
          >{{ historyCount > 99 ? '99+' : historyCount }}</span>
        </NuxtLink>

        <!-- Agent: Get Verified prompt -->
        <template v-if="!authStore.isAdmin && !authStore.isVerifiedAgent && authStore.isAgent">
          <div class="my-3 h-px bg-gray-200 dark:bg-white/10" />
          <NuxtLink
            to="/dashboard/verify"
            class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-brand-gold-deep dark:text-brand-gold hover:bg-brand-gold/10 transition-colors"
            @click="closeAll"
          >
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
            Get Verified
          </NuxtLink>
        </template>

        <!-- Buyer: Become an Agent onboarding -->
        <template v-if="canBecomeAgent">
          <div class="my-3 h-px bg-gray-200 dark:bg-white/10" />
          <NuxtLink
            to="/dashboard/verify"
            class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold text-brand-gold-deep dark:text-brand-gold border border-brand-gold/40 dark:border-brand-gold/25 hover:bg-brand-gold/10 transition-colors"
            @click="closeAll"
          >
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
            Become an Agent
          </NuxtLink>
        </template>
        <template v-else-if="agentPending">
          <div class="my-3 h-px bg-gray-200 dark:bg-white/10" />
          <div class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-brand-navy/40 dark:text-white/40">
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Agent Application Pending
          </div>
        </template>
        <template v-else-if="agentRejected">
          <div class="my-3 h-px bg-gray-200 dark:bg-white/10" />
          <NuxtLink
            to="/dashboard/profile"
            class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-500/10 transition-colors"
            @click="closeAll"
          >
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
            </svg>
            Application Not Approved
          </NuxtLink>
        </template>
      </nav>

      <!-- Browse listings CTA -->
      <div class="p-4">
        <NuxtLink
          to="/dashboard/browse"
          class="flex items-center justify-center gap-2 w-full rounded-xl py-2.5 text-xs font-semibold border transition-all
                 bg-gray-50 border-gray-200 text-brand-navy/70 hover:text-brand-navy hover:bg-gray-100
                 dark:bg-white/5 dark:border-white/10 dark:text-white/70 dark:hover:text-white dark:hover:bg-white/10"
          @click="closeAll"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          Browse Listings
        </NuxtLink>
      </div>
    </aside>

    <!-- Mobile backdrop -->
    <Transition name="fade">
      <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-black/40 lg:hidden" @click="sidebarOpen = false" />
    </Transition>

    <!-- ═══════════ Main column ═══════════ -->
    <div class="flex-1 min-w-0 flex flex-col">

      <!-- Topbar -->
      <header class="sticky top-0 z-20 h-20 bg-white dark:bg-[#0d1f3c] border-b border-gray-100 dark:border-white/10 flex items-center gap-4 px-5 sm:px-8 transition-colors">
        <!-- Mobile menu toggle -->
        <button
          class="lg:hidden p-2 -ml-2 text-brand-navy dark:text-white/80 rounded-lg hover:bg-gray-100 dark:hover:bg-white/10"
          @click="sidebarOpen = !sidebarOpen"
        >
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        </button>

        <!--
          No greeting here. Each page now opens with its own heading, and the
          profile menu on the right already shows who is signed in and in what
          role, so this restated both a few pixels away.
        -->

        <!-- Right actions -->
        <div class="ml-auto flex items-center gap-2 sm:gap-3">
          <!-- Notifications dropdown -->
          <div class="relative">
            <button
              class="relative p-2.5 text-gray-400 hover:text-brand-navy dark:hover:text-white rounded-xl hover:bg-gray-100 dark:hover:bg-white/10 transition-colors"
              aria-label="Notifications"
              @click="notifOpen = !notifOpen; userOpen = false"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
              </svg>
              <span
                v-if="notifStore.unreadCount > 0"
                class="absolute top-1.5 right-1.5 h-4 w-4 bg-brand-gold rounded-full text-[9px] font-bold text-brand-navy flex items-center justify-center"
              >
                {{ notifStore.unreadCount > 9 ? '9+' : notifStore.unreadCount }}
              </span>
            </button>

            <Transition name="dropdown">
              <div
                v-if="notifOpen"
                class="absolute right-0 mt-2 w-80 bg-white rounded-2xl shadow-[0_8px_40px_rgba(0,0,0,0.16)] border border-gray-100 overflow-hidden z-30"
              >
                <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
                  <span class="text-sm font-bold text-brand-navy">Notifications</span>
                  <button
                    v-if="notifStore.unreadCount > 0"
                    class="text-xs text-brand-gold font-medium hover:underline"
                    @click="markAllRead()"
                  >
                    Mark all read
                  </button>
                </div>
                <div class="max-h-80 overflow-y-auto">
                  <p v-if="!notifStore.notifications.length" class="text-sm text-gray-400 text-center py-8">
                    No notifications yet
                  </p>
                  <button
                    v-for="n in notifStore.notifications.slice(0, 15)"
                    :key="n.id"
                    class="w-full text-left px-4 py-3 hover:bg-gray-50 transition-colors border-b border-gray-50 last:border-0 flex gap-3"
                    :class="{ 'bg-brand-gold/5': !n.read_at }"
                    @click="openNotification(n)"
                  >
                    <span class="mt-1.5 h-2 w-2 rounded-full flex-shrink-0" :class="n.read_at ? 'bg-transparent' : 'bg-brand-gold'" />
                    <span class="min-w-0">
                      <span class="block text-xs font-medium text-brand-navy line-clamp-2">{{ notifText(n) }}</span>
                      <span class="block text-[10px] text-gray-400 mt-0.5">{{ notifTime(n.created_at) }}</span>
                    </span>
                  </button>
                </div>
              </div>
            </Transition>
          </div>

          <!-- Profile dropdown -->
          <div class="relative">
            <button
              class="flex items-center gap-2.5 pl-2 pr-3 py-1.5 rounded-xl hover:bg-gray-100 dark:hover:bg-white/10 transition-colors"
              @click="userOpen = !userOpen; notifOpen = false"
            >
              <AppAvatar :name="authStore.user?.name" :src="authStore.user?.avatar" size="sm" />
              <div class="hidden sm:block text-left">
                <p class="text-xs font-semibold text-brand-navy dark:text-white leading-none truncate max-w-[120px]">{{ authStore.user?.name }}</p>
                <p class="text-[10px] text-gray-400 mt-0.5">{{ roleLabel }}</p>
              </div>
              <svg class="h-4 w-4 text-gray-400 hidden sm:block" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
              </svg>
            </button>

            <Transition name="dropdown">
              <div
                v-if="userOpen"
                class="absolute right-0 mt-2 w-56 bg-white rounded-2xl shadow-[0_8px_40px_rgba(0,0,0,0.16)] border border-gray-100 overflow-hidden"
              >
                <div class="px-4 py-3 border-b border-gray-100">
                  <p class="text-sm font-semibold text-brand-navy truncate">{{ authStore.user?.name }}</p>
                  <p class="text-xs text-gray-400 truncate">{{ authStore.user?.email }}</p>
                </div>
                <nav class="py-1">
                  <!-- Admin: link to the admin area; Buyer/Agent: profile + appointments -->
                  <NuxtLink v-if="authStore.isAdmin" to="/admin/users" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-brand-navy hover:bg-gray-50 hover:text-brand-gold transition-colors" @click="closeAll">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg>
                    Admin Dashboard
                  </NuxtLink>
                  <template v-else>
                    <NuxtLink to="/dashboard/profile" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-brand-navy hover:bg-gray-50 hover:text-brand-gold transition-colors" @click="closeAll">
                      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                      My Profile
                    </NuxtLink>
                    <NuxtLink to="/dashboard/appointments" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-brand-navy hover:bg-gray-50 hover:text-brand-gold transition-colors" @click="closeAll">
                      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                      My Appointments
                    </NuxtLink>
                  </template>
                  <div class="my-1 h-px bg-gray-100" />
                  <button class="flex items-center gap-2.5 w-full px-4 py-2.5 text-sm text-red-500 hover:bg-red-50 transition-colors" @click="logout">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
                    Sign out
                  </button>
                </nav>
              </div>
            </Transition>
          </div>
        </div>
      </header>

      <!-- Content -->
      <main class="flex-1 min-w-0 p-5 sm:p-8">
        <slot />
      </main>
    </div>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.dropdown-enter-active { transition: all 0.18s cubic-bezier(0.16,1,0.3,1); }
.dropdown-leave-active { transition: all 0.12s ease; }
.dropdown-enter-from, .dropdown-leave-to { opacity: 0; transform: scale(0.96) translateY(-6px); }
</style>
