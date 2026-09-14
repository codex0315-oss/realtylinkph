import type { ApiResponse, BlockedDate } from '~/types'

export const useAvailability = () => {
  const api = useApi()

  const blockedDates = ref<string[]>([])
  const loading      = ref(false)

  async function fetchAvailability(agentId: number): Promise<void> {
    loading.value = true
    try {
      const res = await api.get<ApiResponse<string[]>>(`/agents/${agentId}/availability`)
      blockedDates.value = res.data
    } finally {
      loading.value = false
    }
  }

  async function fetchMyBlockedDates(): Promise<BlockedDate[]> {
    try {
      const res = await api.get<ApiResponse<Array<{ id: number; agent_id: number; blocked_date: string | null; start_time: string | null; end_time: string | null; reason: string | null; created_at: string }>>>('/blocked-dates')
      // Backend stores `blocked_date` (ISO) — normalise to the `date` (YYYY-MM-DD) the UI uses.
      return res.data.map(b => ({
        id:         b.id,
        user_id:    b.agent_id,
        date:       (b.blocked_date ?? '').slice(0, 10),
        start_time: b.start_time ? b.start_time.slice(0, 5) : null,
        end_time:   b.end_time ? b.end_time.slice(0, 5) : null,
        reason:     b.reason,
        created_at: b.created_at,
      }))
    } catch {
      return []
    }
  }

  /** Block a whole day (omit times) or a time range (pass start/end as "HH:MM"). */
  async function blockDate(date: string, reason?: string, startTime?: string, endTime?: string): Promise<boolean> {
    try {
      const payload: Record<string, unknown> = { blocked_date: date, reason }
      if (startTime && endTime) {
        payload.start_time = startTime
        payload.end_time   = endTime
      }
      await api.post('/blocked-dates', payload)
      return true
    } catch {
      return false
    }
  }

  /** Fully-blocked & limited (partially-blocked) upcoming dates for an agent. */
  async function fetchUnavailableDates(agentId: number): Promise<{ blocked: string[]; limited: string[] }> {
    try {
      const res = await api.get<ApiResponse<{ blocked: string[]; limited: string[] }>>(`/agents/${agentId}/unavailable-dates`)
      return res.data
    } catch {
      return { blocked: [], limited: [] }
    }
  }

  async function unblockDate(id: number): Promise<boolean> {
    try {
      await api.del(`/blocked-dates/${id}`)
      return true
    } catch {
      return false
    }
  }

  function isBlocked(dateStr: string): boolean {
    return blockedDates.value.includes(dateStr)
  }

  /** Available time slots (e.g. ["08:00","08:30"]) for an agent on a given date. */
  async function fetchSlots(agentId: number, date: string): Promise<string[]> {
    try {
      const res = await api.get<ApiResponse<{ date: string; slots: string[] }>>(
        `/agents/${agentId}/availability`, { date },
      )
      return res.data.slots
    } catch {
      return []
    }
  }

  return {
    blockedDates,
    loading,
    fetchAvailability,
    fetchMyBlockedDates,
    blockDate,
    unblockDate,
    isBlocked,
    fetchSlots,
    fetchUnavailableDates,
  }
}
