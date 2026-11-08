<script setup lang="ts">
/**
 * A quiet nudge for people whose email isn't verified yet. Not a gate: they can
 * keep using the app. Admins don't see it (their sign-in already uses email), and
 * "Not now" hides it for a day.
 */
const authStore = useAuthStore()

const DAY = 24 * 60 * 60 * 1000
const hiddenUntil = ref(0)
const key = computed(() => `email-reminder-hidden:${authStore.user?.id ?? 0}`)

onMounted(() => {
  try { hiddenUntil.value = Number(localStorage.getItem(key.value)) || 0 } catch { /* private window: show it */ }
})

const show = computed(() =>
  !!authStore.user
  && !authStore.isAdmin
  && !authStore.user.email_verified_at
  && Date.now() > hiddenUntil.value,
)

function notNow() {
  hiddenUntil.value = Date.now() + DAY
  try { localStorage.setItem(key.value, String(hiddenUntil.value)) } catch { /* fine */ }
}
</script>

<template>
  <div v-if="show" class="page-shell">
    <div class="mb-5 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-500/25 dark:bg-amber-500/10" role="status">
      <svg class="h-5 w-5 flex-none text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
      <p class="min-w-0 flex-1 text-sm text-amber-900 dark:text-amber-200/90">
        <span class="font-semibold">Verify your email</span>
        <span class="hidden cw-sm:inline"> so viewing reminders reach you and you can recover your account.</span>
      </p>
      <div class="flex items-center gap-2">
        <NuxtLink to="/dashboard/profile?section=account" class="rounded-full bg-amber-500 px-4 py-1.5 text-xs font-bold text-white transition hover:bg-amber-600">Verify now</NuxtLink>
        <button type="button" class="rounded-full px-3 py-1.5 text-xs font-semibold text-amber-800 transition hover:bg-amber-500/10 dark:text-amber-300" @click="notNow">Not now</button>
      </div>
    </div>
  </div>
</template>
