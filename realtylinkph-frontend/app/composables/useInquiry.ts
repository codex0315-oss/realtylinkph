import type { Inquiry, PaginatedResponse } from '~/types'

export const useInquiry = () => {
  const api = useApi()

  const inquiries = ref<Inquiry[]>([])
  const loading   = ref(false)
  const error     = ref<string | null>(null)

  /** Buyer submits an inquiry on a property (public route). */
  async function submitInquiry(payload: {
    property_id: number
    name?: string
    email?: string
    phone?: string
    message: string
  }): Promise<boolean> {
    loading.value = true
    error.value   = null
    try {
      const { property_id, ...body } = payload
      await api.post(`/properties/${property_id}/inquiries`, body)
      return true
    } catch (e) {
      error.value = extractError(e)
      return false
    } finally {
      loading.value = false
    }
  }

  /** Agent: list all inquiries across their listings. */
  async function fetchInquiries(page = 1): Promise<void> {
    loading.value = true
    try {
      const res = await api.get<PaginatedResponse<Inquiry>>('/inquiries', { page })
      inquiries.value = res.data
    } catch (e) {
      error.value = extractError(e)
    } finally {
      loading.value = false
    }
  }

  /** Agent: mark an inquiry as read. */
  async function markRead(inquiryId: number): Promise<boolean> {
    try {
      await api.post(`/inquiries/${inquiryId}/read`)
      const found = inquiries.value.find(i => i.id === inquiryId)
      if (found) found.is_read = true
      return true
    } catch {
      return false
    }
  }

  const unreadCount = computed(() => inquiries.value.filter(i => !i.is_read).length)

  return { inquiries, loading, error, unreadCount, submitInquiry, fetchInquiries, markRead }
}

function extractError(e: unknown): string {
  if (typeof e === 'object' && e !== null) {
    const data = (e as { data?: { message?: string; errors?: Record<string, string[]> } }).data
    // Prefer the field message: the API wraps validation failures in a generic
    // "Validation failed.", which tells the sender nothing about what to fix.
    if (data?.errors) {
      const first = Object.values(data.errors).flat().filter(Boolean)[0]
      if (first) return first
    }
    if (data?.message) return data.message
  }
  return 'Something went wrong.'
}
