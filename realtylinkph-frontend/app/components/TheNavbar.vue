<script setup lang="ts">
const authStore  = useAuthStore()
const notifStore = useNotificationStore()
const route      = useRoute()
const { logout } = useAuth()
const authModal  = useAuthModal()

const menuOpen  = ref(false)
const userOpen  = ref(false)
const notifOpen = ref(false)

const navLinks = [
  { label: 'Buy',          href: '/properties?type=house' },
  { label: 'Sell',         href: '/dashboard/listings/new' },
  { label: 'Rent',         href: '/properties?type=apartment' },
  { label: 'About',        href: '#about' },
  { label: 'Testimonials', href: '#testimonials' },
]

/* ── Scroll state + reading progress ─────────────────────────────────────── */
const scrolled = ref(false)
const progress = ref(0)

function onScroll() {
  scrolled.value = window.scrollY > 20
  const max = document.documentElement.scrollHeight - window.innerHeight
  progress.value = max > 0 ? Math.min(100, (window.scrollY / max) * 100) : 0
}

/**
 * At the top of the page the bar goes transparent so the page's own ruled grid
 * runs unbroken behind it. Past 20px of scroll it takes on a solid surface.
 *
 * Only routes that open on the light grid ground may do this. The agent
 * directory still opens with a dark navy band that stays dark even in light
 * mode — leaving the bar transparent there puts navy nav links on a navy
 * header and makes them invisible.
 *
 * Keep this list in step with the pages that actually render `bg-grid-lines`
 * at the top of the page. An entry also covers its sub-paths, so `/properties`
 * takes in `/properties/7`.
 */
const gridBackedRoutes = ['/', '/properties']
const transparentAllowed = computed(() =>
  gridBackedRoutes.some(r => route.path === r || (r !== '/' && route.path.startsWith(r + '/'))),
)

/*
 * Colours are driven by Tailwind `dark:` variants, not a JS flag.
 *
 * They used to key off a computed derived from `useTheme()`, whose state is
 * 'light' during SSR — so in dark mode the server emitted light-mode classes and
 * navy text landed on a dark ground with the links effectively invisible. The
 * `dark` class on <html> is set by the pre-paint script, so variants are correct
 * from the first frame with no hydration gap.
 */

onMounted(() => {
  window.addEventListener('scroll', onScroll, { passive: true })
  onScroll()
})
onUnmounted(() => window.removeEventListener('scroll', onScroll))

function closeAll() {
  menuOpen.value  = false
  userOpen.value  = false
  notifOpen.value = false
}

watch(() => route.path, closeAll)

function isActive(href: string): boolean {
  if (href.startsWith('#')) return false
  return route.fullPath === href
}

/* ── Magnetic CTA ────────────────────────────────────────────────────────── */
const ctaEl = ref<HTMLElement | null>(null)
const ctaOffset = reactive({ x: 0, y: 0 })
let magnetic = true

onMounted(() => {
  magnetic = !(window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false)
})

function onCtaMove(e: MouseEvent) {
  if (!magnetic || !ctaEl.value) return
  const r = ctaEl.value.getBoundingClientRect()
  // Pull the button a little toward the cursor — capped so it never feels loose.
  ctaOffset.x = ((e.clientX - (r.left + r.width / 2)) / r.width) * 12
  ctaOffset.y = ((e.clientY - (r.top + r.height / 2)) / r.height) * 8
}
function onCtaLeave() {
  ctaOffset.x = 0
  ctaOffset.y = 0
}

/* Shared dropdown surface styling (light + dark). */
const panelClass =
  'bg-white dark:bg-brand-navy-mid rounded-2xl shadow-[0_8px_40px_rgba(0,0,0,0.18)] ' +
  'border border-gray-100 dark:border-white/10 overflow-hidden'
</script>

<template>
  <header
    class="fixed top-0 inset-x-0 z-40 transition-[background-color,box-shadow,backdrop-filter] duration-300"
    :class="(scrolled || !transparentAllowed)
      ? 'bg-white/95 dark:bg-brand-navy/95 backdrop-blur-xl shadow-[0_4px_30px_rgba(8,21,47,0.10)] dark:shadow-black/30'
      : 'bg-transparent'"
  >
    <!-- Trust bar — collapses away once you start scrolling to reclaim height -->
    <div
      class="overflow-hidden transition-[max-height,opacity] duration-300"
      :class="scrolled ? 'max-h-0 opacity-0' : 'max-h-10 opacity-100'"
    >
      <TheTopBar />
    </div>

    <!-- Reading progress -->
    <div
      class="absolute top-0 left-0 h-[2px] bg-gradient-to-r from-brand-gold via-brand-gold-light to-brand-gold transition-[width] duration-150 ease-out"
      :style="{ width: progress + '%' }"
      aria-hidden="true"
    />

    <div class="max-w-content mx-auto px-4 h-20 relative flex items-center">

      <!-- Left: Logo -->
      <NuxtLink to="/" class="flex-shrink-0 group" aria-label="RealtyLink PH home" @click="closeAll">
        <AppLogo size="md" />
      </NuxtLink>

      <!-- Center: Desktop nav -->
      <nav class="hidden md:flex absolute left-1/2 -translate-x-1/2 items-center gap-1">
        <NuxtLink
          v-for="link in navLinks"
          :key="link.href"
          :to="link.href"
          class="nav-underline px-4 py-2 text-sm font-medium transition-colors duration-200
                 text-brand-navy/70 hover:text-brand-navy
                 dark:text-white/75 dark:hover:text-white"
          :class="isActive(link.href) ? 'is-active' : ''"
        >
          {{ link.label }}
        </NuxtLink>
      </nav>

      <!-- Right -->
      <div class="ml-auto flex items-center gap-2 sm:gap-3">

        <ThemeToggle />

        <!-- Notification bell -->
        <div v-if="authStore.isAuthenticated" class="relative">
          <button
            class="relative p-2.5 rounded-xl transition-colors
                   text-brand-navy/60 hover:text-brand-navy hover:bg-brand-navy/5
                   dark:text-white/70 dark:hover:text-white dark:hover:bg-white/10"
            aria-label="Notifications"
            @click="notifOpen = !notifOpen; userOpen = false"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
            <span
              v-if="notifStore.unreadCount > 0"
              class="absolute top-1 right-1 h-4 w-4 bg-brand-gold rounded-full text-[0.625rem] font-bold text-brand-navy flex items-center justify-center"
            >
              {{ notifStore.unreadCount > 9 ? '9+' : notifStore.unreadCount }}
            </span>
            <span
              v-if="notifStore.unreadCount > 0"
              class="absolute top-1 right-1 h-4 w-4 rounded-full bg-brand-gold animate-pulse-ring pointer-events-none"
              aria-hidden="true"
            />
          </button>

          <Transition name="dropdown">
            <div v-if="notifOpen" class="absolute right-0 mt-2 w-80 z-50" :class="panelClass">
              <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100 dark:border-white/10">
                <span class="text-sm font-semibold text-brand-navy dark:text-white">Notifications</span>
                <button class="text-xs text-brand-gold font-medium hover:underline" @click="notifStore.markAllRead()">
                  Mark all read
                </button>
              </div>
              <div class="max-h-72 overflow-y-auto">
                <p v-if="!notifStore.notifications.length" class="text-sm text-brand-text-secondary dark:text-white/50 text-center py-6">
                  No notifications yet
                </p>
                <button
                  v-for="n in notifStore.notifications.slice(0, 10)"
                  :key="n.id"
                  class="w-full text-left px-4 py-3 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors border-b border-gray-50 dark:border-white/5 last:border-0"
                  :class="{ 'bg-brand-gold/5': !n.read_at }"
                  @click="notifStore.markRead(n.id); notifOpen = false"
                >
                  <p class="text-xs font-medium text-brand-text-primary dark:text-white/90 line-clamp-2">
                    {{ (n.payload as Record<string, string>).message ?? n.type }}
                  </p>
                  <p class="text-[0.625rem] text-brand-text-light dark:text-white/40 mt-0.5">
                    {{ new Date(n.created_at).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) }}
                  </p>
                </button>
              </div>
            </div>
          </Transition>
        </div>

        <!-- User menu -->
        <div v-if="authStore.isAuthenticated" class="relative">
          <button
            class="flex items-center gap-2 p-1.5 rounded-xl transition-colors
                   text-brand-navy hover:bg-brand-navy/5
                   dark:text-white/80 dark:hover:bg-white/10"
            @click="userOpen = !userOpen; notifOpen = false"
          >
            <AppAvatar :name="authStore.user?.name" :src="authStore.user?.avatar" size="sm" />
            <svg class="h-4 w-4 hidden md:block transition-transform duration-200" :class="userOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
          </button>

          <Transition name="dropdown">
            <div v-if="userOpen" class="absolute right-0 mt-2 w-52 z-50" :class="panelClass">
              <div class="px-4 py-3 border-b border-gray-100 dark:border-white/10">
                <p class="text-sm font-semibold text-brand-text-primary dark:text-white truncate">{{ authStore.user?.name }}</p>
                <p class="text-xs text-brand-text-secondary dark:text-white/50 truncate">{{ authStore.user?.email }}</p>
              </div>
              <nav class="py-1">
                <NuxtLink to="/dashboard/appointments" class="flex items-center px-4 py-2.5 text-sm text-brand-text-primary dark:text-white/85 hover:bg-gray-50 dark:hover:bg-white/5 hover:text-brand-gold transition-colors" @click="closeAll">
                  Dashboard
                </NuxtLink>
                <NuxtLink v-if="authStore.isAgent" to="/dashboard/listings" class="flex items-center px-4 py-2.5 text-sm text-brand-text-primary dark:text-white/85 hover:bg-gray-50 dark:hover:bg-white/5 hover:text-brand-gold transition-colors" @click="closeAll">
                  My Listings
                </NuxtLink>
                <NuxtLink to="/dashboard/messages" class="flex items-center px-4 py-2.5 text-sm text-brand-text-primary dark:text-white/85 hover:bg-gray-50 dark:hover:bg-white/5 hover:text-brand-gold transition-colors" @click="closeAll">
                  Messages
                </NuxtLink>
                <NuxtLink v-if="authStore.isAdmin" to="/admin" class="flex items-center px-4 py-2.5 text-sm text-brand-text-primary dark:text-white/85 hover:bg-gray-50 dark:hover:bg-white/5 hover:text-brand-gold transition-colors" @click="closeAll">
                  Admin Panel
                </NuxtLink>
                <hr class="my-1 border-gray-100 dark:border-white/10" />
                <button class="flex items-center w-full px-4 py-2.5 text-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors" @click="logout">
                  Sign out
                </button>
              </nav>
            </div>
          </Transition>
        </div>

        <!-- Guest buttons -->
        <template v-else>
          <button
            type="button"
            class="hidden md:block text-sm font-medium transition-colors px-3 py-2 rounded-lg
                   text-brand-navy/75 hover:text-brand-navy hover:bg-brand-navy/5
                   dark:text-white/80 dark:hover:text-white dark:hover:bg-white/10"
            @click="authModal.open('login')"
          >
            Sign in
          </button>
          <button
            ref="ctaEl"
            type="button"
            class="relative overflow-hidden bg-brand-gold text-brand-navy font-bold text-sm px-5 py-2.5 rounded-xl
                   shadow-[0_4px_16px_rgba(201,162,39,0.35)] hover:shadow-[0_8px_28px_rgba(201,162,39,0.55)]
                   transition-[box-shadow,transform] duration-200 group/cta"
            :style="{ transform: `translate(${ctaOffset.x}px, ${ctaOffset.y}px)` }"
            @mousemove="onCtaMove"
            @mouseleave="onCtaLeave"
            @click="authModal.open('register')"
          >
            <span class="relative z-10">Get started</span>
            <!-- sheen sweep on hover -->
            <span
              class="absolute inset-0 -translate-x-full group-hover/cta:translate-x-full transition-transform duration-700 ease-out
                     bg-gradient-to-r from-transparent via-white/50 to-transparent"
              aria-hidden="true"
            />
          </button>
        </template>

        <!-- Mobile hamburger -->
        <button
          class="md:hidden p-2 rounded-lg transition-colors
                 text-brand-navy/75 hover:bg-brand-navy/5
                 dark:text-white/80 dark:hover:bg-white/10"
          aria-label="Menu"
          @click="menuOpen = !menuOpen; userOpen = false"
        >
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path v-if="!menuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Mobile menu -->
    <Transition name="slide-down">
      <div
        v-if="menuOpen"
        class="md:hidden bg-white/95 dark:bg-brand-navy/95 backdrop-blur-xl border-t border-gray-100 dark:border-white/10 px-4 py-3 space-y-1"
      >
        <NuxtLink
          v-for="(link, i) in navLinks"
          :key="link.href"
          :to="link.href"
          class="block px-3 py-2.5 text-sm font-medium rounded-lg transition-colors
                 text-brand-navy/80 dark:text-white/75 hover:text-brand-navy dark:hover:text-white
                 hover:bg-brand-navy/5 dark:hover:bg-white/10"
          :style="{ animationDelay: `${i * 40}ms` }"
          @click="closeAll"
        >
          {{ link.label }}
        </NuxtLink>
        <template v-if="!authStore.isAuthenticated">
          <button
            type="button"
            class="block w-full text-left px-3 py-2.5 text-sm font-medium rounded-lg
                   text-brand-navy/80 dark:text-white/75 hover:bg-brand-navy/5 dark:hover:bg-white/10"
            @click="closeAll(); authModal.open('login')"
          >
            Sign in
          </button>
          <button
            type="button"
            class="block w-full text-left px-3 py-2.5 text-sm font-bold text-brand-gold rounded-lg hover:bg-brand-gold/10"
            @click="closeAll(); authModal.open('register')"
          >
            Create account
          </button>
        </template>
      </div>
    </Transition>
  </header>
</template>

<style scoped>
.slide-down-enter-active,
.slide-down-leave-active { transition: all 0.24s cubic-bezier(0.22, 1, 0.36, 1); }
.slide-down-enter-from,
.slide-down-leave-to { opacity: 0; transform: translateY(-10px); }

.dropdown-enter-active { transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1); }
.dropdown-leave-active { transition: all 0.12s ease; }
.dropdown-enter-from,
.dropdown-leave-to { opacity: 0; transform: scale(0.96) translateY(-6px); }
</style>
