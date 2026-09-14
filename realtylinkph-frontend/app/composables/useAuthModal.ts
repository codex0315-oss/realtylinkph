export type AuthModalMode = 'login' | 'register'

export const useAuthModal = () => {
  const isOpen = useState<boolean>('auth-modal-open', () => false)
  const mode   = useState<AuthModalMode>('auth-modal-mode', () => 'login')

  function open(m: AuthModalMode = 'login') {
    mode.value   = m
    isOpen.value = true
  }

  function close() {
    isOpen.value = false
  }

  function setMode(m: AuthModalMode) {
    mode.value = m
  }

  return { isOpen, mode, open, close, setMode }
}
