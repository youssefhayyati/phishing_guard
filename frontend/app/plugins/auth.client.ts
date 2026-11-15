// Initialise auth state from the stored token on first client load.
export default defineNuxtPlugin(async () => {
  const auth = useAuthStore()
  if (!auth.ready) {
    await auth.init()
  }
})
