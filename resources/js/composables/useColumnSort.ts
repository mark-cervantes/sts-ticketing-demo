/**
 * Per-column sort state composable.
 * Module-scoped Map so sort persists across column re-renders triggered
 * by the `columns` computed updates in useKanbanBoard.
 *
 * Persisted in localStorage as `kanban-sort-${slug}`.
 */

import { ref } from 'vue'

export type SortKey = 'priority' | 'newest' | 'updated' | 'oldest'

export const SORT_OPTIONS: { key: SortKey; label: string }[] = [
  { key: 'priority', label: 'Priority' },
  { key: 'newest', label: 'Newest first' },
  { key: 'updated', label: 'Recently updated' },
  { key: 'oldest', label: 'Oldest first' },
]

const DEFAULT_SORT: SortKey = 'priority'

function localStorageKey(slug: string): string {
  return `kanban-sort-${slug}`
}

function readSortKey(slug: string): SortKey {
  try {
    const stored = localStorage.getItem(localStorageKey(slug))
    if (stored === 'priority' || stored === 'newest' || stored === 'updated' || stored === 'oldest') {
      return stored
    }
  } catch {
    // localStorage unavailable (SSR / private mode)
  }
  return DEFAULT_SORT
}

function writeSortKey(slug: string, key: SortKey): void {
  try {
    localStorage.setItem(localStorageKey(slug), key)
  } catch {
    // ignore
  }
}

/**
 * Reactive Map — slug → SortKey.
 * Using a ref wrapping an object so Vue can track changes.
 */
const sortMap = ref<Record<string, SortKey>>({})

export function useColumnSort() {
  function getSortKey(slug: string): SortKey {
    if (!(slug in sortMap.value)) {
      sortMap.value[slug] = readSortKey(slug)
    }
    return sortMap.value[slug]
  }

  function setSortKey(slug: string, key: SortKey): void {
    sortMap.value[slug] = key
    writeSortKey(slug, key)
  }

  return {
    /** Reactive map — use in computed to track changes */
    sortMap,
    getSortKey,
    setSortKey,
  }
}
