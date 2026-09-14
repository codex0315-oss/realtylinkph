export default defineNuxtRouteMiddleware(() => {
  const authStore = useAuthStore()
  if (!authStore.isAuthenticated) {
    // Send guests home and open the login modal instead of a dedicated page.
    return navigateTo('/?auth=login')
  }
})
