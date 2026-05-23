import { ref, computed } from 'vue'
import type { AxiosError } from 'axios'
import type { CreateIssueForm, Issue, ValidationErrors } from '@/types'

function createDefaults(): CreateIssueForm {
  return {
    title: '',
    description: '',
    priority: 'medium',
    category_id: null,
    deadline_at: null,
    visibility: 'private',
  }
}

export function useCreateIssue() {
  const form = ref<CreateIssueForm>(createDefaults())
  const errors = ref<ValidationErrors>({})
  const isSubmitting = ref(false)

  const isDirty = computed<boolean>(() => {
    const defaults = createDefaults()
    return (
      form.value.title !== defaults.title ||
      form.value.description !== defaults.description ||
      form.value.priority !== defaults.priority ||
      form.value.category_id !== defaults.category_id ||
      form.value.deadline_at !== defaults.deadline_at ||
      form.value.visibility !== defaults.visibility
    )
  })

  function clearFieldError(field: string): void {
    if (errors.value[field]) {
      const { [field]: _, ...rest } = errors.value
      errors.value = rest
    }
  }

  function resetForm(): void {
    form.value = createDefaults()
    errors.value = {}
  }

  async function submit(): Promise<Issue | null> {
    errors.value = {}
    isSubmitting.value = true

    try {
      const payload: Record<string, unknown> = {
        title: form.value.title,
        description: form.value.description,
        priority: form.value.priority,
      }

      if (form.value.category_id !== null) {
        payload.category_id = form.value.category_id
      }

      if (form.value.deadline_at !== null) {
        payload.deadline_at = form.value.deadline_at
      }

      if (form.value.visibility !== 'private') {
        payload.visibility = form.value.visibility
      }

      const response = await window.axios.post<Issue>('/api/issues', payload)
      return response.data
    } catch (err) {
      const axiosError = err as AxiosError<{ errors: ValidationErrors }>
      if (axiosError.response?.status === 422 && axiosError.response.data?.errors) {
        errors.value = axiosError.response.data.errors
      }
      return null
    } finally {
      isSubmitting.value = false
    }
  }

  return {
    form,
    errors,
    isSubmitting,
    isDirty,
    clearFieldError,
    resetForm,
    submit,
  }
}
