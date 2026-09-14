<script setup lang="ts">
const authStore = useAuthStore()

// The token itself is restored in plugins/00.auth.client.ts, which runs before
// every other plugin — the WebSocket client needs it at construction time.

// If we have a token but no user object, fetch the profile
if (authStore.token && !authStore.user) {
  const { fetchMe } = useAuth()
  await fetchMe()
}
</script>

<template>
  <div>
    <NuxtRouteAnnouncer />
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>

    <!-- Floating RealtyLink AI launcher — buyer & agent variants (each role-gated inside) -->
    <ClientOnly>
      <RealtyAiBubble />
      <AgentAiBubble />
    </ClientOnly>

    <!-- Global confirmation dialog (replaces window.confirm) -->
    <ClientOnly>
      <ConfirmDialog />
    </ClientOnly>

    <!-- Global toast notifications -->
    <AppToast />
  </div>
</template>
