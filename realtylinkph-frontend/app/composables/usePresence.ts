import type { ApiResponse } from '~/types'

export interface Presence {
  last_seen_at: string | null
  is_online: boolean
}

export const usePresence = () => {
  const api = useApi()

  async function fetchPresence(userId: number): Promise<Presence | null> {
    try {
      const res = await api.get<ApiResponse<Presence>>(`/users/${userId}/presence`)
      return res.data
    } catch {
      return null
    }
  }

  return { fetchPresence }
}

/**
 * "Active now" / "Active just now" / "Active 20 mins ago" / "Active 3 hours ago"
 * / "Active 2 days ago" / "Active 3 weeks ago" — exact, human online status.
 */
export function lastSeenLabel(p: Presence | null | undefined, now: number = Date.now()): string {
  if (!p) return ''
  if (p.is_online) return 'Active now'
  if (!p.last_seen_at) return 'Offline'
  const diff = Math.max(0, now - new Date(p.last_seen_at).getTime())
  const mins = Math.floor(diff / 60_000)
  if (mins < 1)  return 'Active just now'
  if (mins < 60) return `Active ${mins} ${mins === 1 ? 'min' : 'mins'} ago`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24)  return `Active ${hrs} ${hrs === 1 ? 'hour' : 'hours'} ago`
  const days = Math.floor(hrs / 24)
  if (days < 7)  return `Active ${days} ${days === 1 ? 'day' : 'days'} ago`
  const weeks = Math.floor(days / 7)
  return `Active ${weeks} ${weeks === 1 ? 'week' : 'weeks'} ago`
}
