import type { ApiResponse, PaginatedResponse, User } from '~/types'

export const useAdmin = () => {
  const api = useApi()

  const users      = ref<User[]>([])
  const pagination = ref<PaginatedResponse<User>['meta'] | null>(null)
  const loading    = ref(false)
  const error      = ref<string | null>(null)

  const stats = ref<{ total: number; buyers: number; agents: number; admins: number } | null>(null)
  async function fetchStats(): Promise<void> {
    try {
      const res = await api.get<ApiResponse<{ total: number; buyers: number; agents: number; admins: number }>>('/admin/stats')
      stats.value = res.data
    } catch { /* non-critical */ }
  }

  async function fetchUsers(opts: { page?: number; role?: string; search?: string } = {}): Promise<void> {
    loading.value = true
    error.value   = null
    try {
      const params: Record<string, unknown> = { page: opts.page ?? 1 }
      if (opts.role)   params.role   = opts.role
      if (opts.search) params.search = opts.search
      const res = await api.get<PaginatedResponse<User>>('/admin/users', params)
      users.value      = res.data
      pagination.value = res.meta
    } catch (e) {
      error.value = extractError(e)
    } finally {
      loading.value = false
    }
  }

  async function createAdmin(payload: { name: string; email: string; password: string }): Promise<User | null> {
    error.value = null
    try {
      const res = await api.post<ApiResponse<User>>('/admin/admins', payload)
      return res.data
    } catch (e) {
      error.value = extractError(e)
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

  return { users, pagination, loading, error, stats, fetchStats, fetchUsers, createAdmin, deleteUser }
}

function extractError(e: unknown): string {
  if (typeof e === 'object' && e !== null) {
    const data = (e as { data?: { message?: string } }).data
    if (data?.message) return data.message
  }
  return 'Something went wrong.'
}
