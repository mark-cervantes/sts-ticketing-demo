import { ref, computed, watch } from 'vue'
import { toast } from 'vue-sonner'
import type {
  Issue,
  IssueStatus,
  KanbanColumnDef,
  PaginatedResponse,
} from '@/types/issue'
import { useIssueFilters } from '@/composables/useIssueFilters'
import { useStatuses } from '@/composables/useStatuses'
import { apiFetch, apiPatch, buildQueryString } from '@/composables/useApiFetch'

const PER_PAGE = 15

/**
 * All issues keyed by status slug. Module-scoped so the board is a singleton.
 * Populated dynamically after statuses are fetched.
 */
const columnMap = ref<Record<string, Issue[]>>({})

const paginationState = ref<Record<string, { currentPage: number; hasMore: boolean }>>({})

const initialLoading = ref(false)
const columnLoading = ref<Record<string, boolean>>({})

/**
 * Fetch issues for a single status column using the status slug.
 * Backend scopeFilterByStatus accepts slug strings for backward compat.
 */
async function fetchColumn(
  statusSlug: string,
  page: number,
  priorityFilter: string[],
  categoryFilter: string | null,
): Promise<PaginatedResponse<Issue>> {
  const params: Record<string, string> = {
    status: statusSlug,
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
 * Initialize per-slug maps from the dynamic status list.
 */
function initMaps(statuses: IssueStatus[]): void {
  const newColumnMap: Record<string, Issue[]> = {}
  const newPagination: Record<string, { currentPage: number; hasMore: boolean }> = {}
  const newLoading: Record<string, boolean> = {}

  for (const status of statuses) {
    // Preserve existing data if already loaded
    newColumnMap[status.slug] = columnMap.value[status.slug] ?? []
    newPagination[status.slug] = paginationState.value[status.slug] ?? {
      currentPage: 1,
      hasMore: false,
    }
    newLoading[status.slug] = columnLoading.value[status.slug] ?? false
  }

  columnMap.value = newColumnMap
  paginationState.value = newPagination
  columnLoading.value = newLoading
}

/**
 * Update an issue object in the board columns.
 * If status changed, moves the card from the old column to the new one.
 * If other fields changed, updates the issue in-place.
 */
function updateIssueInBoard(updatedIssue: Issue, oldStatus?: string): void {
  const newStatus = updatedIssue.status
  const effectiveOldStatus = oldStatus ?? newStatus

  if (effectiveOldStatus !== newStatus) {
    // Remove from old column
    const oldCol = columnMap.value[effectiveOldStatus]
    if (oldCol) {
      const oldIdx = oldCol.findIndex((i) => i.id === updatedIssue.id)
      if (oldIdx !== -1) {
        oldCol.splice(oldIdx, 1)
      }
    }
    // Add to new column at the top
    if (columnMap.value[newStatus]) {
      columnMap.value[newStatus].unshift(updatedIssue)
    }
  } else {
    // Update in-place within the same column
    const col = columnMap.value[newStatus]
    if (col) {
      const idx = col.findIndex((i) => i.id === updatedIssue.id)
      if (idx !== -1) {
        col.splice(idx, 1, updatedIssue)
      }
    }
  }
}

/**
 * Remove an issue from the board after deletion.
 */
function removeIssueFromBoard(issueId: number, statusSlug: string): void {
  const col = columnMap.value[statusSlug]
  if (!col) return
  const idx = col.findIndex((i) => i.id === issueId)
  if (idx !== -1) {
    col.splice(idx, 1)
  }
}

export function useKanbanBoard() {
  const { filters, initStatusesFilter } = useIssueFilters()
  const { statuses, fetchStatuses } = useStatuses()

  /**
   * Columns ordered by sort_order, filtered by selected status IDs.
   */
  const columns = computed<KanbanColumnDef[]>(() => {
    return statuses.value
      .filter((s) => filters.value.statuses.includes(s.id))
      .sort((a, b) => a.sort_order - b.sort_order)
      .map((status) => {
        const issues = columnMap.value[status.slug] ?? []
        return {
          status: status.slug,
          statusId: status.id,
          label: status.name,
          color: status.color,
          issues,
          loading: columnLoading.value[status.slug] ?? false,
          hasMore: paginationState.value[status.slug]?.hasMore ?? false,
          currentPage: paginationState.value[status.slug]?.currentPage ?? 1,
          isDefault: status.is_default,
          issueCount: issues.length,
        }
      })
  })

  async function loadInitial(): Promise<void> {
    // Ensure statuses are loaded first
    await fetchStatuses()

    if (statuses.value.length === 0) return

    // Hydrate the filter with all status IDs if it's empty
    initStatusesFilter(statuses.value.map((s) => s.id))

    // Initialize slug-keyed maps from the fetched status list
    initMaps(statuses.value)

    initialLoading.value = true

    try {
      const results = await Promise.all(
        statuses.value.map((s) =>
          fetchColumn(s.slug, 1, filters.value.priorities, filters.value.category),
        ),
      )

      for (let i = 0; i < statuses.value.length; i++) {
        const status = statuses.value[i]
        const result = results[i]
        columnMap.value[status.slug] = result.data
        paginationState.value[status.slug] = {
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

  async function loadMore(statusSlug: string): Promise<void> {
    if (columnLoading.value[statusSlug] || !paginationState.value[statusSlug]?.hasMore) return

    const statusObj = statuses.value.find((s) => s.slug === statusSlug)
    columnLoading.value[statusSlug] = true
    try {
      const nextPage = (paginationState.value[statusSlug]?.currentPage ?? 1) + 1
      const result = await fetchColumn(
        statusSlug,
        nextPage,
        filters.value.priorities,
        filters.value.category,
      )
      columnMap.value[statusSlug].push(...result.data)
      paginationState.value[statusSlug] = {
        currentPage: result.meta.current_page,
        hasMore: result.meta.current_page < result.meta.last_page,
      }
    } catch {
      const label = statusObj?.name ?? statusSlug
      toast.error(`Failed to load more ${label} issues.`)
    } finally {
      columnLoading.value[statusSlug] = false
    }
  }

  /**
   * Handle drag-drop: optimistic update + PATCH.
   * Reverts on failure (409 optimistic lock / 422 validation / network error).
   * Sends status_id (integer) in PATCH body — backend UpdateIssueRequest validates it.
   */
  async function moveIssue(
    issueId: number,
    fromStatusSlug: string,
    toStatusSlug: string,
    dropIndex = -1,
  ): Promise<void> {
    if (fromStatusSlug === toStatusSlug) return

    const toStatusObj = statuses.value.find((s) => s.slug === toStatusSlug)
    if (!toStatusObj) {
      toast.error('Unknown destination status.')
      return
    }

    // VueDraggable already moved the card in the local DOM arrays.
    // We must NOT splice+unshift here — that would overwrite localIssues
    // via the watcher and snap the card to the top of the column.
    //
    // Instead, find the issue in the target column (where VueDraggable
    // placed it) and update its status fields in-place.
    const targetColumn = columnMap.value[toStatusSlug]
    const issueInTarget = targetColumn?.find((i) => i.id === issueId)

    // Fallback: if VueDraggable hasn't synced yet, find in source
    const sourceColumn = columnMap.value[fromStatusSlug]
    const issue = issueInTarget ?? sourceColumn?.find((i) => i.id === issueId)
    if (!issue) return

    const cachedUpdatedAt = issue.updated_at

    // Update status fields in-place (no array reorder)
    issue.status = toStatusSlug
    issue.status_id = toStatusObj.id
    issue.status_obj = toStatusObj

    try {
      const response = await apiPatch(`/api/issues/${issueId}`, {
        status_id: toStatusObj.id,
        updated_at: cachedUpdatedAt,
      })

      if (response.status === 409) {
        toast.error('Conflict — issue was updated by someone else', {
          description: `"${issue.title}" was reverted to ${fromStatusSlug}. Refresh and try again.`,
          duration: 6000,
        })
        void loadInitial()
        return
      }

      if (response.status === 422) {
        const errorData = (await response.json()) as { message?: string }
        toast.error('Status change rejected', {
          description: errorData.message ?? `"${issue.title}" could not be moved to ${toStatusObj.name}.`,
        })
        void loadInitial()
        return
      }

      if (response.status === 403) {
        toast.error('Permission denied', {
          description: `You don't have edit access to "${issue.title}". Ask the owner to share it with you.`,
        })
        void loadInitial()
        return
      }

      if (!response.ok) {
        toast.error('Failed to move issue', {
          description: `"${issue.title}" — server returned ${response.status}. The card was reverted.`,
        })
        void loadInitial()
        return
      }

      // Success: update the issue's updated_at from server response
      const responseData = (await response.json()) as { data: Issue }
      issue.updated_at = responseData.data.updated_at

      // Sync columnMap to match what VueDraggable already did to localIssues:
      // 1. Remove from source column
      // 2. Insert into target column at the exact drop position
      // This keeps columnMap in sync so the watcher's ID-set comparison
      // doesn't trigger a reset that would snap the card to a wrong position.
      if (sourceColumn) {
        const srcIdx = sourceColumn.findIndex((i) => i.id === issueId)
        if (srcIdx !== -1) {
          sourceColumn.splice(srcIdx, 1)
        }
      }
      if (targetColumn && !targetColumn.find((i) => i.id === issueId)) {
        if (dropIndex >= 0 && dropIndex <= targetColumn.length) {
          targetColumn.splice(dropIndex, 0, issue)
        } else {
          targetColumn.push(issue)
        }
      }

      toast.success(`Moved to ${toStatusObj.name}`)
    } catch {
      toast.error('Network error', {
        description: `Could not reach the server to move "${issue.title}". The card was reverted.`,
      })
      void loadInitial()
    }
  }

  function revertMove(
    issue: Issue,
    originalStatusSlug: string,
    currentStatusSlug: string,
    originalIndex: number,
  ): void {
    // Remove from current (wrong) column
    const targetCol = columnMap.value[currentStatusSlug]
    if (targetCol) {
      const idx = targetCol.findIndex((i) => i.id === issue.id)
      if (idx !== -1) {
        targetCol.splice(idx, 1)
      }
    }

    // Restore original status data on the issue
    const originalStatusObj = statuses.value.find((s) => s.slug === originalStatusSlug)
    const revertedIssue: Issue = {
      ...issue,
      status: originalStatusSlug,
      status_id: originalStatusObj?.id ?? issue.status_id,
      status_obj: originalStatusObj ?? issue.status_obj,
    }

    // Re-insert into original column at original position
    const sourceCol = columnMap.value[originalStatusSlug]
    if (sourceCol) {
      const insertAt = Math.min(originalIndex, sourceCol.length)
      sourceCol.splice(insertAt, 0, revertedIssue)
    }
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
