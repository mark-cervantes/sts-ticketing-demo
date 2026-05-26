import { toast } from 'vue-sonner'

export function getCsrfToken(): string {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

export function buildQueryString(params: Record<string, string>): string {
  const qs = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value) qs.set(key, value)
  }
  return qs.toString()
}

export async function apiFetch<T>(url: string): Promise<T> {
  const response = await fetch(url, {
    headers: {
      Accept: 'application/json',
      'X-XSRF-TOKEN': getCsrfToken(),
    },
    credentials: 'same-origin',
  })

  if (response.status === 401) {
    toast.error('Session expired — please log in again.')
    throw new Error('Unauthorized')
  }

  if (!response.ok) {
    throw new Error(`API error: ${response.status}`)
  }

  return response.json() as Promise<T>
}

export async function apiPost(url: string, body: Record<string, unknown>): Promise<Response> {
  return fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-XSRF-TOKEN': getCsrfToken(),
    },
    credentials: 'same-origin',
    body: JSON.stringify(body),
  })
}

export async function apiPatch(url: string, body: Record<string, unknown>): Promise<Response> {
  return fetch(url, {
    method: 'PATCH',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-XSRF-TOKEN': getCsrfToken(),
    },
    credentials: 'same-origin',
    body: JSON.stringify(body),
  })
}

export async function apiPut(url: string, body: Record<string, unknown>): Promise<Response> {
  return fetch(url, {
    method: 'PUT',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-XSRF-TOKEN': getCsrfToken(),
    },
    credentials: 'same-origin',
    body: JSON.stringify(body),
  })
}

export async function apiDelete(url: string): Promise<Response> {
  return fetch(url, {
    method: 'DELETE',
    headers: {
      Accept: 'application/json',
      'X-XSRF-TOKEN': getCsrfToken(),
    },
    credentials: 'same-origin',
  })
}
