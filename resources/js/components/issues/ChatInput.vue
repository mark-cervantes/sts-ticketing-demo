<script setup lang="ts">
import { ref } from 'vue'
import { Textarea } from '@/components/ui/textarea'
import { Button } from '@/components/ui/button'
import { SendHorizonalIcon, Loader2Icon } from '@lucide/vue'

interface Props {
  disabled?: boolean
  placeholder?: string
}

const props = withDefaults(defineProps<Props>(), {
  disabled: false,
  placeholder: 'Ask about this issue…',
})

const emit = defineEmits<{
  send: [message: string]
}>()

const text = ref('')

function handleSend(): void {
  const trimmed = text.value.trim()
  if (!trimmed || props.disabled) return
  emit('send', trimmed)
  text.value = ''
}

function handleKeydown(e: KeyboardEvent): void {
  // Enter sends; Shift+Enter inserts a newline
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    handleSend()
  }
}
</script>

<template>
  <div class="flex items-end gap-2">
    <Textarea
      v-model="text"
      :placeholder="placeholder"
      :disabled="disabled"
      rows="1"
      class="max-h-32 min-h-[2.25rem] flex-1 resize-none overflow-y-auto py-2 text-sm leading-normal"
      @keydown="handleKeydown"
    />
    <Button
      type="button"
      size="icon"
      :disabled="disabled || !text.trim()"
      class="shrink-0"
      @click="handleSend"
    >
      <Loader2Icon v-if="disabled" class="size-4 animate-spin" />
      <SendHorizonalIcon v-else class="size-4" />
      <span class="sr-only">Send message</span>
    </Button>
  </div>
</template>
