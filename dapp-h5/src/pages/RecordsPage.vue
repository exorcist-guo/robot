<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { showFailToast } from 'vant'
import { useRouter } from 'vue-router'
import { useAppStore } from '../stores/app'

const router = useRouter()
const store = useAppStore()

const records = computed(() => store.recordsList || [])
const loading = computed(() => store.recordsLoading)
const finished = computed(() => store.recordsFinished)

const hasDisplayValue = (value: unknown) => value !== null && value !== undefined && value !== ''

const formatFilledUsdc = (value: unknown) => {
  if (!hasDisplayValue(value)) return '0.00'
  const amount = Number(value)
  if (Number.isNaN(amount)) return '0.00'
  return (amount / 1_000_000).toLocaleString('zh-CN', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

const formatSignedUsdc = (value: unknown) => {
  if (!hasDisplayValue(value)) return '-'
  const amount = Number(value)
  if (Number.isNaN(amount)) return '-'
  const text = (amount / 1_000_000).toLocaleString('zh-CN', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
  return amount > 0 ? `+${text}` : text
}

const formatBps = (value: unknown) => {
  if (!hasDisplayValue(value)) return '-'
  const amount = Number(value)
  if (Number.isNaN(amount)) return '-'
  return `${(amount / 100).toFixed(2)}%`
}

const formatRecordLabel = (item: any) => {
  const lines = [
    `方向：${item.direction_text || item.direction || '-'}`,
    `状态：${item.settlement_view_text || item.status_text || '-'}`,
  ]

  if (item.is_settled === true) {
    lines.push(`胜出方向：${item.winning_outcome || '-'}`)
    lines.push(`盈亏金额：${formatSignedUsdc(item.pnl_usdc)} / 收益率：${formatBps(item.roi_bps)}`)
  } else {
    lines.push('胜出方向：未结算')
    lines.push('盈亏金额：未结算 / 收益率：未结算')
  }

  return lines.join('\n')
}

const loadRecords = async (reset = false) => {
  try {
    await store.fetchRecords({ reset })
  } catch (error: any) {
    showFailToast(error.message || '加载记录失败')
  }
}

const openDetail = (id: number | string) => {
  router.push(`/records/${id}`)
}

onMounted(() => {
  loadRecords(true)
})
</script>

<template>
  <div class="app-shell">
    <section class="page-head">
      <span class="page-eyebrow">Order Records</span>
      <h1 class="page-title">记录列表</h1>
      <p class="page-description">查看订单记录，并在上滑时自动加载更多历史记录。</p>
    </section>

    <van-list :loading="loading" :finished="finished" finished-text="没有更多记录了" @load="loadRecords(false)">
      <van-cell-group v-if="records.length" inset>
        <van-cell
          v-for="item in records"
          :key="item.id"
          class="record-cell"
          is-link
          :title="`ID：${item.id}`"
          :label="formatRecordLabel(item)"
          :value="formatFilledUsdc(item.filled_usdc)"
          @click="openDetail(item.id)"
        />
      </van-cell-group>
    </van-list>

    <div v-if="!records.length && !loading" class="surface-card empty-state">暂无记录。</div>
  </div>
</template>

<style scoped>
.record-cell :deep(.van-cell__title) {
  flex: 1 1 auto;
  min-width: 0;
}

.record-cell :deep(.van-cell__value) {
  flex: 0 0 88px;
  max-width: 88px;
}

.record-cell :deep(.van-cell__label) {
  white-space: pre-line;
}
</style>
