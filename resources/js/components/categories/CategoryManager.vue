<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { toast } from 'vue-sonner'
import { PlusIcon, Trash2Icon, Loader2Icon, TagIcon } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog'
import { apiFetch, apiPost, apiDelete } from '@/composables/useApiFetch'
import type { Category } from '@/types'

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

const categories = ref<Category[]>([])
const isLoading = ref(false)
const newName = ref('')
const nameError = ref<string | null>(null)
const isCreating = ref(false)

// Per-category inline error message (e.g. 409 "has issues")
const deleteErrors = ref<Record<number, string>>({})
// Track which category IDs are currently being deleted
const deletingIds = ref<Set<number>>(new Set())

// ---------------------------------------------------------------------------
// Load
// ---------------------------------------------------------------------------

async function fetchCategories(): Promise<void> {
  isLoading.value = true
  try {
    categories.value = await apiFetch<Category[]>('/api/categories')
  } catch {
    toast.error('Failed to load categories')
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  void fetchCategories()
})

// ---------------------------------------------------------------------------
// Create
// ---------------------------------------------------------------------------

async function handleCreate(): Promise<void> {
  const name = newName.value.trim()
  if (!name) {
    nameError.value = 'Category name is required.'
    return
  }

  nameError.value = null
  isCreating.value = true

  try {
    const response = await apiPost('/api/categories', { name })

    if (response.status === 422) {
      const body = await response.json() as { errors?: Record<string, string[]>; message?: string }
      nameError.value = body.errors?.name?.[0] ?? body.message ?? 'Validation error.'
      return
    }

    if (!response.ok) {
      toast.error('Failed to create category')
      return
    }

    const created = await response.json() as Category
    categories.value.push(created)
    newName.value = ''
    toast.success(`Category "${created.name}" created`)
  } catch {
    toast.error('Failed to create category')
  } finally {
    isCreating.value = false
  }
}

function clearNameError(): void {
  if (nameError.value) nameError.value = null
}

// ---------------------------------------------------------------------------
// Delete
// ---------------------------------------------------------------------------

async function handleDelete(category: Category): Promise<void> {
  if (deletingIds.value.has(category.id)) return

  // Clear any prior inline error
  const errors = { ...deleteErrors.value }
  delete errors[category.id]
  deleteErrors.value = errors

  deletingIds.value = new Set(deletingIds.value).add(category.id)

  try {
    const response = await apiDelete(`/api/categories/${category.id}`)

    if (response.status === 409) {
      const body = await response.json() as { message?: string; count?: number }
      const count = body.count ?? '?'
      deleteErrors.value = {
        ...deleteErrors.value,
        [category.id]: `Cannot delete — ${count} issue${count === 1 ? '' : 's'} use this category`,
      }
      return
    }

    if (!response.ok) {
      toast.error(`Failed to delete "${category.name}"`)
      return
    }

    categories.value = categories.value.filter((c) => c.id !== category.id)
    toast.success(`Category "${category.name}" deleted`)
  } catch {
    toast.error(`Failed to delete "${category.name}"`)
  } finally {
    const next = new Set(deletingIds.value)
    next.delete(category.id)
    deletingIds.value = next
  }
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <!-- Header -->
    <div class="flex items-center gap-2">
      <TagIcon class="size-5 text-muted-foreground" />
      <h2 class="text-base font-semibold">Categories</h2>
    </div>

    <!-- Create form -->
    <div class="space-y-1.5">
      <div class="flex gap-2">
        <Input
          v-model="newName"
          placeholder="New category name…"
          class="flex-1"
          :disabled="isCreating"
          @input="clearNameError"
          @keydown.enter.prevent="handleCreate"
        />
        <Button :disabled="isCreating" @click="handleCreate">
          <Loader2Icon v-if="isCreating" class="size-4 animate-spin" />
          <PlusIcon v-else class="size-4" />
          <span class="ml-1.5">Add</span>
        </Button>
      </div>
      <p v-if="nameError" class="text-sm text-destructive">{{ nameError }}</p>
    </div>

    <!-- List -->
    <div>
      <!-- Skeleton while loading -->
      <div v-if="isLoading" class="space-y-2">
        <Skeleton v-for="n in 4" :key="n" class="h-10 w-full rounded-md" />
      </div>

      <!-- Empty state -->
      <p v-else-if="categories.length === 0" class="text-sm text-muted-foreground">
        No categories yet. Create one above.
      </p>

      <!-- Category rows -->
      <ul v-else class="space-y-2">
        <li
          v-for="cat in categories"
          :key="cat.id"
          class="flex flex-col gap-1"
        >
          <div class="flex items-center justify-between rounded-md border border-border bg-card px-3 py-2">
            <div class="flex items-center gap-2 min-w-0">
              <Badge variant="secondary" class="shrink-0">{{ cat.name }}</Badge>
              <span class="truncate text-xs text-muted-foreground">{{ cat.slug }}</span>
            </div>

            <!-- Delete — wrapped in AlertDialog for confirmation -->
            <AlertDialog>
              <AlertDialogTrigger as-child>
                <Button
                  variant="ghost"
                  size="icon"
                  class="size-7 shrink-0 text-muted-foreground hover:text-destructive"
                  :disabled="deletingIds.has(cat.id)"
                  :aria-label="`Delete category ${cat.name}`"
                >
                  <Loader2Icon v-if="deletingIds.has(cat.id)" class="size-3.5 animate-spin" />
                  <Trash2Icon v-else class="size-3.5" />
                </Button>
              </AlertDialogTrigger>

              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>Delete "{{ cat.name }}"?</AlertDialogTitle>
                  <AlertDialogDescription>
                    This action cannot be undone. If any issues use this category the delete will be blocked.
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>Cancel</AlertDialogCancel>
                  <AlertDialogAction
                    class="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                    @click="handleDelete(cat)"
                  >
                    Delete
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          </div>

          <!-- Inline 409 error -->
          <p
            v-if="deleteErrors[cat.id]"
            class="pl-1 text-xs text-destructive"
          >
            {{ deleteErrors[cat.id] }}
          </p>
        </li>
      </ul>
    </div>
  </div>
</template>
