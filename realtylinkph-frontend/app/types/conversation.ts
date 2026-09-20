import type { Property } from './property'
import type { User } from './user'

export interface Message {
  id: number
  conversation_id: number
  sender_id: number
  body: string
  /** Posted by RealtyLink AI while the agent was offline. */
  is_ai?: boolean
  is_read: boolean
  /** The other party's app received it (✓✓). Set by the server; null until then. */
  delivered_at?: string | null
  /** The other party had the thread open (✓✓ gold). */
  read_at?: string | null
  created_at: string
  sender?: User
  /**
   * Client-only. Present on messages the user just sent: 'sending' until the
   * API confirms (then the real row replaces this one), 'failed' if it didn't.
   */
  local_status?: 'sending' | 'failed'
  /** Client-only key that ties an optimistic bubble to the retry action. */
  client_id?: string
}

/** Server-sent receipt for the caller's own messages (MessagesReceipt event). */
export interface MessagesReceiptEvent {
  conversation_id: number
  by_user_id: number
  kind: 'delivered' | 'read'
  message_ids: number[]
  at: string
}

export interface Conversation {
  id: number
  property_id: number
  buyer_id: number
  agent_id: number
  last_message_at: string | null
  unread_count?: number
  property?: Property
  buyer?: User
  agent?: User
  latest_message?: Message | null
}
