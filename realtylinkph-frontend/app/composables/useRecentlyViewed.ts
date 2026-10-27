import type { Property } from '~/types'

/**
 * Listings this person opened recently, newest first, so the dashboard can
 * offer "pick up where you left off". Kept in this browser only (per user),
 * as a small snapshot rather than the whole listing.
 */
export interface RecentListing {
  id: number
  title: string
  price: string
  offer_type: Property['offer_type']
  address: string
  photo: string | null
  bedrooms: number
  bathrooms: number
  viewedAt: number
}

const MAX = 8

export function useRecentlyViewed() {
  const authStore = useAuthStore()
  const items = useState<RecentListing[]>('recently-viewed', () => [])
  const key = computed(() => `rl-recent-${authStore.user?.id ?? 'guest'}`)

  function load() {
    if (!import.meta.client) return
    try {
      const saved = JSON.parse(localStorage.getItem(key.value) ?? '[]')
      items.value = Array.isArray(saved) ? saved : []
    } catch { items.value = [] }
  }

  function record(p: Property) {
    if (!import.meta.client) return
    load()
    const photo = p.photos?.find(ph => !ph.is_360) ?? p.photos?.[0]
    const entry: RecentListing = {
      id: p.id, title: p.title, price: p.price, offer_type: p.offer_type, address: p.address,
      photo: photo ? (photo.thumb_url ?? photo.url) : null,
      bedrooms: p.bedrooms, bathrooms: p.bathrooms, viewedAt: Date.now(),
    }
    items.value = [entry, ...items.value.filter(i => i.id !== p.id)].slice(0, MAX)
    try { localStorage.setItem(key.value, JSON.stringify(items.value)) } catch { /* storage blocked */ }
  }

  return { items, load, record }
}
