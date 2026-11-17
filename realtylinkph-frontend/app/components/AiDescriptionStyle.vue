<script setup lang="ts">
/**
 * Plain or "With emoji" for "Write it with AI". Plain is the default; each
 * browser remembers the agent's last choice.
 */
export type DescriptionStyle = 'plain' | 'emoji'

const model = defineModel<DescriptionStyle>({ default: 'plain' })
const KEY = 'rl-desc-style'

onMounted(() => {
  try { if (localStorage.getItem(KEY) === 'emoji') model.value = 'emoji' } catch { /* storage blocked: plain */ }
})
watch(model, (v) => {
  try { localStorage.setItem(KEY, v) } catch { /* fine */ }
})

const OPTIONS: Array<{ key: DescriptionStyle; label: string }> = [
  { key: 'plain', label: 'Plain' },
  { key: 'emoji', label: 'With emoji' },
]
</script>

<template>
  <div class="inline-flex items-center gap-0.5 rounded-full p-0.5 bg-white dark:bg-white/[0.05] ring-1 ring-brand-navy/10 dark:ring-white/10" role="radiogroup" aria-label="AI description style">
    <button
      v-for="o in OPTIONS"
      :key="o.key"
      type="button"
      role="radio"
      :aria-checked="model === o.key"
      class="rounded-full px-2.5 py-1 text-[0.6875rem] font-semibold transition"
      :class="model === o.key ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-[#0B2A5C]' : 'text-brand-navy/60 hover:text-brand-navy dark:text-white/55 dark:hover:text-white'"
      @click="model = o.key"
    >{{ o.label }}</button>
  </div>
</template>
