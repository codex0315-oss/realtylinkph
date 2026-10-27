<script setup lang="ts">
import type { Agent } from '~/types'

interface Props {
  agent: Agent
}

defineProps<Props>()

const agentHref = useAgentHref()
</script>

<template>
  <div
    class="group h-full flex flex-col overflow-hidden bg-white dark:bg-brand-navy-mid rounded-2xl border border-gray-200 dark:border-white/10
           hover:shadow-[0_18px_44px_rgba(11,42,92,0.14)] dark:hover:shadow-[0_18px_44px_rgba(0,0,0,0.55)]
           hover:border-brand-gold/40 hover:-translate-y-1.5 transition-all duration-300"
  >
    <!-- Cover strip (the agent's cover photo, or the default navy banner) -->
    <AgentCover :src="agent.cover_url" :position="agent.cover_position ?? 50" compact class="h-20 flex-none" />

    <div class="flex-1 flex flex-col px-5 pb-5">
    <!-- Avatar (overlapping the strip) + info -->
    <div class="flex items-end gap-4 -mt-7 mb-5">
      <div class="relative flex-shrink-0">
        <div class="rounded-full ring-4 ring-white dark:ring-brand-navy-mid transition-transform duration-300 group-hover:scale-105">
          <AppAvatar :name="agent.name" :src="agent.avatar" size="lg" />
        </div>
        <span
          v-if="agent.agent_profile?.status === 'approved'"
          class="absolute -bottom-1 -right-1 rounded-full bg-white dark:bg-brand-navy-mid p-px"
        >
          <AppVerifiedTick size="md" />
        </span>
      </div>

      <div class="min-w-0 flex-1 pb-0.5">
        <p class="font-semibold text-brand-text-primary dark:text-white text-sm leading-tight truncate
                  group-hover:text-brand-gold transition-colors duration-200">
          {{ agent.name }}
        </p>
        <p class="text-xs text-brand-text-secondary dark:text-white/45 mt-0.5">Licensed Agent</p>

        <!-- Listings + rating row -->
        <div class="flex items-center justify-between mt-2.5">
          <span v-if="agent.listing_count" class="text-xs text-brand-text-secondary dark:text-white/50">
            {{ agent.listing_count }} Listings
          </span>
          <div v-if="agent.average_rating" class="flex items-center gap-1">
            <svg class="h-3.5 w-3.5 text-brand-gold" fill="currentColor" viewBox="0 0 20 20">
              <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
            </svg>
            <span class="text-xs font-semibold text-brand-text-primary dark:text-white">
              {{ agent.average_rating.toFixed(1) }}
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- View Profile button -->
    <NuxtLink
      :to="agentHref(agent.id)"
      class="mt-auto block w-full text-center text-xs font-bold rounded-lg py-2.5
             border border-brand-navy dark:border-white/25 text-brand-navy dark:text-white
             hover:bg-brand-navy dark:hover:bg-brand-gold hover:text-white dark:hover:text-brand-navy
             dark:hover:border-brand-gold transition-colors duration-200"
    >
      View Profile
    </NuxtLink>
    </div>
  </div>
</template>
