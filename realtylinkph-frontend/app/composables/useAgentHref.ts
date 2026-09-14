/**
 * The correct agent-profile path for the current user:
 * - logged-in buyers → in-account page (keeps them inside the dashboard)
 * - everyone else (guests, agents) → public page
 */
export function useAgentHref() {
  const authStore = useAuthStore()
  return (id: number): string =>
    authStore.isAuthenticated && authStore.isBuyer
      ? `/dashboard/agents/${id}`
      : `/agents/${id}`
}
