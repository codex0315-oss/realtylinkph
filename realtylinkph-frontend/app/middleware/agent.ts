/**
 * Agent-only pages (My Listings, the listing wizard, Calendar, Inquiries).
 *
 * The server already refuses a buyer's saves, but the page itself should not
 * open for them: send buyers to their dashboard with a short note instead.
 * On a fresh load this runs before the session plugin and before app.vue
 * fetches the profile, so restore the saved token and fetch the profile here
 * first. Guests and admins are left to the dashboard layout, which sends them
 * to sign-in or to /admin.
 */
export default defineNuxtRouteMiddleware(async () => {
  if (import.meta.server) return
  const authStore = useAuthStore()
  if (!authStore.isAuthenticated) authStore.initFromStorage()
  if (!authStore.isAuthenticated) return
  if (!authStore.user) await useAuth().fetchMe()
  if (authStore.user && !authStore.isAgent && !authStore.isAdmin) {
    useToast().info('That page is for agents', { description: 'Become an agent from your dashboard to list homes.' })
    return navigateTo('/dashboard', { replace: true })
  }
})
