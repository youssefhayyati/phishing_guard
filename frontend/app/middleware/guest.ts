// Keep authenticated users away from the login / register pages.
export default defineNuxtRouteMiddleware(async () => {
  if (import.meta.server) return
  const auth = useAuthStore()
  if (!auth.ready) await auth.init()
  if (auth.isAuthenticated) {
    return navigateTo('/')
  }
})
