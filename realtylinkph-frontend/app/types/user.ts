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
  created_at: string
  agent_profile?: import('./agent').AgentProfile | null
}
