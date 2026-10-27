<script setup lang="ts">
// In-account property detail (logged-in buyers) — dashboard layout, so the
// buyer stays inside their account instead of bouncing to the public page.
definePageMeta({ layout: 'dashboard' })
</script>

<template>
  <!-- Same centred column as Browse and the overview (max-w-6xl) -->
  <div class="max-w-6xl mx-auto">
    <PropertyDetailView />
  </div>
</template>
