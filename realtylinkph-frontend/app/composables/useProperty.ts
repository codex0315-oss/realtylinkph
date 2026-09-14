import type {
  ApiResponse,
  PaginatedResponse,
  Property,
  PropertyFilters,
  CreatePropertyRequest,
  UpdatePropertyRequest,
} from '~/types'

/**
 * Mirrors `UploadPhotoRequest`'s `max:10240`. Checked in the browser too, so an
 * agent finds out a photo is too big when they pick it — not after filling in
 * the whole form and submitting.
 */
export const MAX_PHOTO_BYTES = 10 * 1024 * 1024
export const MAX_PHOTO_LABEL = '10 MB'

export const useProperty = () => {
  const api = useApi()

  const properties    = ref<Property[]>([])
  const property      = ref<Property | null>(null)
  const pagination    = ref<PaginatedResponse<Property>['meta'] | null>(null)
  const loading       = ref(false)
  const error         = ref<string | null>(null)

  // Price sanity thresholds — must mirror the backend (Property::RENT_MAX_SANE / SALE_MIN_SANE).
  const RENT_MAX_SANE = 1_000_000
  const SALE_MIN_SANE = 100_000
  function priceLooksOff(price: number, offerType: string): boolean {
    if (!price || price <= 0) return false
    return offerType === 'rent' ? price >= RENT_MAX_SANE : price < SALE_MIN_SANE
  }
  /** Soft guard before save — resolves true to proceed (price ok or user confirmed), false to stop. */
  async function confirmPriceIfOdd(price: number, offerType: string): Promise<boolean> {
    if (!priceLooksOff(price, offerType) || !import.meta.client) return true

    const peso   = `₱${Number(price).toLocaleString('en-PH')}`
    const isRent = offerType === 'rent'

    return await useConfirm()({
      title:   isRent ? 'That looks high for a monthly rent' : 'That looks low for a sale price',
      message: isRent
        ? `${peso} per month is well above the usual range. Did you mean to list this For Sale instead?`
        : `${peso} is well below the usual range for a sale. Please double-check the figure.`,
      consequences: [
        isRent ? 'Keep it as For Rent, or go back and switch to For Sale' : 'Keep it, or go back and correct the price',
        'Listings with an unusual price are not eligible to be featured',
      ],
      confirmLabel: 'Keep this price',
      cancelLabel:  'Let me fix it',
      tone: 'warning',
    })
  }

  async function fetchProperties(filters?: PropertyFilters): Promise<void> {
    loading.value = true
    error.value   = null
    try {
      const res = await api.get<PaginatedResponse<Property>>('/properties', filters as Record<string, unknown>)
      properties.value = res.data
      pagination.value  = res.meta
    } catch (e) {
      error.value = extractError(e)
    } finally {
      loading.value = false
    }
  }

  async function fetchProperty(id: number): Promise<void> {
    loading.value = true
    error.value   = null
    try {
      const res = await api.get<ApiResponse<Property>>(`/properties/${id}`)
      property.value = res.data
    } catch (e) {
      error.value = extractError(e)
    } finally {
      loading.value = false
    }
  }

  async function createProperty(payload: CreatePropertyRequest): Promise<Property | null> {
    loading.value = true
    error.value   = null
    try {
      const res = await api.post<ApiResponse<Property>>('/properties', payload)
      return res.data
    } catch (e) {
      error.value = extractError(e)
      return null
    } finally {
      loading.value = false
    }
  }

  async function updateProperty(id: number, payload: UpdatePropertyRequest): Promise<Property | null> {
    loading.value = true
    error.value   = null
    try {
      const res = await api.put<ApiResponse<Property>>(`/properties/${id}`, payload)
      return res.data
    } catch (e) {
      error.value = extractError(e)
      return null
    } finally {
      loading.value = false
    }
  }

  async function deleteProperty(id: number): Promise<boolean> {
    try {
      await api.del(`/properties/${id}`)
      return true
    } catch (e) {
      error.value = extractError(e)
      return false
    }
  }

  async function publishProperty(id: number): Promise<Property | null> {
    try {
      const res = await api.post<ApiResponse<Property>>(`/properties/${id}/publish`)
      return res.data
    } catch (e) {
      error.value = extractError(e)
      return null
    }
  }

  async function unpublishProperty(id: number): Promise<Property | null> {
    try {
      const res = await api.post<ApiResponse<Property>>(`/properties/${id}/unpublish`)
      return res.data
    } catch (e) {
      error.value = extractError(e)
      return null
    }
  }

  async function markSold(id: number): Promise<Property | null> {
    try {
      const res = await api.post<ApiResponse<Property>>(`/properties/${id}/mark-sold`)
      return res.data
    } catch (e) {
      error.value = extractError(e)
      return null
    }
  }

  async function relistProperty(id: number): Promise<Property | null> {
    try {
      const res = await api.post<ApiResponse<Property>>(`/properties/${id}/relist`)
      return res.data
    } catch (e) {
      error.value = extractError(e)
      return null
    }
  }

  async function fetchInventory(page = 1): Promise<void> {
    loading.value = true
    try {
      const res = await api.get<PaginatedResponse<Property>>('/my-inventory', { page })
      properties.value = res.data
      pagination.value  = res.meta
    } catch (e) {
      error.value = extractError(e)
    } finally {
      loading.value = false
    }
  }

  async function generateDescription(
    payload: {
      type?: string
      offer_type?: string
      price?: number
      bedrooms?: number
      bathrooms?: number
      floor_area?: number
      lot_area?: number
      address?: string
    },
    photos: File[] = [],
  ): Promise<string | null> {
    try {
      const fd = new FormData()
      for (const [k, v] of Object.entries(payload)) {
        if (v !== undefined && v !== null && v !== '') fd.append(k, String(v))
      }
      photos.slice(0, 6).forEach(f => fd.append('photos[]', f))
      const res = await api.postForm<ApiResponse<{ description: string }>>('/ai/generate-description', fd)
      return res.data.description
    } catch (e) {
      error.value = extractError(e)
      return null
    }
  }

  async function uploadPhoto(propertyId: number, file: File, sortOrder = 0, is360 = false): Promise<boolean> {
    const form = new FormData()
    form.append('photo', file)
    form.append('sort_order', String(sortOrder))
    if (is360) form.append('is_360', '1')
    try {
      await api.postForm(`/properties/${propertyId}/photos`, form)
      return true
    } catch (e) {
      error.value = extractError(e)
      return false
    }
  }

  async function deletePhoto(propertyId: number, photoId: number): Promise<boolean> {
    try {
      await api.del(`/properties/${propertyId}/photos/${photoId}`)
      return true
    } catch {
      return false
    }
  }

  async function getFeatured(): Promise<Property[]> {
    try {
      // Merit-ranked featured set (gates + quality score + 1-per-agent fairness).
      const res = await api.get<ApiResponse<Property[]>>('/properties/featured')
      return res.data
    } catch {
      return []
    }
  }

  async function fetchMyListings(page = 1): Promise<void> {
    loading.value = true
    try {
      const res = await api.get<PaginatedResponse<Property>>('/my-listings', { page })
      properties.value = res.data
      pagination.value  = res.meta
    } catch (e) {
      error.value = extractError(e)
    } finally {
      loading.value = false
    }
  }

  return {
    properties,
    property,
    pagination,
    loading,
    error,
    fetchProperties,
    fetchProperty,
    createProperty,
    updateProperty,
    deleteProperty,
    publishProperty,
    unpublishProperty,
    markSold,
    relistProperty,
    fetchInventory,
    priceLooksOff,
    confirmPriceIfOdd,
    generateDescription,
    uploadPhoto,
    deletePhoto,
    getFeatured,
    fetchMyListings,
  }
}

function extractError(e: unknown): string {
  if (typeof e === 'object' && e !== null) {
    const data = (e as { data?: { message?: string; errors?: Record<string, string[]> } }).data
    // Prefer the specific field message: the API wraps validation failures in a
    // generic "Validation failed.", so reading `message` alone threw away the
    // only part that tells the agent what to actually fix.
    if (data?.errors) {
      const first = Object.values(data.errors).flat().filter(Boolean)[0]
      if (first) return first
    }
    if (data?.message) return data.message
  }
  return 'Something went wrong.'
}
