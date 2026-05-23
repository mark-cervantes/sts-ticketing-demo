import { ref, readonly } from 'vue'
import { toast } from 'vue-sonner'
import type { Share, SharePermission } from '@/types/issue'
import { apiFetch, apiPost, apiPatch, apiDelete } from '@/composables/useApiFetch'

const shares = ref<Share[]>([])
const loading = ref(false)
const saving = ref(false)

export function useIssueShares() {
  /** Fetch all shares for an issue. */
  async function fetchShares(issueId: number): Promise<void> {
    loading.value = true
    try {
      const response = await apiFetch<{ data: Share[] }>(
        `/api/issues/${issueId}/shares`,
      )
      shares.value = response.data
    } catch {
      shares.value = []
    } finally {
      loading.value = false
    }
  }

  /**
   * Add a share. Returns `null` on success or an object with field errors on 422.
   */
  async function addShare(
    issueId: number,
    email: string,
    permission: SharePermission,
  ): Promise<Record<string, string[]> | null> {
    saving.value = true
    try {
      const response = await apiPost(`/api/issues/${issueId}/shares`, {
        email,
        permission,
      })

      if (response.status === 422) {
        const errorData = (await response.json()) as {
          errors?: Record<string, string[]>
        }
        return errorData.errors ?? { email: ['Validation error.'] }
      }

      if (response.status === 401) {
        toast.error('Session expired — please log in again.')
        return null
      }

      if (!response.ok) {
        toast.error('Failed to add share.')
        return null
      }

      // Re-fetch to get the full list with user data
      await fetchShares(issueId)
      toast.success('Share added')
      return null
    } catch {
      toast.error('Network error — could not add share.')
      return null
    } finally {
      saving.value = false
    }
  }

  /** Update the permission level on an existing share. */
  async function updatePermission(
    shareId: number,
    permission: SharePermission,
  ): Promise<boolean> {
    saving.value = true
    try {
      const response = await apiPatch(`/api/shares/${shareId}`, { permission })

      if (response.status === 401) {
        toast.error('Session expired — please log in again.')
        return false
      }

      if (!response.ok) {
        toast.error('Failed to update permission.')
        return false
      }

      // Update locally without re-fetch
      const share = shares.value.find((s) => s.id === shareId)
      if (share) share.permission = permission

      return true
    } catch {
      toast.error('Network error — could not update permission.')
      return false
    } finally {
      saving.value = false
    }
  }

  /** Remove a share. */
  async function removeShare(shareId: number): Promise<boolean> {
    saving.value = true
    try {
      const response = await apiDelete(`/api/shares/${shareId}`)

      if (response.status === 401) {
        toast.error('Session expired — please log in again.')
        return false
      }

      if (!response.ok) {
        toast.error('Failed to remove share.')
        return false
      }

      shares.value = shares.value.filter((s) => s.id !== shareId)
      toast.success('Share removed')
      return true
    } catch {
      toast.error('Network error — could not remove share.')
      return false
    } finally {
      saving.value = false
    }
  }

  /** Clear shares state (on slide-over close). */
  function clearShares(): void {
    shares.value = []
  }

  return {
    shares: readonly(shares),
    loading: readonly(loading),
    saving: readonly(saving),
    fetchShares,
    addShare,
    updatePermission,
    removeShare,
    clearShares,
  }
}
