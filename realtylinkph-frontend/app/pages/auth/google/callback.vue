<script setup lang="ts">
// Google sign-in landing: Google redirects here with ?code=…; we exchange it
// for a session, then send the user to their dashboard.
definePageMeta({ layout: false })

import type { GoogleIntent } from '~/composables/useGoogleAuth'

const route     = useRoute()
const authStore = useAuthStore()
const { completeGoogleSignIn } = useGoogleAuth()

onMounted(async () => {
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

  await navigateTo(authStore.isAgent ? '/dashboard/listings' : '/dashboard/appointments')
})
</script>

<template>
  <div class="min-h-screen flex flex-col items-center justify-center gap-4" style="background: linear-gradient(160deg, #0d1f3c 0%, #08152F 100%)">
    <svg class="animate-spin h-9 w-9 text-brand-gold" fill="none" viewBox="0 0 24 24">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
    </svg>
    <p class="text-white/70 text-sm">Signing you in with Google…</p>
  </div>
</template>
