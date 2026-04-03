<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAppStore } from '../stores/app'

const router = useRouter()
const store = useAppStore()

const formattedAvailableBalance = computed(() => {
  const raw = store.walletAllowanceStatus?.allowance?.balance
  if (raw !== null && raw !== undefined && raw !== '') {
    const value = Number(raw)
    if (!Number.isNaN(value)) {
      return `${(value / 1_000_000).toLocaleString('zh-CN', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 6,
      })}`
    }
  }

  const fallback = store.home?.available_usdc
  if (fallback === null || fallback === undefined || fallback === '') {
    return '0'
  }

  const fallbackValue = Number(fallback)
  if (Number.isNaN(fallbackValue)) {
    return String(fallback)
  }

  return `${(fallbackValue / 1_000_000).toLocaleString('zh-CN', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 6,
  })} USDC.E`
})

const formatFilledUsdc = (value: unknown) => {
  const amount = Number(value)
  if (Number.isNaN(amount)) return '0.00'
  return (amount / 1_000_000).toLocaleString('zh-CN', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

const openRecordDetail = (id: number | string) => {
  router.push(`/records/${id}`)
}

const openRecords = () => {
  router.push('/records')
}

onMounted(async () => {
  await Promise.all([
    store.fetchHome().catch(() => null),
    store.fetchCopyTasks().catch(() => null),
  ])

  const walletStatus = await store.fetchWalletStatus().catch(() => null)
  if (walletStatus?.has_wallet) {
    await store.fetchWalletAllowanceStatus().catch(() => null)
  }
})
</script>

<template>
  <div class="app-shell">
    <section class="page-head">
      <span class="page-eyebrow">Polymarket Desk</span>
      <h1 class="page-title">首页</h1>
      <p class="page-description">查看账户资产、收益表现与最近跟单动态。</p>
    </section>

    <section class="hero-card glass-card">
      <div class="hero-top">
        <div>
          <div class="hero-label">账户概览</div>
          <div class="hero-value">{{ formattedAvailableBalance }}</div>
          <div class="hero-caption">可用余额 · USDC.e</div>
        </div>
        <span class="info-chip info-chip--brand">{{ store.home?.active_task_count || 0 }} 个任务运行中</span>
      </div>
      <div class="metrics-grid">
        <div class="metric-card">
          <div class="metric-label">今日收益</div>
          <div class="metric-value metric-value--accent">{{ store.home?.pnl_today_usdc || '0' }}</div>
        </div>
        <div class="metric-card">
          <div class="metric-label">累计收益</div>
          <div class="metric-value">{{ store.home?.pnl_total_usdc || '0' }}</div>
        </div>
      </div>
    </section>

    <section class="surface-card quick-action">
      <div>
        <div class="section-title">跟单控制台</div>
        <p class="inline-note">快速进入任务配置，新增或调整你的跟单策略。</p>
      </div>
      <van-button block type="primary" to="/copy-tasks">添加/管理跟单任务</van-button>
    </section>

    <section>
      <div class="section-header">
        <h2 class="section-title">最近记录</h2>
        <van-button size="small" plain type="primary" @click="openRecords">查看更多</van-button>
      </div>
      <van-cell-group v-if="store.home?.recent_orders?.length" inset>
        <van-cell
          v-for="item in store.home?.recent_orders || []"
          :key="item.id || item.order_id"
          is-link
          :title="`ID：${item.id || item.order_id}`"
          :label="`方向：${item.direction_text || item.direction || '-'} / 状态：${item.settlement_view_text || item.status_text || '-'}`"
          :value="formatFilledUsdc(item.filled_usdc)"
          @click="openRecordDetail(item.id || item.order_id)"
        />
      </van-cell-group>
      <div v-else class="surface-card empty-state">暂无最近记录，开始创建跟单任务后会显示在这里。</div>
    </section>
  </div>
</template>

<style scoped>
.hero-card {
  display: grid;
  gap: 18px;
  padding: 20px;
  margin-bottom: 18px;
}

.hero-top {
  display: grid;
  gap: 14px;
}

.hero-label {
  color: var(--text-secondary);
  font-size: 13px;
  font-weight: 600;
}

.hero-value {
  margin-top: 8px;
  font-size: 36px;
  line-height: 1;
  font-weight: 800;
  letter-spacing: -0.06em;
}

.hero-caption {
  margin-top: 8px;
  color: var(--text-tertiary);
  font-size: 13px;
}

.quick-action {
  display: grid;
  gap: 14px;
  padding: 18px;
  margin-bottom: 18px;
}

.section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}
</style>
