import type { User } from './user'

export type VerificationStatus = 'pending' | 'approved' | 'rejected'
export type ApplicantType = 'salesperson' | 'broker'

export interface AgentProfile {
  id: number
  user_id: number
  applicant_type?: ApplicantType | null
  prc_number: string
  supervising_broker?: string | null
  status: VerificationStatus
  admin_note: string | null
  ai_comment?: string | null
  ai_assessed_at?: string | null
  reviewed_at?: string | null
  reapply_at?: string | null
  license_doc?: string | null
  accreditation_doc?: string | null
  valid_id?: string | null
  face_image?: string | null
  has_gcal: boolean
  created_at: string
  user?: User
}

export interface Agent extends User {
  agent_profile?: AgentProfile | null
  average_rating?: number
  review_count?: number
  listing_count?: number
}

export interface BlockedDate {
  id: number
  user_id: number
  date: string
  start_time?: string | null
  end_time?: string | null
  reason: string | null
  created_at: string
}
