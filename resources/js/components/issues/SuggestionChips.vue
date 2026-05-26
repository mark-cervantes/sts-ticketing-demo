<script setup lang="ts">
import { computed } from 'vue'
import { SparklesIcon } from '@lucide/vue'

interface Props {
  chips: string[]
}

const props = defineProps<Props>()

const emit = defineEmits<{
  (e: 'select', text: string): void
}>()

const STATIC_CHIPS = ['Summarize the discussion', "What's the root cause?", 'What should we do next?']

interface ChipItem {
  text: string
  isToolChip: boolean
}

const allChips = computed<ChipItem[]>(() => {
  const staticItems: ChipItem[] = STATIC_CHIPS.map((text) => ({ text, isToolChip: false }))
  const toolItems: ChipItem[] = props.chips.map((text) => ({ text, isToolChip: true }))
  return [...staticItems, ...toolItems]
})
</script>

<template>
  <div class="space-y-2">
    <p class="text-xs font-medium text-muted-foreground">
      💡 Try asking:
    </p>
    <div class="flex flex-wrap gap-2">
      <button
        v-for="chip in allChips"
        :key="chip.text"
        type="button"
        class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-border px-3 py-1.5 text-xs text-muted-foreground transition-colors hover:border-primary/40 hover:text-foreground"
        @click="emit('select', chip.text)"
      >
        <SparklesIcon
          v-if="chip.isToolChip"
          class="size-3 text-primary/70"
        />
        {{ chip.text }}
      </button>
    </div>
  </div>
</template>
