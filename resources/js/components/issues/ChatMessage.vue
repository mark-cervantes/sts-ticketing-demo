<script setup lang="ts">
import { computed } from 'vue'
import { marked } from 'marked'
import DOMPurify from 'dompurify'
import ToolCallCard from '@/components/issues/ToolCallCard.vue'
import type { ChatMessage, ToolConfirmResult } from '@/types/chat'
import type { Priority } from '@/types'

interface Props {
  message: ChatMessage
  streaming?: boolean
  issueId?: number
}

const props = withDefaults(defineProps<Props>(), {
  streaming: false,
  issueId: 0,
})

const emit = defineEmits<{
  (e: 'toolConfirm', messageIndex: number, tool: string, args: Record<string, unknown>): void
  (e: 'toolEditAndCreate', prefill: { title?: string; description?: string; priority?: Priority; category_id?: number | null }): void
}>()

// Configure marked for safe, compact rendering
marked.setOptions({
  breaks: true,
})

const renderedContent = computed(() => {
  if (props.message.role !== 'assistant') return ''
  const raw = marked.parse(props.message.content) as string
  return DOMPurify.sanitize(raw)
})
</script>

<template>
  <!-- Tool call card — no chat bubble, rendered inline -->
  <div v-if="message.role === 'tool_call' && message.toolCall">
    <ToolCallCard
      :tool-call="message.toolCall"
      :tool-result="message.toolResult"
      :issue-id="issueId"
      @confirm="(tool, args) => emit('toolConfirm', 0, tool, args)"
      @edit-and-create="(prefill) => emit('toolEditAndCreate', prefill)"
    />
  </div>

  <!-- User message — right aligned -->
  <div
    v-else-if="message.role === 'user'"
    class="flex justify-end"
  >
    <div class="max-w-[80%] rounded-2xl rounded-tr-sm bg-primary/10 px-3 py-2 text-sm text-foreground">
      {{ message.content }}
    </div>
  </div>

  <!-- AI message — left aligned, renders markdown -->
  <div
    v-else
    class="flex justify-start"
  >
    <div class="max-w-[85%]">
      <div class="rounded-2xl rounded-tl-sm bg-muted px-3 py-2 text-sm text-foreground">
        <!-- eslint-disable-next-line vue/no-v-html -->
        <div
          class="prose prose-sm dark:prose-invert max-w-none [&>*:first-child]:mt-0 [&>*:last-child]:mb-0"
          v-html="renderedContent"
        />
        <!-- Pulsing dot while streaming -->
        <span
          v-if="streaming"
          class="streaming-dot ml-0.5 inline-block h-1.5 w-1.5 rounded-full bg-current opacity-70"
          aria-hidden="true"
        />
      </div>

      <!-- Contextual nudge — shown once per session on action-oriented AI responses -->
      <p
        v-if="message.showNudge"
        class="mt-1 text-xs italic text-muted-foreground/70"
      >
        💡 You can ask me to create a ticket for this.
      </p>
    </div>
  </div>
</template>

<style scoped>
.streaming-dot {
  animation: pulse-dot 1.2s ease-in-out infinite;
}

@keyframes pulse-dot {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.3;
  }
}
</style>
