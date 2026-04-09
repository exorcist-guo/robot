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

const formatDateTime = (value: unknown) => {
  if (!hasDisplayValue(value)) return '-'
  try {
    const date = new Date(value as string)
    if (Number.isNaN(date.getTime())) return '-'
    return date.toLocaleString('zh-CN', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: false,
    })
  } catch {
    return '-'
  }
}

const formatRecordLabel = (item: any) => {
  const lines = [
    `方向：${item.direction_text || item.direction || '-'}`,
    `状态：${item.settlement_view_text || item.status_text || '-'}`,
  ]

  // 添加下单时间
  if (item.submitted_at) {
    lines.push(`下单时间：${formatDateTime(item.submitted_at)}`)
  }

  // 添加触发条件
  if (item.intent?.price_time_limit) {
    lines.push(`触发条件：${item.intent.price_time_limit}`)
  }

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

const loadStats = async () => {
  try {
    await store.fetchRecordsStatsByTrigger()
  } catch (error: any) {
    showFailToast(error.message || '加载统计失败')
  }
}

const openDetail = (id: number | string) => {
  router.push(`/records/${id}`)
}

onMounted(() => {
  loadRecords(true)
  loadStats()
})
</script>

<template>
  <div class="app-shell">
    <section class="page-head">
      <span class="page-eyebrow">Order Records</span>
      <h1 class="page-title">记录列表</h1>
      <p class="page-description">查看订单记录，并在上滑时自动加载更多历史记录。</p>
    </section>

    <!-- 触发条件统计模块 -->
 

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

.stats-section {
  margin-bottom: 16px;
}

.stats-title {
  font-size: 16px;
  font-weight: 600;
  padding: 12px 16px 8px;
  margin: 0;
  color: var(--van-text-color);
}

.stat-cell {
  padding: 12px 16px;
}

.stat-header {
  font-size: 15px;
  font-weight: 600;
  color: var(--van-text-color);
  margin-bottom: 8px;
}

.stat-details {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.stat-row {
  display: flex;
  gap: 16px;
  font-size: 13px;
  color: var(--van-text-color-2);
}

.win-rate {
  font-weight: 600;
}

.pnl-amount {
  font-weight: 600;
}

.pnl-amount.positive {
  color: #07c160;
}

.pnl-amount.negative {
  color: #ee0a24;
}

.high-rate {
  color: #07c160;
}

.low-rate {
  color: #ee0a24;
}
</style>
