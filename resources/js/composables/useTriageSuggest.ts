import { ref } from 'vue'
import { apiPost } from '@/composables/useApiFetch'
import type { IssuePriority } from '@/types/issue'

export interface TriageSuggestion {
  priority: IssuePriority
  category_id: number | null
  category_name: string
  confidence: 'ai' | 'heuristic'
}

export function useTriageSuggest() {
  const suggestion = ref<TriageSuggestion | null>(null)
  const isLoading = ref(false)

  // Cache the last title+description pair to avoid redundant calls
  let lastTitle = ''
  let lastDescription = ''
  let debounceTimer: ReturnType<typeof setTimeout> | null = null

  async function _callApi(title: string, description: string): Promise<void> {
    // Skip if identical to last successful call
    if (title === lastTitle && description === lastDescription) return

    isLoading.value = true
    try {
      const response = await apiPost('/api/issues/triage-suggest', { title, description })
      if (!response.ok) {
        // Non-2xx (e.g. 422 validation) — silently discard
        return
      }
      const json = await response.json() as { data: TriageSuggestion }
      suggestion.value = json.data
      lastTitle = title
      lastDescription = description
    } catch {
      // Network errors are silently ignored — don't block the user
    } finally {
      isLoading.value = false
    }
  }

  /**
   * Schedule a debounced triage call (800 ms).
   * Fires only when title ≥ 3 chars AND description ≥ 10 chars.
   */
  function scheduleTriage(title: string, description: string): void {
    if (debounceTimer !== null) {
      clearTimeout(debounceTimer)
      debounceTimer = null
    }

    if (title.length < 3 || description.length < 10) return

    debounceTimer = setTimeout(() => {
      debounceTimer = null
      void _callApi(title, description)
    }, 800)
  }

  /** Clear the current suggestion and cancel any pending call. */
  function clearSuggestion(): void {
    if (debounceTimer !== null) {
      clearTimeout(debounceTimer)
      debounceTimer = null
    }
    suggestion.value = null
    lastTitle = ''
    lastDescription = ''
  }

  return {
    suggestion,
    isLoading,
    scheduleTriage,
    clearSuggestion,
  }
}
