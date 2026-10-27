<script setup lang="ts">
/**
 * A titled modal whose content lays itself out. Same frame as every other
 * dialog (AppDialog): centred on desktop, a bottom sheet on phones, Escape
 * and backdrop close, focus kept inside.
 */
withDefaults(defineProps<{
  open: boolean
  title?: string
  size?: 'sm' | 'md' | 'lg' | 'xl'
  busy?: boolean
}>(), { size: 'md', title: '', busy: false })

const emit = defineEmits<{ close: [] }>()
</script>

<template>
  <AppDialog :open="open" :title="title" :size="size" :busy="busy" bare @close="emit('close')">
    <slot />
  </AppDialog>
</template>
