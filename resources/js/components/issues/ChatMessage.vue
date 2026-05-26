<script setup lang="ts">
import { computed } from 'vue'
import { marked } from 'marked'
import DOMPurify from 'dompurify'
import type { ChatMessage } from '@/types/chat'

interface Props {
  message: ChatMessage
  streaming?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  streaming: false,
})

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
  <!-- User message — right aligned -->
  <div
    v-if="message.role === 'user'"
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
    <div class="max-w-[85%] rounded-2xl rounded-tl-sm bg-muted px-3 py-2 text-sm text-foreground">
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
