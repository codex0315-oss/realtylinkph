<script setup lang="ts">
import { Viewer } from '@photo-sphere-viewer/core'
import { AutorotatePlugin } from '@photo-sphere-viewer/autorotate-plugin'
import '@photo-sphere-viewer/core/index.css'

const props = defineProps<{ src: string }>()

const el = ref<HTMLElement | null>(null)
let viewer: Viewer | null = null

// Strip the backend origin so the panorama is loaded same-origin via the
// /storage proxy — WebGL textures from a different origin are CORS-blocked.
function sameOrigin(url: string): string {
  const i = url.indexOf('/storage/')
  return i >= 0 ? url.slice(i) : url
}

onMounted(() => {
  if (!el.value) return
  viewer = new Viewer({
    container:      el.value,
    panorama:       sameOrigin(props.src),
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
})

watch(() => props.src, (src) => {
  if (viewer && src) viewer.setPanorama(sameOrigin(src))
})

onBeforeUnmount(() => {
  viewer?.destroy()
  viewer = null
})
</script>

<template>
  <div ref="el" class="w-full h-[420px] rounded-2xl overflow-hidden bg-black" />
</template>
