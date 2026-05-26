import { ref } from 'vue'
import type { IssueFilters, IssuePriority, Category } from '@/types/issue'
import { useStatuses } from '@/composables/useStatuses'

/**
 * Module-scoped shared filter state.
 * Follows useDarkMode pattern — single instance across all consumers.
 */
const filters = ref<IssueFilters>({
  // Start empty (meaning "all") — hydrated from useStatuses after first fetch.
  statuses: [],
  priorities: [],
  category: null,
})

const categories = ref<Category[]>([])
const categoriesLoading = ref(false)

function getCsrfToken(): string {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

async function fetchCategories(): Promise<void> {
  if (categories.value.length > 0) return
  categoriesLoading.value = true
  try {
    const response = await fetch('/api/categories', {
      headers: {
        Accept: 'application/json',
        'X-XSRF-TOKEN': getCsrfToken(),
      },
      credentials: 'same-origin',
    })
    if (response.ok) {
      categories.value = (await response.json()) as Category[]
    }
  } finally {
    categoriesLoading.value = false
  }
}

/**
 * Ensure the statuses filter is hydrated with all status IDs.
 * Call after useStatuses has fetched the list.
 */
function initStatusesFilter(allStatusIds: number[]): void {
  if (filters.value.statuses.length === 0 && allStatusIds.length > 0) {
    filters.value.statuses = [...allStatusIds]
  }
}

function toggleStatus(statusId: number): void {
  const idx = filters.value.statuses.indexOf(statusId)
  if (idx === -1) {
    filters.value.statuses.push(statusId)
  } else {
    // Don't allow deselecting ALL statuses
    if (filters.value.statuses.length > 1) {
      filters.value.statuses.splice(idx, 1)
    }
  }
}

function togglePriority(priority: IssuePriority): void {
  const idx = filters.value.priorities.indexOf(priority)
  if (idx === -1) {
    filters.value.priorities.push(priority)
  } else {
    filters.value.priorities.splice(idx, 1)
  }
}

function setCategory(slug: string | null): void {
  filters.value.category = slug
}

function clearFilters(): void {
  const { statuses: allStatuses } = useStatuses()
  filters.value = {
    statuses: allStatuses.value.map((s) => s.id),
    priorities: [],
    category: null,
  }
}

export function useIssueFilters() {
  return {
    filters,
    categories,
    categoriesLoading,
    fetchCategories,
    initStatusesFilter,
    toggleStatus,
    togglePriority,
    setCategory,
    clearFilters,
  }
}
