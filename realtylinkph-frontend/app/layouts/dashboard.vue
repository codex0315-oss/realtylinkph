<script setup lang="ts">
/*
 * Dashboard shell for buyers, agents and admins:
 *  - a sidebar that collapses to an icon rail (with tooltips), remembered per
 *    browser; a drawer on phones;
 *  - a top bar with Ctrl K search (DashCommandPalette), RealtyLink AI (buyers),
 *    Messages and Notifications toggles, and the profile menu;
 *  - a docked side panel for AI / Notifications / Messages that the page
 *    narrows beside on desktop (a full-screen sheet on phones). See useDashPanel.
 */
const authStore  = useAuthStore()
const notifStore = useNotificationStore()
const convStore  = useConversationStore()
const route      = useRoute()

const { appointments, fetchMyAppointments } = useAppointment()
const { fetchConversations } = useConversation()
const { onNotificationType } = useEcho()
const { panel, toggle: togglePanel, close: closePanel } = useDashPanel()

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
  await navigateTo('/admin')
}
watch(() => route.path, (p) => {
  if (import.meta.client && authStore.isAdmin && p.startsWith('/dashboard')) navigateTo('/admin')
})

/*
 * The space a page really gets: <main> minus its padding, in rem. The sidebar
 * (open or collapsed) and a docked panel both change it, and the pages' layouts
 * follow through the cw-* variants (see tailwind.config.ts).
 */
const mainEl = ref<HTMLElement | null>(null)
const contentRem = ref(0)
const CW_STEPS = [['sm', 40], ['md', 48], ['lg', 64], ['xl', 80], ['2xl', 96]] as const
const cwClasses = computed(() => CW_STEPS.filter(([, min]) => contentRem.value >= min).map(([k]) => `cw-${k}`))
function measureMain() {
  const el = mainEl.value
  if (!el) return
  const cs = getComputedStyle(el)
  const px = el.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight)
  const rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16
  contentRem.value = px / rem
}
let mainObserver: ResizeObserver | null = null
onMounted(() => {
  measureMain()   // before first paint, so the first frame already has the right layout
  if (mainEl.value) { mainObserver = new ResizeObserver(measureMain); mainObserver.observe(mainEl.value) }
})
onUnmounted(() => mainObserver?.disconnect())

const sidebarOpen = ref(false)   // mobile drawer
const userOpen    = ref(false)   // profile dropdown
const cmdkOpen    = ref(false)   // Ctrl K search

/* ── Collapsible sidebar (desktop), remembered in this browser ── */
const collapsed = ref(false)
onMounted(() => { try { collapsed.value = localStorage.getItem('rl-sidebar-collapsed') === '1' } catch { /* storage blocked */ } })
watch(collapsed, (v) => { try { localStorage.setItem('rl-sidebar-collapsed', v ? '1' : '0') } catch { /* ignore */ } })

/* ── Messages badge: unread from the conversations, refreshed live ── */
const canChat = computed(() => authStore.isAuthenticated && !authStore.isAdmin)
let stopNewMessage: (() => void) | null = null
onMounted(() => {
  if (!canChat.value) return
  if (!convStore.conversations.length) fetchConversations()
  stopNewMessage = onNotificationType('new_message', () => { fetchConversations() })
})
onUnmounted(() => stopNewMessage?.())

/* ── Keyboard: Ctrl/⌘ K opens search; Esc closes the side panel ── */
function onGlobalKey(e: KeyboardEvent) {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault()
    cmdkOpen.value = !cmdkOpen.value
    return
  }
  if (e.key === 'Escape' && panel.value && !cmdkOpen.value) {
    const tag = (e.target as HTMLElement | null)?.tagName
    if (tag !== 'INPUT' && tag !== 'TEXTAREA') closePanel()
  }
}
onMounted(() => document.addEventListener('keydown', onGlobalKey))
onUnmounted(() => document.removeEventListener('keydown', onGlobalKey))

function openPanel(p: 'assistant' | 'notifications' | 'messages') {
  userOpen.value = false
  togglePanel(p)
}

const ICONS: Record<string, string> = {
  dashboard:    'M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM14 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zM14 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z',
  calendar:     'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
  chat:         'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z',
  home:         'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
  clock:        'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
  /* A month grid: the Calendar page, told apart from Appointments' plain calendar */
  calendarDays: 'M8 3v4m8-4v4M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2zM3 10h18M7.5 13.5h.01M12 13.5h.01M16.5 13.5h.01M7.5 17h.01M12 17h.01M16.5 17h.01',
  users:        'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
  star:         'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z',
  search:       'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z',
  heart:        'M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z',
  user:         'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
  sparkle:      'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.456-2.456L14.25 6l1.035-.259a3.375 3.375 0 002.456-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z',
  inbox:        'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
  help:         'M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z',
}

const buyerLinks = [
  { label: 'Dashboard',    href: '/dashboard',              icon: 'dashboard' },
  { label: 'Browse',       href: '/dashboard/browse',       icon: 'search' },
  { label: 'Saved',        href: '/dashboard/saved',        icon: 'heart' },
  { label: 'Appointments', href: '/dashboard/appointments', icon: 'calendar' },
  { label: 'History',      href: '/dashboard/history',      icon: 'clock' },
  { label: 'Reviews',      href: '/dashboard/reviews',      icon: 'star' },
  { label: 'My Profile',   href: '/dashboard/profile',      icon: 'user' },
  { label: 'Help & support', href: '/dashboard/support',    icon: 'help' },
]
// Buyers reach chats from the top-bar Messages panel; the full-screen chat
// page stays reachable from its "Open full chat" link (and Ctrl K).

const agentLinks = [
  { label: 'Dashboard',    href: '/dashboard',              icon: 'dashboard' },
  { label: 'My Listings',  href: '/dashboard/listings',     icon: 'home' },
  { label: 'Inquiries',    href: '/dashboard/inquiries',    icon: 'inbox' },
  { label: 'Appointments', href: '/dashboard/appointments', icon: 'calendar' },
  { label: 'History',      href: '/dashboard/history',      icon: 'clock' },
  { label: 'Messages',     href: '/dashboard/messages',     icon: 'chat' },
  { label: 'Calendar',     href: '/dashboard/calendar',     icon: 'calendarDays' },
  { label: 'Reviews',      href: '/dashboard/reviews',      icon: 'star' },
  { label: 'My Profile',   href: '/dashboard/profile',      icon: 'user' },
  { label: 'Help & support', href: '/dashboard/support',    icon: 'help' },
]

const adminLinks = [
  { label: 'Overview', href: '/admin',          icon: 'dashboard' },
  { label: 'Users',    href: '/admin/users',    icon: 'users' },
  { label: 'Agents',   href: '/admin/agents',   icon: 'user' },
  { label: 'Listings', href: '/admin/listings', icon: 'home' },
  { label: 'Reviews',  href: '/admin/reviews',  icon: 'star' },
  { label: 'Support',  href: '/admin/support',  icon: 'help' },
  { label: 'Activity', href: '/admin/activity', icon: 'clock' },
]

const navLinks = computed(() => {
  if (authStore.isAdmin) return adminLinks
  if (authStore.isAgent) return agentLinks
  return buyerLinks
})

/* Sidebar groups (the flat lists above still feed Ctrl K). */
type NavLink = (typeof buyerLinks)[number]
const pick = (list: NavLink[], hrefs: string[]) => hrefs.map(h => list.find(l => l.href === h)!).filter(Boolean)
const navGroups = computed<Array<{ title: string; links: NavLink[] }>>(() => {
  if (authStore.isAdmin) return [{ title: 'Manage', links: adminLinks }]
  if (authStore.isAgent) {
    return [
      // Messages and Inquiries live in the top bar's Messages panel instead.
      { title: 'Work',     links: pick(agentLinks, ['/dashboard', '/dashboard/listings']) },
      { title: 'Schedule', links: pick(agentLinks, ['/dashboard/appointments', '/dashboard/calendar', '/dashboard/history']) },
      { title: 'Account',  links: pick(agentLinks, ['/dashboard/reviews', '/dashboard/profile', '/dashboard/support']) },
    ]
  }
  return [
    { title: 'Discover',    links: pick(buyerLinks, ['/dashboard', '/dashboard/browse', '/dashboard/saved']) },
    { title: 'My viewings', links: pick(buyerLinks, ['/dashboard/appointments', '/dashboard/history', '/dashboard/reviews']) },
    { title: 'Account',     links: pick(buyerLinks, ['/dashboard/profile', '/dashboard/support']) },
  ]
})

/* Live counts on menu items. Saved is refreshed on navigation, so un-saving
   on one page shows up as soon as you move on. */
const { favorites: savedHomes, fetchFavorites: fetchSavedHomes } = useFavorite()
onMounted(() => { if (authStore.isBuyer) fetchSavedHomes() })
watch(() => route.path, () => { if (authStore.isBuyer) fetchSavedHomes() })

/* Unread replies from the support team (buyers and agents). */
const { fetchUnread: fetchSupportUnread } = useSupport()
const supportUnread = useState('support-unread', () => 0)
async function refreshSupportUnread() { if (!authStore.isAdmin && authStore.isAuthenticated) supportUnread.value = await fetchSupportUnread() }
onMounted(refreshSupportUnread)
watch(() => route.path, refreshSupportUnread)

/* Agents: unread inquiries count toward the Messages badge (they live in that panel). */
const { unreadCount: inquiryUnread, fetchInquiries } = useInquiry()
onMounted(() => { if (authStore.isAgent) fetchInquiries() })
const messagesBadge = computed(() => convStore.totalUnread + (authStore.isAgent ? inquiryUnread.value : 0))

/* ?panel=messages|notifications|inquiries (RealtyLink AI's "open my messages"
   from a public page, or an "Inquiries" link) opens that panel on arrival,
   then tidies the URL. */
const { openInquiries } = useDashPanel()
watch(() => route.query.panel, (p) => {
  if (p !== 'messages' && p !== 'notifications' && p !== 'inquiries') return
  if (p === 'inquiries') openInquiries()
  else panel.value = p
  const { panel: _, ...rest } = route.query
  navigateTo({ path: route.path, query: rest }, { replace: true })
}, { immediate: true })
const upcomingCount = computed(() =>
  appointments.value.filter(a => (a.status === 'pending' || a.status === 'confirmed') && +new Date(a.preferred_datetime) >= Date.now()).length,
)
function countFor(href: string): number {
  switch (href) {
    case '/dashboard/saved':        return authStore.isBuyer ? savedHomes.value.length : 0
    case '/dashboard/appointments': return upcomingCount.value
    case '/dashboard/history':      return historyCount.value
    case '/dashboard/messages':     return convStore.totalUnread
    case '/dashboard/support':      return supportUnread.value
    default:                        return 0
  }
}
/* Gold = needs attention (upcoming viewings, unread chats); quiet otherwise. */
const loudCount = (href: string) => href === '/dashboard/appointments' || href === '/dashboard/messages' || href === '/dashboard/support'

const firstName = computed(() => authStore.user?.name?.split(' ')[0] ?? '')

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
  // Home pages are only active on themselves, not on every page below them.
  if (href === '/dashboard' || href === '/admin') return route.path === href
  return route.path === href || route.path.startsWith(href + '/')
}

/* The docked panel stays open across pages on desktop; on a phone (where it's
   a full-screen sheet) navigating closes it. */
function closeAll() {
  sidebarOpen.value = false
  userOpen.value    = false
  if (import.meta.client && window.innerWidth < 1024) closePanel()
}

watch(() => route.path, closeAll)

/* ── Back arrow (below lg, where the sidebar is a drawer) ──
   Every page except the home one gets it. It returns to wherever the person
   came from. Opened with no in-app history (a reload, a shared link, back from
   Google's sign-in) it goes one level up instead: a listing to Browse, a
   profile section to My Profile, anything else home. */
const homePath = computed(() => (authStore.isAdmin ? '/admin' : '/dashboard'))
const showBack = computed(() => route.path !== homePath.value)
const router = useRouter()
function parentPath(): string {
  const p = route.path
  if (p.startsWith('/dashboard/properties/')) return authStore.isAgent ? '/dashboard/listings' : '/dashboard/browse'
  if (p.startsWith('/dashboard/agents/'))     return authStore.isAgent ? homePath.value : '/dashboard/browse'
  if (p.startsWith('/dashboard/listings/'))   return '/dashboard/listings'
  if (p === '/dashboard/profile' && route.query.section) return '/dashboard/profile'
  return homePath.value
}
function goBack() {
  const cameFromApp = import.meta.client && typeof window.history.state?.back === 'string'
  if (cameFromApp) router.back()
  // Replace, not push: otherwise the next Back would return to this page.
  else navigateTo(parentPath(), { replace: true })
}

/* Pages for the Ctrl K palette — the sidebar's links plus Browse and Messages. */
const cmdkPages = computed(() => {
  const list = navLinks.value.map(l => ({ label: l.label, href: l.href, icon: ICONS[l.icon]! }))
  if (!authStore.isAdmin && !list.some(l => l.href === '/dashboard/browse')) {
    list.push({ label: 'Browse listings', href: '/dashboard/browse', icon: ICONS.search! })
  }
  if (canChat.value && !list.some(l => l.href === '/dashboard/messages')) {
    list.push({ label: 'Messages', href: '/dashboard/messages', icon: ICONS.chat! })
  }
  return list
})

const panelLabel = computed(() =>
  panel.value === 'assistant' ? 'RealtyLink AI' : panel.value === 'messages' ? 'Messages' : 'Notifications',
)

/* Shared styling for the collapsed-rail tooltips. */
const tip =
  'pointer-events-none absolute left-full top-1/2 -translate-y-1/2 ml-3 z-50 whitespace-nowrap rounded-lg px-2.5 py-1.5 ' +
  'text-xs font-semibold text-white bg-[#06183A] ring-1 ring-brand-gold/40 shadow-lg opacity-0 -translate-x-1 ' +
  'group-hover:opacity-100 group-hover:translate-x-0 group-focus-visible:opacity-100 transition duration-150 hidden lg:block'
const topIcon =
  'relative h-11 w-11 flex items-center justify-center rounded-full transition-colors'
const topIconIdle =
  'text-brand-navy/55 hover:text-brand-navy hover:bg-gray-100 dark:text-white/60 dark:hover:text-white dark:hover:bg-white/10'
const topIconOn =
  'bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold'
</script>

<template>
  <div class="min-h-screen flex bg-gray-50 dark:bg-[#06183A] transition-colors">

    <!-- ═══════════ Sidebar ═══════════
         Navy in both themes (like the brand's hero and AI cards), with grouped
         links, live counts, a gold active pill and the signed-in user at the
         bottom. Collapses to an icon rail with tooltips. -->
    <aside
      class="sidebar fixed lg:sticky top-0 z-50 h-screen w-64 flex-shrink-0 flex flex-col overflow-hidden
             transition-[transform,width] duration-300 lg:translate-x-0
             bg-gradient-to-b from-[#0d2550] via-[#0B2A5C] to-[#06183A] text-white border-r border-white/[0.06]"
      :class="[sidebarOpen ? 'translate-x-0' : '-translate-x-full', collapsed ? 'lg:w-[4.75rem] lg:overflow-visible' : 'lg:w-64']"
    >
      <!-- Texture: faint gold grid + a warm glow at the top -->
      <div class="absolute inset-0 bg-grid-gold opacity-[0.05] pointer-events-none" aria-hidden="true" />
      <div class="absolute -top-24 -left-16 h-56 w-56 rounded-full bg-brand-gold/20 blur-[80px] pointer-events-none" aria-hidden="true" />

      <!-- Logo -->
      <div class="relative h-20 flex items-center justify-center border-b border-white/[0.08]" :class="collapsed ? 'lg:px-2 px-6' : 'px-6'">
        <NuxtLink to="/" class="flex items-center" aria-label="RealtyLink PH home">
          <AppLogo :size="collapsed ? 'sm' : 'md'" :on-dark="true" />
        </NuxtLink>
      </div>

      <!-- Nav. Collapsed, it can't scroll-clip: the tooltips hang outside the rail. -->
      <nav class="relative flex-1 py-5" :class="collapsed ? 'px-4 lg:px-3 lg:overflow-visible overflow-y-auto' : 'px-4 overflow-y-auto no-scrollbar'" aria-label="Dashboard">
        <div v-for="(g, gi) in navGroups" :key="g.title" :class="gi ? 'mt-5' : ''">
          <p class="px-3 mb-1.5 text-[0.625rem] font-bold uppercase tracking-[0.2em] text-brand-gold/70" :class="collapsed ? 'lg:hidden' : ''">{{ g.title }}</p>
          <div v-if="collapsed && gi" class="hidden lg:block mx-auto mb-3 h-px w-6 bg-white/10" aria-hidden="true" />
          <div class="space-y-1">
            <NuxtLink
              v-for="link in g.links"
              :key="link.href"
              :to="link.href"
              class="nav-item group relative flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium"
              :class="[
                isActive(link.href)
                  ? 'bg-gradient-to-r from-brand-gold to-brand-gold-light text-[#0B2A5C] font-semibold shadow-[0_6px_18px_-6px_rgba(212,165,74,0.7)]'
                  : 'text-white/70 hover:text-white hover:bg-white/[0.07]',
                collapsed ? 'lg:justify-center lg:px-0' : '',
              ]"
              :aria-current="isActive(link.href) ? 'page' : undefined"
              :aria-label="collapsed ? link.label : undefined"
              @click="closeAll"
            >
              <!-- Active marker on the left edge -->
              <span v-if="isActive(link.href)" class="absolute -left-4 top-1/2 -translate-y-1/2 h-6 w-1 rounded-r-full bg-brand-gold" :class="collapsed ? 'lg:-left-3' : ''" aria-hidden="true" />
              <span class="relative flex-shrink-0">
                <svg class="nav-icon h-5 w-5" :class="isActive(link.href) ? '' : 'text-white/55 group-hover:text-brand-gold'" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" :d="ICONS[link.icon]" />
                </svg>
                <!-- Collapsed: counts shrink to a dot on the icon -->
                <span
                  v-if="collapsed && countFor(link.href)"
                  class="hidden lg:block absolute -top-1 -right-1 h-2.5 w-2.5 rounded-full ring-2 ring-[#0B2A5C]"
                  :class="loudCount(link.href) ? 'bg-brand-gold' : 'bg-white/60'"
                  aria-hidden="true"
                />
              </span>
              <span class="nav-label flex-1 truncate" :class="collapsed ? 'lg:hidden' : ''">{{ link.label }}</span>
              <span
                v-if="countFor(link.href)"
                class="flex-shrink-0 min-w-[1.25rem] h-5 px-1.5 rounded-full text-[0.625rem] font-bold flex items-center justify-center tabular-nums"
                :class="[
                  isActive(link.href) ? 'bg-[#0B2A5C]/15 text-[#0B2A5C]'
                  : loudCount(link.href) ? 'bg-brand-gold text-brand-navy' : 'bg-white/10 text-white/75',
                  collapsed ? 'lg:hidden' : '',
                ]"
                :aria-label="`${countFor(link.href)}`"
              >{{ countFor(link.href) > 99 ? '99+' : countFor(link.href) }}</span>
              <span v-if="collapsed" :class="tip">{{ link.label }}<template v-if="countFor(link.href)"> · {{ countFor(link.href) }}</template></span>
            </NuxtLink>
          </div>
        </div>

        <!-- Agent: Get Verified prompt -->
        <template v-if="!authStore.isAdmin && !authStore.isVerifiedAgent && authStore.isAgent">
          <div class="my-4 h-px bg-white/10" />
          <NuxtLink
            to="/dashboard/verify"
            class="group relative flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold text-brand-gold hover:bg-brand-gold/10 transition-colors"
            :class="collapsed ? 'lg:justify-center lg:px-0' : ''"
            @click="closeAll"
          >
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
            <span :class="collapsed ? 'lg:hidden' : ''">Get Verified</span>
            <span v-if="collapsed" :class="tip">Get Verified</span>
          </NuxtLink>
        </template>
      </nav>

      <!-- Bottom: agent onboarding (buyers), the user, collapse -->
      <div class="relative p-3 space-y-2 border-t border-white/[0.08]" :class="collapsed ? 'lg:px-2.5' : ''">
        <!-- Become an Agent -->
        <NuxtLink
          v-if="canBecomeAgent"
          to="/dashboard/verify"
          class="group relative flex items-center gap-3 rounded-2xl transition bg-brand-gold/[0.12] ring-1 ring-brand-gold/40 hover:ring-brand-gold hover:bg-brand-gold/20"
          :class="collapsed ? 'lg:justify-center lg:p-2 p-3' : 'p-3'"
          @click="closeAll"
        >
          <span class="h-9 w-9 flex-none rounded-xl flex items-center justify-center bg-brand-gold text-brand-navy shadow-[0_4px_12px_rgba(212,165,74,0.35)]">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
            </svg>
          </span>
          <span class="min-w-0 flex-1" :class="collapsed ? 'lg:hidden' : ''">
            <span class="block text-sm font-bold text-white">Become an agent</span>
            <span class="block text-[0.6875rem] leading-snug text-white/55">List homes with your PRC licence</span>
          </span>
          <svg class="h-4 w-4 flex-none text-brand-gold transition-transform group-hover:translate-x-0.5" :class="collapsed ? 'lg:hidden' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
          <span v-if="collapsed" :class="tip">Become an agent</span>
        </NuxtLink>

        <!-- The signed-in user, with the agent application status as a chip -->
        <NuxtLink
          :to="authStore.isAdmin ? '/admin' : '/dashboard/profile'"
          class="group relative flex items-center gap-3 rounded-2xl p-2 hover:bg-white/[0.06] transition"
          :class="collapsed ? 'lg:justify-center' : ''"
          @click="closeAll"
        >
          <span class="relative flex-none">
            <AppAvatar :name="authStore.user?.name" :src="authStore.user?.avatar" size="sm" class="ring-2 ring-brand-gold/60" />
            <span
              v-if="agentPending || agentRejected"
              class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full ring-2 ring-[#0a2350]"
              :class="agentPending ? 'bg-amber-400' : 'bg-red-500'"
              aria-hidden="true"
            />
          </span>
          <span class="min-w-0 flex-1" :class="collapsed ? 'lg:hidden' : ''">
            <span class="block truncate text-sm font-semibold text-white">{{ authStore.user?.name }}</span>
            <span v-if="agentPending" class="mt-0.5 inline-flex items-center gap-1 rounded-full bg-amber-400/15 px-2 py-0.5 text-[0.625rem] font-bold text-amber-300">
              <span class="h-1.5 w-1.5 rounded-full bg-amber-400 animate-pulse motion-reduce:animate-none" /> Agent application pending
            </span>
            <span v-else-if="agentRejected" class="mt-0.5 inline-flex items-center rounded-full bg-red-500/15 px-2 py-0.5 text-[0.625rem] font-bold text-red-300">Application not approved</span>
            <span v-else class="block text-[0.6875rem] text-white/50">{{ roleLabel }}</span>
          </span>
          <span v-if="collapsed" :class="tip">{{ authStore.user?.name }}<template v-if="agentPending"> · application pending</template></span>
        </NuxtLink>

        <button
          type="button"
          class="group relative hidden lg:flex items-center gap-2 w-full rounded-xl py-2 text-xs font-semibold text-white/50 hover:text-white hover:bg-white/[0.06] transition"
          :class="collapsed ? 'justify-center' : 'px-3'"
          :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
          :aria-expanded="!collapsed"
          @click="collapsed = !collapsed"
        >
          <svg class="h-[1.125rem] w-[1.125rem] transition-transform duration-300" :class="collapsed ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <rect x="3.75" y="4.5" width="16.5" height="15" rx="2.25" />
            <path stroke-linecap="round" d="M9 4.5v15M15.75 10.5L13.5 12l2.25 1.5" />
          </svg>
          <span v-if="!collapsed">Collapse</span>
          <span v-if="collapsed" :class="tip">Expand</span>
        </button>
      </div>
    </aside>

    <!-- Mobile backdrop -->
    <Transition name="fade">
      <div v-if="sidebarOpen" class="fixed inset-0 z-[45] bg-black/40 lg:hidden" @click="sidebarOpen = false" />
    </Transition>

    <!-- ═══════════ Main column ═══════════ -->
    <div class="flex-1 min-w-0 flex flex-col">

      <!-- Topbar -->
      <header class="sticky top-0 z-40 h-20 bg-white/90 dark:bg-[#0d1f3c]/90 backdrop-blur-xl border-b border-gray-100 dark:border-white/10 flex items-center gap-3 px-4 sm:px-6 transition-colors">
        <!-- Back (phones/tablets, inner pages) -->
        <button
          v-if="showBack"
          type="button"
          class="lg:hidden -ml-1 h-10 w-10 flex-none flex items-center justify-center rounded-full text-brand-navy dark:text-white/85
                 ring-1 ring-brand-navy/10 dark:ring-white/15 hover:bg-gray-100 dark:hover:bg-white/10 active:scale-95 transition"
          aria-label="Go back"
          @click="goBack"
        >
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
          </svg>
        </button>

        <!-- Mobile menu toggle -->
        <button
          class="lg:hidden p-2 text-brand-navy dark:text-white/80 rounded-lg hover:bg-gray-100 dark:hover:bg-white/10"
          :class="showBack ? '' : '-ml-1'"
          aria-label="Open menu"
          @click="sidebarOpen = !sidebarOpen"
        >
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        </button>

        <!-- Search (Ctrl K) -->
        <button
          type="button"
          class="hidden sm:flex mx-auto w-full max-w-md items-center gap-3 h-11 rounded-full px-4 text-sm
                 bg-gray-100/80 text-brand-navy/45 ring-1 ring-transparent hover:ring-brand-gold/50 hover:bg-gray-100
                 dark:bg-white/[0.06] dark:text-white/45 dark:hover:bg-white/[0.09] transition"
          @click="cmdkOpen = true"
        >
          <svg class="h-[1.125rem] w-[1.125rem] flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
          <span class="flex-1 text-left truncate">{{ authStore.isAdmin ? 'Search pages…' : 'Search listings, chats, pages…' }}</span>
          <kbd class="flex-none rounded-md bg-white dark:bg-white/10 px-1.5 py-0.5 text-[0.625rem] font-semibold text-brand-navy/55 dark:text-white/55 ring-1 ring-brand-navy/10 dark:ring-white/10">Ctrl K</kbd>
        </button>

        <!-- Right actions -->
        <div class="ml-auto sm:ml-0 flex items-center gap-1 sm:gap-1.5">
          <button type="button" :class="[topIcon, topIconIdle]" class="sm:hidden" aria-label="Search" @click="cmdkOpen = true">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
          </button>

          <!-- RealtyLink AI (buyers: home finder; agents: listing assistant) — the mascot's face, ringed gold while open -->
          <button
            v-if="authStore.isBuyer || authStore.isAgent"
            type="button"
            :class="[topIcon, panel === 'assistant' ? 'bg-brand-gold/15' : 'hover:bg-gray-100 dark:hover:bg-white/10']"
            aria-label="RealtyLink AI"
            title="Ask RealtyLink AI"
            :aria-pressed="panel === 'assistant'"
            @click="openPanel('assistant')"
          >
            <img
              src="/ai/avatar-96.webp"
              alt=""
              class="h-8 w-8 rounded-full object-cover bg-white transition"
              :class="panel === 'assistant' ? 'ring-2 ring-brand-gold' : 'ring-1 ring-brand-navy/15 dark:ring-white/25'"
            />
            <span class="absolute bottom-1.5 right-1.5 h-2.5 w-2.5 rounded-full bg-emerald-500 ring-2 ring-white dark:ring-[#0d1f3c]" aria-hidden="true" />
          </button>

          <!-- Messages (chats) — admins don't chat -->
          <button
            v-if="canChat"
            type="button"
            :class="[topIcon, panel === 'messages' ? topIconOn : topIconIdle]"
            :aria-label="messagesBadge ? `Messages, ${messagesBadge} unread` : 'Messages'"
            :aria-pressed="panel === 'messages'"
            @click="openPanel('messages')"
          >
            <svg v-if="panel !== 'messages'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
            </svg>
            <svg v-else class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M4.913 2.658c2.075-.27 4.19-.408 6.337-.408 2.147 0 4.262.139 6.337.408 1.922.25 3.291 1.861 3.405 3.727a4.403 4.403 0 00-1.032-.211 50.89 50.89 0 00-8.42 0c-2.358.196-4.04 2.19-4.04 4.434v4.286a4.47 4.47 0 002.433 3.984L7.28 21.53A.75.75 0 016 21v-4.03a48.527 48.527 0 01-1.087-.128C2.905 16.58 1.5 14.833 1.5 12.862V6.638c0-1.97 1.405-3.718 3.413-3.979z" />
              <path d="M15.75 7.5c-1.376 0-2.739.057-4.086.169C10.124 7.797 9 9.103 9 10.609v4.285c0 1.507 1.128 2.814 2.67 2.94 1.243.102 2.5.157 3.768.165l2.782 2.781a.75.75 0 001.28-.53v-2.39l.33-.026c1.542-.125 2.67-1.433 2.67-2.94v-4.286c0-1.505-1.125-2.811-2.664-2.94A49.392 49.392 0 0015.75 7.5z" />
            </svg>
            <span v-if="messagesBadge" class="absolute top-1.5 right-1.5 h-4 min-w-[1rem] px-1 rounded-full bg-brand-gold text-[0.5625rem] font-bold text-brand-navy flex items-center justify-center ring-2 ring-white dark:ring-[#0d1f3c]">
              {{ messagesBadge > 9 ? '9+' : messagesBadge }}
            </span>
          </button>

          <!-- Notifications (everything except chats) -->
          <button
            type="button"
            :class="[topIcon, panel === 'notifications' ? topIconOn : topIconIdle]"
            :aria-label="notifStore.bellUnread ? `Notifications, ${notifStore.bellUnread} unread` : 'Notifications'"
            :aria-pressed="panel === 'notifications'"
            @click="openPanel('notifications')"
          >
            <svg class="h-5 w-5" :fill="panel === 'notifications' ? 'currentColor' : 'none'" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
            </svg>
            <span v-if="notifStore.bellUnread" class="absolute top-1.5 right-1.5 h-4 min-w-[1rem] px-1 rounded-full bg-brand-gold text-[0.5625rem] font-bold text-brand-navy flex items-center justify-center ring-2 ring-white dark:ring-[#0d1f3c]">
              {{ notifStore.bellUnread > 9 ? '9+' : notifStore.bellUnread }}
            </span>
          </button>

          <!-- Account menu (shared with the public navbar) -->
          <UserMenu v-model:open="userOpen" show-theme />
        </div>
      </header>

      <!-- Content + docked panel -->
      <div class="flex-1 flex min-h-0">
        <main ref="mainEl" class="flex-1 min-w-0 p-5 sm:p-8" :class="cwClasses">
          <slot />
        </main>

        <!-- Desktop: docked beside the content (which narrows). Phone: a
             full-screen sheet. One instance either way. -->
        <Transition name="dock">
          <aside
            v-if="panel"
            class="fixed inset-0 z-50 flex flex-col bg-[#ffffff] dark:bg-[#0b1f45]
                   lg:sticky lg:top-20 lg:inset-auto lg:z-10 lg:self-start lg:h-[calc(100vh-5rem)] lg:w-[24rem] lg:flex-none
                   lg:border-l border-gray-200 dark:border-white/10 lg:shadow-[-20px_0_40px_-30px_rgba(11,42,92,0.35)]"
            :aria-label="panelLabel"
          >
            <AgentAiChat v-if="panel === 'assistant' && authStore.isAgent" closable @close="closePanel" />
            <DashAssistantPanel v-else-if="panel === 'assistant'" @close="closePanel" />
            <DashNotificationsPanel v-else-if="panel === 'notifications'" @close="closePanel" />
            <DashMessagesPanel v-else @close="closePanel" />
          </aside>
        </Transition>
      </div>
    </div>

    <DashCommandPalette v-model:open="cmdkOpen" :pages="cmdkPages" />
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.dropdown-enter-active { transition: all 0.18s cubic-bezier(0.16,1,0.3,1); }
.dropdown-leave-active { transition: all 0.12s ease; }
.dropdown-enter-from, .dropdown-leave-to { opacity: 0; transform: scale(0.96) translateY(-6px); }

.dock-enter-active { transition: opacity 0.22s ease, transform 0.28s cubic-bezier(0.22, 1, 0.36, 1); }
.dock-leave-active { transition: opacity 0.15s ease, transform 0.18s ease; }
.dock-enter-from, .dock-leave-to { opacity: 0; transform: translateX(24px); }
@media (prefers-reduced-motion: reduce) {
  .dock-enter-active, .dock-leave-active { transition: none; }
}
/* Sidebar items: a small slide on hover; the icon warms to gold. */
.nav-item { transition: background-color 0.18s ease, color 0.18s ease, transform 0.18s ease, box-shadow 0.25s ease; }
.nav-item:not([aria-current]):hover { transform: translateX(3px); }
.nav-icon { transition: color 0.18s ease, transform 0.25s cubic-bezier(0.22, 1, 0.36, 1); }
.nav-item:hover .nav-icon { transform: scale(1.08); }
/* The active marker grows in when you land on a page. */
.nav-item[aria-current] > span:first-child { animation: nav-mark 0.3s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes nav-mark { from { transform: translateY(-50%) scaleY(0.2); opacity: 0; } to { transform: translateY(-50%) scaleY(1); opacity: 1; } }
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }
@media (prefers-reduced-motion: reduce) {
  .nav-item, .nav-icon { transition: none; }
  .nav-item:not([aria-current]):hover { transform: none; }
  .nav-item[aria-current] > span:first-child { animation: none; }
}
</style>
