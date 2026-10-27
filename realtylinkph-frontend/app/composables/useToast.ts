export interface ToastAction { label: string; run: () => void }

export interface Toast {
  id: number
  message: string
  type: 'success' | 'info' | 'error'
  /** A second, quieter line under the message. */
  description?: string
  /** Optional button on the toast (e.g. "Undo", "View"). */
  action?: ToastAction
  /** How long it stays, in ms (the progress line counts this down). */
  ms: number
}

export interface ToastOptions {
  description?: string
  action?: ToastAction
  ms?: number
}

let seq = 0
const timers = new Map<number, ReturnType<typeof setTimeout>>()

/**
 * Small notices at the bottom of the screen. Errors and toasts with a button
 * stay longer, so there's time to read or use them.
 *
 *   toast.success('Published')
 *   toast.success('Published', { description: 'Buyers can see it now', action: { label: 'View', run } })
 */
export const useToast = () => {
  const toasts = useState<Toast[]>('toasts', () => [])

  function dismiss(id: number): void {
    clearTimeout(timers.get(id))
    timers.delete(id)
    toasts.value = toasts.value.filter(t => t.id !== id)
  }

  function add(message: string, type: Toast['type'], opts: ToastOptions = {}): void {
    const id = ++seq
    const ms = opts.ms ?? (opts.action ? 6000 : type === 'error' ? 5000 : opts.description ? 4000 : 3000)
    // Never more than three at once; the oldest goes first.
    toasts.value = [...toasts.value.slice(-2), { id, message, type, description: opts.description, action: opts.action, ms }]
    if (import.meta.client) timers.set(id, setTimeout(() => dismiss(id), ms))
  }

  return {
    toasts,
    dismiss,
    success: (m: string, opts?: ToastOptions) => add(m, 'success', opts),
    info:    (m: string, opts?: ToastOptions) => add(m, 'info', opts),
    error:   (m: string, opts?: ToastOptions) => add(m, 'error', opts),
    /** A toast with one action, shown longer so there's time to use it. */
    withAction: (m: string, label: string, run: () => void, ms = 6000) => add(m, 'info', { action: { label, run }, ms }),
  }
}
