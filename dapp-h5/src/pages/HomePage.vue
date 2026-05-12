<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import http from '../api/http'
import { useAppStore } from '../stores/app'

type Segment = 'holding' | 'records'
type PositionStatus = 'active' | 'closed'

type SummaryState = {
  holdingValue: string
  bestProfit: string
  predictions: string
  pnlLabel: string
  pnlValue: string
  pnlPeriod: string
}

type PositionItem = {
  key: string
  title: string
  subtitle: string
  badge: string
  shares: string
  value: string
  pnl: string
  positive: boolean
  icon: string
  logo: string
  tint: string
}

type ActivityItem = {
  key: string
  title: string
  subtitle: string
  value: string
  detail: string
  positive: boolean
}

const store = useAppStore()

const profile = ref({
  name: 'Anon',
  avatarGradient: 'linear-gradient(135deg, #5b6cff 0%, #6c4cf5 35%, #54d38a 100%)',
})

const summary = ref<SummaryState>({
  holdingValue: '$0.00',
  bestProfit: '$0.00',
  predictions: '0',
  pnlLabel: '盈亏',
  pnlValue: '$0.00',
  pnlPeriod: '过去 24 小时',
})

const periodTabs = [
  { label: '1天', value: '1D', pnlInterval: '1d', pnlFidelity: '1h' },
  { label: '1周', value: '1W', pnlInterval: '1w', pnlFidelity: '3h' },
  { label: '1个月', value: '1M', pnlInterval: '1m', pnlFidelity: '18h' },
  { label: '1年', value: '1Y', pnlInterval: 'all', pnlFidelity: '1d' },
  { label: '年初至今', value: 'YTD', pnlInterval: 'all', pnlFidelity: '1d' },
  { label: '全部', value: 'ALL', pnlInterval: 'all', pnlFidelity: 'all' },
]

const activePeriod = ref('1D')
const activeSegment = ref<Segment>('holding')
const activeStatus = ref<PositionStatus>('active')
const loading = ref(false)
const searchKeyword = ref('')
const trendPoints = ref<number[]>([])
const activePositions = ref<any[]>([])
const closedPositions = ref<any[]>([])
const activities = ref<any[]>([])
const userAddress = ref('')

const findPeriodConfig = () => periodTabs.find(item => item.value === activePeriod.value) || periodTabs[0]

const formatCurrency = (value: unknown) => {
  const numeric = Number(value)
  if (Number.isNaN(numeric)) return '$0.00'
  const sign = numeric < 0 ? '-' : ''
  return `${sign}$${Math.abs(numeric).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`
}

const formatCompactNumber = (value: unknown) => {
  const numeric = Number(value)
  if (Number.isNaN(numeric)) return '0'
  return numeric.toLocaleString('en-US')
}

const formatShare = (value: unknown) => {
  const numeric = Number(value)
  if (Number.isNaN(numeric)) return '0 份额'
  return `${numeric.toLocaleString('zh-CN', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 1,
  })} 份额`
}

const formatBadge = (value: unknown) => {
  const numeric = Number(value)
  if (Number.isNaN(numeric)) return '0¢'
  return `${Math.round(numeric * 100)}¢`
}

const toTitle = (item: any) => String(item?.title || item?.slug || item?.market || '未命名市场')
const toSubtitle = (item: any) => String(item?.outcome || item?.side || '仓位')

const tintOptions = ['emerald', 'blue', 'orange', 'gold', 'teal', 'rose'] as const
const iconOptions = ['⚽', '🏀', '🎾', '⚾', '🏒', '📈']
const pickTint = (index: number) => tintOptions[index % tintOptions.length]
const pickLogo = (index: number) => iconOptions[index % iconOptions.length]

const mapPositionToCard = (item: any, index: number): PositionItem => {
  const pnlRaw = Number(item?.cashPnl ?? item?.pnl ?? 0)
  const percentRaw = Number(item?.percentPnl ?? item?.roi ?? 0)
  return {
    key: String(item?.asset || item?.conditionId || item?.slug || index),
    title: toTitle(item),
    subtitle: toSubtitle(item),
    badge: formatBadge(item?.curPrice ?? item?.avgPrice ?? item?.price ?? 0),
    shares: formatShare(item?.size ?? item?.totalBought ?? 0),
    value: formatCurrency(item?.currentValue ?? item?.cashValue ?? item?.value ?? 0),
    pnl: `${formatCurrency(pnlRaw)} (${percentRaw.toFixed(2)}%)`,
    positive: pnlRaw >= 0,
    icon: String(item?.icon || ''),
    logo: pickLogo(index),
    tint: pickTint(index),
  }
}

const mapActivityToCard = (item: any, index: number): ActivityItem => {
  const title = String(item?.title || item?.slug || item?.marketTitle || '交易记录')
  const side = String(item?.side || item?.type || item?.outcome || '记录')
  const size = Number(item?.size ?? item?.usdcSize ?? item?.size_usdc ?? item?.value ?? 0)
  const price = Number(item?.price ?? item?.curPrice ?? 0)
  const positive = side.toUpperCase().includes('BUY') || side === 'Yes'
  return {
    key: String(item?.id || item?.transactionHash || `${title}-${index}`),
    title,
    subtitle: side,
    value: formatCurrency(size && price ? size * price : size),
    detail: item?.timestamp ? new Date(Number(item.timestamp) * 1000).toLocaleString('zh-CN') : '最近成交',
    positive,
  }
}

const positionCards = computed(() => {
  const source = activeStatus.value === 'active' ? activePositions.value : closedPositions.value
  const keyword = searchKeyword.value.trim().toLowerCase()
  return source
    .filter(item => {
      if (!keyword) return true
      return `${toTitle(item)} ${toSubtitle(item)}`.toLowerCase().includes(keyword)
    })
    .map((item, index) => mapPositionToCard(item, index))
})

const activityCards = computed(() => activities.value.map((item, index) => mapActivityToCard(item, index)))

const chartPath = computed(() => {
  const points = trendPoints.value.length > 1 ? trendPoints.value : [0, 0, 0, 0, 0, 0]
  const width = 500
  const height = 120
  const min = Math.min(...points)
  const max = Math.max(...points)
  const range = max - min || 1

  return points
    .map((point, index) => {
      const x = (index / Math.max(points.length - 1, 1)) * (width - 8) + 4
      const y = height - ((point - min) / range) * (height - 24) - 12
      return `${index === 0 ? 'M' : 'L'} ${x.toFixed(2)} ${y.toFixed(2)}`
    })
    .join(' ')
})

const extractBestProfit = (stats: any) => {
  const candidates = [
    stats?.bestProfit,
    stats?.best_profit,
    stats?.maxProfit,
    stats?.max_profit,
    stats?.largestProfit,
    stats?.largest_profit,
    stats?.totalProfit,
    stats?.total_profit,
    stats?.profit,
    stats?.pnl,
  ]

  for (const candidate of candidates) {
    const numeric = Number(candidate)
    if (!Number.isNaN(numeric)) {
      return numeric
    }
  }

  return 0
}

const extractPredictions = (stats: any) => {
  const candidates = [
    stats?.predictions,
    stats?.predictionCount,
    stats?.prediction_count,
    stats?.trades,
    stats?.tradeCount,
    stats?.trade_count,
    stats?.markets,
  ]

  for (const candidate of candidates) {
    const numeric = Number(candidate)
    if (!Number.isNaN(numeric)) {
      return numeric
    }
  }

  return 0
}

const normalizePnlSeries = (payload: any) => {
  const source = Array.isArray(payload)
    ? payload
    : Array.isArray(payload?.history)
      ? payload.history
      : Array.isArray(payload?.pnl)
        ? payload.pnl
        : []

  return source
    .map((item: any) => Number(item?.p ?? item?.pnl ?? item?.value ?? item?.amount ?? item?.y ?? 0))
    .filter((value: number) => !Number.isNaN(value))
}

const resolveUserAddress = async () => {
  if (!store.me) {
    await store.fetchMe().catch(() => null)
  }
  const me = store.me || {}
  return String(me?.wallet?.signer_address || me?.address || '').toLowerCase()
}

const loadPnlData = async (address: string) => {
  if (!address) {
    trendPoints.value = []
    summary.value = {
      ...summary.value,
      pnlValue: '$0.00',
      pnlPeriod: activePeriod.value === '1D' ? '过去 24 小时' : '区间盈亏走势',
    }
    return
  }

  const periodConfig = findPeriodConfig()
  const { data } = await http.get('/markets/user-pnl', {
    params: {
      user_address: address,
      interval: periodConfig.pnlInterval,
      fidelity: periodConfig.pnlFidelity,
    },
  })

  const pnlPayload = data?.data?.pnl ?? []
  trendPoints.value = normalizePnlSeries(pnlPayload)
  const pnlSeries = trendPoints.value
  const latestPnl = pnlSeries.length ? pnlSeries[pnlSeries.length - 1] : 0

  summary.value = {
    ...summary.value,
    pnlValue: formatCurrency(latestPnl),
    pnlPeriod: activePeriod.value === '1D' ? '过去 24 小时' : '区间盈亏走势',
  }
}

const loadHomeData = async () => {
  loading.value = true
  try {
    const address = await resolveUserAddress()
    userAddress.value = address

    if (!address) {
      return
    }

    profile.value = {
      ...profile.value,
      name: String(store.me?.nickname || store.me?.address || 'Anon'),
    }

    const [valueRes, statsRes, positionsRes, closedRes, activityRes] = await Promise.all([
      http.get('/markets/value', { params: { user: address } }),
      http.get('/markets/user-stats', { params: { address } }),
      http.get('/markets/positions', { params: { user: address, limit: 30, offset: 0 } }),
      http.get('/markets/closed-positions', { params: { user: address, limit: 30, offset: 0 } }),
      http.get('/markets/activity', { params: { user: address, limit: 30, offset: 0 } }),
    ])

    const valuePayload = valueRes.data?.data?.value ?? {}
    const statsPayload = statsRes.data?.data?.stats ?? {}

    activePositions.value = valuePayload?.positions ?? positionsRes.data?.data?.list ?? []
    closedPositions.value = closedRes.data?.data?.list ?? []
    activities.value = activityRes.data?.data?.list ?? []

    summary.value = {
      holdingValue: formatCurrency(valuePayload?.totalValue ?? valuePayload?.holdingValue ?? valuePayload?.value ?? 0),
      bestProfit: formatCurrency(extractBestProfit(statsPayload)),
      predictions: formatCompactNumber(extractPredictions(statsPayload)),
      pnlLabel: '盈亏',
      pnlValue: '$0.00',
      pnlPeriod: activePeriod.value === '1D' ? '过去 24 小时' : '区间盈亏走势',
    }

    await loadPnlData(address)
  } finally {
    loading.value = false
  }
}

const setPeriod = async (value: string) => {
  activePeriod.value = value
  await loadPnlData(userAddress.value)
}

onMounted(async () => {
  await loadHomeData()
})
</script>

<template>
  <div class="portfolio-page">
    <header class="portfolio-hero">
      <div class="portfolio-profile">
        <div class="portfolio-avatar" :style="{ background: profile.avatarGradient }"></div>
        <div>
          <div class="portfolio-name">{{ profile.name }}</div>
        </div>
      </div>
      <div class="portfolio-actions">
        <button class="ghost-icon" type="button" aria-label="scan">
          <span>⌁</span>
        </button>
        <button class="ghost-icon" type="button" aria-label="share">
          <span>↗</span>
        </button>
      </div>
    </header>

    <section class="summary-strip surface-card">
      <div class="summary-item">
        <div class="summary-value">{{ summary.holdingValue }}</div>
        <div class="summary-label">持仓价值</div>
      </div>
      <div class="summary-item">
        <div class="summary-value">{{ summary.bestProfit }}</div>
        <div class="summary-label">最高盈利</div>
      </div>
      <div class="summary-item">
        <div class="summary-value">{{ summary.predictions }}</div>
        <div class="summary-label">预测</div>
      </div>
    </section>

    <section class="pnl-card glass-card">
      <div class="pnl-header">
        <div class="pnl-meta">
          <span class="pnl-dot"></span>
          <span>{{ summary.pnlLabel }}</span>
        </div>
        <div class="pnl-tabs">
          <button
            v-for="tab in periodTabs"
            :key="tab.value"
            type="button"
            class="period-tab"
            :class="{ 'period-tab--active': activePeriod === tab.value }"
            @click="setPeriod(tab.value)"
          >
            {{ tab.label }}
          </button>
        </div>
      </div>

      <div class="pnl-number">{{ summary.pnlValue }}</div>
      <div class="pnl-period">{{ summary.pnlPeriod }}</div>

      <div class="chart-shell">
        <svg viewBox="0 0 500 120" class="trend-chart" preserveAspectRatio="none">
          <defs>
            <linearGradient id="trendLine" x1="0%" y1="0%" x2="100%" y2="0%">
              <stop offset="0%" stop-color="#5b4eff" />
              <stop offset="100%" stop-color="#6f63ff" />
            </linearGradient>
          </defs>
          <path :d="chartPath" fill="none" stroke="url(#trendLine)" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </div>
    </section>

    <section class="content-block">
      <div class="section-switcher">
        <button
          type="button"
          class="section-switcher__tab"
          :class="{ 'section-switcher__tab--active': activeSegment === 'holding' }"
          @click="activeSegment = 'holding'"
        >
          持仓
        </button>
        <button
          type="button"
          class="section-switcher__tab"
          :class="{ 'section-switcher__tab--active': activeSegment === 'records' }"
          @click="activeSegment = 'records'"
        >
          交易记录
        </button>
      </div>

      <div v-if="activeSegment === 'holding'" class="toolbar-row">
        <button type="button" class="toolbar-pill" :class="{ 'toolbar-pill--active': activeStatus === 'active' }" @click="activeStatus = 'active'">生效中</button>
        <button type="button" class="toolbar-pill" :class="{ 'toolbar-pill--active': activeStatus === 'closed' }" @click="activeStatus = 'closed'">已结束</button>
        <button type="button" class="toolbar-pill toolbar-pill--sort">⇅ 价值</button>
      </div>

    </section>

    <section v-if="activeSegment === 'holding'" class="position-list">
      <article v-for="item in positionCards" :key="item.key" class="position-card surface-card">
        <div class="position-card__left">
          <div class="position-logo" :class="`position-logo--${item.tint}`">
            <img v-if="item.icon" :src="item.icon" :alt="item.title" class="position-logo__image" />
            <span v-else>{{ item.logo }}</span>
          </div>
          <div class="position-copy">
            <h3 class="position-title">{{ item.title }}</h3>
            <div class="position-meta">
              <span class="position-badge" :class="{ 'position-badge--negative': !item.positive }">{{ item.subtitle }} {{ item.badge }}</span>
              <span class="position-shares">{{ item.shares }}</span>
            </div>
          </div>
        </div>
        <div class="position-card__right">
          <div class="position-value">{{ item.value }}</div>
          <div class="position-pnl" :class="{ 'position-pnl--negative': !item.positive }">{{ item.pnl }}</div>
        </div>
      </article>

      <div v-if="!positionCards.length && !loading" class="empty-records surface-card">
        <div class="empty-records__title">暂无持仓</div>
        <p class="empty-records__text">当前条件下没有可展示的持仓数据。</p>
      </div>
    </section>

    <section v-else class="position-list">
      <article v-for="item in activityCards" :key="item.key" class="position-card surface-card">
        <div class="position-card__left">
          <div class="position-logo" :class="`position-logo--${item.positive ? 'emerald' : 'rose'}`">{{ item.positive ? '↗' : '↘' }}</div>
          <div class="position-copy">
            <h3 class="position-title">{{ item.title }}</h3>
            <div class="position-meta">
              <span class="position-badge" :class="{ 'position-badge--negative': !item.positive }">{{ item.subtitle }}</span>
              <span class="position-shares">{{ item.detail }}</span>
            </div>
          </div>
        </div>
        <div class="position-card__right">
          <div class="position-value">{{ item.value }}</div>
        </div>
      </article>

      <div v-if="!activityCards.length && !loading" class="empty-records surface-card">
        <div class="empty-records__title">暂无交易记录</div>
        <p class="empty-records__text">当前没有可展示的活动数据。</p>
      </div>
    </section>
  </div>
</template>

<style scoped>
.portfolio-page {
  min-height: 100vh;
  padding: 24px 14px calc(112px + var(--safe-bottom));
  background:
    radial-gradient(circle at top left, rgba(87, 103, 255, 0.16), transparent 26%),
    linear-gradient(180deg, #fbfbff 0%, #f2f4fb 36%, #eef1f7 100%);
}

.portfolio-hero {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 18px;
}

.portfolio-profile {
  display: flex;
  align-items: center;
  gap: 12px;
}

.portfolio-avatar {
  width: 54px;
  height: 54px;
  border-radius: 50%;
  box-shadow: 0 12px 28px rgba(79, 91, 255, 0.22);
}

.portfolio-name {
  font-size: 20px;
  font-weight: 800;
  letter-spacing: -0.03em;
  color: #111827;
}

.portfolio-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.ghost-icon {
  width: 36px;
  height: 36px;
  border: 0;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.72);
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
  color: #1f2937;
  font-size: 18px;
}

.summary-strip {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0;
  margin-bottom: 18px;
  padding: 18px 12px;
}

.summary-item {
  position: relative;
  text-align: center;
}

.summary-item:not(:last-child)::after {
  content: '';
  position: absolute;
  top: 4px;
  right: 0;
  width: 1px;
  height: calc(100% - 8px);
  background: rgba(148, 163, 184, 0.26);
}

.summary-value {
  font-size: 18px;
  font-weight: 800;
  color: #111827;
  letter-spacing: -0.04em;
}

.summary-label {
  margin-top: 4px;
  font-size: 12px;
  color: #6b7280;
}

.pnl-card {
  padding: 18px 16px 16px;
  border-radius: 26px;
  margin-bottom: 20px;
}

.pnl-header {
  display: grid;
  gap: 14px;
}

.pnl-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  color: #ef4444;
  font-size: 13px;
  font-weight: 700;
}

.pnl-dot {
  width: 0;
  height: 0;
  border-left: 5px solid transparent;
  border-right: 5px solid transparent;
  border-top: 8px solid #ef4444;
}

.pnl-tabs {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  padding-bottom: 4px;
}

.period-tab {
  flex: 0 0 auto;
  border: 0;
  border-radius: 999px;
  padding: 6px 10px;
  background: transparent;
  color: #6b7280;
  font-size: 12px;
  font-weight: 700;
}

.period-tab--active {
  background: #edf2ff;
  color: #4f46e5;
}

.pnl-number {
  margin-top: 14px;
  font-size: 44px;
  line-height: 1;
  font-weight: 800;
  letter-spacing: -0.08em;
  color: #111827;
}

.pnl-period {
  margin-top: 6px;
  color: #9ca3af;
  font-size: 13px;
}

.chart-shell {
  margin-top: 12px;
  border-radius: 18px;
  background: linear-gradient(180deg, rgba(255,255,255,0.72), rgba(255,255,255,0.35));
  overflow: hidden;
}

.trend-chart {
  width: 100%;
  height: 140px;
  display: block;
}

.content-block {
  margin-bottom: 14px;
}

.section-switcher {
  display: flex;
  gap: 22px;
  margin-bottom: 14px;
}

.section-switcher__tab {
  border: 0;
  background: transparent;
  padding: 0;
  color: #9ca3af;
  font-size: 18px;
  font-weight: 800;
}

.section-switcher__tab--active {
  color: #111827;
}

.toolbar-row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 12px;
}

.toolbar-pill {
  border: 0;
  border-radius: 14px;
  padding: 11px 10px;
  background: rgba(255, 255, 255, 0.86);
  color: #4b5563;
  font-size: 14px;
  font-weight: 700;
  box-shadow: 0 10px 22px rgba(15, 23, 42, 0.06);
}

.toolbar-pill--active {
  background: #f3f4f6;
  color: #111827;
}

.toolbar-pill--sort {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 6px;
}

.search-shell {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.9);
  color: #9ca3af;
  box-shadow: inset 0 0 0 1px rgba(226, 232, 240, 0.9);
}

.search-shell__icon {
  font-size: 16px;
}

.search-shell__input {
  width: 100%;
  border: 0;
  outline: none;
  background: transparent;
  color: #111827;
  font-size: 14px;
}

.position-list {
  display: grid;
  gap: 12px;
}

.position-card {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 14px;
}

.position-card__left {
  display: flex;
  gap: 12px;
  min-width: 0;
}

.position-logo {
  flex: 0 0 auto;
  width: 52px;
  height: 52px;
  border-radius: 16px;
  display: grid;
  place-items: center;
  overflow: hidden;
  font-size: 25px;
  box-shadow: inset 0 0 0 1px rgba(255,255,255,0.6);
}

.position-logo__image {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.position-logo--emerald { background: linear-gradient(135deg, #d1fae5, #86efac); }
.position-logo--blue { background: linear-gradient(135deg, #dbeafe, #bfdbfe); }
.position-logo--orange { background: linear-gradient(135deg, #fff7ed, #fed7aa); }
.position-logo--gold { background: linear-gradient(135deg, #fef3c7, #fde68a); }
.position-logo--teal { background: linear-gradient(135deg, #ccfbf1, #99f6e4); }
.position-logo--rose { background: linear-gradient(135deg, #ffe4e6, #fecdd3); }

.position-copy {
  min-width: 0;
}

.position-title {
  margin: 0;
  color: #111827;
  font-size: 15px;
  line-height: 1.35;
  font-weight: 700;
}

.position-meta {
  margin-top: 8px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.position-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  min-height: 28px;
  padding: 0 10px;
  border-radius: 999px;
  background: #dcfce7;
  color: #16a34a;
  font-size: 13px;
  font-weight: 700;
}

.position-badge--negative {
  background: #fee2e2;
  color: #ef4444;
}

.position-shares {
  color: #9ca3af;
  font-size: 13px;
}

.position-card__right {
  text-align: right;
  flex: 0 0 auto;
}

.position-value {
  color: #111827;
  font-size: 15px;
  font-weight: 800;
}

.position-pnl {
  margin-top: 8px;
  color: #16a34a;
  font-size: 13px;
  font-weight: 700;
}

.position-pnl--negative {
  color: #ef4444;
}

.empty-records {
  padding: 18px;
}

.empty-records__title {
  color: #111827;
  font-size: 16px;
  font-weight: 800;
}

.empty-records__text {
  margin: 8px 0 0;
  color: #6b7280;
  font-size: 14px;
  line-height: 1.6;
}
</style>
