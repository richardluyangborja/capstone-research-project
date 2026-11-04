import axios from 'axios'
import { clearAuthSession } from './authEvents'

// Single API client. Base URL is the versioned Laravel API (ADR-0007).
export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api/v1',
  headers: { Accept: 'application/json' },
})

// JWT bearer auth (no cookies — see AGENTS.md). Token stored by the auth flow later.
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// An expired token must not leave the shell looking signed in (spec 16 §Sign-in modal). The
// auth provider listens for the event and the protected route asks for a sign-in with `next`.
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      clearAuthSession()
    }

    return Promise.reject(error)
  },
)
