<script setup lang="ts">
/**
 * The one heading every landing section uses, so the page has a single type
 * scale: tracked-caps eyebrow → serif headline → optional lead → optional
 * "view all" link. `inverse` is for sections that sit on navy in both themes.
 */
interface Props {
  eyebrow?: string
  title: string
  lead?: string
  align?: 'left' | 'center'
  linkTo?: string
  linkLabel?: string
  inverse?: boolean
  /** A word or phrase inside `title` to set in gold italic ("Pick your *path*."). */
  accent?: string
}

const props = withDefaults(defineProps<Props>(), {
  eyebrow: '',
  lead: '',
  align: 'left',
  linkTo: '',
  linkLabel: 'View all',
  inverse: false,
  accent: '',
})

const centered = computed(() => props.align === 'center')

/* Title split around the accent so it can be styled without v-html. */
const titleParts = computed(() => {
  const i = props.accent ? props.title.indexOf(props.accent) : -1
  if (i < 0) return { before: props.title, accent: '', after: '' }
  return {
    before: props.title.slice(0, i),
    accent: props.accent,
    after:  props.title.slice(i + props.accent.length),
  }
})
</script>

<template>
  <div
    class="flex gap-6"
    :class="centered ? 'flex-col items-center text-center' : 'flex-col sm:flex-row sm:items-end sm:justify-between'"
  >
    <div :class="centered ? 'max-w-3xl' : 'max-w-2xl'">
      <p
        v-if="eyebrow"
        class="text-[0.6875rem] font-semibold uppercase tracking-[0.22em] mb-3"
        :class="inverse ? 'text-brand-gold' : 'text-brand-gold-deep dark:text-brand-gold'"
      >
        {{ eyebrow }}
      </p>
      <h2
        class="font-heading font-semibold tracking-[-0.015em] leading-[1.12] text-[2rem] sm:text-[2.5rem] lg:text-[2.75rem]"
        :class="inverse ? 'text-white' : 'text-brand-navy dark:text-white'"
      >
        {{ titleParts.before }}<em
          v-if="titleParts.accent"
          class="italic font-medium pr-[0.08em]"
          :class="inverse ? 'text-gold-metal-bright' : 'text-gold-metal'"
        >{{ titleParts.accent }}</em>{{ titleParts.after }}
      </h2>
      <p
        v-if="lead"
        class="mt-4 text-base leading-relaxed"
        :class="inverse ? 'text-white/65' : 'text-brand-text-secondary dark:text-white/60'"
      >
        {{ lead }}
      </p>
    </div>

    <NuxtLink
      v-if="linkTo"
      :to="linkTo"
      class="group inline-flex flex-none items-center gap-2 text-sm font-semibold transition-colors"
      :class="inverse ? 'text-white hover:text-brand-gold' : 'text-brand-navy hover:text-brand-gold-deep dark:text-white dark:hover:text-brand-gold'"
    >
      {{ linkLabel }}
      <svg class="h-4 w-4 transition-transform group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
      </svg>
    </NuxtLink>
  </div>
</template>
