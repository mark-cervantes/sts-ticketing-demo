import { ref, readonly } from 'vue'
import { toast } from 'vue-sonner'
import type { Issue, IssueStatus } from '@/types/issue'
import { apiFetch, apiPatch, apiDelete } from '@/composables/useApiFetch'
import { useKanbanBoard } from '@/composables/useKanbanBoard'

const issue = ref<Issue | null>(null)
const loading = ref(false)
const saving = ref(false)
const deleting = ref(false)
const error = ref<string | null>(null)
const conflictDetected = ref(false)

export function useIssueDetail() {
  const { updateIssueInBoard, removeIssueFromBoard } = useKanbanBoard()

  /** Fetch a single issue by ID. */
  async function fetchIssue(id: number): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const response = await apiFetch<{ data: Issue }>(`/api/issues/${id}`)
      issue.value = response.data
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to load issue'
      issue.value = null
    } finally {
      loading.value = false
    }
  }

  /**
   * Patch a single field (or multiple fields) on the current issue.
   * Sends `updated_at` for optimistic locking.
   * Returns `true` on success, `false` on non-conflict error, `'conflict'` on 409.
   */
  async function patchIssue(fields: Record<string, unknown>): Promise<boolean | 'conflict'> {
    if (!issue.value) return false

    const currentIssue = issue.value
    const oldStatus = currentIssue.status
    saving.value = true

    try {
      const response = await apiPatch(`/api/issues/${currentIssue.id}`, {
        ...fields,
        updated_at: currentIssue.updated_at,
      })

      if (response.status === 409) {
        await fetchIssue(currentIssue.id)
        conflictDetected.value = true
        return 'conflict'
      }

      if (response.status === 401) {
        toast.error('Session expired — please log in again.')
        return false
      }

      if (response.status === 422) {
        const errorData = await response.json() as { message?: string }
        toast.error(errorData.message ?? 'Validation error.')
        return false
      }

      if (!response.ok) {
        toast.error('Failed to save changes.')
        return false
      }

      const responseData = await response.json() as { data: Issue }
      issue.value = responseData.data

      // Sync the kanban board
      updateIssueInBoard(responseData.data, oldStatus)

      return true
    } catch {
      toast.error('Network error — could not save changes.')
      return false
    } finally {
      saving.value = false
    }
  }

  /** Delete the current issue. Returns true on success. */
  async function deleteIssue(): Promise<boolean> {
    if (!issue.value) return false

    const currentIssue = issue.value
    deleting.value = true

    try {
      const response = await apiDelete(`/api/issues/${currentIssue.id}`)

      if (response.status === 401) {
        toast.error('Session expired — please log in again.')
        return false
      }

      if (!response.ok) {
        toast.error('Failed to delete issue.')
        return false
      }

      // Remove from kanban board
      removeIssueFromBoard(currentIssue.id, currentIssue.status)
      issue.value = null
      toast.success('Issue deleted')
      return true
    } catch {
      toast.error('Network error — could not delete issue.')
      return false
    } finally {
      deleting.value = false
    }
  }

  /** Clear the issue state (on close). */
  function clearIssue(): void {
    issue.value = null
    error.value = null
  }

  // --- URL query param sync ---

  function setIssueQueryParam(id: number): void {
    const url = new URL(window.location.href)
    url.searchParams.set('issue', String(id))
    window.history.replaceState({}, '', url.toString())
  }

  function clearIssueQueryParam(): void {
    const url = new URL(window.location.href)
    url.searchParams.delete('issue')
    window.history.replaceState({}, '', url.toString())
  }

  function getIssueQueryParam(): number | null {
    const params = new URLSearchParams(window.location.search)
    const raw = params.get('issue')
    if (!raw) return null
    const id = Number(raw)
    return isNaN(id) || id <= 0 ? null : id
  }

  /** Reset the conflict flag (call after handling the dialog). */
  function clearConflict(): void {
    conflictDetected.value = false
  }

  return {
    issue: readonly(issue),
    loading: readonly(loading),
    saving: readonly(saving),
    deleting: readonly(deleting),
    error: readonly(error),
    conflictDetected: readonly(conflictDetected),
    fetchIssue,
    patchIssue,
    deleteIssue,
    clearIssue,
    clearConflict,
    setIssueQueryParam,
    clearIssueQueryParam,
    getIssueQueryParam,
  }
}
