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
  /** Unsent for everyone: the text is gone (body is null) and the bubble reads "… unsent a message". */
  unsent?: boolean
  /** The sender changed the text (shown as "Edited"). */
  edited?: boolean
  edited_at?: string | null
  /** Pinned by either person; listed in the chat's "Pinned messages" bar. */
  pinned_at?: string | null
  pinned_by_id?: number | null
  sender?: User
  /**
   * Client-only. Present on messages the user just sent: 'sending' until the
   * API confirms (then the real row replaces this one), 'failed' if it didn't.
   */
  local_status?: 'sending' | 'failed'
  /** Client-only key that ties an optimistic bubble to the retry action. */
  client_id?: string
  /** Photos, videos and files sent with the message (empty once unsent). */
  attachments?: MessageAttachment[]
  /** "📷 2 photos" — for previews when there is no text. */
  attachment_summary?: string | null
  /** Client-only: upload progress (0–100) while files are being sent. */
  upload_progress?: number
}

export interface MessageAttachment {
  id: number
  kind: 'image' | 'video' | 'file'
  name: string
  mime: string
  size: number
  width?: number | null
  height?: number | null
  url: string | null
  thumb_url?: string | null
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
