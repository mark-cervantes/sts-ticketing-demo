import { ref } from 'vue'
import type { Category } from '@/types'

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

  return {
    categories,
    isLoading,
    isCreating,
    fetchCategories,
    createCategory,
  }
}
