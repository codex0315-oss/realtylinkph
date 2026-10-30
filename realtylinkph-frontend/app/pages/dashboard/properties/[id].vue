<script setup lang="ts">
// In-account property detail (logged-in buyers) — dashboard layout, so the
// buyer stays inside their account instead of bouncing to the public page.
definePageMeta({ layout: 'dashboard' })
</script>

<template>
  <!-- The view sets its own width in the dashboard (page-shell: 72rem, wider on big screens) -->
  <PropertyDetailView />
</template>
