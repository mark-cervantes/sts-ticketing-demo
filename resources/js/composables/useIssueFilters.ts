import { ref } from 'vue'
import type { IssueFilters, IssueStatus, IssuePriority, Category } from '@/types/issue'

/**
 * Module-scoped shared filter state.
 * Follows useDarkMode pattern — single instance across all consumers.
 */
const filters = ref<IssueFilters>({
  statuses: ['open', 'in_progress', 'resolved'],
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
      categories.value = await response.json() as Category[]
    }
  } finally {
    categoriesLoading.value = false
  }
}

function toggleStatus(status: IssueStatus): void {
  const idx = filters.value.statuses.indexOf(status)
  if (idx === -1) {
    filters.value.statuses.push(status)
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
  filters.value = {
    statuses: ['open', 'in_progress', 'resolved'],
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
    toggleStatus,
    togglePriority,
    setCategory,
    clearFilters,
  }
}
