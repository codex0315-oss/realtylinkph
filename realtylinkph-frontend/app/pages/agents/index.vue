<script setup lang="ts">
import type { Agent } from '~/types'

definePageMeta({ layout: 'default' })

useHead({ title: 'Verified Agents — RealtyLinkPH' })

const { fetchTopAgents } = useAgent()

// useAsyncData keeps SSR + client hydration in sync (no mismatch).
const { data: agents } = await useAsyncData(
  'agents-directory',
  () => fetchTopAgents(24),
  { default: () => [] as Agent[] },
)
</script>

<template>
  <div class="min-h-screen bg-white">
    <!-- Hero -->
    <section class="relative overflow-hidden pt-40 pb-14" style="background: linear-gradient(135deg, #0d1f3c 0%, #08152F 60%, #060E1F 100%)">
      <div class="absolute inset-0 opacity-[0.05] pointer-events-none" style="background-image: radial-gradient(circle, #D4AF37 1px, transparent 1px); background-size: 26px 26px;" />
      <div class="max-w-content mx-auto px-6 relative text-center">
        <div class="flex items-center justify-center gap-3 mb-3">
          <div class="h-px w-8 bg-brand-gold/50" />
          <span class="text-brand-gold text-[11px] font-bold tracking-[0.25em] uppercase">Trusted Professionals</span>
          <div class="h-px w-8 bg-brand-gold/50" />
        </div>
        <h1 class="font-playfair text-3xl md:text-4xl font-bold text-white leading-tight">Verified Agents</h1>
        <p class="mt-3 text-sm text-white/50 max-w-xl mx-auto">
          Browse PRC-licensed real estate professionals on RealtyLinkPH, ready to help you find or sell your next property.
        </p>
      </div>
    </section>

    <!-- Grid -->
    <section class="max-w-content mx-auto px-6 py-14">
      <div v-if="agents && agents.length" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <AgentCard v-for="agent in agents" :key="agent.id" :agent="agent" />
      </div>

      <div v-else class="text-center py-20">
        <div class="h-14 w-14 rounded-full bg-gray-100 flex items-center justify-center mx-auto mb-4">
          <svg class="h-7 w-7 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
        </div>
        <p class="text-sm text-gray-500">No verified agents to show yet. Check back soon.</p>
      </div>
    </section>
  </div>
</template>
