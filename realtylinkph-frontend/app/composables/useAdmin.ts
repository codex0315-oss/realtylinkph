import type { ApiResponse, PaginatedResponse, User } from '~/types'

export interface AdminStats {
  total: number; buyers: number; agents: number; admins: number; week: number
  /** Users page side panel: the five newest accounts, and every admin. */
  recent: User[]
  admin_list: User[]
}
export type UserSort = 'newest' | 'oldest' | 'name' | 'active'
export type UserQuickFilter = '' | 'online' | 'unverified' | 'applied'

/** /admin/overview: the admin home page. */
export interface AdminOverview {
  attention: { applications: number; hidden_reviews: number; unpublished: number; support: number }
  pending_applications: Array<{ id: number; name: string; email: string; avatar: string | null; applicant_type: 'broker' | 'salesperson'; submitted_at: string }>
  numbers: Record<'users' | 'agents' | 'listings' | 'viewings', { total: number; week: number }>
  online: number
}

/** /admin/users/{id}: the Users page's details drawer. */
export interface AdminUserDetails {
  user: User
  counts: {
    listings: number; published: number
    viewings_booked: number; viewings_hosted: number
    reviews_written: number; reviews_received: number; rating: number
    saved: number
  }
}

export const useAdmin = () => {
  const api = useApi()

  const users      = ref<User[]>([])
  const pagination = ref<PaginatedResponse<User>['meta'] | null>(null)
  const loading    = ref(false)
  const error      = ref<string | null>(null)

  const stats = ref<AdminStats | null>(null)
  async function fetchStats(): Promise<void> {
    try {
      const res = await api.get<ApiResponse<AdminStats>>('/admin/stats')
      stats.value = res.data
    } catch { /* non-critical */ }
  }

  const overview = ref<AdminOverview | null>(null)
  async function fetchOverview(): Promise<void> {
    try {
      const res = await api.get<ApiResponse<AdminOverview>>('/admin/overview')
      overview.value = res.data
    } catch (e) {
      error.value = extractError(e)
    }
  }

  async function fetchUser(id: number): Promise<AdminUserDetails | null> {
    try {
      const res = await api.get<ApiResponse<AdminUserDetails>>(`/admin/users/${id}`)
      return res.data
    } catch {
      return null
    }
  }

  async function fetchUsers(opts: { page?: number; role?: string; search?: string; sort?: UserSort; only?: UserQuickFilter } = {}): Promise<void> {
    loading.value = true
    error.value   = null
    try {
      const params: Record<string, unknown> = { page: opts.page ?? 1 }
      if (opts.role)   params.role   = opts.role
      if (opts.search) params.search = opts.search
      if (opts.sort && opts.sort !== 'newest') params.sort = opts.sort
      if (opts.only)   params.only   = opts.only
      const res = await api.get<PaginatedResponse<User>>('/admin/users', params)
      users.value      = res.data
      pagination.value = res.meta
    } catch (e) {
      error.value = extractError(e)
    } finally {
      loading.value = false
    }
  }

  async function createAdmin(payload: { name: string; email: string; password: string; password_confirmation: string }): Promise<User | null> {
    error.value = null
    try {
      const res = await api.post<ApiResponse<User>>('/admin/admins', payload)
      return res.data
    } catch (e) {
      error.value = extractError(e, true)
      return null
    }
  }

  async function deleteUser(id: number): Promise<boolean> {
    error.value = null
    try {
      await api.del(`/admin/users/${id}`)
      return true
    } catch (e) {
      error.value = extractError(e)
      return false
    }
  }

  return {
    users, pagination, loading, error, stats, overview,
    fetchStats, fetchOverview, fetchUser, fetchUsers, createAdmin, deleteUser,
  }
}

/** `fieldFirst`: prefer the first field error ("The email has already been taken."). */
function extractError(e: unknown, fieldFirst = false): string {
  if (typeof e === 'object' && e !== null) {
    const data = (e as { data?: { message?: string; errors?: Record<string, string[]> } }).data
    const field = data?.errors ? Object.values(data.errors).flat()[0] : undefined
    if (fieldFirst && field) return field
    if (data?.message) return data.message
  }
  return 'Something went wrong.'
}
