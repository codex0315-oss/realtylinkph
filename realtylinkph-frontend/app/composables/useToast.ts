export interface Toast {
  id: number
  message: string
  type: 'success' | 'info' | 'error'
  /** Optional button on the toast (e.g. "Undo"). */
  action?: { label: string; run: () => void }
}

let seq = 0

export const useToast = () => {
  const toasts = useState<Toast[]>('toasts', () => [])

  function dismiss(id: number): void {
    toasts.value = toasts.value.filter(t => t.id !== id)
  }

  function add(message: string, type: Toast['type'] = 'success', ms = 2500, action?: Toast['action']): void {
    const id = ++seq
    toasts.value = [...toasts.value, { id, message, type, action }]
    if (import.meta.client) setTimeout(() => dismiss(id), ms)
  }

  return {
    toasts,
    dismiss,
    success: (m: string) => add(m, 'success'),
    info:    (m: string) => add(m, 'info'),
    error:   (m: string) => add(m, 'error'),
    /** A toast with one action, shown longer so there's time to use it. */
    withAction: (m: string, label: string, run: () => void, ms = 5000) => add(m, 'info', ms, { label, run }),
  }
}
