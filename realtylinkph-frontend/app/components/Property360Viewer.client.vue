<script setup lang="ts">
import { Viewer } from '@photo-sphere-viewer/core'
import { AutorotatePlugin } from '@photo-sphere-viewer/autorotate-plugin'
import '@photo-sphere-viewer/core/index.css'

/**
 * The 360° tour. `src` is what it opens on (the light 4096 px preview when the
 * panorama has one); `fullSrc`, when given, is the full original, downloaded
 * once the tour is showing and swapped in without a flash, at the same angle.
 *
 * Images come from fetchPanorama (utils/panorama.ts): if the listing page
 * already downloaded one in the background, the viewer uses that copy.
 */
const props = defineProps<{ src: string; fullSrc?: string | null }>()

const el = ref<HTMLElement | null>(null)
const loading = ref(true)
let viewer: Viewer | null = null
/** The panorama currently wanted, so a late download can't replace a newer one. */
let current = ''
const objectUrls: string[] = []

/** The prefetched Blob as a same-origin object URL, or the plain URL if it failed. */
async function resolve(src: string): Promise<string> {
  const blob = await fetchPanorama(src)
  if (!blob) return panoramaUrl(src)
  const u = URL.createObjectURL(blob)
  objectUrls.push(u)
  return u
}

async function open(src: string, full?: string | null) {
  current = src
  loading.value = true
  const path = await resolve(src)
  if (current !== src || !el.value) return

  if (!viewer) {
    viewer = new Viewer({
      container:      el.value,
      panorama:       path,
      navbar:         ['zoom', 'move', 'fullscreen'],
      defaultZoomLvl: 0,
      loadingTxt:     'Loading virtual tour…',
      plugins: [
        // Gently auto-rotate; pauses while the buyer drags, resumes when idle.
        [AutorotatePlugin, {
          autorotateSpeed: '0.6rpm',
          autostartDelay:  2000,
          autostartOnIdle: true,
        }],
      ],
    })
    viewer.addEventListener('panorama-loaded', () => { loading.value = false })
  } else {
    await viewer.setPanorama(path)
    loading.value = false
  }

  // Then the sharp original, in the background, swapped in at the same angle.
  if (full && full !== src) {
    const fullPath = await resolve(full)
    if (!viewer || current !== src) return
    if (loading.value) await new Promise(r => viewer!.addEventListener('panorama-loaded', r, { once: true }))
    if (!viewer || current !== src) return
    viewer.setPanorama(fullPath, {
      transition: false,
      showLoader: false,
      position: viewer.getPosition(),
      zoom: viewer.getZoomLevel(),
    }).catch(() => { /* the preview stays; it's already sharp enough */ })
  }
}

onMounted(() => { open(props.src, props.fullSrc) })
watch(() => [props.src, props.fullSrc] as const, ([src, full]) => { if (src) open(src, full) })

onBeforeUnmount(() => {
  current = ''
  viewer?.destroy()
  viewer = null
  objectUrls.forEach(u => URL.revokeObjectURL(u))
})
</script>

<template>
  <div class="relative w-full h-[26.25rem] rounded-2xl overflow-hidden bg-black">
    <div ref="el" class="absolute inset-0" />
    <!-- Shown while the panorama downloads, before the viewer's own loader exists -->
    <div v-if="loading" class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-3 text-white/80">
      <span class="h-10 w-10 rounded-full border-2 border-white/20 border-t-brand-gold animate-spin" aria-hidden="true" />
      <span class="text-sm font-semibold">Loading virtual tour…</span>
    </div>
  </div>
</template>
