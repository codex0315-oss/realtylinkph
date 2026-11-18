<script setup lang="ts">
/** The files picked for the next message, each with a ✕ to take it out again. */
const props = defineProps<{ files: File[] }>()
const emit = defineEmits<{ remove: [index: number] }>()

const previews = ref<Array<{ url: string | null; kind: ChatFileKind }>>([])
watch(() => props.files, (files, _old, onCleanup) => {
  previews.value = files.map((f) => {
    const kind = chatFileKind(f) ?? 'file'
    return { kind, url: kind === 'image' ? URL.createObjectURL(f) : null }
  })
  const made = previews.value
  onCleanup(() => made.forEach(p => p.url && URL.revokeObjectURL(p.url)))
}, { immediate: true })
onUnmounted(() => previews.value.forEach(p => p.url && URL.revokeObjectURL(p.url)))
</script>

<template>
  <div v-if="files.length" class="flex gap-2 overflow-x-auto pb-1 pt-1 no-scrollbar">
    <div v-for="(f, i) in files" :key="`${f.name}-${f.size}-${i}`" class="relative flex-none">
      <div class="flex h-16 w-16 items-center justify-center overflow-hidden rounded-xl bg-gray-100 ring-1 ring-gray-200 dark:bg-white/10 dark:ring-white/10">
        <img v-if="previews[i]?.url" :src="previews[i]!.url!" alt="" class="h-full w-full object-cover" />
        <span v-else-if="previews[i]?.kind === 'video'" class="flex flex-col items-center text-gray-500 dark:text-white/60">
          <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
          <span class="text-[0.5625rem] font-semibold">{{ formatBytes(f.size) }}</span>
        </span>
        <span v-else class="px-1 text-center text-[0.5625rem] font-bold uppercase text-gray-500 dark:text-white/60">{{ f.name.split('.').pop() }}<br>{{ formatBytes(f.size) }}</span>
      </div>
      <button
        type="button"
        class="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-brand-navy text-white shadow ring-2 ring-white dark:ring-[#17417F]"
        :aria-label="`Remove ${f.name}`"
        @click="emit('remove', i)"
      >
        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
      </button>
    </div>
  </div>
</template>

<style scoped>
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }
</style>
