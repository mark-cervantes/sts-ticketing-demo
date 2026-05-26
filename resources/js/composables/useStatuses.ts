import { ref } from 'vue'
import type { IssueStatus } from '@/types/issue'

/**
 * Module-scoped cache — singleton across all consumers.
 * Fetches from GET /api/statuses on first call, then caches.
 */
const statuses = ref<IssueStatus[]>([])
const loading = ref(false)
let fetched = false

export function useStatuses() {
  async function fetchStatuses(): Promise<void> {
    if (fetched || loading.value) return
    loading.value = true
    try {
      const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
      const csrfToken = match ? decodeURIComponent(match[1]) : ''
      const response = await fetch('/api/statuses', {
        headers: {
          Accept: 'application/json',
          'X-XSRF-TOKEN': csrfToken,
        },
        credentials: 'same-origin',
      })
      if (response.ok) {
        statuses.value = (await response.json()) as IssueStatus[]
        fetched = true
      }
    } finally {
      loading.value = false
    }
  }

  /** Force re-fetch (e.g. after create/delete in StatusSettings). */
  async function refresh(): Promise<void> {
    fetched = false
    statuses.value = []
    await fetchStatuses()
  }

  function statusById(id: number): IssueStatus | undefined {
    return statuses.value.find((s) => s.id === id)
  }

  function statusBySlug(slug: string): IssueStatus | undefined {
    return statuses.value.find((s) => s.slug === slug)
  }

  return {
    statuses,
    loading,
    fetchStatuses,
    refresh,
    statusById,
    statusBySlug,
  }
}
