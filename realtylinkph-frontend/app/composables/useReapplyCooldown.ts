import type { MaybeRefOrGetter } from 'vue'

/**
 * Live countdown for the 12h re-apply cool-down after a rejected agent
 * application. Pass the profile's `reapply_at` ISO string (ref/getter/plain).
 */
export function useReapplyCooldown(reapplyAtIso: MaybeRefOrGetter<string | null | undefined>) {
  const now = ref(Date.now())
  let timer: ReturnType<typeof setInterval> | null = null

  onMounted(() => { timer = setInterval(() => { now.value = Date.now() }, 1000) })
  onUnmounted(() => { if (timer) clearInterval(timer) })

  const target = computed(() => {
    const iso = toValue(reapplyAtIso)
    return iso ? new Date(iso).getTime() : null
  })

  const inCooldown = computed(() => target.value !== null && target.value > now.value)

  // "8h 12m" while hours remain, then "12m 30s", then "30s".
  const text = computed(() => {
    if (!inCooldown.value || target.value === null) return ''
    let s = Math.max(0, Math.floor((target.value - now.value) / 1000))
    const h = Math.floor(s / 3600); s -= h * 3600
    const m = Math.floor(s / 60);   s -= m * 60
    if (h > 0) return `${h}h ${m}m`
    if (m > 0) return `${m}m ${s}s`
    return `${s}s`
  })

  return { inCooldown, text }
}
