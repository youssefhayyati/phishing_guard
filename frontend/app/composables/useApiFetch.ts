/**
 * Thin wrapper around $fetch for talking to the Laravel API.
 * - Prefixes the configured API base URL
 * - Attaches the Sanctum bearer token (read from localStorage)
 * - Normalises the response to `{ data }`
 */
export async function useApiFetch<T = unknown>(
  path: string,
  options: Parameters<typeof $fetch>[1] = {},
): Promise<{ data: T }> {
  const config = useRuntimeConfig()

  let token: string | null = null
  if (import.meta.client) {
    token = localStorage.getItem('pg_token')
  }

  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...(options.headers as Record<string, string> | undefined),
  }
  if (token) headers.Authorization = `Bearer ${token}`

  const data = await $fetch<T>(path, {
    baseURL: config.public.apiBase,
    ...options,
    headers,
  })

  return { data }
}
