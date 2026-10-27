export type UserRole = 'buyer' | 'ghost_buyer' | 'agent' | 'admin' | 'super_admin'

export interface User {
  id: number
  name: string
  email: string
  phone: string | null
  avatar: string | null
  /** Agent profile cover photo (null → the default navy banner). */
  cover_url?: string | null
  /** Vertical focal point of the cover, 0 (top) – 100 (bottom). */
  cover_position?: number
  role_type: UserRole
  theme?: 'light' | 'dark' | 'system'
  property_alerts?: boolean
  has_gcal?: boolean
  /** Own account only. False for "Continue with Google" sign-ups who never set one. */
  password_set?: boolean
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

/** One row of the admin audit trail (GET /admin/actions). */
export interface AdminAction {
  id: number
  admin_id: number | null
  admin_name: string
  action: string
  label: string
  subject_type: string | null
  subject_id: number | null
  subject_label: string
  details: Record<string, unknown> | null
  ip: string | null
  created_at: string
}
