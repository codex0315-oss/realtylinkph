<script setup lang="ts">
/**
 * The overview counters, as one strip rather than a row of floating cards.
 *
 * Four equally-sized cards each with its own gold icon chip gave every number
 * the same importance — and when they all read 0, the page was four boxes of
 * nothing. One panel divided by hairlines reads as a single summary, and
 * `accent` lets the one number that actually wants acting on stand out.
 *
 * The hairlines are the container's own background showing through a 1px grid
 * gap, which avoids per-cell border logic that breaks at wrap boundaries.
 *
 * A cell either links to a page (`href`) or runs `onSelect` (e.g. opening the
 * Messages panel), and can carry a one-line `hint` under the number.
 */
export interface DashStat {
  label: string
  value: number | string
  href?: string
  onSelect?: () => void
  /** One line of context under the number ("Next: Sat 25 Oct"). */
  hint?: string
  /** Highlight in gold — use when the number represents something to act on. */
  accent?: boolean
}

const props = defineProps<{ stats: DashStat[] }>()
const NuxtLinkCmp = resolveComponent('NuxtLink')

// Literal class strings so Tailwind's scanner can see both variants.
const colsClass = computed(() =>
  props.stats.length === 3 ? 'grid-cols-1 sm:grid-cols-3' : 'grid-cols-2 lg:grid-cols-4',
)
</script>

<template>
  <div
    class="grid gap-px rounded-2xl overflow-hidden border
           bg-gray-200 border-gray-200
           dark:bg-white/[0.08] dark:border-white/[0.08]"
    :class="colsClass"
  >
    <component
      :is="s.href ? NuxtLinkCmp : 'button'"
      v-for="s in stats"
      :key="s.label"
      :to="s.href"
      :type="s.href ? undefined : 'button'"
      class="group px-5 py-4 text-left transition-colors bg-white hover:bg-gray-50 dark:bg-[#0B1A35] dark:hover:bg-[#12233F]"
      @click="s.onSelect?.()"
    >
      <p class="flex items-center gap-1.5 text-[0.625rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40">
        <span
          v-if="s.accent"
          class="h-1.5 w-1.5 rounded-full bg-brand-gold flex-shrink-0"
          aria-hidden="true"
        />
        {{ s.label }}
      </p>
      <p
        class="mt-2 text-[1.75rem] leading-none font-bold tabular-nums transition-colors"
        :class="s.accent
          ? 'text-brand-gold-deep dark:text-brand-gold'
          : 'text-brand-navy dark:text-white group-hover:text-brand-gold-deep dark:group-hover:text-brand-gold'"
      >
        {{ s.value }}
      </p>
      <p v-if="s.hint" class="mt-1.5 truncate text-xs text-brand-navy/50 dark:text-white/45">{{ s.hint }}</p>
    </component>
  </div>
</template>
