export type UserRole = 'buyer' | 'ghost_buyer' | 'agent' | 'admin' | 'super_admin'

export interface User {
  id: number
  name: string
  email: string
  phone: string | null
  avatar: string | null
  role_type: UserRole
  theme?: 'light' | 'dark' | 'system'
  property_alerts?: boolean
  has_gcal?: boolean
  email_verified_at: string | null
  last_seen_at?: string | null
  is_online?: boolean
  /** How reliably this person honours confirmed viewings. `rate` is null below the minimum sample. */
  reliability?: ViewingReliability
  /** ISO date until which booking is paused for repeated late cancellations. Own/admin only. */
  booking_locked_until?: string | null
  created_at: string
  agent_profile?: import('./agent').AgentProfile | null
}

export interface ViewingReliability {
  kept: number
  missed: number
  rate: number | null
  has_enough: boolean
}
