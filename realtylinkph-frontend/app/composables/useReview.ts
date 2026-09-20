import type { ApiResponse, AgentReview, PaginatedResponse, ReviewEligibility } from '~/types'

export const useReview = () => {
  const api = useApi()

  const reviews    = ref<AgentReview[]>([])
  const pagination = ref<PaginatedResponse<AgentReview>['meta'] | null>(null)
  const loading    = ref(false)
  const error      = ref<string | null>(null)

  async function fetchAgentReviews(agentId: number, page = 1): Promise<void> {
    loading.value = true
    try {
      const res = await api.get<PaginatedResponse<AgentReview>>(
        `/agents/${agentId}/reviews`,
        { page }
      )
      reviews.value    = res.data
      pagination.value = res.meta
    } catch (e) {
      error.value = extractError(e)
    } finally {
      loading.value = false
    }
  }

  /** Create the buyer's review of an agent — by agent, or pinned to one viewing. */
  async function submitReview(payload: {
    agent_id?: number
    appointment_id?: number
    rating: number
    review_text?: string
  }): Promise<AgentReview | null> {
    loading.value = true
    error.value   = null
    try {
      const res = await api.post<ApiResponse<AgentReview>>('/reviews', payload)
      return res.data
    } catch (e) {
      error.value = extractError(e)
      return null
    } finally {
      loading.value = false
    }
  }

  /** Edit your own review (rating / text). */
  async function updateReview(reviewId: number, payload: { rating: number; review_text?: string }): Promise<AgentReview | null> {
    loading.value = true
    error.value   = null
    try {
      const res = await api.put<ApiResponse<AgentReview>>(`/reviews/${reviewId}`, payload)
      return res.data
    } catch (e) {
      error.value = extractError(e)
      return null
    } finally {
      loading.value = false
    }
  }

  const NOT_ELIGIBLE: ReviewEligibility = { eligible: false, basis: null, verified: false, appointments: [], conversation_id: null, review: null }

  /** May the signed-in buyer review this agent, on what basis, and have they already? */
  async function fetchEligibility(agentId: number): Promise<ReviewEligibility> {
    try {
      const res = await api.get<ApiResponse<ReviewEligibility>>(`/agents/${agentId}/reviewable`)
      return res.data
    } catch {
      return NOT_ELIGIBLE
    }
  }

  async function toggleVisibility(reviewId: number): Promise<boolean> {
    try {
      await api.post(`/admin/reviews/${reviewId}/toggle-visibility`)
      return true
    } catch {
      return false
    }
  }

  const averageRating = computed(() => {
    if (!reviews.value.length) return 0
    const sum = reviews.value.reduce((acc: number, r: AgentReview) => acc + r.rating, 0)
    return Math.round((sum / reviews.value.length) * 10) / 10
  })

  return {
    reviews,
    pagination,
    loading,
    error,
    averageRating,
    fetchAgentReviews,
    submitReview,
    updateReview,
    fetchEligibility,
    toggleVisibility,
  }
}

function extractError(e: unknown): string {
  if (typeof e === 'object' && e !== null) {
    const data = (e as { data?: { message?: string } }).data
    if (data?.message) return data.message
  }
  return 'Something went wrong.'
}
