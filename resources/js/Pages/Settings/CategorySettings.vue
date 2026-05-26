<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { toast } from 'vue-sonner'
import { TagIcon, PlusIcon, Trash2Icon, Loader2Icon } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
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
import { apiPost } from '@/composables/useApiFetch'
import { useCategories } from '@/composables/useCategories'
import type { Category } from '@/types'

defineOptions({ layout: AppLayout })

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

const { categories, isLoading, fetchCategories, deleteCategory } = useCategories()

// Create form
const newName = ref('')
const nameError = ref<string | null>(null)
const isCreating = ref(false)

// Per-category inline error messages (e.g. 409 "has issues")
const deleteErrors = ref<Record<number, string>>({})
// Track which IDs are currently being deleted
const deletingIds = ref<Set<number>>(new Set())

// ---------------------------------------------------------------------------
// Load
// ---------------------------------------------------------------------------

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
      const body = (await response.json()) as { errors?: Record<string, string[]>; message?: string }
      nameError.value = body.errors?.name?.[0] ?? body.message ?? 'Validation error.'
      return
    }

    if (!response.ok) {
      toast.error('Failed to create category')
      return
    }

    const created = (await response.json()) as Category
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

  // Clear any prior inline error for this row
  const errors = { ...deleteErrors.value }
  delete errors[category.id]
  deleteErrors.value = errors

  deletingIds.value = new Set(deletingIds.value).add(category.id)

  try {
    const errorMessage = await deleteCategory(category.id)

    if (errorMessage !== null) {
      // 409 or other error — show inline under the row
      deleteErrors.value = {
        ...deleteErrors.value,
        [category.id]: errorMessage,
      }

      if (!errorMessage.startsWith('Cannot delete')) {
        toast.error(`Failed to delete "${category.name}"`)
      }
      return
    }

    // Success (null returned) — category already removed from shared ref
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
  <div class="mx-auto max-w-2xl px-4 py-8">
    <!-- Page header -->
    <div class="mb-6 flex items-center gap-3">
      <div class="flex size-10 items-center justify-center rounded-lg bg-primary/10">
        <TagIcon class="size-5 text-primary" />
      </div>
      <div>
        <h1 class="text-xl font-semibold text-foreground">Category Management</h1>
        <p class="text-sm text-muted-foreground">
          Create and manage issue categories for your team
        </p>
      </div>
    </div>

    <!-- Create form -->
    <div class="mb-6 rounded-lg border border-border bg-card p-4">
      <h2 class="mb-3 text-sm font-semibold text-foreground">Add new category</h2>
      <div class="space-y-3">
        <div class="flex gap-2">
          <Input
            v-model="newName"
            placeholder="Category name…"
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
    </div>

    <!-- Category list -->
    <div>
      <!-- Skeleton while loading -->
      <div v-if="isLoading" class="space-y-2">
        <Skeleton v-for="n in 3" :key="n" class="h-14 w-full rounded-md" />
      </div>

      <!-- Empty state -->
      <p v-else-if="categories.length === 0" class="text-sm text-muted-foreground">
        No categories yet. Create one above.
      </p>

      <!-- Category rows sorted alphabetically by name -->
      <ul v-else class="space-y-2">
        <li
          v-for="category in [...categories].sort((a, b) => a.name.localeCompare(b.name))"
          :key="category.id"
          class="flex flex-col gap-1"
        >
          <!-- Category row -->
          <div class="flex items-center justify-between rounded-md border border-border bg-card px-3 py-2">
            <div class="min-w-0">
              <span class="text-sm font-medium text-foreground">{{ category.name }}</span>
              <p class="font-mono text-xs text-muted-foreground">{{ category.slug }}</p>
            </div>

            <!-- Delete — wrapped in AlertDialog for confirmation -->
            <AlertDialog>
              <AlertDialogTrigger as-child>
                <Button
                  variant="ghost"
                  size="icon"
                  class="size-7 shrink-0 text-muted-foreground hover:text-destructive"
                  :disabled="deletingIds.has(category.id)"
                  :aria-label="`Delete category ${category.name}`"
                >
                  <Loader2Icon v-if="deletingIds.has(category.id)" class="size-3.5 animate-spin" />
                  <Trash2Icon v-else class="size-3.5" />
                </Button>
              </AlertDialogTrigger>

              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>Delete "{{ category.name }}"?</AlertDialogTitle>
                  <AlertDialogDescription>
                    This action cannot be undone. If any issues use this category the delete will be blocked.
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>Cancel</AlertDialogCancel>
                  <AlertDialogAction
                    class="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                    @click="handleDelete(category)"
                  >
                    Delete
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          </div>

          <!-- Inline 409 error -->
          <p
            v-if="deleteErrors[category.id]"
            class="pl-1 text-xs text-destructive"
          >
            {{ deleteErrors[category.id] }}
          </p>
        </li>
      </ul>
    </div>
  </div>
</template>
