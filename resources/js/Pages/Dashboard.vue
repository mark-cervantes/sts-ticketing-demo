<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import KanbanBoard from '@/components/kanban/KanbanBoard.vue'

defineOptions({ layout: AppLayout })
</script>

<template>
  <KanbanBoard />
</template>
