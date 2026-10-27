<script setup lang="ts">
// Google sign-in landing: Google redirects here with ?code=…; we exchange it
// for a session, then send the user to their dashboard.
definePageMeta({ layout: false })

import type { GoogleIntent } from '~/composables/useGoogleAuth'

const route     = useRoute()
const authStore = useAuthStore()
const { completeGoogleSignIn } = useGoogleAuth()

/*
 * The exchange is one request, so there's no real percentage to show. The bar
 * eases toward 90% while we wait (fast at first, slowing as it nears the cap),
 * the caption steps through what's happening, and on success it snaps to 100%
 * and the screen fades before we navigate — so finishing reads as finishing.
 */
const progress = ref(0)
const leaving  = ref(false)
const STEPS = ['Verifying with Google', 'Setting up your account', 'Almost there']
const step = computed(() => (progress.value < 35 ? 0 : progress.value < 70 ? 1 : 2))

let timer: ReturnType<typeof setInterval> | undefined
function startCreep() {
  timer = setInterval(() => {
    // ~50% after 3 s, ~75% after 6 s, easing off after that, so a slow
    // Google round-trip doesn't leave the bar parked at 90% for long.
    progress.value += Math.max(0.15, (90 - progress.value) * 0.035)
    if (progress.value >= 90) { progress.value = 90; clearInterval(timer) }
  }, 120)
}
onUnmounted(() => clearInterval(timer))

const sleep = (ms: number) => new Promise(r => setTimeout(r, ms))
async function finish(to: string) {
  clearInterval(timer)
  progress.value = 100
  await sleep(350)
  leaving.value = true
  await sleep(250)
  await navigateTo(to)
}

onMounted(async () => {
  startCreep()

  const code  = route.query.code  as string | undefined
  const error = route.query.error as string | undefined

  // Google echoes our `state` back untouched — it carries which button was
  // pressed, so an unregistered address is refused on sign-in but allowed to
  // create an account from the register form.
  const intent: GoogleIntent = route.query.state === 'register' ? 'register' : 'login'

  if (error || !code) {
    await navigateTo('/?auth=login&google=failed')
    return
  }

  const res = await completeGoogleSignIn(code, intent)
  if (!res.ok) {
    // No account for that Google address: send them to the create-account form
    // rather than looping them back through a sign-in they cannot complete.
    if (res.unregistered) {
      await navigateTo('/?auth=register&google=unregistered')
      return
    }
    // Couldn't reach Google — worth saying so, since retrying may just work.
    await navigateTo(res.unreachable
      ? `/?auth=${intent}&google=unreachable`
      : `/?auth=${intent}&google=failed`)
    return
  }

  await finish('/dashboard')
})
</script>

<template>
  <div
    class="relative min-h-screen overflow-hidden flex items-center justify-center px-6 bg-brand-cream dark:bg-brand-navy-deep transition-opacity duration-300"
    :class="leaving ? 'opacity-0' : 'opacity-100'"
  >
    <!-- Same ruled grid + gold wash as the landing page -->
    <div class="absolute inset-0 bg-grid-lines mask-radial-fade pointer-events-none" />
    <div class="absolute inset-0 bg-grid-lines-lg mask-radial-fade pointer-events-none" />
    <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 h-[28rem] w-[28rem] max-w-full rounded-full bg-brand-gold/15 dark:bg-brand-gold/[0.08] blur-[110px] pointer-events-none" />

    <div class="relative w-full max-w-xs flex flex-col items-center text-center" role="status" aria-live="polite">
      <!-- Logo: navy mark on light, light mark on dark -->
      <div class="cb-logo">
        <img src="/realtylinkphlogo-trim.png" alt="RealtyLinkPH" class="h-24 w-auto block dark:hidden" />
        <img src="/realtylinkphlogo-light-trim.png" alt="RealtyLinkPH" class="h-24 w-auto hidden dark:block" />
      </div>

      <p class="cb-rise mt-8 font-heading text-[1.375rem] font-semibold tracking-[-0.01em] text-brand-navy dark:text-white" style="animation-delay: 120ms">
        Preparing your experience
      </p>

      <!-- Progress bar -->
      <div
        class="cb-rise mt-5 h-1.5 w-full overflow-hidden rounded-full bg-brand-navy/10 dark:bg-white/10"
        style="animation-delay: 200ms"
        role="progressbar"
        aria-label="Signing in"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-valuenow="Math.round(progress)"
      >
        <div
          class="cb-bar relative h-full rounded-full bg-gradient-to-r from-brand-gold-deep via-brand-gold to-brand-gold-light transition-[width] duration-300 ease-out"
          :style="{ width: `${progress}%` }"
        />
      </div>

      <!-- What's happening now -->
      <div class="cb-rise mt-3 h-5 relative w-full" style="animation-delay: 260ms">
        <Transition name="cb-step" mode="out-in">
          <p :key="progress >= 100 ? 'done' : step" class="text-xs font-medium text-brand-navy/55 dark:text-white/55">
            {{ progress >= 100 ? 'Welcome to RealtyLinkPH' : `${STEPS[step]}…` }}
          </p>
        </Transition>
      </div>

      <p class="cb-rise mt-10 flex items-center gap-1.5 text-[0.6875rem] text-brand-navy/40 dark:text-white/35" style="animation-delay: 320ms">
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4" />
          <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853" />
          <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05" />
          <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335" />
        </svg>
        Signing in securely with Google
      </p>
    </div>
  </div>
</template>

<style scoped>
/* Logo settles in with a soft scale, then breathes gently while we wait. */
.cb-logo { animation: cb-logo-in 0.6s cubic-bezier(0.22, 1, 0.36, 1) both, cb-breathe 2.8s 0.6s ease-in-out infinite; }
@keyframes cb-logo-in { from { opacity: 0; transform: scale(0.92); } to { opacity: 1; transform: none; } }
@keyframes cb-breathe { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.03); } }

.cb-rise { animation: cb-rise 0.5s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes cb-rise { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }

/* A light glint runs along the filled part of the bar. */
.cb-bar::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.55), transparent);
  transform: translateX(-100%);
  animation: cb-glint 1.4s ease-in-out infinite;
}
@keyframes cb-glint { to { transform: translateX(100%); } }

.cb-step-enter-active, .cb-step-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.cb-step-enter-from { opacity: 0; transform: translateY(4px); }
.cb-step-leave-to   { opacity: 0; transform: translateY(-4px); }

@media (prefers-reduced-motion: reduce) {
  .cb-logo, .cb-rise, .cb-bar::after { animation: none; }
  .cb-step-enter-active, .cb-step-leave-active { transition: none; }
}
</style>
