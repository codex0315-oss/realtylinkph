<script setup lang="ts">
import { Viewer } from '@photo-sphere-viewer/core'
import { AutorotatePlugin } from '@photo-sphere-viewer/autorotate-plugin'
import '@photo-sphere-viewer/core/index.css'

const props = defineProps<{ src: string }>()

const el = ref<HTMLElement | null>(null)
let viewer: Viewer | null = null

/**
 * WebGL refuses a cross-origin texture unless the server sends CORS headers.
 *
 * Two cases:
 *  - served by our own backend (`<api host>/storage/…`) → strip the origin so
 *    it loads through this app's /storage proxy, i.e. same-origin.
 *  - served by a bucket (Supabase/S3/R2) → used as-is; the bucket's CORS
 *    headers allow it (Supabase sends `*`; see DEPLOYMENT.md).
 *
 * The decision is made on the HOST, not on the path. It used to trigger on
 * any URL containing "/storage/", which also matched Supabase's
 * `…supabase.co/storage/v1/object/…` — so every bucket-hosted panorama was
 * rewritten onto this app's origin and proxied to nowhere: "The panorama
 * cannot be loaded", while the thumbnails (plain <img>) worked fine.
 */
const apiHost = (() => {
  try { return new URL(useRuntimeConfig().public.apiBase as string).host } catch { return '' }
})()

function sameOrigin(url: string): string {
  try {
    const u = new URL(url, window.location.href)
    if (apiHost && u.host === apiHost && u.pathname.startsWith('/storage/')) return u.pathname + u.search
  } catch { /* not a URL — hand it to the viewer untouched */ }
  return url
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
  <div ref="el" class="w-full h-[26.25rem] rounded-2xl overflow-hidden bg-black" />
</template>
