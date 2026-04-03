<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount } from 'vue'
import { showFailToast } from 'vant'
import { useRoute } from 'vue-router'
import { useAppStore } from '../stores/app'

const route = useRoute()
const store = useAppStore()

const detail = computed(() => store.recordDetail)

const hasDisplayValue = (value: unknown) => value !== null && value !== undefined && value !== ''

const formatUsdc = (value: unknown) => {
  if (!hasDisplayValue(value)) return '-'
  const amount = Number(value)
  if (Number.isNaN(amount)) return '-'
  return `${(amount / 1_000_000).toLocaleString('zh-CN', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })} USDC`
}

const formatBps = (value: unknown) => {
  if (!hasDisplayValue(value)) return '-'
  const amount = Number(value)
  if (Number.isNaN(amount)) return '-'
  return `${(amount / 100).toFixed(2)}%`
}

const settlementValue = (detail: any, value: unknown) => {
  if (detail?.is_settled !== true) return '未结算'
  return formatUsdc(value)
}

const settlementBpsValue = (detail: any, value: unknown) => {
  if (detail?.is_settled !== true) return '未结算'
  return formatBps(value)
}

const yesNo = (value: unknown) => {
  if (value === null || value === undefined) return '-'
  return value ? '是' : '否'
}

onMounted(async () => {
  try {
    await store.fetchRecordDetail(String(route.params.id))
  } catch (error: any) {
    showFailToast(error.message || '加载记录详情失败')
  }
})

onBeforeUnmount(() => {
  store.resetRecordDetail()
})
</script>

<template>
  <div class="app-shell">
    <section class="page-head">
      <span class="page-eyebrow">Order Detail</span>
      <h1 class="page-title">记录详情</h1>
      <p class="page-description">查看订单的完整交易、结算与兑奖数据。</p>
    </section>

    <template v-if="detail">
      <section class="surface-card detail-card">
        <div class="section-title">订单概览</div>
        <van-cell-group inset>
          <van-cell title="订单 ID" :value="String(detail.id || '-')" />
          <van-cell title="Poly Order ID" :label="detail.poly_order_id || '-'" class="break-row" />
          <van-cell title="订单状态" :value="detail.status_text || '-'" />
          <van-cell title="结算状态" :value="detail.settlement_view_text || '-'" />
          <van-cell title="提交时间" :value="detail.submitted_at || '-'" />
        </van-cell-group>
      </section>

      <section class="surface-card detail-card">
        <div class="section-title">交易信息</div>
        <van-cell-group inset>
          <van-cell title="下单方向" :value="detail.direction_text || detail.direction || '-'" />
          <van-cell title="已成交金额" :value="formatUsdc(detail.filled_usdc)" />
          <van-cell title="平均成交价" :value="detail.avg_price || '-'" />
          <van-cell title="原始数量" :value="detail.original_size || '-'" />
          <van-cell title="已成交数量" :value="detail.filled_size || '-'" />
          <van-cell title="下单价格" :value="detail.order_price || '-'" />
          <van-cell title="Outcome" :value="detail.outcome || '-'" />
          <van-cell title="订单类型" :value="detail.order_type || '-'" />
          <van-cell title="远端状态" :value="detail.remote_order_status || '-'" />
        </van-cell-group>
      </section>

      <section class="surface-card detail-card">
        <div class="section-title">结算与收益</div>
        <van-cell-group inset>
          <van-cell title="是否已结算" :value="yesNo(detail.is_settled)" />
          <van-cell title="是否盈利" :value="yesNo(detail.is_win)" />
          <van-cell title="胜出方向" :value="detail.is_settled === true ? (detail.winning_outcome || '-') : '未结算'" />
          <van-cell title="盈亏金额" :value="settlementValue(detail, detail.pnl_usdc)" />
          <van-cell title="收益金额" :value="settlementValue(detail, detail.profit_usdc)" />
          <van-cell title="收益率" :value="settlementBpsValue(detail, detail.roi_bps)" />
          <van-cell title="兑奖状态" :value="detail.claim_status_text || String(detail.claim_status ?? '-')" />
          <van-cell title="兑奖交易哈希" :label="detail.claim_tx_hash || '-'" class="break-row" />
        </van-cell-group>
      </section>

      <section class="surface-card detail-card">
        <div class="section-title">异常信息</div>
        <van-cell-group inset>
          <van-cell title="失败分类" :value="detail.failure_category || '-'" />
          <van-cell title="可重试" :value="yesNo(detail.is_retryable)" />
          <van-cell title="重试次数" :value="String(detail.retry_count ?? 0)" />
          <van-cell title="错误码" :value="detail.error_code || '-'" />
          <van-cell title="错误信息" :label="detail.error_message || '-'" class="break-row" />
        </van-cell-group>
      </section>
    </template>

    <div v-else class="surface-card empty-state">记录详情加载中...</div>
  </div>
</template>

<style scoped>
.detail-card {
  display: grid;
  gap: 12px;
  padding: 18px;
  margin-bottom: 18px;
}

.break-row :deep(.van-cell__label),
.break-row :deep(.van-cell__title) {
  word-break: break-all;
}
</style>
