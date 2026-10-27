<script setup lang="ts">
definePageMeta({ layout: 'dashboard' })
</script>

<template>
  <!-- Same centred column as the overview, Saved and Profile (max-w-6xl) -->
  <div class="max-w-6xl mx-auto">
    <!-- Browse experience (embedded — no extra page chrome) -->
    <PropertyBrowser embedded />
  </div>
</template>
