<script setup lang="ts">
// Public agent profile (guests, homepage directory, agents) — default layout.
definePageMeta({ layout: 'default' })
</script>

<template>
  <!-- Premium dark, scoped via `dark` so the shared dashboard version stays light. -->
  <div class="dark bg-[#08152F] min-h-screen pt-32 pb-16">
    <AgentProfileView />
  </div>
</template>
