<script setup lang="ts">
/**
 * Hero heading with two layered effects:
 *  1. A staggered word entrance on load (rise + unblur).
 *  2. A looping-once "camera focus" reticle that scans word-to-word, sharpening
 *     the focused word while the rest blur, then settles the whole line sharp.
 */
const lines = [
  [{ t: 'Find', g: false }, { t: 'Verified', g: true }, { t: 'Properties', g: true }],
  [{ t: 'Across', g: false }, { t: 'the', g: false }, { t: 'Philippines', g: false }],
]
const words = lines.flat()

function flatIndex(li: number, wi: number): number {
  let idx = 0
  for (let i = 0; i < li; i++) idx += lines[i]!.length
  return idx + wi
}

const headingEl = ref<HTMLElement | null>(null)
const wordEls   = ref<(HTMLElement | null)[]>([])
const rects     = ref<Array<{ left: number; top: number; width: number; height: number }>>([])
const active    = ref(0)
const reduce    = ref(false)
const done      = ref(false)   // scan finished → whole headline stays sharp
const entered   = ref(false)   // entrance animation complete

function setWordRef(el: Element | null, idx: number) {
  wordEls.value[idx] = el as HTMLElement | null
}

function isFocused(idx: number): boolean {
  return reduce.value || done.value || idx === active.value
}

function measure() {
  const base = headingEl.value?.getBoundingClientRect()
  if (!base) return
  rects.value = wordEls.value.map((el) => {
    if (!el) return { left: 0, top: 0, width: 0, height: 0 }
    const r = el.getBoundingClientRect()
    return { left: r.left - base.left, top: r.top - base.top, width: r.width, height: r.height }
  })
}

const PAD_X = 12
const PAD_Y = 6
const reticleStyle = computed(() => {
  const r = rects.value[active.value]
  if (!r || !r.width) return { opacity: '0' }
  return {
    left:    `${r.left - PAD_X}px`,
    top:     `${r.top - PAD_Y}px`,
    width:   `${r.width + PAD_X * 2}px`,
    height:  `${r.height + PAD_Y * 2}px`,
    opacity: '1',
  }
})

let timer: ReturnType<typeof setInterval> | undefined
let entryTimer: ReturnType<typeof setTimeout> | undefined

onMounted(() => {
  reduce.value = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false

  nextTick(() => {
    measure()
    requestAnimationFrame(measure) // catch layout settling after first paint
  })
  // Re-measure once the web font is ready (metrics shift after load).
  if (document.fonts?.ready) document.fonts.ready.then(() => nextTick(measure))
  window.addEventListener('resize', measure, { passive: true })

  if (reduce.value) {
    entered.value = true
    return
  }

  // Words rise in first; the focus scan starts once they've all landed.
  requestAnimationFrame(() => { entered.value = true })
  const entryDuration = 260 + words.length * 90

  entryTimer = setTimeout(() => {
    measure()
    timer = setInterval(() => {
      measure() // refresh positions each tick so the reticle self-corrects
      if (active.value >= words.length - 1) {
        done.value = true
        if (timer) { clearInterval(timer); timer = undefined }
        return
      }
      active.value += 1
    }, 780)
  }, entryDuration)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
  if (entryTimer) clearTimeout(entryTimer)
  window.removeEventListener('resize', measure)
})
</script>

<template>
  <h1
    ref="headingEl"
    class="relative font-display font-bold text-white leading-[1.05]"
    style="font-size: clamp(1.875rem, 5vw, 3.75rem)"
  >
    <span v-for="(line, li) in lines" :key="li" class="block whitespace-nowrap">
      <span
        v-for="(w, wi) in line"
        :key="wi"
        :ref="(el) => setWordRef(el as Element | null, flatIndex(li, wi))"
        class="inline-block mr-[0.28em] will-change-[filter,opacity,transform]"
        :class="[
          w.g ? 'text-gold-gradient animate-shimmer' : 'text-white',
          entered ? 'word-in' : 'word-out',
        ]"
        :style="{
          transitionDelay: `${flatIndex(li, wi) * 90}ms`,
          ...(entered && !isFocused(flatIndex(li, wi))
            ? { filter: 'blur(6px)', opacity: '0.4' }
            : {}),
        }"
      >{{ w.t }}</span>
    </span>

    <!-- Focus reticle (gold corner brackets) — fades out once the scan settles -->
    <span v-if="!reduce && !done && entered" class="reticle" :style="reticleStyle" aria-hidden="true">
      <span class="corner tl" />
      <span class="corner tr" />
      <span class="corner bl" />
      <span class="corner br" />
    </span>
  </h1>
</template>

<style scoped>
.word-out {
  opacity: 0;
  transform: translateY(26px);
  filter: blur(10px);
}
.word-in {
  opacity: 1;
  transform: translateY(0);
  filter: blur(0);
  transition:
    opacity 0.6s cubic-bezier(0.22, 1, 0.36, 1),
    transform 0.6s cubic-bezier(0.22, 1, 0.36, 1),
    filter 0.6s cubic-bezier(0.22, 1, 0.36, 1);
}

.reticle {
  position: absolute;
  pointer-events: none;
  transition: all 0.55s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.4s ease;
}
.corner {
  position: absolute;
  width: 16px;
  height: 16px;
  border-color: #D4AF37;
}
.corner.tl { top: 0; left: 0; border-top: 3px solid; border-left: 3px solid; }
.corner.tr { top: 0; right: 0; border-top: 3px solid; border-right: 3px solid; }
.corner.bl { bottom: 0; left: 0; border-bottom: 3px solid; border-left: 3px solid; }
.corner.br { bottom: 0; right: 0; border-bottom: 3px solid; border-right: 3px solid; }

@media (prefers-reduced-motion: reduce) {
  .word-out, .word-in {
    opacity: 1 !important;
    transform: none !important;
    filter: none !important;
  }
}
</style>
