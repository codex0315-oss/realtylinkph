<script setup lang="ts">
import type { MessageAttachment } from '~/types'

/**
 * Photos, videos and files inside a chat bubble. Photos sit in a small grid
 * and open full screen (swipe or arrow keys between them); videos play in
 * place; files are a card with a download link.
 */
const props = defineProps<{
  attachments: MessageAttachment[]
  mine?: boolean
  /** Upload progress 0–100 while sending; undefined once sent. */
  progress?: number
  compact?: boolean
}>()

const images = computed(() => props.attachments.filter(a => a.kind === 'image'))
const videos = computed(() => props.attachments.filter(a => a.kind === 'video'))
const files  = computed(() => props.attachments.filter(a => a.kind === 'file'))
const uploading = computed(() => props.progress !== undefined && props.progress < 100)

/* Full-screen viewer */
const open = ref<number | null>(null)
function show(i: number) { if (!uploading.value) open.value = i }
function step(d: number) {
  if (open.value === null) return
  open.value = (open.value + d + images.value.length) % images.value.length
}
function onKey(e: KeyboardEvent) {
  if (open.value === null) return
  if (e.key === 'Escape') open.value = null
  else if (e.key === 'ArrowRight') step(1)
  else if (e.key === 'ArrowLeft') step(-1)
}
onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))
let touchX = 0
function onTouchStart(e: TouchEvent) { touchX = e.touches[0]?.clientX ?? 0 }
function onTouchEnd(e: TouchEvent) {
  const dx = (e.changedTouches[0]?.clientX ?? 0) - touchX
  if (Math.abs(dx) > 50) step(dx < 0 ? 1 : -1)
}

const gridCls = computed(() => images.value.length === 1 ? 'grid-cols-1' : 'grid-cols-2')
const maxW = computed(() => props.compact ? 'w-[13rem] max-w-full' : 'w-[15rem] sm:w-[17rem] max-w-full')
function ext(name: string) { return (name.split('.').pop() || '').toUpperCase().slice(0, 4) }
function fileTone(name: string) {
  const e = ext(name)
  return e === 'PDF' ? 'bg-red-500/10 text-red-600 dark:text-red-400' : e.startsWith('XLS') ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' : 'bg-blue-500/10 text-blue-700 dark:text-blue-300'
}
</script>

<template>
  <div class="flex flex-col gap-1.5" :class="[mine ? 'items-end' : 'items-start', maxW]">
    <!-- Photos -->
    <div v-if="images.length" class="relative grid w-full gap-1 overflow-hidden rounded-2xl" :class="gridCls">
      <button
        v-for="(img, i) in images.slice(0, 4)"
        :key="img.id"
        type="button"
        class="relative block overflow-hidden bg-gray-100 dark:bg-white/10"
        :class="images.length === 1 ? 'max-h-80' : images.length === 3 && i === 0 ? 'col-span-2 aspect-[2/1]' : 'aspect-square'"
        :aria-label="`Open photo ${i + 1} of ${images.length}`"
        @click="show(i)"
      >
        <img
          :src="(img.thumb_url || img.url)!"
          :alt="img.name"
          class="h-full w-full object-cover"
          :style="images.length === 1 && img.width && img.height ? { aspectRatio: `${img.width} / ${img.height}` } : undefined"
          loading="lazy"
        />
        <span v-if="i === 3 && images.length > 4" class="absolute inset-0 flex items-center justify-center bg-black/50 text-lg font-bold text-white">+{{ images.length - 4 }}</span>
      </button>
      <div v-if="uploading" class="absolute inset-0 flex items-center justify-center bg-black/35">
        <span class="rounded-full bg-black/60 px-3 py-1 text-xs font-semibold text-white tabular-nums">{{ progress }}%</span>
      </div>
    </div>

    <!-- Videos -->
    <div v-for="v in videos" :key="v.id" class="relative w-full overflow-hidden rounded-2xl bg-black">
      <video :src="v.url ?? undefined" controls playsinline preload="metadata" class="block max-h-72 w-full" />
      <div v-if="uploading" class="absolute inset-0 flex items-center justify-center bg-black/50">
        <span class="rounded-full bg-black/60 px-3 py-1 text-xs font-semibold text-white tabular-nums">Uploading {{ progress }}%</span>
      </div>
    </div>

    <!-- Files -->
    <component
      :is="f.url && !uploading ? 'a' : 'div'"
      v-for="f in files"
      :key="f.id"
      :href="f.url && !uploading ? f.url : undefined"
      target="_blank"
      rel="noopener"
      :download="f.name"
      class="flex w-full items-center gap-3 rounded-2xl bg-white px-3 py-2.5 ring-1 ring-gray-200 transition hover:ring-brand-gold dark:bg-white/[0.06] dark:ring-white/10"
    >
      <span class="flex h-10 w-10 flex-none items-center justify-center rounded-xl text-[0.625rem] font-bold" :class="fileTone(f.name)">{{ ext(f.name) || 'FILE' }}</span>
      <span class="min-w-0 flex-1">
        <span class="block truncate text-sm font-semibold text-brand-navy dark:text-white">{{ f.name }}</span>
        <span class="block text-xs text-gray-500 dark:text-white/50">{{ uploading ? `Uploading ${progress}%` : formatBytes(f.size) }}</span>
      </span>
      <svg v-if="!uploading" class="h-5 w-5 flex-none text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
    </component>

    <!-- Full-screen photo viewer -->
    <Teleport to="body">
      <div
        v-if="open !== null && images[open]"
        class="fixed inset-0 z-[90] flex items-center justify-center bg-black/90"
        role="dialog"
        aria-modal="true"
        aria-label="Photo viewer"
        @click.self="open = null"
        @touchstart.passive="onTouchStart"
        @touchend.passive="onTouchEnd"
      >
        <img :src="images[open]!.url!" :alt="images[open]!.name" class="max-h-[88vh] max-w-[94vw] object-contain" />
        <p class="absolute left-4 top-4 rounded-full bg-black/50 px-3 py-1 text-xs font-semibold text-white">{{ open + 1 }} / {{ images.length }}</p>
        <div class="absolute right-3 top-3 flex gap-2">
          <a :href="images[open]!.url!" :download="images[open]!.name" target="_blank" rel="noopener" class="flex h-10 w-10 items-center justify-center rounded-full bg-white/15 text-white hover:bg-white/25" aria-label="Download photo">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
          </a>
          <button type="button" class="flex h-10 w-10 items-center justify-center rounded-full bg-white/15 text-white hover:bg-white/25" aria-label="Close" @click="open = null">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>
        </div>
        <template v-if="images.length > 1">
          <button type="button" class="absolute left-3 top-1/2 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/15 text-white hover:bg-white/25 sm:flex" aria-label="Previous photo" @click="step(-1)">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
          </button>
          <button type="button" class="absolute right-3 top-1/2 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/15 text-white hover:bg-white/25 sm:flex" aria-label="Next photo" @click="step(1)">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
          </button>
        </template>
      </div>
    </Teleport>
  </div>
</template>
