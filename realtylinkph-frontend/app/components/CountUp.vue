<script setup lang="ts">
/**
 * Animated number that counts up the first time it scrolls into view.
 * Falls straight to the final value when the user prefers reduced motion,
 * so the figure is never missing — only the animation is skipped.
 */
interface Props {
  to: number
  duration?: number
  prefix?: string
  suffix?: string
  decimals?: number
}

const props = withDefaults(defineProps<Props>(), {
  duration: 1800,
  prefix: '',
  suffix: '',
  decimals: 0,
})

const el      = ref<HTMLElement | null>(null)
const current = ref(0)
let observer: IntersectionObserver | null = null
let frame = 0

const display = computed(() => {
  const n = current.value.toFixed(props.decimals)
  return props.prefix + Number(n).toLocaleString('en-PH', {
    minimumFractionDigits: props.decimals,
    maximumFractionDigits: props.decimals,
  }) + props.suffix
})

// easeOutExpo — fast start, long graceful settle.
function ease(t: number): number {
  return t === 1 ? 1 : 1 - Math.pow(2, -10 * t)
}

function run() {
  const start = performance.now()
  const step = (now: number) => {
    const t = Math.min(1, (now - start) / props.duration)
    current.value = props.to * ease(t)
    if (t < 1) frame = requestAnimationFrame(step)
    else current.value = props.to
  }
  frame = requestAnimationFrame(step)
}

onMounted(() => {
  const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
  if (reduced) {
    current.value = props.to
    return
  }

  observer = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (entry.isIntersecting) {
        run()
        observer?.disconnect()
      }
    }
  }, { threshold: 0.4 })

  if (el.value) observer.observe(el.value)
})

onUnmounted(() => {
  observer?.disconnect()
  cancelAnimationFrame(frame)
})
</script>

<template>
  <span ref="el" class="tabular-nums">{{ display }}</span>
</template>
