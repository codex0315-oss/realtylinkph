<script setup lang="ts">
/**
 * Where you stand on viewings. Shows only when there is something to say:
 * a pause (and when it lifts), or strikes on the record. Read from the user's
 * own profile, so it stays right whoever paused them: the system after five
 * strikes, or an admin.
 */
const props = defineProps<{ isAgent: boolean }>()

const authStore = useAuthStore()
const locked = computed(() => authStore.user?.booking_locked_until ?? null)
const strikes = computed(() => authStore.user?.viewing_strikes ?? null)
const count = computed(() => strikes.value?.count ?? 0)
const limit = computed(() => strikes.value?.limit ?? 5)
const left = computed(() => Math.max(0, limit.value - count.value))
const show = computed(() => !!locked.value || count.value > 0)
const atLimit = computed(() => count.value >= limit.value)

const fmt = (iso: string) => new Date(iso).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
const close = computed(() => !locked.value && count.value >= 3)
</script>

<template>
  <div v-if="show" class="mb-6">
    <!-- Paused -->
    <div v-if="locked" class="rounded-2xl border border-red-200 dark:border-red-500/30 bg-red-50 dark:bg-red-500/10 px-5 py-4">
      <p class="text-sm font-bold text-red-700 dark:text-red-300">
        {{ props.isAgent ? 'Your listings are not taking new viewings' : 'Booking is paused' }}
      </p>
      <p class="mt-1 text-xs leading-relaxed text-red-700/80 dark:text-red-200/70">
        This lifts on <span class="font-semibold">{{ fmt(locked) }}</span>.
        {{ props.isAgent ? 'You cannot confirm new requests until then; confirmed viewings are not affected.' : 'Viewings you already confirmed are not affected.' }}
        <template v-if="atLimit">That is {{ count }} strikes in the last {{ strikes?.window_days ?? 30 }} days.</template>
        <template v-else>An admin paused this. If you think that is a mistake, write to us from Help &amp; support.</template>
      </p>
    </div>

    <!-- Strikes, before any pause -->
    <details
      v-else
      class="group rounded-2xl border px-5 py-3"
      :class="close
        ? 'border-amber-200 dark:border-amber-500/25 bg-amber-50 dark:bg-amber-500/10'
        : 'border-brand-navy/10 dark:border-white/10 bg-white dark:bg-white/[0.04]'"
    >
      <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-x-4 gap-y-1">
        <span class="text-xs leading-relaxed" :class="close ? 'text-amber-900 dark:text-amber-200/90' : 'text-brand-navy/75 dark:text-white/65'">
          <span class="font-bold">{{ count }}</span> of {{ limit }} strikes in the last {{ strikes?.window_days ?? 30 }} days.
          <template v-if="close">{{ left }} more pauses {{ props.isAgent ? 'your listings' : 'booking' }} for a week.</template>
        </span>
        <span class="text-xs font-semibold text-brand-gold-deep dark:text-brand-gold group-open:hidden">How strikes work</span>
      </summary>
      <p class="mt-2 text-xs leading-relaxed text-brand-text-secondary dark:text-white/55">
        Cancelling a confirmed viewing less than 24 hours ahead counts as 1 strike. A reported no-show counts as 2.
        Strikes fade after 30 days, and five pause {{ props.isAgent ? 'your listings' : 'booking' }} for a week.
        Cancelling earlier, or before the agent confirms, never counts.
      </p>
    </details>
  </div>
</template>
