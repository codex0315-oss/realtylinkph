/**
 * The correct property-detail path for the current user:
 * - anyone signed in → the in-account page, so they stay inside the dashboard
 * - guests            → the public page
 *
 * Agents used to be sent to the public page as well, which drops them out of
 * the dashboard onto the marketing chrome (public navbar, footer, "Get started")
 * — from Messages or History that reads as being thrown to the landing page.
 * The in-account page already handles the agent role, so it serves both.
 */
export function usePropertyHref() {
  const authStore = useAuthStore()
  return (id: number): string =>
    authStore.isAuthenticated
      ? `/dashboard/properties/${id}`
      : `/properties/${id}`
}
