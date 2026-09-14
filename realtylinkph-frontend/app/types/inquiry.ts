import type { Property } from './property'

export interface Inquiry {
  id: number
  property_id: number
  user_id: number | null
  name: string
  email: string
  phone: string | null
  message: string
  is_read: boolean
  is_ghost_buyer: boolean
  created_at: string
  property?: Property
}
