<script setup lang="ts">
// OAuth landing page: Google redirects here with ?code=…; we exchange it for
// tokens, refresh the user, then bounce back to the profile.
definePageMeta({ layout: false })

const route = useRoute()
const { exchangeCode } = useGoogleCalendar()
const { fetchMe } = useAuth()

onMounted(async () => {
  const code  = route.query.code  as string | undefined
  const error = route.query.error as string | undefined

  if (error || !code) {
    await navigateTo('/dashboard/profile?gcal=error')
    return
  }

  const ok = await exchangeCode(code)
  if (ok) await fetchMe()
  await navigateTo(ok ? '/dashboard/profile?gcal=connected' : '/dashboard/profile?gcal=error')
})
</script>

<template>
  <div class="min-h-screen flex flex-col items-center justify-center gap-4" style="background: linear-gradient(160deg, #0d1f3c 0%, #08152F 100%)">
    <svg class="animate-spin h-9 w-9 text-brand-gold" fill="none" viewBox="0 0 24 24">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
    </svg>
    <p class="text-white/70 text-sm">Connecting your Google Calendar…</p>
  </div>
</template>
