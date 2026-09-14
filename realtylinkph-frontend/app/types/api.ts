export interface ApiResponse<T> {
  success: boolean
  message: string
  data: T
}

export interface PaginatedMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface PaginatedResponse<T> {
  success: boolean
  message: string
  data: T[]
  meta: PaginatedMeta
}

export interface ApiError {
  success: false
  message: string
  errors: Record<string, string[]>
}

export interface LoginRequest {
  email: string
  password: string
}

export interface RegisterRequest {
  name: string
  email: string
  password: string
  password_confirmation: string
  role_type?: 'buyer' | 'ghost_buyer'
}
