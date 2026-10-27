<script setup lang="ts">
// OAuth landing page: Google redirects here with ?code=…; we exchange it for
// tokens, refresh the user, then bounce back to the profile's Connected apps.
definePageMeta({ layout: false })

const route = useRoute()
const { exchangeCode } = useGoogleCalendar()
const { fetchMe } = useAuth()

/*
 * Same screen as the Google sign-in (pages/auth/google/callback): the bar
 * eases toward 90% while we wait, the caption steps through what's happening,
 * then it fills and the screen fades into the profile.
 */
const progress = ref(0)
const leaving  = ref(false)
const STEPS = ['Verifying with Google', 'Linking your calendar', 'Almost there']
const step = computed(() => (progress.value < 35 ? 0 : progress.value < 70 ? 1 : 2))

let timer: ReturnType<typeof setInterval> | undefined
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
  timer = setInterval(() => {
    progress.value += Math.max(0.15, (90 - progress.value) * 0.035)
    if (progress.value >= 90) { progress.value = 90; clearInterval(timer) }
  }, 120)

  const code  = route.query.code  as string | undefined
  const error = route.query.error as string | undefined

  if (error || !code) {
    await navigateTo('/dashboard/profile?section=apps&gcal=error')
    return
  }

  const ok = await exchangeCode(code)
  if (ok) await fetchMe()
  if (ok) await finish('/dashboard/profile?section=apps&gcal=connected')
  else await navigateTo('/dashboard/profile?section=apps&gcal=error')
})
</script>

<template>
  <div
    class="relative min-h-screen overflow-hidden flex items-center justify-center px-6 bg-brand-cream dark:bg-brand-navy-deep transition-opacity duration-300"
    :class="leaving ? 'opacity-0' : 'opacity-100'"
  >
    <div class="absolute inset-0 bg-grid-lines mask-radial-fade pointer-events-none" />
    <div class="absolute inset-0 bg-grid-lines-lg mask-radial-fade pointer-events-none" />
    <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 h-[28rem] w-[28rem] max-w-full rounded-full bg-brand-gold/15 dark:bg-brand-gold/[0.08] blur-[110px] pointer-events-none" />

    <div class="relative w-full max-w-xs flex flex-col items-center text-center" role="status" aria-live="polite">
      <!-- RealtyLinkPH ⇄ Google Calendar -->
      <div class="gc-logo flex items-center gap-4">
        <img src="/realtylinkphlogo-trim.png" alt="RealtyLinkPH" class="h-20 w-auto block dark:hidden" />
        <img src="/realtylinkphlogo-light-trim.png" alt="RealtyLinkPH" class="h-20 w-auto hidden dark:block" />
        <span class="flex items-center gap-1 text-brand-gold-deep dark:text-brand-gold" aria-hidden="true">
          <span v-for="i in 3" :key="i" class="h-1.5 w-1.5 rounded-full bg-current gc-dot" :style="{ animationDelay: `${(i - 1) * 0.18}s` }" />
        </span>
        <span class="h-16 w-16 flex-none rounded-2xl bg-[#ffffff] ring-1 ring-brand-navy/10 shadow-[0_12px_28px_-14px_rgba(11,42,92,0.45)] flex items-center justify-center">
          <svg class="h-10 w-10" viewBox="0 0 48 48" role="img" aria-label="Google Calendar"><rect width="22" height="22" x="13" y="13" fill="#fff"/><polygon fill="#1e88e5" points="25.68,20.92 26.688,22.36 28.272,21.208 28.272,29.56 30,29.56 30,18.616 28.56,18.616"/><path fill="#1e88e5" d="M22.943,23.745c0.625-0.574,1.013-1.37,1.013-2.249c0-1.747-1.533-3.168-3.417-3.168c-1.602,0-2.972,1.009-3.33,2.453l1.657,0.421c0.165-0.664,0.868-1.146,1.673-1.146c0.942,0,1.709,0.646,1.709,1.44c0,0.794-0.767,1.44-1.709,1.44h-0.997v1.728h0.997c1.081,0,1.993,0.751,1.993,1.64c0,0.904-0.866,1.64-1.931,1.64c-0.962,0-1.784-0.61-1.914-1.418l-1.708,0.275c0.262,1.638,1.789,2.873,3.552,2.873c1.981,0,3.593-1.514,3.593-3.371C24.118,25.439,23.671,24.412,22.943,23.745z"/><polygon fill="#fbc02d" points="34,42 14,42 13,38 14,34 34,34 35,38"/><polygon fill="#4caf50" points="38,35 42,34 42,14 38,13 34,14 34,34"/><path fill="#1e88e5" d="M34,14l1-4l-1-4H9C7.343,6,6,7.343,6,9v25l4,1l4-1V14H34z"/><polygon fill="#e53935" points="34,34 34,42 42,34"/><polygon fill="#1565c0" points="39,6 34,6 34,14 42,14 42,9 41,6"/><polygon fill="#2e7d32" points="9,42 14,42 14,34 6,34 6,39 7,42"/></svg>
        </span>
      </div>

      <p class="gc-rise mt-8 font-heading text-[1.375rem] font-semibold tracking-[-0.01em] text-brand-navy dark:text-white" style="animation-delay: 120ms">
        Connecting your calendar
      </p>

      <div
        class="gc-rise mt-5 h-1.5 w-full overflow-hidden rounded-full bg-brand-navy/10 dark:bg-white/10"
        style="animation-delay: 200ms"
        role="progressbar"
        aria-label="Connecting Google Calendar"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-valuenow="Math.round(progress)"
      >
        <div class="gc-bar relative h-full rounded-full bg-gradient-to-r from-brand-gold-deep via-brand-gold to-brand-gold-light transition-[width] duration-300 ease-out" :style="{ width: `${progress}%` }" />
      </div>

      <div class="gc-rise mt-3 h-5 w-full" style="animation-delay: 260ms">
        <Transition name="gc-step" mode="out-in">
          <p :key="progress >= 100 ? 'done' : step" class="text-xs font-medium text-brand-navy/55 dark:text-white/55">
            {{ progress >= 100 ? 'Calendar connected' : `${STEPS[step]}…` }}
          </p>
        </Transition>
      </div>

      <p class="gc-rise mt-10 max-w-[17rem] text-[0.6875rem] leading-relaxed text-brand-navy/40 dark:text-white/35" style="animation-delay: 320ms">
        Confirmed viewings go into a separate “RealtyLink PH Viewings” calendar. Your other calendars are never touched.
      </p>
    </div>
  </div>
</template>

<style scoped>
.gc-logo { animation: gc-logo-in 0.6s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes gc-logo-in { from { opacity: 0; transform: scale(0.92); } to { opacity: 1; transform: none; } }

/* The three dots between the two logos pulse in turn, like data moving across. */
.gc-dot { animation: gc-dot 1.2s ease-in-out infinite; }
@keyframes gc-dot { 0%, 100% { opacity: 0.25; transform: scale(0.8); } 50% { opacity: 1; transform: scale(1.15); } }

.gc-rise { animation: gc-rise 0.5s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes gc-rise { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }

.gc-bar::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.55), transparent);
  transform: translateX(-100%);
  animation: gc-glint 1.4s ease-in-out infinite;
}
@keyframes gc-glint { to { transform: translateX(100%); } }

.gc-step-enter-active, .gc-step-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.gc-step-enter-from { opacity: 0; transform: translateY(4px); }
.gc-step-leave-to   { opacity: 0; transform: translateY(-4px); }

@media (prefers-reduced-motion: reduce) {
  .gc-logo, .gc-rise, .gc-dot, .gc-bar::after { animation: none; }
  .gc-step-enter-active, .gc-step-leave-active { transition: none; }
}
</style>
