import { ref, computed, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import type {
  Issue,
  IssueStatus,
  KanbanColumnDef,
  PaginatedResponse,
} from '@/types/issue'
import { PRIORITY_CONFIG } from '@/types/issue'
import { useIssueFilters } from '@/composables/useIssueFilters'
import { useStatuses } from '@/composables/useStatuses'
import { useColumnSort } from '@/composables/useColumnSort'
import { apiFetch, apiPatch, buildQueryString } from '@/composables/useApiFetch'

const PER_PAGE = 15

/**
 * All issues keyed by status slug. Module-scoped so the board is a singleton.
 * Populated dynamically after statuses are fetched.
 */
const columnMap = ref<Record<string, Issue[]>>({})

const paginationState = ref<Record<string, { currentPage: number; hasMore: boolean; total: number }>>({})

const initialLoading = ref(false)
const columnLoading = ref<Record<string, boolean>>({})

/**
 * Fetch issues for a single status column using the status slug.
 * Backend scopeFilterByStatus accepts slug strings for backward compat.
 * @param perPage - Override per-page count (defaults to module-level PER_PAGE)
 * @param includeArchived - When true, appends ?include_archived=1 to include archived issues.
 *   Unlike myTickets (client-side), this changes the server query.
 */
async function fetchColumn(
  statusSlug: string,
  page: number,
  priorityFilter: string[],
  categoryFilter: string | null,
  perPage: number = PER_PAGE,
  includeArchived: boolean = false,
): Promise<PaginatedResponse<Issue>> {
  const params: Record<string, string> = {
    status: statusSlug,
    per_page: String(perPage),
    page: String(page),
  }
  if (priorityFilter.length > 0) {
    params.priority = priorityFilter.join(',')
  }
  if (categoryFilter) {
    params.category = categoryFilter
  }
  if (includeArchived) {
    params.include_archived = '1'
  }
  const qs = buildQueryString(params)
  return apiFetch<PaginatedResponse<Issue>>(`/api/issues?${qs}`)
}

/**
 * Initialize per-slug maps from the dynamic status list.
 */
function initMaps(statuses: IssueStatus[]): void {
  const newColumnMap: Record<string, Issue[]> = {}
  const newPagination: Record<string, { currentPage: number; hasMore: boolean; total: number }> = {}
  const newLoading: Record<string, boolean> = {}

  for (const status of statuses) {
    // Preserve existing data if already loaded
    newColumnMap[status.slug] = columnMap.value[status.slug] ?? []
    newPagination[status.slug] = paginationState.value[status.slug] ?? {
      currentPage: 1,
      hasMore: false,
      total: 0,
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

/**
 * Sort issues by the given sort key.
 * Returns a NEW array (slice) to avoid mutating columnMap.
 */
function sortIssues(issues: Issue[], sortKey: string): Issue[] {
  const sorted = issues.slice()
  switch (sortKey) {
    case 'priority':
      sorted.sort((a, b) => {
        const aOrder = PRIORITY_CONFIG[a.priority]?.order ?? 99
        const bOrder = PRIORITY_CONFIG[b.priority]?.order ?? 99
        return aOrder - bOrder
      })
      break
    case 'newest':
      sorted.sort((a, b) => b.created_at.localeCompare(a.created_at))
      break
    case 'updated':
      sorted.sort((a, b) => b.updated_at.localeCompare(a.updated_at))
      break
    case 'oldest':
      sorted.sort((a, b) => a.created_at.localeCompare(b.created_at))
      break
  }
  return sorted
}

export function useKanbanBoard() {
  const { filters, myTickets, showArchived, initStatusesFilter } = useIssueFilters()
  const { statuses, fetchStatuses } = useStatuses()
  const { sortMap, getSortKey } = useColumnSort()

  // Read auth user ID once at setup time — NOT inside the computed.
  // Per tech-lead warning: calling usePage() inside computed risks stale refs.
  const page = usePage()
  const authUserId = page.props.auth?.user?.id as number | undefined

  /**
   * Columns ordered by sort_order, filtered by selected status IDs.
   * Applies "My Tickets" client-side filter and per-column sorting.
   *
   * NOTE: sortMap is accessed here to ensure this computed re-evaluates
   * when sort keys change.
   */
  const columns = computed<KanbanColumnDef[]>(() => {
    // Access sortMap.value to make Vue track changes to sort keys
    const _sortMap = sortMap.value

    return statuses.value
      .filter((s) => filters.value.statuses.includes(s.id))
      .sort((a, b) => a.sort_order - b.sort_order)
      .map((status) => {
        let issues = columnMap.value[status.slug] ?? []

        // Apply "My Tickets" client-side filter (no API re-fetch)
        const totalBeforeMyTickets = issues.length
        if (myTickets.value && authUserId !== undefined) {
          issues = issues.filter((issue) => issue.user?.id === authUserId)
        }

        // Apply per-column sort
        const sortKey = _sortMap[status.slug] ?? getSortKey(status.slug)
        issues = sortIssues(issues, sortKey)

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
          isResolved: status.slug === 'resolved',
          totalCount: myTickets.value
            // When "My Tickets" is active, totalCount is the unfiltered column length
            ? totalBeforeMyTickets
            : paginationState.value[status.slug]?.total ?? issues.length,
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
          fetchColumn(
            s.slug,
            1,
            filters.value.priorities,
            filters.value.category,
            // Resolved column gets a smaller initial page (10) so the collapsed
            // state is cheap — the server still returns meta.total for the badge.
            s.slug === 'resolved' ? 10 : PER_PAGE,
            showArchived.value,
          ),
        ),
      )

      for (let i = 0; i < statuses.value.length; i++) {
        const status = statuses.value[i]
        const result = results[i]
        columnMap.value[status.slug] = result.data
        paginationState.value[status.slug] = {
          currentPage: result.meta.current_page,
          hasMore: result.meta.current_page < result.meta.last_page,
          total: result.meta.total,
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
        PER_PAGE,
        showArchived.value,
      )
      columnMap.value[statusSlug].push(...result.data)
      paginationState.value[statusSlug] = {
        currentPage: result.meta.current_page,
        hasMore: result.meta.current_page < result.meta.last_page,
        total: result.meta.total,
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

    // ── Optimistic update ──────────────────────────────────────────────────
    // Sync columnMap IMMEDIATELY so the watcher never sees stale data.
    // VueDraggable already moved the card in localIssues (DOM), but columnMap
    // still has the card in the source. Fixing it now prevents the watcher
    // from triggering a re-render flash during the API round-trip.
    issue.status = toStatusSlug
    issue.status_id = toStatusObj.id
    issue.status_obj = toStatusObj

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

    // ── API call ───────────────────────────────────────────────────────────
    // On failure, loadInitial() resets the entire board from the server.
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

      // Success: update timestamp from server
      const responseData = (await response.json()) as { data: Issue }
      issue.updated_at = responseData.data.updated_at

      // No success toast — moving a card is expected behavior.
      // Only surface errors/failures (shown above).
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
  // "My Tickets" is intentionally excluded — it is client-side filtering only
  // and must NOT trigger a round-trip that clears the board.
  // "Show Archived" IS included — it changes the API query (?include_archived=1)
  // and must trigger a full re-fetch from the server.
  watch(
    () => [filters.value.priorities.slice(), filters.value.category, showArchived.value] as const,
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
