export type { ApiResponse, PaginatedResponse, PaginatedMeta, ApiError, LoginRequest, RegisterRequest } from './api'
export type { User, UserRole, AdminAction } from './user'
export type { Agent, AgentProfile, VerificationStatus, BlockedDate } from './agent'
export type {
  Property,
  PropertyPhoto,
  PropertyType,
  PropertyStatus,
  OfferType,
  PropertyFilters,
  CreatePropertyRequest,
  UpdatePropertyRequest,
} from './property'
export { PROPERTY_TYPES, OFFER_TYPES } from './property'
export type { Appointment, AppointmentStatus, BookAppointmentRequest } from './appointment'
export type { Conversation, Message } from './conversation'
export type { Inquiry } from './inquiry'
export type { AgentReview } from './review'

export interface Notification {
  id: string
  type: string
  payload: Record<string, unknown>
  read_at: string | null
  created_at: string
}
