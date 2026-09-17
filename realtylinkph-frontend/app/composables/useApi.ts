/** Shared $fetch wrapper that injects auth header and base URL. */
export const useApi = () => {
  const config    = useRuntimeConfig()
  const authStore = useAuthStore()

  function headers() {
    const h: Record<string, string> = { Accept: 'application/json' }
    if (authStore.token) h.Authorization = `Bearer ${authStore.token}`
    // Lets the server's broadcast(...)->toOthers() skip THIS browser's socket.
    // Echo adds this automatically for axios/jQuery but not for $fetch — without
    // it every sender also received their own message over the socket, so their
    // sent messages showed up twice.
    if (import.meta.client) {
      try {
        const id = useNuxtApp().$echo?.socketId()
        if (id) h['X-Socket-Id'] = id
      } catch { /* echo not ready yet — header simply omitted */ }
    }
    return h
  }

  function get<T>(path: string, query?: Record<string, unknown>): Promise<T> {
    return $fetch<T>(path, { baseURL: config.public.apiBase, headers: headers(), params: query })
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  function post<T>(path: string, body?: any): Promise<T> {
    return $fetch<T>(path, { baseURL: config.public.apiBase, method: 'POST', headers: headers(), body })
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  function put<T>(path: string, body?: any): Promise<T> {
    return $fetch<T>(path, { baseURL: config.public.apiBase, method: 'PUT', headers: headers(), body })
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  function patch<T>(path: string, body?: any): Promise<T> {
    return $fetch<T>(path, { baseURL: config.public.apiBase, method: 'PATCH', headers: headers(), body })
  }

  function del<T>(path: string): Promise<T> {
    return $fetch<T>(path, { baseURL: config.public.apiBase, method: 'DELETE', headers: headers() })
  }

  function postForm<T>(path: string, formData: FormData): Promise<T> {
    const h = headers()
    // Let browser set Content-Type with boundary for multipart
    return $fetch<T>(path, { baseURL: config.public.apiBase, method: 'POST', headers: h, body: formData })
  }

  /**
   * Multipart POST that reports upload progress. `$fetch` (and fetch itself)
   * expose no upload progress, so this one goes through XMLHttpRequest. The
   * percentage is bytes-on-the-wire — real, not an animation — which matters
   * for the agent application: several photos from a phone on mobile data.
   *
   * Rejects with the same `{ data: { message, errors } }` shape $fetch uses,
   * so callers' existing error extraction keeps working.
   */
  function postFormWithProgress<T>(
    path: string,
    formData: FormData,
    onProgress: (percent: number) => void,
  ): Promise<T> {
    return new Promise<T>((resolve, reject) => {
      const xhr = new XMLHttpRequest()
      xhr.open('POST', `${config.public.apiBase}${path}`)
      for (const [k, v] of Object.entries(headers())) xhr.setRequestHeader(k, v)

      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) onProgress(Math.round((e.loaded / e.total) * 100))
      }

      xhr.onload = () => {
        let data: unknown = null
        try { data = JSON.parse(xhr.responseText) } catch { /* non-JSON body */ }
        if (xhr.status >= 200 && xhr.status < 300) resolve(data as T)
        else reject({ status: xhr.status, data })
      }
      xhr.onerror   = () => reject({ status: 0, data: { message: 'Network error — check your connection and try again.' } })
      xhr.ontimeout = () => reject({ status: 0, data: { message: 'The upload timed out. Please try again.' } })

      xhr.send(formData)
    })
  }

  return { get, post, put, patch, del, postForm, postFormWithProgress }
}
