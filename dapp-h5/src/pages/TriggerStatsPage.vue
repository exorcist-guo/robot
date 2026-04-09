<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { showFailToast } from 'vant'
import { useAppStore } from '../stores/app'

const store = useAppStore()

const statsByTrigger = computed(() => store.recordsStatsByTrigger || [])

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

const loadStats = async () => {
  try {
    await store.fetchRecordsStatsByTrigger()
  } catch (error: any) {
    showFailToast(error.message || '加载统计失败')
  }
}

onMounted(() => {
  loadStats()
})
</script>

<template>
  <div class="app-shell">
    <section class="page-head">
      <span class="page-eyebrow">Trigger Statistics</span>
      <h1 class="page-title">触发条件统计</h1>
      <p class="page-description">查看不同触发条件下的订单表现和盈亏情况。</p>
    </section>

    <!-- 触发条件统计模块 -->
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

    <div v-else class="surface-card empty-state">暂无统计数据。</div>
  </div>
</template>

<style scoped>
.stats-section {
  margin-bottom: 16px;
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
