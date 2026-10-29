<script setup lang="ts">
import type { ThemeMode } from '~/composables/useTheme'

/**
 * The signed-in account menu, shared by the dashboard header and the public
 * navbar so both look and behave the same: who you are (photo, name, email,
 * role), the links that matter for your role, an optional theme switch, and
 * Sign out. Closes on an outside click, Escape, or a route change.
 *
 *   full    — avatar + name + role on the trigger (dashboard header)
 *   compact — avatar + chevron only (public navbar)
 */
const props = withDefaults(defineProps<{ variant?: 'full' | 'compact'; showTheme?: boolean }>(), { variant: 'full', showTheme: false })
const open = defineModel<boolean>('open', { default: false })

const authStore = useAuthStore()
const route = useRoute()
const { logout } = useAuth()
const { theme, setTheme } = useTheme()

const root = ref<HTMLElement | null>(null)

const role = computed(() => (authStore.isAdmin
  ? { label: 'Administrator', cls: 'bg-brand-navy/[0.08] text-brand-navy dark:bg-white/10 dark:text-white' }
  : authStore.isAgent
    ? { label: 'Verified agent', cls: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' }
    : { label: 'Buyer', cls: 'bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold' }))

const I = {
  home:     'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
  user:     'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z',
  calendar: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',
  heart:    'M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z',
  listings: 'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21',
  help:     'M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z',
  shield:   'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
  chat:     'M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z',
  signout:  'M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9',
}

/* The links that matter for this role. On public pages Dashboard comes first. */
const links = computed(() => {
  const onDashboard = route.path.startsWith('/dashboard') || route.path.startsWith('/admin')
  if (authStore.isAdmin) return [{ to: '/admin', label: 'Admin dashboard', icon: I.shield }]
  const home = { to: '/dashboard', label: 'Dashboard', icon: I.home }
  const items = authStore.isAgent
    ? [
        { to: '/dashboard/profile', label: 'My Profile', icon: I.user },
        { to: '/dashboard/listings', label: 'My Listings', icon: I.listings },
        { to: '/dashboard/appointments', label: 'Appointments', icon: I.calendar },
      ]
    : [
        { to: '/dashboard/profile', label: 'My Profile', icon: I.user },
        { to: '/dashboard/appointments', label: 'My Appointments', icon: I.calendar },
        { to: '/dashboard/saved', label: 'Saved homes', icon: I.heart },
      ]
  if (!onDashboard) items.splice(1, 0, { to: '/dashboard?panel=messages', label: 'Messages', icon: I.chat })
  return [...(onDashboard ? [] : [home]), ...items, { to: '/dashboard/support', label: 'Help & support', icon: I.help }]
})

const THEMES: Array<{ mode: ThemeMode; label: string }> = [
  { mode: 'light', label: 'Light' }, { mode: 'dark', label: 'Dark' }, { mode: 'system', label: 'Device' },
]

function onDocClick(e: MouseEvent) {
  if (open.value && root.value && !root.value.contains(e.target as Node)) open.value = false
}
function onKey(e: KeyboardEvent) { if (e.key === 'Escape' && open.value) open.value = false }
onMounted(() => { document.addEventListener('click', onDocClick); document.addEventListener('keydown', onKey) })
onUnmounted(() => { document.removeEventListener('click', onDocClick); document.removeEventListener('keydown', onKey) })
watch(() => route.fullPath, () => { open.value = false })

function signOut() {
  open.value = false
  logout()
}

const item = 'group flex items-center gap-3 rounded-xl px-2.5 py-2 text-sm font-medium text-brand-navy hover:bg-brand-navy/[0.05] dark:text-white/85 dark:hover:bg-white/[0.06] transition'
const tile = 'h-8 w-8 flex-none rounded-lg flex items-center justify-center bg-brand-navy/[0.05] text-brand-navy/70 group-hover:bg-brand-gold/15 group-hover:text-brand-gold-deep dark:bg-white/[0.06] dark:text-white/70 dark:group-hover:text-brand-gold transition'
</script>

<template>
  <div ref="root" class="relative">
    <button
      type="button"
      class="flex items-center rounded-xl transition-colors hover:bg-brand-navy/[0.05] dark:hover:bg-white/10"
      :class="props.variant === 'full' ? 'gap-2.5 pl-2 pr-3 py-1.5' : 'gap-1.5 p-1.5'"
      :aria-expanded="open"
      aria-haspopup="menu"
      aria-label="Account menu"
      @click.stop="open = !open"
    >
      <AppAvatar :name="authStore.user?.name" :src="authStore.user?.avatar" size="sm" />
      <span v-if="props.variant === 'full'" class="hidden sm:block text-left">
        <span class="block text-xs font-semibold text-brand-navy dark:text-white leading-none truncate max-w-[120px]">{{ authStore.user?.name }}</span>
        <span class="block text-[0.625rem] text-brand-navy/50 dark:text-white/45 mt-1">{{ role.label }}</span>
      </span>
      <svg class="h-4 w-4 text-brand-navy/45 dark:text-white/45 transition-transform duration-200" :class="[open ? 'rotate-180' : '', props.variant === 'full' ? 'hidden sm:block' : 'hidden md:block']" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
    </button>

    <Transition name="um">
      <div
        v-if="open"
        role="menu"
        class="absolute right-0 top-full mt-2 z-50 w-[min(18rem,calc(100vw-1.5rem))] overflow-hidden rounded-2xl
               bg-[#ffffff] dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.08] dark:ring-white/10
               shadow-[0_24px_60px_-18px_rgba(11,42,92,0.45)] dark:shadow-[0_24px_60px_-18px_rgba(0,0,0,0.75)]"
      >
        <!-- Who -->
        <div class="flex items-center gap-3 px-4 pt-4 pb-3.5 border-b border-brand-navy/[0.06] dark:border-white/[0.08]">
          <AppAvatar :name="authStore.user?.name" :src="authStore.user?.avatar" size="md" />
          <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-brand-navy dark:text-white">{{ authStore.user?.name }}</p>
            <p class="truncate text-xs text-brand-navy/55 dark:text-white/50">{{ authStore.user?.email }}</p>
            <span class="mt-1.5 inline-flex rounded-full px-2 py-0.5 text-[0.625rem] font-bold uppercase tracking-wide" :class="role.cls">{{ role.label }}</span>
          </div>
        </div>

        <!-- Where to -->
        <nav class="p-1.5">
          <NuxtLink v-for="l in links" :key="l.to" :to="l.to" role="menuitem" :class="item" @click="open = false">
            <span :class="tile"><svg class="h-[1.125rem] w-[1.125rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="l.icon" /></svg></span>
            {{ l.label }}
          </NuxtLink>
        </nav>

        <!-- Theme -->
        <div v-if="showTheme" class="px-4 pb-3">
          <p class="mb-1.5 text-[0.625rem] font-bold uppercase tracking-[0.14em] text-brand-navy/45 dark:text-white/40">Theme</p>
          <div class="grid grid-cols-3 gap-1 rounded-xl bg-brand-navy/[0.05] dark:bg-white/[0.06] p-1">
            <button
              v-for="t in THEMES"
              :key="t.mode"
              type="button"
              class="rounded-lg py-1.5 text-xs font-semibold transition"
              :class="theme === t.mode ? 'bg-[#ffffff] text-brand-navy shadow-sm dark:bg-brand-gold dark:text-[#0B2A5C]' : 'text-brand-navy/60 hover:text-brand-navy dark:text-white/60 dark:hover:text-white'"
              :aria-pressed="theme === t.mode"
              @click="setTheme(t.mode)"
            >{{ t.label }}</button>
          </div>
        </div>

        <!-- Sign out -->
        <div class="p-1.5 border-t border-brand-navy/[0.06] dark:border-white/[0.08]">
          <button type="button" role="menuitem" class="group flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-sm font-semibold text-red-600 hover:bg-red-500/[0.08] dark:text-red-400 transition" @click="signOut">
            <span class="h-8 w-8 flex-none rounded-lg flex items-center justify-center bg-red-500/10"><svg class="h-[1.125rem] w-[1.125rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="I.signout" /></svg></span>
            Sign out
          </button>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.um-enter-active { transition: opacity 0.16s ease, transform 0.2s cubic-bezier(0.16, 1, 0.3, 1); }
.um-leave-active { transition: opacity 0.12s ease, transform 0.12s ease; }
.um-enter-from, .um-leave-to { opacity: 0; transform: translateY(-6px) scale(0.97); }
@media (prefers-reduced-motion: reduce) { .um-enter-active, .um-leave-active { transition: none; } }
</style>
