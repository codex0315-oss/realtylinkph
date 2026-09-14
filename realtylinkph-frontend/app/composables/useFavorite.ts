import type { ApiResponse, PaginatedResponse, Property } from '~/types'

export const useFavorite = () => {
  const api = useApi()

  const favorites = ref<Property[]>([])
  const loading   = ref(false)

  async function fetchFavorites(): Promise<void> {
    loading.value = true
    try {
      const res = await api.get<PaginatedResponse<Property>>('/favorites')
      favorites.value = res.data
    } catch {
      favorites.value = []
    } finally {
      loading.value = false
    }
  }

  /** Toggle a property in the user's favorites. Returns true if now favorited. */
  async function toggleFavorite(propertyId: number): Promise<boolean> {
    const res = await api.post<ApiResponse<{ favorited: boolean }>>(
      `/properties/${propertyId}/favorite`,
    )
    return res.data.favorited
  }

  return { favorites, loading, fetchFavorites, toggleFavorite }
}
