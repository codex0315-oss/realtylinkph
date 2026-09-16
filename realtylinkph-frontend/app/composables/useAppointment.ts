import type { ApiResponse, Appointment, BookAppointmentRequest, PaginatedResponse } from '~/types'

export const useAppointment = () => {
  const api = useApi()

  // Shared across pages + the dashboard layout (History nav badge) so a cancel/
  // refetch on one screen updates everywhere.
  const appointments = useState<Appointment[]>('appointments', () => [])
  const pagination   = ref<PaginatedResponse<Appointment>['meta'] | null>(null)
  const loading      = ref(false)
  const error        = ref<string | null>(null)

  async function fetchMyAppointments(page = 1): Promise<void> {
    loading.value = true
    error.value   = null
    try {
      const res = await api.get<PaginatedResponse<Appointment>>('/appointments', { page })
      appointments.value = res.data
      pagination.value   = res.meta
    } catch (e) {
      error.value = extractError(e)
    } finally {
      loading.value = false
    }
  }

  async function book(payload: BookAppointmentRequest): Promise<Appointment | null> {
    loading.value = true
    error.value   = null
    try {
      // Booking lives under the property; the agent is derived from it server-side.
      const res = await api.post<ApiResponse<Appointment>>(
        `/properties/${payload.property_id}/appointments`,
        { preferred_datetime: payload.preferred_datetime, notes: payload.notes },
      )
      return res.data
    } catch (e) {
      error.value = extractError(e)
      return null
    } finally {
      loading.value = false
    }
  }

  async function confirm(appointmentId: number): Promise<boolean> {
    try {
      await api.post(`/appointments/${appointmentId}/confirm`)
      return true
    } catch (e) {
      error.value = extractError(e)
      return false
    }
  }

  async function complete(appointmentId: number): Promise<boolean> {
    try {
      await api.post(`/appointments/${appointmentId}/complete`)
      return true
    } catch (e) {
      error.value = extractError(e)
      return false
    }
  }

  /**
   * Cancel a viewing. A reason category is required by the API — a confirmed
   * viewing can no longer be dropped without one.
   */
  async function cancel(appointmentId: number, reasonCode: string, reasonNote?: string): Promise<boolean> {
    try {
      await api.post(`/appointments/${appointmentId}/cancel`, {
        reason_code: reasonCode,
        reason_note: reasonNote || undefined,
      })
      return true
    } catch (e) {
      error.value = extractError(e)
      return false
    }
  }

  return {
    appointments,
    pagination,
    loading,
    error,
    fetchMyAppointments,
    book,
    confirm,
    complete,
    cancel,
  }
}

function extractError(e: unknown): string {
  if (typeof e === 'object' && e !== null) {
    const data = (e as { data?: { message?: string; errors?: Record<string, string[]> } }).data
    // Prefer the field message — the API wraps validation in a generic
    // "Validation failed.", which wouldn't say which field was wrong.
    if (data?.errors) {
      const first = Object.values(data.errors).flat().filter(Boolean)[0]
      if (first) return first
    }
    if (data?.message) return data.message
  }
  return 'Something went wrong.'
}
