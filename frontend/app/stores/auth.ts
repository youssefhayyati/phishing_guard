import { defineStore } from 'pinia'

export interface User {
  id: number
  name: string
  email: string
}

interface AuthState {
  user: User | null
  token: string | null
  ready: boolean
}

export const useAuthStore = defineStore('auth', {
  state: (): AuthState => ({
    user: null,
    token: null,
    ready: false,
  }),

  getters: {
    isAuthenticated: (state): boolean => !!state.token && !!state.user,
    initials: (state): string => {
      if (!state.user?.name) return '?'
      return state.user.name
        .split(' ')
        .map(p => p[0])
        .slice(0, 2)
        .join('')
        .toUpperCase()
    },
  },

  actions: {
    setToken(token: string | null) {
      this.token = token
      if (import.meta.client) {
        if (token) localStorage.setItem('pg_token', token)
        else localStorage.removeItem('pg_token')
      }
    },

    async register(payload: { name: string; email: string; password: string; password_confirmation: string }) {
      const { data } = await useApiFetch<{ token: string; user: User }>('/api/register', {
        method: 'POST',
        body: payload,
      })
      this.setToken(data.token)
      this.user = data.user
    },

    async login(payload: { email: string; password: string }) {
      const { data } = await useApiFetch<{ token: string; user: User }>('/api/login', {
        method: 'POST',
        body: payload,
      })
      this.setToken(data.token)
      this.user = data.user
    },

    async fetchUser() {
      if (!this.token) return
      try {
        const { data } = await useApiFetch<{ user: User }>('/api/user')
        this.user = data.user
      } catch {
        this.setToken(null)
        this.user = null
      }
    },

    async logout() {
      try {
        if (this.token) await useApiFetch('/api/logout', { method: 'POST' })
      } catch {
        // ignore network errors on logout
      }
      this.setToken(null)
      this.user = null
    },

    async init() {
      if (import.meta.client) {
        const stored = localStorage.getItem('pg_token')
        if (stored) {
          this.token = stored
          await this.fetchUser()
        }
      }
      this.ready = true
    },
  },
})
