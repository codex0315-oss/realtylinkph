export interface Toast {
  id: number
  message: string
  type: 'success' | 'info' | 'error'
}

let seq = 0

export const useToast = () => {
  const toasts = useState<Toast[]>('toasts', () => [])

  function dismiss(id: number): void {
    toasts.value = toasts.value.filter(t => t.id !== id)
  }

  function add(message: string, type: Toast['type'] = 'success', ms = 2500): void {
    const id = ++seq
    toasts.value = [...toasts.value, { id, message, type }]
    if (import.meta.client) setTimeout(() => dismiss(id), ms)
  }

  return {
    toasts,
    dismiss,
    success: (m: string) => add(m, 'success'),
    info:    (m: string) => add(m, 'info'),
    error:   (m: string) => add(m, 'error'),
  }
}
