<script setup lang="ts">
definePageMeta({ layout: 'dashboard' })

const authStore = useAuthStore()
</script>

<template>
  <!-- Buyers: the reviews they've written. Agents: reviews about them, with replies. -->
  <div class="max-w-6xl mx-auto">
    <AgentReviews v-if="authStore.isAgent" />
    <BuyerReviews v-else />
  </div>
</template>
