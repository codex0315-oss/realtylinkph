<script setup lang="ts">
// In-account property detail (logged-in buyers) — dashboard layout, so the
// buyer stays inside their account instead of bouncing to the public page.
definePageMeta({ layout: 'dashboard' })
</script>

<template>
  <PropertyDetailView />
</template>
