import axios from 'axios'

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
