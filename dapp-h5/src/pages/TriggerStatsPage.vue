<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { showFailToast } from 'vant'
import { useAppStore } from '../stores/app'

const store = useAppStore()

const statsByTrigger = computed(() => store.recordsStatsByTrigger || [])
const activeQuickRange = ref<'1d' | '1w' | '1m' | ''>('1w')
const filters = reactive({
  start_time: '',
  end_time: '',
})

const hasDisplayValue = (value: unknown) => value !== null && value !== undefined && value !== ''

const formatSignedUsdcShort = (value: unknown) => {
  if (!hasDisplayValue(value)) return '$0.00'
  const amount = Number(value)
  if (Number.isNaN(amount)) return '$0.00'
  const text = (amount / 1_000_000).toLocaleString('zh-CN', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
  return amount > 0 ? `+$${text}` : `-$${Math.abs(amount / 1_000_000).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

const formatDateTimeInput = (date: Date) => {
  const year = date.getFullYear()
  const month = `${date.getMonth() + 1}`.padStart(2, '0')
  const day = `${date.getDate()}`.padStart(2, '0')
  const hour = `${date.getHours()}`.padStart(2, '0')
  const minute = `${date.getMinutes()}`.padStart(2, '0')

  return `${year}-${month}-${day}T${hour}:${minute}`
}

const formatApiTime = (value: string) => {
  if (!value) return ''
  return `${value.replace('T', ' ')}:00`
}

const formatDisplayTime = (value: string) => {
  if (!value) return '-'
  return value.replace('T', ' ')
}

const applyQuickRange = (range: '1d' | '1w' | '1m') => {
  activeQuickRange.value = range
  const end = new Date()
  const start = new Date(end)

  if (range === '1d') {
    start.setDate(start.getDate() - 1)
  } else if (range === '1w') {
    start.setDate(start.getDate() - 7)
  } else {
    start.setMonth(start.getMonth() - 1)
  }

  filters.start_time = formatDateTimeInput(start)
  filters.end_time = formatDateTimeInput(end)
  void loadStats()
}

const validateTimeRange = () => {
  if (filters.start_time && filters.end_time && new Date(filters.start_time).getTime() > new Date(filters.end_time).getTime()) {
    showFailToast('开始时间不能大于结束时间')
    return false
  }

  return true
}

const loadStats = async () => {
  if (!validateTimeRange()) {
    return
  }

  try {
    await store.fetchRecordsStatsByTrigger({
      start_time: formatApiTime(filters.start_time),
      end_time: formatApiTime(filters.end_time),
    })
  } catch (error: any) {
    showFailToast(error.message || '加载统计失败')
  }
}

const submitFilters = async () => {
  activeQuickRange.value = ''
  await loadStats()
}

const resetFilters = async () => {
  applyQuickRange('1w')
}

onMounted(() => {
  applyQuickRange('1w')
})
</script>

<template>
  <div class="app-shell">
    <section class="page-head">
      <span class="page-eyebrow">Trigger Statistics</span>
      <h1 class="page-title">触发条件统计</h1>
      <p class="page-description">查看不同触发条件下的订单表现和盈亏情况。</p>
    </section>

    <section class="surface-card filter-section">
      <div class="quick-actions">
        <van-button size="small" :type="activeQuickRange === '1d' ? 'primary' : 'default'" @click="applyQuickRange('1d')">1日</van-button>
        <van-button size="small" :type="activeQuickRange === '1w' ? 'primary' : 'default'" @click="applyQuickRange('1w')">1周</van-button>
        <van-button size="small" :type="activeQuickRange === '1m' ? 'primary' : 'default'" @click="applyQuickRange('1m')">1月</van-button>
      </div>

      <van-cell-group inset>
        <van-field v-model="filters.start_time" label="开始时间" type="datetime-local" />
        <van-field v-model="filters.end_time" label="结束时间" type="datetime-local" />
      </van-cell-group>

      <div class="filter-summary">
        统计范围：{{ formatDisplayTime(filters.start_time) }} 至 {{ formatDisplayTime(filters.end_time) }}
      </div>

      <div class="filter-actions">
        <van-button plain type="primary" block @click="submitFilters">查询</van-button>
        <van-button plain block @click="resetFilters">重置</van-button>
      </div>
    </section>

    <div v-if="statsByTrigger.length" class="stats-section">
      <van-cell-group inset>
        <van-cell
          v-for="stat in statsByTrigger"
          :key="stat.trigger_condition"
          class="stat-cell"
        >
          <template #title>
            <div class="stat-header">{{ stat.trigger_condition }}</div>
          </template>
          <template #label>
            <div class="stat-details">
              <div class="stat-row">
                <span>总订单：{{ stat.total_orders }}</span>
                <span>盈利：{{ stat.profit_orders }}</span>
                <span>亏损：{{ stat.loss_orders }}</span>
              </div>
              <div class="stat-row">
                <span class="pnl-amount" :class="{ 'positive': Number(stat.total_pnl_usdc) > 0, 'negative': Number(stat.total_pnl_usdc) < 0 }">
                  盈亏：{{ formatSignedUsdcShort(stat.total_pnl_usdc) }}
                </span>
                <span class="win-rate" :class="{ 'high-rate': stat.win_rate >= 60, 'low-rate': stat.win_rate < 40 }">
                  胜率：{{ stat.win_rate }}%
                </span>
              </div>
            </div>
          </template>
        </van-cell>
      </van-cell-group>
    </div>

    <div v-else class="surface-card empty-state">该时间范围内暂无统计数据。</div>
  </div>
</template>

<style scoped>
.filter-section,
.stats-section {
  margin-bottom: 16px;
}

.filter-section {
  display: grid;
  gap: 12px;
  padding: 16px;
}

.quick-actions,
.filter-actions {
  display: flex;
  gap: 8px;
}

.quick-actions :deep(.van-button),
.filter-actions :deep(.van-button) {
  flex: 1;
}

.filter-summary {
  font-size: 12px;
  color: var(--van-text-color-3);
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
  flex-wrap: wrap;
}

.win-rate,
.pnl-amount {
  font-weight: 600;
}

.pnl-amount.positive,
.high-rate {
  color: #07c160;
}

.pnl-amount.negative,
.low-rate {
  color: #ee0a24;
}
</style>
