import { ref } from 'vue'
import type { Category } from '@/types'
import { apiDelete } from '@/composables/useApiFetch'

const categories = ref<Category[]>([])
const isLoading = ref(false)
const isCreating = ref(false)

export function useCategories() {
  async function fetchCategories(): Promise<void> {
    isLoading.value = true
    try {
      const response = await window.axios.get<Category[]>('/api/categories')
      categories.value = response.data
    } catch {
      categories.value = []
    } finally {
      isLoading.value = false
    }
  }

  async function createCategory(name: string): Promise<Category | null> {
    if (!name.trim()) return null
    isCreating.value = true
    try {
      const response = await window.axios.post<Category>('/api/categories', { name: name.trim() })
      const newCategory = response.data
      categories.value.push(newCategory)
      return newCategory
    } catch {
      return null
    } finally {
      isCreating.value = false
    }
  }

  /**
   * Delete a category by id.
   *
   * Returns null on success (204), or the error message string on 409 (category in use).
   * Removes the category from the shared `categories` ref on success.
   * Does NOT throw — the caller is responsible for handling the returned message.
   */
  async function deleteCategory(id: number): Promise<string | null> {
    const response = await apiDelete(`/api/categories/${id}`)

    if (response.status === 409) {
      const body = (await response.json()) as { message?: string }
      return body.message ?? 'Cannot delete: category is in use'
    }

    if (response.status === 204) {
      categories.value = categories.value.filter((c) => c.id !== id)
      return null
    }

    return 'Failed to delete category'
  }

  return {
    categories,
    isLoading,
    isCreating,
    fetchCategories,
    createCategory,
    deleteCategory,
  }
}
