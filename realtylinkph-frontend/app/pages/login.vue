<script setup lang="ts">
// Login is now a modal on the homepage. Any visit to /login redirects home
// and opens the login modal (handled by AuthModal via the ?auth query).
definePageMeta({ middleware: 'guest' })
await navigateTo({ path: '/', query: { auth: 'login' } }, { replace: true })
</script>

<template>
  <div />
</template>
