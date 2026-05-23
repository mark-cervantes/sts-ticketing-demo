import { ref, computed, watch } from 'vue'
import { toast } from 'vue-sonner'
import type {
  Issue,
  IssueStatus,
  KanbanColumnDef,
  PaginatedResponse,
} from '@/types/issue'
import { STATUS_CONFIG } from '@/types/issue'
import { useIssueFilters } from '@/composables/useIssueFilters'
import { apiFetch, apiPatch, buildQueryString } from '@/composables/useApiFetch'

const PER_PAGE = 15

/** All issues keyed by status. Module-scoped so the board is a singleton. */
const columnMap = ref<Record<IssueStatus, Issue[]>>({
  open: [],
  in_progress: [],
  resolved: [],
})

const paginationState = ref<Record<IssueStatus, { currentPage: number; hasMore: boolean }>>({
  open: { currentPage: 1, hasMore: false },
  in_progress: { currentPage: 1, hasMore: false },
  resolved: { currentPage: 1, hasMore: false },
})

const initialLoading = ref(false)
const columnLoading = ref<Record<IssueStatus, boolean>>({
  open: false,
  in_progress: false,
  resolved: false,
})

/**
 * Fetch issues for a single status column (or all if no status specified).
 * Uses per-column pagination.
 */
async function fetchColumn(
  status: IssueStatus,
  page: number,
  priorityFilter: string[],
  categoryFilter: string | null,
): Promise<PaginatedResponse<Issue>> {
  const params: Record<string, string> = {
    status,
    per_page: String(PER_PAGE),
    page: String(page),
  }
  if (priorityFilter.length > 0) {
    params.priority = priorityFilter.join(',')
  }
  if (categoryFilter) {
    params.category = categoryFilter
  }
  const qs = buildQueryString(params)
  return apiFetch<PaginatedResponse<Issue>>(`/api/issues?${qs}`)
}

/**
 * Update an issue object in the board columns.
 * If status changed, moves the card from the old column to the new one.
 * If other fields changed, updates the issue in-place.
 */
function updateIssueInBoard(updatedIssue: Issue, oldStatus?: IssueStatus): void {
  const newStatus = updatedIssue.status
  const effectiveOldStatus = oldStatus ?? newStatus

  if (effectiveOldStatus !== newStatus) {
    // Remove from old column
    const oldCol = columnMap.value[effectiveOldStatus]
    const oldIdx = oldCol.findIndex((i) => i.id === updatedIssue.id)
    if (oldIdx !== -1) {
      oldCol.splice(oldIdx, 1)
    }
    // Add to new column at the top
    columnMap.value[newStatus].unshift(updatedIssue)
  } else {
    // Update in-place within the same column
    const col = columnMap.value[newStatus]
    const idx = col.findIndex((i) => i.id === updatedIssue.id)
    if (idx !== -1) {
      col.splice(idx, 1, updatedIssue)
    }
  }
}

/**
 * Remove an issue from the board after deletion.
 */
function removeIssueFromBoard(issueId: number, status: IssueStatus): void {
  const col = columnMap.value[status]
  const idx = col.findIndex((i) => i.id === issueId)
  if (idx !== -1) {
    col.splice(idx, 1)
  }
}

export function useKanbanBoard() {
  const { filters } = useIssueFilters()

  const columns = computed<KanbanColumnDef[]>(() => {
    const statuses: IssueStatus[] = ['open', 'in_progress', 'resolved']
    return statuses
      .filter((s) => filters.value.statuses.includes(s))
      .map((status) => ({
        status,
        label: STATUS_CONFIG[status].label,
        issues: columnMap.value[status],
        loading: columnLoading.value[status],
        hasMore: paginationState.value[status].hasMore,
        currentPage: paginationState.value[status].currentPage,
      }))
  })

  async function loadInitial(): Promise<void> {
    initialLoading.value = true
    const statuses: IssueStatus[] = ['open', 'in_progress', 'resolved']

    try {
      const results = await Promise.all(
        statuses.map((s) =>
          fetchColumn(s, 1, filters.value.priorities, filters.value.category),
        ),
      )

      for (let i = 0; i < statuses.length; i++) {
        const status = statuses[i]
        const result = results[i]
        columnMap.value[status] = result.data
        paginationState.value[status] = {
          currentPage: result.meta.current_page,
          hasMore: result.meta.current_page < result.meta.last_page,
        }
      }
    } catch (err) {
      if (err instanceof Error && err.message !== 'Unauthorized') {
        toast.error('Failed to load issues. Please try again.')
      }
    } finally {
      initialLoading.value = false
    }
  }

  async function loadMore(status: IssueStatus): Promise<void> {
    if (columnLoading.value[status] || !paginationState.value[status].hasMore) return

    columnLoading.value[status] = true
    try {
      const nextPage = paginationState.value[status].currentPage + 1
      const result = await fetchColumn(
        status,
        nextPage,
        filters.value.priorities,
        filters.value.category,
      )
      columnMap.value[status].push(...result.data)
      paginationState.value[status] = {
        currentPage: result.meta.current_page,
        hasMore: result.meta.current_page < result.meta.last_page,
      }
    } catch {
      toast.error(`Failed to load more ${STATUS_CONFIG[status].label} issues.`)
    } finally {
      columnLoading.value[status] = false
    }
  }

  /**
   * Handle drag-drop: optimistic update + PATCH.
   * Reverts on failure (409 optimistic lock / 422 validation / network error).
   */
  async function moveIssue(
    issueId: number,
    fromStatus: IssueStatus,
    toStatus: IssueStatus,
  ): Promise<void> {
    if (fromStatus === toStatus) return

    // Find and remove from source column
    const sourceColumn = columnMap.value[fromStatus]
    const issueIndex = sourceColumn.findIndex((i) => i.id === issueId)
    if (issueIndex === -1) return

    const issue = sourceColumn[issueIndex]
    const cachedUpdatedAt = issue.updated_at

    // Optimistic: remove from source, add to target
    sourceColumn.splice(issueIndex, 1)
    const updatedIssue: Issue = { ...issue, status: toStatus }
    columnMap.value[toStatus].unshift(updatedIssue)

    try {
      const response = await apiPatch(`/api/issues/${issueId}`, {
        status: toStatus,
        updated_at: cachedUpdatedAt,
      })

      if (response.status === 409) {
        // Optimistic lock conflict — revert
        revertMove(updatedIssue, fromStatus, toStatus, issueIndex)
        toast.error('This issue was updated by someone else. The board has been refreshed.', {
          duration: 5000,
        })
        return
      }

      if (response.status === 422) {
        const errorData = await response.json() as { message?: string }
        revertMove(updatedIssue, fromStatus, toStatus, issueIndex)
        toast.error(errorData.message ?? 'Validation error — status change rejected.')
        return
      }

      if (!response.ok) {
        revertMove(updatedIssue, fromStatus, toStatus, issueIndex)
        toast.error('Failed to update issue status.')
        return
      }

      // Success: update the issue's updated_at from server response
      const responseData = await response.json() as { data: Issue }
      const targetColumn = columnMap.value[toStatus]
      const movedIdx = targetColumn.findIndex((i) => i.id === issueId)
      if (movedIdx !== -1) {
        targetColumn[movedIdx] = { ...targetColumn[movedIdx], updated_at: responseData.data.updated_at }
      }

      toast.success(`Moved to ${STATUS_CONFIG[toStatus].label}`)
    } catch {
      revertMove(updatedIssue, fromStatus, toStatus, issueIndex)
      toast.error('Network error — could not update issue status.')
    }
  }

  function revertMove(
    issue: Issue,
    originalStatus: IssueStatus,
    currentStatus: IssueStatus,
    originalIndex: number,
  ): void {
    // Remove from current (wrong) column
    const targetCol = columnMap.value[currentStatus]
    const idx = targetCol.findIndex((i) => i.id === issue.id)
    if (idx !== -1) {
      targetCol.splice(idx, 1)
    }

    // Re-insert into original column at original position
    const revertedIssue: Issue = { ...issue, status: originalStatus }
    const sourceCol = columnMap.value[originalStatus]
    const insertAt = Math.min(originalIndex, sourceCol.length)
    sourceCol.splice(insertAt, 0, revertedIssue)
  }

  // Re-fetch when filters change (priority, category — status hides columns client-side)
  watch(
    () => [filters.value.priorities.slice(), filters.value.category] as const,
    () => {
      void loadInitial()
    },
    { deep: true },
  )

  return {
    columns,
    initialLoading,
    loadInitial,
    loadMore,
    moveIssue,
    updateIssueInBoard,
    removeIssueFromBoard,
  }
}
