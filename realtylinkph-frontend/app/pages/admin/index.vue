<script setup lang="ts">
definePageMeta({ layout: 'dashboard' })
await navigateTo('/admin/agents')
</script>

<template>
  <div class="flex items-center justify-center py-16">
    <AppSpinner size="lg" />
  </div>
</template>
