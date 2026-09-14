<script setup lang="ts">
// Register is now a modal on the homepage. Any visit to /register redirects
// home and opens the register modal (handled by AuthModal via the ?auth query).
definePageMeta({ middleware: 'guest' })
await navigateTo({ path: '/', query: { auth: 'register' } }, { replace: true })
</script>

<template>
  <div />
</template>
