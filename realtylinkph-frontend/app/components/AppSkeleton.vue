<script setup lang="ts">
interface Props {
  width?: string
  height?: string
  rounded?: 'none' | 'sm' | 'md' | 'lg' | 'xl' | '2xl' | 'full'
  lines?: number
}

withDefaults(defineProps<Props>(), {
  rounded: 'md',
  lines:   1,
})

const roundedClasses: Record<string, string> = {
  none: '',
  sm:   'rounded',
  md:   'rounded-md',
  lg:   'rounded-lg',
  xl:   'rounded-xl',
  '2xl': 'rounded-2xl',
  full: 'rounded-full',
}
</script>

<template>
  <div v-if="lines === 1" class="animate-pulse bg-brand-silver-light" :class="roundedClasses[rounded!]" :style="{ width, height }" />
  <div v-else class="flex flex-col gap-2">
    <div
      v-for="i in lines"
      :key="i"
      class="animate-pulse bg-brand-silver-light rounded-md h-4"
      :style="{ width: i === lines ? '70%' : '100%' }"
    />
  </div>
</template>
