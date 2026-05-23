<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { toast } from 'vue-sonner'
import { getLocalTimeZone, today, type DateValue } from '@internationalized/date'
import { CalendarIcon, PlusIcon, Loader2Icon } from '@lucide/vue'
import { toDate } from 'reka-ui/date'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Calendar } from '@/components/ui/calendar'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { useCreateIssue } from '@/composables/useCreateIssue'
import { useCategories } from '@/composables/useCategories'
import type { Issue, Priority } from '@/types'

const props = defineProps<{
  open: boolean
}>()

const emit = defineEmits<{
  (e: 'update:open', value: boolean): void
  (e: 'created', issue: Issue): void
}>()

const { form, errors, isSubmitting, isDirty, clearFieldError, resetForm, submit } = useCreateIssue()
const { categories, isLoading: categoriesLoading, isCreating: categoryCreating, fetchCategories, createCategory } = useCategories()

const newCategoryName = ref('')
const showNewCategoryInput = ref(false)
const datePickerOpen = ref(false)

const priorityOptions: { value: Priority; label: string }[] = [
  { value: 'low', label: 'Low' },
  { value: 'medium', label: 'Medium' },
  { value: 'high', label: 'High' },
  { value: 'critical', label: 'Critical' },
]

const todayDate = today(getLocalTimeZone())

onMounted(() => {
  fetchCategories()
})

watch(() => props.open, (isOpen) => {
  if (isOpen) {
    fetchCategories()
  }
})

function handleOpenChange(value: boolean): void {
  if (!value && isDirty.value) {
    const confirmed = window.confirm('You have unsaved changes. Are you sure you want to close?')
    if (!confirmed) return
  }
  if (!value) {
    resetForm()
    newCategoryName.value = ''
    showNewCategoryInput.value = false
  }
  emit('update:open', value)
}

async function handleSubmit(): Promise<void> {
  const issue = await submit()
  if (issue) {
    toast.success('Issue created successfully')
    emit('created', issue)
    resetForm()
    emit('update:open', false)
  }
}

async function handleAddCategory(): Promise<void> {
  const category = await createCategory(newCategoryName.value)
  if (category) {
    form.value.category_id = category.id
    newCategoryName.value = ''
    showNewCategoryInput.value = false
    clearFieldError('category_id')
  }
}

function handleDateSelect(date: DateValue | undefined): void {
  if (!date) return
  const jsDate = toDate(date)
  form.value.deadline_at = jsDate.toISOString().split('T')[0]
  clearFieldError('deadline_at')
  datePickerOpen.value = false
}

function clearDeadline(): void {
  form.value.deadline_at = null
}

function fieldError(field: string): string | undefined {
  return errors.value[field]?.[0]
}
</script>

<template>
  <Dialog :open="open" @update:open="handleOpenChange">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>Create New Issue</DialogTitle>
        <DialogDescription>
          Fill in the details below to create a new issue.
        </DialogDescription>
      </DialogHeader>

      <form class="space-y-4" @submit.prevent="handleSubmit">
        <!-- Title -->
        <div class="space-y-1.5">
          <Label for="issue-title">Title</Label>
          <Input
            id="issue-title"
            v-model="form.title"
            placeholder="Issue title"
            :aria-invalid="!!fieldError('title')"
            @input="clearFieldError('title')"
          />
          <p v-if="fieldError('title')" class="text-sm text-destructive">
            {{ fieldError('title') }}
          </p>
        </div>

        <!-- Description -->
        <div class="space-y-1.5">
          <Label for="issue-description">Description</Label>
          <Textarea
            id="issue-description"
            v-model="form.description"
            placeholder="Describe the issue..."
            :aria-invalid="!!fieldError('description')"
            @input="clearFieldError('description')"
          />
          <p v-if="fieldError('description')" class="text-sm text-destructive">
            {{ fieldError('description') }}
          </p>
        </div>

        <!-- Priority & Category row -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <!-- Priority -->
          <div class="space-y-1.5">
            <Label>Priority</Label>
            <Select
              v-model="form.priority"
              @update:model-value="clearFieldError('priority')"
            >
              <SelectTrigger>
                <SelectValue placeholder="Select priority" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="option in priorityOptions"
                  :key="option.value"
                  :value="option.value"
                >
                  {{ option.label }}
                </SelectItem>
              </SelectContent>
            </Select>
            <p v-if="fieldError('priority')" class="text-sm text-destructive">
              {{ fieldError('priority') }}
            </p>
          </div>

          <!-- Category -->
          <div class="space-y-1.5">
            <Label>Category</Label>
            <Select
              :model-value="form.category_id !== null ? String(form.category_id) : undefined"
              @update:model-value="(val) => {
                const v = String(val)
                if (v === '__new__') {
                  showNewCategoryInput = true
                } else {
                  form.category_id = Number(v)
                  clearFieldError('category_id')
                }
              }"
            >
              <SelectTrigger>
                <SelectValue placeholder="Select category" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="cat in categories"
                  :key="cat.id"
                  :value="String(cat.id)"
                >
                  {{ cat.name }}
                </SelectItem>
                <SelectItem value="__new__" class="text-primary">
                  <span class="flex items-center gap-1.5">
                    <PlusIcon class="size-3.5" />
                    Add new category
                  </span>
                </SelectItem>
              </SelectContent>
            </Select>

            <!-- Inline add category -->
            <div v-if="showNewCategoryInput" class="flex gap-2">
              <Input
                v-model="newCategoryName"
                placeholder="Category name"
                class="flex-1"
                @keydown.enter.prevent="handleAddCategory"
              />
              <Button
                type="button"
                size="sm"
                :disabled="categoryCreating || !newCategoryName.trim()"
                @click="handleAddCategory"
              >
                <Loader2Icon v-if="categoryCreating" class="size-3.5 animate-spin" />
                <span v-else>Add</span>
              </Button>
            </div>
            <p v-if="fieldError('category_id')" class="text-sm text-destructive">
              {{ fieldError('category_id') }}
            </p>
          </div>
        </div>

        <!-- Deadline -->
        <div class="space-y-1.5">
          <Label>Deadline</Label>
          <Popover v-model:open="datePickerOpen">
            <PopoverTrigger as-child>
              <Button
                type="button"
                variant="outline"
                class="w-full justify-start text-left font-normal"
                :class="{ 'text-muted-foreground': !form.deadline_at }"
              >
                <CalendarIcon class="size-4" />
                <span v-if="form.deadline_at">{{ form.deadline_at }}</span>
                <span v-else>Pick a date</span>
              </Button>
            </PopoverTrigger>
            <PopoverContent class="w-auto p-0" align="start">
              <Calendar
                :min-value="todayDate"
                @update:model-value="handleDateSelect"
              />
            </PopoverContent>
          </Popover>
          <Button
            v-if="form.deadline_at"
            type="button"
            variant="link"
            size="xs"
            class="h-auto p-0 text-xs text-muted-foreground"
            @click="clearDeadline"
          >
            Clear deadline
          </Button>
          <p v-if="fieldError('deadline_at')" class="text-sm text-destructive">
            {{ fieldError('deadline_at') }}
          </p>
        </div>

        <!-- Visibility -->
        <div class="flex items-center justify-between">
          <div>
            <Label for="issue-visibility">Public</Label>
            <p class="text-xs text-muted-foreground">
              Make this issue visible to everyone
            </p>
          </div>
          <Switch
            id="issue-visibility"
            :checked="form.visibility === 'public'"
            @update:checked="(val: boolean) => {
              form.visibility = val ? 'public' : 'private'
            }"
          />
        </div>

        <!-- Footer -->
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            @click="handleOpenChange(false)"
          >
            Cancel
          </Button>
          <Button type="submit" :disabled="isSubmitting">
            <Loader2Icon v-if="isSubmitting" class="size-4 animate-spin" />
            <span v-if="isSubmitting">Creating...</span>
            <span v-else>Create Issue</span>
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
