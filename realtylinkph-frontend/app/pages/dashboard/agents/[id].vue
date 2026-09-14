<script setup lang="ts">
// In-account agent profile (logged-in buyers) — dashboard layout, so the buyer
// stays inside their account instead of bouncing to the public site.
definePageMeta({ layout: 'dashboard' })
</script>

<template>
  <AgentProfileView />
</template>
