import type { Property } from '~/types'

/**
 * Listings picked for side-by-side comparison on Browse (up to three).
 * Shared state, so the picks survive paging and filter changes.
 */
export const COMPARE_MAX = 3

export function useCompare() {
  const picks = useState<Property[]>('compare-picks', () => [])

  const has = (id: number) => picks.value.some(p => p.id === id)
  const full = computed(() => picks.value.length >= COMPARE_MAX)

  function toggle(p: Property) {
    if (has(p.id)) picks.value = picks.value.filter(x => x.id !== p.id)
    else if (!full.value) picks.value = [...picks.value, p]
  }
  function remove(id: number) { picks.value = picks.value.filter(x => x.id !== id) }
  function clear() { picks.value = [] }

  return { picks, has, full, toggle, remove, clear }
}
