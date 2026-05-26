<script setup lang="ts">
import { ref } from 'vue'
import { TicketPlusIcon, CheckCircleIcon } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import type { ToolCallData, ToolConfirmResult } from '@/types/chat'
import type { Priority } from '@/types'

interface Props {
  toolCall: ToolCallData
  toolResult?: ToolConfirmResult
  issueId: number
}

const props = defineProps<Props>()

const emit = defineEmits<{
  (e: 'confirm', tool: string, args: Record<string, unknown>): void
  (e: 'editAndCreate', prefill: { title?: string; description?: string; priority?: Priority; category_id?: number | null }): void
}>()

// Local confirming state — prevents double-submit
const confirming = ref(false)

function handleConfirm(): void {
  if (confirming.value || props.toolResult) return
  confirming.value = true
  emit('confirm', props.toolCall.tool, props.toolCall.arguments)
}

function handleEditAndCreate(): void {
  const args = props.toolCall.arguments
  emit('editAndCreate', {
    title: typeof args.title === 'string' ? args.title : undefined,
    description: typeof args.description === 'string' ? args.description : undefined,
    priority: typeof args.priority === 'string' ? (args.priority as Priority) : undefined,
    // category_id resolution happens in the parent — we pass null here since
    // the LLM gives us a category name string, not an ID
    category_id: null,
  })
}
</script>

<template>
  <div class="my-2 rounded-lg border border-border bg-card p-4">
    <!-- create_ticket template -->
    <template v-if="toolCall.tool === 'create_ticket'">
      <div class="flex items-center gap-2 text-sm font-medium text-foreground">
        <TicketPlusIcon class="size-4 text-primary" />
        Create Ticket
      </div>

      <div class="mt-2 space-y-1 text-sm">
        <p class="font-medium">{{ toolCall.arguments.title }}</p>
        <div class="flex gap-2 text-xs capitalize text-muted-foreground">
          <span>{{ toolCall.arguments.priority }}</span>
          <span>·</span>
          <span>{{ toolCall.arguments.category }}</span>
        </div>
        <p class="mt-1 line-clamp-3 text-xs text-muted-foreground">
          {{ toolCall.arguments.description }}
        </p>
      </div>

      <!-- Before confirmation -->
      <div
        v-if="!toolResult"
        class="mt-3 flex gap-2"
      >
        <Button
          size="sm"
          :disabled="confirming"
          @click="handleConfirm"
        >
          <template v-if="confirming">
            Creating...
          </template>
          <template v-else>
            Create
          </template>
        </Button>
        <Button
          size="sm"
          variant="outline"
          @click="handleEditAndCreate"
        >
          Edit &amp; Create
        </Button>
      </div>

      <!-- After confirmation — success -->
      <div
        v-else-if="toolResult.success"
        class="mt-3 flex items-center gap-2 text-sm text-emerald-600 dark:text-emerald-400"
      >
        <CheckCircleIcon class="size-4" />
        Created — #{{ toolResult.data?.id }}
      </div>

      <!-- After confirmation — failure -->
      <div
        v-else
        class="mt-3 text-sm text-destructive"
      >
        {{ toolResult.message }}
      </div>
    </template>

    <!-- Generic fallback for future tools -->
    <template v-else>
      <p class="text-sm font-medium capitalize text-foreground">
        {{ toolCall.tool.replace(/_/g, ' ') }}
      </p>
      <pre class="mt-1 overflow-x-auto text-xs text-muted-foreground">{{ JSON.stringify(toolCall.arguments, null, 2) }}</pre>
    </template>
  </div>
</template>
